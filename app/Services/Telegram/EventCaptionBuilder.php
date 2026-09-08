<?php

namespace App\Services\Telegram;

use App\Models\Event;
use App\Support\Telegram\CaptionTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Сборка подписи поста рассылки — перенос из бота в API.
 *
 * ЗАЧЕМ. До этого API отдавал боту только event_id и код шаблона, а текст бот
 * собирал сам (services/kudab-bot/app/bot/router/handlers/events.py:297 и
 * app/bot/domain/events_dto.py). Из-за этого текста поста нигде не
 * существовало: ни отредактировать его, ни показать честное превью в админке
 * было нельзя. Теперь текст строится здесь и хранится вместе с записью очереди.
 *
 * ПРАВИЛО ПЕРЕНОСА. Повторяем поведение бота ПОБАЙТОВО, включая странности:
 * заголовок не экранируется, строка тегов не печатается никогда, у пустого
 * адреса остаётся висящий пробел после эмодзи. Эти вещи уже уходили
 * подписчикам; чинить их надо отдельно и осознанно, а не заодно с переездом,
 * иначе нельзя будет понять, что именно изменило текст.
 *
 * ВХОД. Берём Event::toArray() — ровно то, что отдаёт боту
 * EventController::show (response()->json($event), без ресурса). Значит на
 * вход нормализации приходит тот же массив, что и раньше, и расхождение может
 * появиться только в самой нормализации.
 */
final class EventCaptionBuilder
{
    /** Часовой пояс. events.timezone = NULL у всех строк, другого источника нет. */
    private const TZ = 'Europe/Moscow';

    /** Месяцы сокращённо — как в боте (events_dto.py:10-24). */
    private const MONTHS = [
        1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'май', 6 => 'июн',
        7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
    ];

    /** Статусы цены, которые бот считает известными; всё прочее — unknown. */
    private const PRICE_STATUSES = ['unknown', 'free', 'paid', 'range', 'donation', 'external', 'tbd'];

    public function __construct(
        private readonly TelegramMessageTemplateService $templates,
    ) {}

    /**
     * @param  CarbonImmutable|null  $asOf  день, КОГДА пост увидят. От него
     *                                      считаются «сегодня» и «завтра»: текст собирается заранее, иногда за
     *                                      неделю, и относительно момента сборки эти слова врут подписчику.
     */
    public function build(Event $event, string $templateCode = 'basic', ?CarbonImmutable $asOf = null): string
    {
        $raw = $event->toArray();

        $body = $this->templateBody($templateCode);

        return $this->assemble($raw, $body, $templateCode, $asOf);
    }

    /**
     * Собрать текст по ПРОИЗВОЛЬНОМУ шаблону, не сохраняя его.
     *
     * Нужно редактору шаблонов: превью обязано идти тем же кодом, что и
     * настоящий пост, иначе оно врёт — а именно ради «увидеть, что получится»
     * редактор и делается.
     */
    public function buildWithBody(Event $event, string $body, ?CarbonImmutable $asOf = null): string
    {
        return $this->assemble($event->toArray(), $body, 'preview', $asOf);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function assemble(array $raw, ?string $body, string $templateCode, ?CarbonImmutable $asOf): string
    {
        $ctx = $this->context($raw, $asOf);

        if ($body === null || trim($body) === '') {
            // Бот в этом случае уходит в _build_event_caption_fallback, который
            // даёт ДРУГОЙ текст. Здесь это не воспроизводим: шаблоны лежат в
            // той же базе, и их отсутствие — авария, а не штатная ветка.
            Log::warning('caption.template_not_found', ['template_code' => $templateCode]);

            throw new \RuntimeException("Шаблон поста «{$templateCode}» не найден.");
        }

        $caption = trim(CaptionTemplate::render($body, $ctx));

        $caption = $this->fixEmptyLocationLine($caption, (string) $ctx['address']);
        $caption = $this->applyExternalPrice($caption, $ctx);
        $caption = $this->appendMoreAndOriginal($caption, $ctx);

        return $caption;
    }

    private function templateBody(string $code): ?string
    {
        $tpl = $this->templates->findActiveSingleByCode($code);
        $body = is_object($tpl) ? (string) ($tpl->body ?? '') : '';

        return $body === '' ? null : $body;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function context(array $raw, ?CarbonImmutable $asOf = null): array
    {
        $city = $this->firstNonEmpty($raw, ['city', 'city_name']);
        $addressRaw = trim((string) ($raw['address'] ?? ''));
        $address = $this->normalizeAddress($city, $addressRaw);
        $placeShort = $this->placeShort($raw, $city, $address);

        // Строка места: город и «что-то поконкретнее». Ровно как в боте
        // (events.py:322-327) — сюда же подставляются {place} и {location}.
        $loc = implode(', ', array_values(array_filter(
            [trim($city), trim($placeShort !== '' ? $placeShort : $address)],
            static fn (string $s): bool => $s !== '',
        )));

        $canonicalUrl = $this->firstNonEmpty($raw, ['canonical_url', 'external_url', 'source_url', 'original_url']);
        $eventId = trim((string) ($raw['id'] ?? $raw['event_id'] ?? ''));

        return [
            // Заголовок ЭКРАНИРУЕМ. В боте он подставлялся сырым, и событие с
            // «<» или «&» в названии ломало разметку Telegram: пост уходил
            // битым или не уходил вовсе. Названия приходят из парсеров, то
            // есть это чужой текст, а шаблон оборачивает его в <b>…</b>.
            //
            // Это единственное осознанное отступление от побайтового переноса.
            // Оно меняет вывод ТОЛЬКО у событий, где в названии есть & < >, —
            // у остальных строка совпадает с прежней символ в символ.
            'title' => htmlspecialchars(
                $this->firstNonEmpty($raw, ['title', 'name']) ?: 'Без названия',
                ENT_NOQUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
            'description' => $this->firstNonEmpty($raw, ['tg_description', 'description', 'short_description', 'excerpt', 'body', 'text']),
            'address' => $loc,
            'place' => $loc,
            'location' => $loc,
            'start_time' => $this->startHuman($raw, $asOf),
            'price_label' => $this->priceLabel($raw, $canonicalUrl),
            'price_url' => trim((string) ($raw['price_url'] ?? '')),
            'price_status' => trim((string) ($raw['price_status'] ?? '')),
            'price_text' => trim((string) ($raw['price_text'] ?? '')),
            'canonical_url' => $canonicalUrl,
            'url' => $this->eventUrl($eventId),
            // Ключа tags в боте нет вовсе, значение всегда пустое, и строка
            // «🏷 …» не печаталась ни разу. Сохраняем это поведение явно.
            'tags' => '',
        ];
    }

    private function eventUrl(string $eventId): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');

        return $eventId === '' ? $base : $base.'/events/'.$eventId;
    }

    // ------------------------------------------------------------------
    // Дата и время
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     */
    private function startHuman(array $raw, ?CarbonImmutable $asOf = null): string
    {
        $rawStart = $this->firstNonEmpty($raw, ['start_time', 'start_date', 'start_at', 'start', 'date']);
        if ($rawStart === '') {
            return '';
        }

        $dt = $this->parseMsk($rawStart);
        if ($dt === null) {
            // Бот в этом случае подставляет исходную строку как есть.
            return $rawStart;
        }

        // Точка отсчёта — день публикации, а не «сейчас». Пост про концерт
        // 12-го, поставленный 8-го на 11-е, обязан читаться «завтра», а не
        // «12 сен» и уж точно не «сегодня».
        $today = ($asOf ?? CarbonImmutable::now(self::TZ))->setTimezone(self::TZ)->startOfDay();
        $day = $dt->startOfDay();

        // Полночь по МСК — признак «время неизвестно», а не «в 00:00».
        $hasTime = ! ($dt->hour === 0 && $dt->minute === 0);
        $time = $dt->format('H:i');

        if ($day->equalTo($today)) {
            return $hasTime ? "сегодня, {$time}" : 'сегодня';
        }
        if ($day->equalTo($today->addDay())) {
            return $hasTime ? "завтра, {$time}" : 'завтра';
        }

        $date = $dt->day.' '.self::MONTHS[$dt->month];

        return $hasTime ? "{$date} {$time}" : $date;
    }

    private function parseMsk(string $s): ?CarbonImmutable
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // 'Z' → '+00:00' и обрезка микросекунд длиннее шести знаков — так же,
        // как это делает бот перед datetime.fromisoformat.
        if (str_ends_with($s, 'Z')) {
            $s = substr($s, 0, -1).'+00:00';
        }
        $s = preg_replace('/(\.\d{6})\d+/', '$1', $s) ?? $s;

        try {
            $dt = CarbonImmutable::parse($s);
        } catch (\Throwable) {
            return null;
        }

        // Строка без пояса считается уже московской — это поведение бота.
        $hasTz = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s);

        return $hasTz
            ? $dt->setTimezone(self::TZ)
            : CarbonImmutable::parse($s, self::TZ);
    }

    // ------------------------------------------------------------------
    // Цена
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     */
    private function priceLabel(array $raw, string $canonicalUrl): string
    {
        $status = mb_strtolower(trim((string) ($raw['price_status'] ?? '')));
        $known = in_array($status, self::PRICE_STATUSES, true) ? $status : 'unknown';

        $min = $this->toInt($raw['price_min'] ?? null);
        $max = $this->toInt($raw['price_max'] ?? null);
        $sym = $this->currencySymbol(mb_strtoupper(trim((string) ($raw['price_currency'] ?? ''))));
        $priceUrl = trim((string) ($raw['price_url'] ?? ''));
        $priceText = trim((string) ($raw['price_text'] ?? ''));

        $label = match ($known) {
            'free' => 'Бесплатно',
            'donation' => 'Донат / свободный взнос',
            'paid', 'range' => $this->paidLabel($min, $max, $sym),
            'external' => $this->externalLabel($priceUrl, $canonicalUrl, $priceText),
            default => 'Уточняется',
        };

        // Обратная совместимость: если структурного статуса нет вовсе, берём
        // легаси-поля. Так в боте (events_dto.py:501-509).
        if (trim((string) ($raw['price_status'] ?? '')) === '') {
            $legacy = $this->firstNonEmpty($raw, ['price_label', 'price', 'cost']);
            if ($legacy !== '') {
                $label = $legacy;
            }
        }

        return $label !== '' ? $label : 'Уточняется';
    }

    private function paidLabel(?int $min, ?int $max, string $sym): string
    {
        if ($min !== null && $max !== null) {
            // Разделитель — EN DASH без пробелов вокруг, как в боте.
            return $min === $max ? "{$min} {$sym}" : "{$min} {$sym}\u{2013}{$max} {$sym}";
        }
        if ($min !== null) {
            return "от {$min} {$sym}";
        }
        if ($max !== null) {
            return "до {$max} {$sym}";
        }

        return 'Уточняется';
    }

    private function externalLabel(string $priceUrl, string $canonicalUrl, string $priceText): string
    {
        if ($priceUrl !== '' || $canonicalUrl !== '') {
            return 'Цена по ссылке';
        }

        // EM DASH — как в боте.
        return $priceText === ''
            ? "Билеты/цена \u{2014} по ссылке у организатора"
            : 'Цена по ссылке у организатора';
    }

    private function currencySymbol(string $code): string
    {
        return match ($code) {
            '', 'RUB', 'RUR', '₽' => "\u{20BD}",
            'EUR' => '€',
            'USD' => '$',
            default => $code,
        };
    }

    // ------------------------------------------------------------------
    // Адрес и площадка
    // ------------------------------------------------------------------

    /**
     * «394036, Воронежская обл, г Воронеж, пр-кт Революции, д 46»
     * → «г Воронеж, пр-кт Революции, д 46».
     */
    private function normalizeAddress(string $city, string $addressRaw): string
    {
        $addressRaw = trim($addressRaw);
        if ($addressRaw === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $addressRaw)), static fn ($p) => $p !== ''));
        if ($parts === []) {
            return '';
        }

        $cityNorm = mb_strtolower(trim($city));
        $regionMarkers = ['обл', 'область', 'край', 'респ', 'республика', 'округ', 'район', 'рай.'];

        if ($cityNorm !== '') {
            foreach ($parts as $i => $part) {
                if (mb_strtolower($part) === $cityNorm) {
                    return implode(', ', array_slice($parts, $i));
                }
            }
            foreach ($parts as $i => $part) {
                $pl = mb_strtolower($part);
                $isRegion = false;
                foreach ($regionMarkers as $mark) {
                    if (str_contains($pl, $mark)) {
                        $isRegion = true;
                        break;
                    }
                }
                if (str_contains($pl, $cityNorm) && ! $isRegion) {
                    return implode(', ', array_slice($parts, $i));
                }
            }
        }

        $cleaned = [];
        foreach ($parts as $part) {
            $pl = mb_strtolower($part);
            if (preg_match('/^\d{5,6}$/', $part)) {
                continue;
            }
            $drop = str_contains($pl, 'россия') || str_contains($pl, 'рф');
            foreach ($regionMarkers as $mark) {
                if (str_contains($pl, $mark)) {
                    $drop = true;
                    break;
                }
            }
            if ($drop) {
                continue;
            }
            $cleaned[] = $part;
        }

        return $cleaned !== [] ? implode(', ', $cleaned) : $addressRaw;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function placeShort(array $raw, string $city, string $address): string
    {
        // venue в сыром JSON — объект связи, а не строка; берём только строки,
        // как это делает _extract_first_non_empty_string в боте.
        $name = $this->firstNonEmpty($raw, ['place', 'venue', 'location_name']);
        if ($name !== '') {
            return $name;
        }

        if ($address === '') {
            return '';
        }

        // Хвост адреса «улица, дом»: последние два сегмента. Один сегмент не
        // берём — «г Воронеж» в роли названия площадки бесполезно.
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return '';
        }

        return implode(', ', array_slice($parts, -2));
    }

    // ------------------------------------------------------------------
    // Постобработка — три шага, в том же порядке, что и в боте
    // ------------------------------------------------------------------

    /** Пустая строка с 📍 заполняется местом. Для нынешних шаблонов ветка мертва. */
    private function fixEmptyLocationLine(string $caption, string $loc): string
    {
        $lines = explode("\n", $caption);
        foreach ($lines as $i => $line) {
            if (! str_starts_with(trim($line), '📍')) {
                continue;
            }
            if (trim(mb_substr(trim($line), 1)) === '' && $loc !== '') {
                $lines[$i] = '📍 '.htmlspecialchars($loc, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            break;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, string>  $ctx
     */
    private function applyExternalPrice(string $caption, array $ctx): string
    {
        if (mb_strtolower($ctx['price_status']) !== 'external') {
            return $caption;
        }

        $href = $ctx['price_url'] !== '' ? $ctx['price_url'] : $ctx['canonical_url'];
        if ($href === '') {
            return $caption;
        }

        $text = $ctx['price_text'] !== '' ? $ctx['price_text'] : 'Цена по ссылке';
        if (mb_strlen($text) > 120) {
            $text = rtrim(mb_substr($text, 0, 119))."\u{2026}";
        }

        $anchor = '💸 <a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a>';

        $lines = explode("\n", $caption);
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), '💸')) {
                $lines[$i] = $anchor;

                return trim(implode("\n", $lines));
            }
        }

        return trim($caption."\n".$anchor);
    }

    /**
     * Строка «Подробнее на kudab.ru → · Открыть оригинал →».
     *
     * Пробелы внутри подписей НЕРАЗРЫВНЫЕ (U+00A0), между ссылками — ровно
     * десять обычных. Так в боте, и это видно в реальных постах канала.
     *
     * @param  array<string, string>  $ctx
     */
    private function appendMoreAndOriginal(string $caption, array $ctx): string
    {
        $caption = trim($caption);
        if ($caption === '' || $ctx['canonical_url'] === '') {
            return $caption;
        }
        if (str_contains($caption, $ctx['canonical_url'])) {
            return $caption;
        }

        $nb = "\u{00A0}";
        $parts = [];
        if ($ctx['url'] !== '') {
            $parts[] = '<a href="'.htmlspecialchars($ctx['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
                ."Подробнее{$nb}на{$nb}kudab.ru{$nb}\u{2192}</a>";
        }
        $parts[] = '<a href="'.htmlspecialchars($ctx['canonical_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            ."Открыть{$nb}оригинал{$nb}\u{2192}</a>";

        $line = trim(implode(str_repeat(' ', 10), $parts));
        if ($line === '') {
            return $caption;
        }

        $lines = explode("\n", $caption);
        if ($ctx['url'] !== '') {
            foreach ($lines as $i => $l) {
                if (str_contains($l, $ctx['url'])) {
                    $lines[$i] = $line;

                    return trim(implode("\n", $lines));
                }
            }
        }

        return trim(rtrim($caption)."\n".$line);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $keys
     */
    private function firstNonEmpty(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            $v = $raw[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_int($v) || is_float($v)) {
                return trim((string) $v);
            }
        }

        return '';
    }

    private function toInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_numeric($v) ? (int) $v : null;
    }
}
