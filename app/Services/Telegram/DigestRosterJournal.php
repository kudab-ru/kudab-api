<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Журнал состава подборок: какой состав выбрал автомат и что потом поменял человек.
 * Отчёт по нему показывает, какую долю строк автомата владелец оставляет.
 */
final class DigestRosterJournal
{
    private const TABLE = 'roster_edits';

    public const AUTO = 'auto';

    public const ADD = 'add';

    public const REMOVE = 'remove';

    public const REPLACE = 'replace';

    public const REORDER = 'reorder';

    /** Сколько последних правок показывает отчёт. */
    private const RECENT = 80;

    /**
     * Автомат выбрал состав.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     */
    public function picked(TelegramChatBroadcastItem $item, array $before, array $after): void
    {
        $this->write($item, self::AUTO, $before, $after);
    }

    /**
     * Человек поправил состав.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @param  array{in?: int|null, out?: int|null, cooled?: bool, reason?: string|null, missed?: array{rule: string, note: string}|null}  $details
     */
    public function edited(TelegramChatBroadcastItem $item, string $action, array $before, array $after, array $details = []): void
    {
        $this->write($item, $action, $before, $after, $details);
    }

    /** Когда автомат последний раз выбирал состав записи; null — не выбирал с тех пор, как ведётся журнал. */
    public function pickedAt(int $itemId): ?Carbon
    {
        $at = DB::table(self::TABLE)->where('item_id', $itemId)->where('action', self::AUTO)->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @param  array<string, mixed>  $details
     */
    private function write(TelegramChatBroadcastItem $item, string $action, array $before, array $after, array $details = []): void
    {
        $reason = trim((string) ($details['reason'] ?? ''));
        $missed = $details['missed'] ?? null;

        $row = [
            'scope' => 'digest',
            'rubric' => $item->digestTheme(),
            'item_id' => $item->id,
            'broadcast_id' => $item->broadcast_id,
            'action' => $action,
            'event_in' => $details['in'] ?? null,
            'event_out' => $details['out'] ?? null,
            'roster_before' => json_encode(array_values(array_map('intval', $before))),
            'roster_after' => json_encode(array_values(array_map('intval', $after))),
            'cooled' => (bool) ($details['cooled'] ?? false),
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 300),
            'missed_rule' => $missed['rule'] ?? null,
            'missed_note' => isset($missed['note']) ? mb_substr((string) $missed['note'], 0, 300) : null,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ];

        // Журнал не должен ронять сборку и правку: при выкате новый код встаёт раньше миграции.
        // Своя точка отката внутри общей транзакции, иначе ошибка вставки испортила бы и её.
        try {
            DB::transaction(fn () => DB::table(self::TABLE)->insert($row));
        } catch (Throwable $e) {
            Log::warning('broadcast.digest.journal_failed', [
                'item_id' => $item->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отчёт по рубрикам канала: доля строк автомата, которые остались в вышедших подборках,
     * что убирают и добавляют руками, и последние правки.
     *
     * @return array{rubrics: list<array<string, mixed>>, entries: list<array<string, mixed>>}
     */
    public function report(int $broadcastId): array
    {
        $themes = collect((array) config('broadcast_digest.themes', []))->keyBy('slug');
        $rows = DB::table(self::TABLE.' as j')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'j.item_id')
            ->where('j.scope', 'digest')
            ->where('j.broadcast_id', $broadcastId)
            ->orderBy('j.id')
            ->get(['j.*', 'i.status as item_status']);

        $final = DB::table('telegram.chat_broadcast_item_events')
            ->whereIn('item_id', $rows->pluck('item_id')->unique()->all())
            ->where('position', '>', 0)
            ->get(['item_id', 'event_id'])
            ->groupBy('item_id')
            ->map(fn ($g) => $g->pluck('event_id')->map(fn ($v) => (int) $v)->all());

        $titles = $this->titles($rows);
        $interests = $this->mainInterests($rows);

        $stats = [];
        foreach ($rows->groupBy('item_id') as $itemId => $entries) {
            $auto = $entries->where('action', self::AUTO)->last();
            $rubric = (string) ($auto->rubric ?? $entries->last()->rubric ?? '');
            if ($rubric === '' || $entries->first()->item_status !== TelegramChatBroadcastItem::STATUS_POSTED || $auto === null) {
                continue;
            }

            $s = $stats[$rubric] ?? ['issues' => 0, 'auto_lines' => 0, 'kept' => 0, 'removed' => [], 'added' => [], 'missed' => []];
            $picked = json_decode((string) $auto->roster_after, true) ?: [];
            $s['issues']++;
            $s['auto_lines'] += count($picked);
            $s['kept'] += count(array_intersect($picked, $final[$itemId] ?? []));

            foreach ($entries->where('id', '>', $auto->id) as $e) {
                if ($e->event_out !== null) {
                    $s['removed'][] = $interests[(int) $e->event_out] ?? 'без темы';
                }
                if ($e->event_in !== null) {
                    $s['added'][] = $interests[(int) $e->event_in] ?? 'без темы';
                    if ((string) $e->missed_rule !== '') {
                        $s['missed'][] = (string) $e->missed_rule;
                    }
                }
            }
            $stats[$rubric] = $s;
        }

        $rubrics = [];
        foreach ($stats as $slug => $s) {
            $rubrics[] = [
                'slug' => $slug,
                'title' => (string) ($themes[$slug]['title'] ?? $slug),
                'emoji' => (string) ($themes[$slug]['emoji'] ?? ''),
                'issues' => $s['issues'],
                'auto_lines' => $s['auto_lines'],
                'kept' => $s['kept'],
                'removed' => self::counted($s['removed']),
                'added' => self::counted($s['added']),
                'missed' => array_map(
                    fn ($c) => ['label' => BroadcastDigestComposer::MISS_NOTES[$c['label']] ?? $c['label'], 'count' => $c['count']],
                    self::counted($s['missed']),
                ),
            ];
        }
        usort($rubrics, fn ($a, $b) => $b['issues'] <=> $a['issues']);

        $users = DB::table('users')->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $named = fn (array $ids) => array_map(fn ($id) => ['id' => (int) $id, 'title' => $titles[(int) $id] ?? '—'], $ids);

        $entries = [];
        foreach ($rows->sortByDesc('id')->take(self::RECENT) as $e) {
            $entries[] = [
                'id' => (int) $e->id,
                'at' => Carbon::parse($e->created_at)->toIso8601String(),
                'item_id' => (int) $e->item_id,
                'rubric' => (string) ($themes[$e->rubric]['title'] ?? $e->rubric ?? ''),
                'action' => (string) $e->action,
                'user' => $e->user_id !== null ? ($users[$e->user_id] ?? 'человек') : null,
                'in' => $e->event_in !== null ? $named([$e->event_in])[0] : null,
                'out' => $e->event_out !== null ? $named([$e->event_out])[0] : null,
                'before' => $named(json_decode((string) $e->roster_before, true) ?: []),
                'after' => $named(json_decode((string) $e->roster_after, true) ?: []),
                'cooled' => (bool) $e->cooled,
                'reason' => $e->reason,
                'missed' => $e->missed_note,
            ];
        }

        return ['rubrics' => $rubrics, 'entries' => $entries];
    }

    /**
     * Названия всех событий журнала одним запросом.
     *
     * @return array<int, string>
     */
    private function titles(\Illuminate\Support\Collection $rows): array
    {
        $ids = [];
        foreach ($rows as $e) {
            $ids = [...$ids, ...(json_decode((string) $e->roster_before, true) ?: []), ...(json_decode((string) $e->roster_after, true) ?: [])];
            $ids[] = $e->event_in;
            $ids[] = $e->event_out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return DB::table('events')->whereIn('id', $ids)->pluck('title', 'id')->map(fn ($t) => (string) $t)->all();
    }

    /**
     * Основная тема (интерес) убранных и добавленных событий: по ней видно, что владелец чаще правит.
     *
     * @return array<int, string>
     */
    private function mainInterests(\Illuminate\Support\Collection $rows): array
    {
        $ids = $rows->pluck('event_in')->merge($rows->pluck('event_out'))->filter()->unique()->values()->all();

        return DB::table('event_interest as ei')
            ->join('interests as n', 'n.id', '=', 'ei.interest_id')
            ->whereIn('ei.event_id', $ids)
            ->where('ei.rank', 0)
            ->pluck('n.name', 'ei.event_id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /**
     * @param  list<string>  $labels
     * @return list<array{label: string, count: int}>
     */
    private static function counted(array $labels): array
    {
        $counts = array_count_values($labels);
        arsort($counts);

        return array_map(fn ($label, $count) => ['label' => (string) $label, 'count' => $count], array_keys($counts), $counts);
    }
}
