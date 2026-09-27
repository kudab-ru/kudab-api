<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramChatBroadcast extends Model
{
    protected $table = 'telegram.chat_broadcasts';

    protected $fillable = [
        'chat_id',
        'enabled',
        'settings',
        'last_run_at',
        'last_preview_at',
        'idle_notified_at',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'settings' => 'array',
        'last_run_at' => 'datetime',
        'last_preview_at' => 'datetime',
        'idle_notified_at' => 'datetime',
    ];

    /**
     * Связанный телеграм-чат (telegram.chats).
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    /** Больше четырёх постов в день канал-афиша не выдержит по содержанию. */
    public const MAX_SLOTS = 4;

    /**
     * Сколько событийных записей держать в ленте канала одновременно;
     * портреты и подборки не в счёт.
     */
    public function getFeedLimitAttribute(): int
    {
        $settings = $this->settings ?? [];
        $raw = $settings['feed_limit'] ?? null;

        if (! is_numeric($raw)) {
            return max(1, min(31, $this->horizon_days * max(1, count($this->slots))));
        }

        return max(1, min(31, (int) $raw));
    }

    public function setFeedLimitAttribute(int $limit): void
    {
        $settings = $this->settings ?? [];
        $settings['feed_limit'] = max(1, min(31, $limit));
        $this->settings = $settings;
    }

    /**
     * Часы публикации внутри дня по Москве, например [10, 19].
     * Пусто — один слот, час из period.
     *
     * @return list<int>
     */
    public function getSlotsAttribute(): array
    {
        $raw = ($this->settings ?? [])['slots'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $hours = [];
        foreach ($raw as $h) {
            if (! is_numeric($h)) {
                continue;
            }
            $hour = (int) $h;
            if ($hour >= 0 && $hour <= 23) {
                $hours[] = $hour;
            }
        }

        $hours = array_values(array_unique($hours));
        sort($hours);

        return array_slice($hours, 0, self::MAX_SLOTS);
    }

    /** @param  array<int|string>  $hours */
    public function setSlotsAttribute(array $hours): void
    {
        $settings = $this->settings ?? [];

        $clean = [];
        foreach ($hours as $h) {
            if (! is_numeric($h)) {
                continue;
            }
            $hour = (int) $h;
            if ($hour >= 0 && $hour <= 23) {
                $clean[] = $hour;
            }
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        $settings['slots'] = array_slice($clean, 0, self::MAX_SLOTS);
        $this->settings = $settings;
    }

    /** На сколько дней вперёд собирается лента (число записей — feed_limit). */
    public function getHorizonDaysAttribute(): int
    {
        $raw = ($this->settings ?? [])['horizon_days'] ?? null;

        if (! is_numeric($raw)) {
            return 7;
        }

        return max(1, min(31, (int) $raw));
    }

    public function setHorizonDaysAttribute(int $days): void
    {
        $settings = $this->settings ?? [];
        $settings['horizon_days'] = max(1, min(31, $days));
        $this->settings = $settings;
    }

    /**
     * За сколько дней вперёд заполняются слоты дня после первого; первый
     * собирается на весь горизонт. null — все слоты сразу на весь горизонт.
     */
    public function getFillLeadDaysAttribute(): ?int
    {
        $raw = ($this->settings ?? [])['fill_lead_days'] ?? null;

        if (! is_numeric($raw)) {
            return null;
        }

        return max(1, min(31, (int) $raw));
    }

    public function setFillLeadDaysAttribute(?int $days): void
    {
        $settings = $this->settings ?? [];

        if ($days === null) {
            unset($settings['fill_lead_days']);
        } else {
            $settings['fill_lead_days'] = max(1, min(31, $days));
        }

        $this->settings = $settings;
    }

    /**
     * Как часто канал публикует портрет площадки. Потолок частоты задаёт пул,
     * см. TelegramVenuePortraitService::POOL_PER_WEEKLY_POST.
     */
    public function getPortraitEveryDaysAttribute(): int
    {
        $raw = ($this->settings ?? [])['portrait_every_days'] ?? null;

        if (! is_numeric($raw)) {
            return 7;
        }

        return max(1, min(90, (int) $raw));
    }

    public function setPortraitEveryDaysAttribute(int $days): void
    {
        $settings = $this->settings ?? [];
        $settings['portrait_every_days'] = max(1, min(90, $days));
        $this->settings = $settings;
    }

    /** Минимальный зазор между постами канала; 0 — без зазора. */
    public function getMinGapMinutesAttribute(): int
    {
        $raw = ($this->settings ?? [])['min_gap_minutes'] ?? null;

        if (! is_numeric($raw)) {
            return 90;
        }

        return max(0, min(24 * 60, (int) $raw));
    }

    public function setMinGapMinutesAttribute(int $minutes): void
    {
        $settings = $this->settings ?? [];
        $settings['min_gap_minutes'] = max(0, min(24 * 60, $minutes));
        $this->settings = $settings;
    }

    /**
     * Слоты подборок: [{weekday: 1..7, hour: 0..23, theme: slug|null}].
     *
     * Было одно число `digest_weekday` и час, намертво равный последнему слоту
     * канала, — то есть не больше одной подборки в неделю и без выбора часа.
     * Старая настройка читается как один слот, пока не задан новый список.
     *
     * @return list<array{weekday: int, hour: int, theme: string|null}>
     */
    public function getDigestSlotsAttribute(): array
    {
        $raw = ($this->settings ?? [])['digest_slots'] ?? null;

        if (! is_array($raw)) {
            $legacy = $this->legacyDigestWeekday();

            return $legacy === null
                ? []
                : [['weekday' => $legacy, 'hour' => $this->fallbackDigestHour(), 'theme' => null]];
        }

        $out = [];
        foreach ($raw as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $weekday = (int) ($slot['weekday'] ?? 0);
            $hour = (int) ($slot['hour'] ?? -1);
            if ($weekday < 1 || $weekday > 7 || $hour < 0 || $hour > 23) {
                continue;
            }
            $theme = trim((string) ($slot['theme'] ?? ''));
            $out[] = ['weekday' => $weekday, 'hour' => $hour, 'theme' => $theme !== '' ? $theme : null];
        }

        return array_values($out);
    }

    /** @param  list<array<string, mixed>>  $slots */
    public function setDigestSlotsAttribute(array $slots): void
    {
        $settings = $this->settings ?? [];

        $clean = [];
        foreach ($slots as $slot) {
            $weekday = (int) ($slot['weekday'] ?? 0);
            $hour = (int) ($slot['hour'] ?? -1);
            if ($weekday < 1 || $weekday > 7 || $hour < 0 || $hour > 23) {
                continue;
            }
            $theme = trim((string) ($slot['theme'] ?? ''));
            $key = $weekday.':'.$hour;
            $clean[$key] = ['weekday' => $weekday, 'hour' => $hour, 'theme' => $theme !== '' ? $theme : null];
        }

        $settings['digest_slots'] = array_values($clean);
        $this->settings = $settings;
    }

    private function legacyDigestWeekday(): ?int
    {
        $raw = ($this->settings ?? [])['digest_weekday'] ?? null;
        if (! is_numeric($raw)) {
            return null;
        }
        $day = (int) $raw;

        return $day >= 1 && $day <= 7 ? $day : null;
    }

    /** Час по старой раскладке: последний слот канала. */
    private function fallbackDigestHour(): int
    {
        $slots = $this->slots;
        if ($slots !== []) {
            return (int) max($slots);
        }

        if (preg_match('/_(\d{1,2})$/', (string) $this->period, $m)) {
            return max(0, min(23, (int) $m[1]));
        }

        return 19;
    }

    /**
     * День недели подборки: 1 — понедельник, 7 — воскресенье. null — рубрика
     * выключена; это умолчание, чтобы рубрика не вышла в канал сама после выкатки.
     */
    public function getDigestWeekdayAttribute(): ?int
    {
        // Первый слот: старые места кода спрашивают «какой день у рубрики».
        return $this->digest_slots[0]['weekday'] ?? null;
    }

    public function setDigestWeekdayAttribute(?int $weekday): void
    {
        $settings = $this->settings ?? [];
        $settings['digest_weekday'] = $weekday !== null && $weekday >= 1 && $weekday <= 7
            ? $weekday
            : null;
        // Список слотов задан — старая настройка его не трогает.
        if (! isset($settings['digest_slots'])) {
            $this->settings = $settings;

            return;
        }

        $settings['digest_slots'] = $weekday === null
            ? []
            : [['weekday' => $weekday, 'hour' => $this->digest_slots[0]['hour'] ?? $this->fallbackDigestHour(), 'theme' => $this->digest_slots[0]['theme'] ?? null]];

        $this->settings = $settings;
    }

    /** Час подборки: вечерний слот канала. Без слотов — час расписания. */
    public function getDigestHourAttribute(): int
    {
        return $this->digest_slots[0]['hour'] ?? $this->fallbackDigestHour();
    }

    /**
     * За сколько минут до слота парсер пишет анонс посту ленты. Без настройки
     * парсер берёт llm_text.tg_lead_minutes, умолчание здесь держать таким же.
     */
    public function getTextLeadMinutesAttribute(): int
    {
        $raw = ($this->settings ?? [])['text_lead_minutes'] ?? null;

        if (! is_numeric($raw)) {
            return max(1, (int) config('services.bot.broadcast_text_lead_minutes', 60));
        }

        return max(1, min(24 * 60, (int) $raw));
    }

    public function setTextLeadMinutesAttribute(int $minutes): void
    {
        $settings = $this->settings ?? [];
        $settings['text_lead_minutes'] = max(1, min(24 * 60, $minutes));
        $this->settings = $settings;
    }

    /**
     * Писать ли анонсы ИИ автоматически перед публикацией. Заявку «написать
     * текст» из админки выключатель не отменяет.
     */
    public function getAiTextAttribute(): bool
    {
        $raw = ($this->settings ?? [])['ai_text'] ?? null;

        return $raw === null ? true : (bool) $raw;
    }

    public function setAiTextAttribute(bool $on): void
    {
        $settings = $this->settings ?? [];
        $settings['ai_text'] = $on;
        $this->settings = $settings;
    }

    public function getPeriodAttribute(): string
    {
        $settings = $this->settings ?? [];

        return (string) ($settings['period'] ?? 'off');
    }

    public function setPeriodAttribute(string $period): void
    {
        $settings = $this->settings ?? [];
        $settings['period'] = $period;
        $this->settings = $settings;
    }

    public function getTemplateCodeAttribute(): string
    {
        $settings = $this->settings ?? [];

        return (string) ($settings['template_code'] ?? 'basic');
    }

    public function setTemplateCodeAttribute(string $templateCode): void
    {
        $settings = $this->settings ?? [];
        $settings['template_code'] = $templateCode;
        $this->settings = $settings;
    }

    /**
     * Формы поста, которые канал чередует по дням. Пусто — одна форма, template_code.
     *
     * @return list<string>
     */
    public function getTemplateRotationAttribute(): array
    {
        $settings = $this->settings ?? [];
        $codes = $settings['template_rotation'] ?? [];

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $codes,
        ), static fn ($c) => $c !== ''));
    }

    /**
     * Форма поста на день: весь день одной формой, назавтра другая.
     * Не по id записи: id идут с пропусками, и одна форма встаёт дважды подряд.
     * Сутки от эпохи, а не день месяца, чтобы на стыке месяцев не было повтора.
     */
    public function templateCodeForDate(?\DateTimeInterface $date): string
    {
        $rotation = $this->template_rotation;
        if ($rotation === [] || $date === null) {
            return $this->template_code;
        }

        $days = intdiv($date->getTimestamp(), 86400);

        return $rotation[$days % count($rotation)];
    }

    public function items()
    {
        return $this->hasMany(TelegramChatBroadcastItem::class, 'broadcast_id');
    }
}
