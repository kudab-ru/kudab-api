<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Kulisy;

use App\Models\Venue;
use App\Models\VenueManager;
use App\Services\Analytics\AnalyticsUnavailable;
use App\Services\Analytics\MetrikaClient;
use App\Services\Kulisy\VenueClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Площадка организатора: заявить, подтвердить кодом в описании группы ВК или заявкой нам,
 * и первый экран кабинета с её зрителями.
 */
class KulisyVenueController extends Controller
{
    // кассы, где страница события и есть покупка билета; тот же список в ticketHref.ts сайта
    private const TICKET_OFFICES = '^https?://([^/]*\\.)?(afisha\\.yandex\\.ru|bezantrakta\\.ru|kassir\\.ru|qtickets\\.events|live\\.mts\\.ru|quicktickets\\.ru)(/|$)';

    public function __construct(private readonly VenueClaims $claims) {}

    public function claim(Request $request, int $id): JsonResponse
    {
        $venue = Venue::findOrFail($id);
        if ($this->takenByOther($venue, $request->user()->id)) {
            return $this->taken();
        }

        $claim = VenueManager::firstOrCreate(
            ['venue_id' => $venue->id, 'user_id' => $request->user()->id],
            ['code' => $this->claims->code(), 'vk_url' => $this->claims->groupsFor($venue)[0] ?? null],
        );

        return response()->json(['data' => $this->serializeClaim($claim)]);
    }

    public function check(Request $request, int $id): JsonResponse
    {
        $claim = VenueManager::where(['venue_id' => $id, 'user_id' => $request->user()->id])->firstOrFail();
        if ($claim->status === 'confirmed') {
            return response()->json(['data' => $this->serializeClaim($claim)]);
        }
        if ($this->takenByOther($claim->venue, $request->user()->id)) {
            return $this->taken();
        }

        return match ($this->claims->check($claim)) {
            'confirmed' => response()->json(['data' => $this->serializeClaim($claim->fresh())]),
            'no_group' => $this->fail('no_group', 'своей группы ВК у площадки kudab не знает, оставьте заявку'),
            'not_found' => $this->fail('not_found', 'в описании группы строки пока нет. сохранили? тогда попробуйте через минуту'),
            default => $this->fail('vk_failed', 'ВК не ответил, попробуйте через минуту'),
        };
    }

    // заявку разбирает владелец kudab в админке, раздел «Заявки»: «подключили» подтверждает площадку
    public function manual(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['contact' => ['nullable', 'string', 'max:300'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user();
        $claim = VenueManager::where(['venue_id' => $id, 'user_id' => $user->id])->firstOrFail();
        if ($claim->status !== 'pending') {
            return response()->json(['data' => $this->serializeClaim($claim)]);
        }

        $tg = $user->telegramUsers()->value('telegram_username');
        DB::table('organizer_leads')->insert([
            'kind' => 'venue',
            'venue_manager_id' => $claim->id,
            'source_url' => $claim->vk_url,
            'contact' => trim((string) ($data['contact'] ?? '')) ?: ($user->email ?? ($tg ? '@'.$tg : 'Telegram, пользователь #'.$user->id)),
            'comment' => trim('Подтвердить площадку «'.$claim->venue->name.'» (#'.$claim->venue_id.') за кулисами, пользователь #'.$user->id.'. '.($data['comment'] ?? '')),
            'page_path' => '/kulisy',
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $claim->forceFill(['status' => 'manual'])->save();

        return response()->json(['data' => $this->serializeClaim($claim)]);
    }

    public function cabinet(Request $request): JsonResponse
    {
        $claims = $request->user()->venueManagers()->with('venue')->latest('updated_at')->get();
        $mine = $claims->firstWhere('status', 'confirmed');
        if ($mine === null) {
            $open = $claims->first();

            return response()->json(['data' => ['venue' => null, 'claim' => $open ? $this->serializeClaim($open) : null, 'stats' => null]]);
        }

        $venue = $mine->venue;

        return response()->json(['data' => [
            'venue' => ['id' => $venue->id, 'name' => $venue->name, 'slug' => $venue->slug],
            'claim' => null,
            'stats' => $this->stats($venue),
        ]]);
    }

    /**
     * Сколько людей открывали страницы площадки: по заголовку, а не по адресу — у склеенных
     * событий старый адрес уводит на новый, и счёт по адресу занижает.
     */
    private function stats(Venue $venue): array
    {
        $today = now('Europe/Moscow')->startOfDay();
        $upcoming = DB::table('events')->where('venue_id', $venue->id)->where('status', 'active')
            ->where(fn ($q) => $q->where('start_time', '>=', $today->copy()->utc())->orWhere(fn ($q2) => $q2->whereNull('start_time')->where('start_date', '>=', $today->toDateString())));

        $views = Cache::get($key = 'kulisy:venue:'.$venue->id.':'.$today->toDateString());
        if ($views === null) {
            try {
                $views = ['month' => $this->users($venue->name, 29), 'week' => $this->users($venue->name, 6)];
                Cache::put($key, $views, now()->addHours(3));
            } catch (AnalyticsUnavailable) {
                $views = ['month' => null, 'week' => null];
            }
        }

        return $views + [
            'upcoming' => (clone $upcoming)->count(),
            'with_tickets' => (clone $upcoming)->where(fn ($q) => $q->where('price_url', '!=', '')->orWhere('external_url', '~*', self::TICKET_OFFICES))->count(),
        ];
    }

    private function users(string $name, int $days): int
    {
        $totals = app(MetrikaClient::class)->visits([
            'date1' => now('Europe/Moscow')->subDays($days)->toDateString(),
            'date2' => now('Europe/Moscow')->toDateString(),
            'metrics' => 'ym:pv:users',
            'filters' => "ym:pv:title=@'".str_replace("'", "\\'", $name)."'",
        ])['totals'] ?? [];

        return (int) ($totals[0] ?? 0);
    }

    private function takenByOther(Venue $venue, int $userId): bool
    {
        return VenueManager::where('venue_id', $venue->id)->where('status', 'confirmed')->where('user_id', '!=', $userId)->exists();
    }

    private function taken(): JsonResponse
    {
        return response()->json(['code' => 'taken', 'message' => 'площадку уже подтвердил другой человек'], 409);
    }

    private function fail(string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], 422);
    }

    private function serializeClaim(VenueManager $claim): array
    {
        return [
            'venue' => ['id' => $claim->venue_id, 'name' => $claim->venue?->name],
            'status' => $claim->status,
            'code' => $claim->code,
            'vk_url' => $claim->vk_url,
        ];
    }
}
