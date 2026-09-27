<?php

namespace App\Services\Telegram;

use App\Models\Event;
use App\Support\Telegram\CaptionTemplate;
use App\Support\Telegram\VenueName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Подпись поста ленты. Если сборка упала, а caption в записи пуст, бот соберёт текст
 * сам старой копией (_build_event_caption_from_template в kudab-bot).
 */
final class EventCaptionBuilder
{
    /** events.timezone парсер не пишет, пояс один на всех */
    private const TZ = 'Europe/Moscow';

    /** как MONTHS_RU_SHORT в боте (domain/events_dto.py) */
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
     * @param  CarbonImmutable|null  $asOf  день выхода поста: от него считаются «сегодня»
     *                                      и «завтра», текст собирают заранее
     * @param  int|null  $itemId  запись очереди, её номер уходит в utm_content (см. PostLink)
     */
    public function build(
        Event $event,
        string $templateCode = 'basic',
        ?CarbonImmutable $asOf = null,
        ?int $itemId = null,
    ): string {
        $body = $this->templateBody($templateCode);

        return $this->assemble($this->raw($event), $body, $templateCode, $asOf, $itemId);
    }

    /**
     * Превью для редактора шаблонов: тело приходит из формы, код тот же, что у поста.
     */
    public function buildWithBody(Event $event, string $body, ?CarbonImmutable $asOf = null): string
    {
        return $this->assemble($this->raw($event), $body, 'preview', $asOf);
    }

    /**
     * toArray() плюс venue_name и interest_slugs: вложенный venue из toArray()
     * firstNonEmpty не читает. Связи тянутся здесь, так что без with() у вызывающего подпись не ломается.
     *
     * @return array<string, mixed>
     */
    private function raw(Event $event): array
    {
        $raw = $event->toArray();

        try {
            $raw['venue_name'] = VenueName::label($event->venue?->name);
        } catch (\Throwable $e) {
            // строка места возьмёт хвост адреса, пост не теряем
            Log::warning('caption.venue_unavailable', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            $raw['venue_name'] = '';
        }

        try {
            // порядок rank из Event::interests: первая тема главная, по ней значок
            $raw['interest_slugs'] = $event->interests->pluck('slug')->filter()->values()->all();
        } catch (\Throwable $e) {
            Log::warning('caption.interests_unavailable', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            $raw['interest_slugs'] = [];
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function assemble(
        array $raw,
        ?string $body,
        string $templateCode,
        ?CarbonImmutable $asOf,
        ?int $itemId = null,
    ): string {
        $ctx = $this->context($raw, $asOf, $itemId);

        if ($body === null || trim($body) === '') {
            // fallback-текста, как в боте, здесь нет: пустой шаблон — исключение
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
    private function context(array $raw, ?CarbonImmutable $asOf = null, ?int $itemId = null): array
    {
        $city = $this->firstNonEmpty($raw, ['city', 'city_name']);
        $addressRaw = trim((string) ($raw['address'] ?? ''));
        $address = $this->normalizeAddress($city, $addressRaw);
        $placeShort = $this->placeShort($raw, $city, $address);

        // город НЕ убирать: в city населённый пункт (Рамонь, Костёнки), а не город канала
        $loc = implode(', ', array_values(array_filter(
            [trim($city), trim($placeShort !== '' ? $placeShort : $address)],
            static fn (string $s): bool => $s !== '',
        )));

        // имена площадок из парсеров («Bar&Kitchen»): без экранирования Telegram отклонит весь пост
        $locSafe = htmlspecialchars($loc, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // lead — фраза модели, about — релиз, пустой при фразе, чтобы текст не ушёл дважды.
        // Та же пара в боте (_build_event_caption_from_template), правятся вместе
        $lead = $this->firstNonEmpty($raw, ['tg_description']);
        $about = $lead !== ''
            ? ''
            : $this->firstNonEmpty($raw, ['description', 'short_description', 'excerpt', 'body', 'text']);

        $canonicalUrl = $this->firstNonEmpty($raw, ['canonical_url', 'external_url', 'source_url', 'original_url']);
        $eventId = trim((string) ($raw['id'] ?? $raw['event_id'] ?? ''));

        return [
            // тоже чужой текст из парсеров, см. $locSafe
            'title' => htmlspecialchars(
                $this->firstNonEmpty($raw, ['title', 'name']) ?: 'Без названия',
                ENT_NOQUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
            'description' => $this->firstNonEmpty($raw, ['tg_description', 'description', 'short_description', 'excerpt', 'body', 'text']),
            'lead' => $lead,
            'about' => $about,
            // фраза модели, а без неё релиз (то же значение, что description);
            // один {lead} в шаблоне оставит без текста посты, где фразы нет
            'text' => $lead !== '' ? $lead : $about,
            // целый <blockquote> или пусто: голый <blockquote>{text}</blockquote> даст пустую цитату без текста
            'quote' => self::quoteBlock($lead !== '' ? $lead : $about),
            'address' => $locSafe,
            'place' => $locSafe,
            'location' => $locSafe,
            'start_time' => $this->startHuman($raw, $asOf),
            'kind_emoji' => self::kindEmoji($raw),
            'price_label' => $this->priceLabel($raw, $canonicalUrl),
            'price_url' => trim((string) ($raw['price_url'] ?? '')),
            'price_status' => trim((string) ($raw['price_status'] ?? '')),
            'price_text' => trim((string) ($raw['price_text'] ?? '')),
            'canonical_url' => $canonicalUrl,
            'url' => $this->eventUrl($eventId, $itemId),
            // целый тег или пусто, как quote: голый {canonical_url} в href вёл бы в никуда
            'original_link' => $canonicalUrl === '' ? '' : $this->link($canonicalUrl, 'Открыть оригинал'),
            'more_link' => $eventId === '' ? '' : $this->link($this->eventUrl($eventId, $itemId), 'Подробнее на kudab.ru'),
            'tags' => '',
        ];
    }

    /**
     * Подавление включено: значок стоит вплотную к названию, и повторять тему,
     * уже названную в нём словом, незачем.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function kindEmoji(array $raw): string
    {
        return \App\Support\Telegram\KindEmoji::for($raw, suppressWhenTitleSaysIt: true);
    }

    /**
     * Срез и экранирование здесь, а не фильтрами шаблона: фильтр задел бы и сами теги <blockquote>.
     */
    private static function quoteBlock(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = CaptionTemplate::sentence($text, 400);
        $safe = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<blockquote>'.$safe.'</blockquote>';
    }

    /** неразрывные пробелы, чтобы подпись ссылки не разорвал перенос */
    private function link(string $href, string $label): string
    {
        $text = str_replace(' ', "\u{00A0}", $label);

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$text.'</a>';
    }

    private function eventUrl(string $eventId, ?int $itemId = null): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');

        if ($eventId === '') {
            return $base;
        }

        return PostLink::utm($base.'/events/'.$eventId, PostLink::MEDIUM_EVENT, $itemId);
    }


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

        // нормализация как в боте перед datetime.fromisoformat
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
        // venue_name кладёт raw(), уже через VenueName::label
        $name = $this->firstNonEmpty($raw, ['venue_name', 'place', 'venue', 'location_name']);
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


    /**
     * Пустая строка с 📍 заполняется местом. $loc уже экранирован (то же, что {address}),
     * второй раз не экранировать: подписчик увидит «Bar&amp;Kitchen».
     */
    private function fixEmptyLocationLine(string $caption, string $loc): string
    {
        $lines = explode("\n", $caption);
        foreach ($lines as $i => $line) {
            if (! str_starts_with(trim($line), '📍')) {
                continue;
            }
            if (trim(mb_substr(trim($line), 1)) === '' && $loc !== '') {
                $lines[$i] = '📍 '.$loc;
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

        $anchor = '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a>';

        // 1. Цена отдельной строкой «💸 {price_label}» — заменяем строку целиком.
        $lines = explode("\n", $caption);
        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), '💸')) {
                $lines[$i] = '💸 '.$anchor;

                return trim(implode("\n", $lines));
            }
        }

        // 2. Цена внутри строки («📍 … · 💸 {price_label}»): подменяем саму подпись, свой 💸 не добавляем
        $label = trim((string) ($ctx['price_label'] ?? ''));
        if ($label !== '' && str_contains($caption, $label)) {
            $pos = mb_strpos($caption, $label);

            return trim(
                mb_substr($caption, 0, $pos)
                .$anchor
                .mb_substr($caption, $pos + mb_strlen($label))
            );
        }

        // 3. Подписи в шаблоне нет вовсе — дописываем, чтобы ссылка не пропала.
        return trim($caption."\n".'💸 '.$anchor);
    }

    /**
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
                ."Подробнее{$nb}на{$nb}kudab.ru</a>";
        }
        $parts[] = '<a href="'.htmlspecialchars($ctx['canonical_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            ."Открыть{$nb}оригинал</a>";

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
