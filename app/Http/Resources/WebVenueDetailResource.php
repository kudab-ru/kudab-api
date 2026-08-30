<?php

namespace App\Http\Resources;

use App\Support\SocialNetworkLabel;
use App\Support\VenueContactLinks;
use App\Support\VenueKindLabel;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Детальная карточка venue (`/web/venues/{id}`).
 *
 * Поверх WebVenueResource: street/house/fias, description, future_events
 * (top-N грядущих ивентов на этом venue через тот же WebEventResource
 * что в каталоге).
 *
 * Страница площадки — долгоиграющий SEO-актив (в отличие от карточек событий
 * она не протухает), поэтому всё, что ниже, отдаёт ФАКТЫ о месте: тип, ритм
 * программы, ближайшее событие, контакты. Там, где факта нет, стоит null —
 * заглушки вроде «Площадка» или «0 событий в месяц» превращают страницу в тот
 * самый малополезный контент, из-за которого такие страницы вылетают из
 * индекса пачками.
 */
class WebVenueDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $description = $this->describe();
        $next        = $this->getAttribute('next_event_payload');

        return [
            'id'              => (int) $this->id,
            'slug'            => (string) ($this->slug ?? ''),
            'name'            => (string) ($this->name ?? ''),
            'kind'            => $this->kind !== null ? (string) $this->kind : null,

            // Подпись типа в единственном числе: сам kind — категория каталога
            // («Музеи»), под названием места нужен «Музей». См. VenueKindLabel;
            // неизвестный тип → null, блок просто не рисуется.
            'kind_label'      => VenueKindLabel::for($this->kind !== null ? (string) $this->kind : null),

            // Каскад описаний. Поле остаётся строкой — его уже читает фронт
            // (useWebVenueDetail), объект на его месте отрисовался бы как
            // «[object Object]». Происхождение вынесено соседним полем:
            //   own      — текст из venues.description (venues:describe в
            //              парсере либо правка человека через админку);
            //   portrait — портрет из ТГ-канала (venues.tg_portrait): та же
            //              проза о месте, собранная по его реальной программе,
            //              без дат и ссылок, и уже опубликованная в канале.
            // ВАЖНО: own НЕ значит «написано человеком». Отличить ручную правку
            // можно было бы по venues.text_meta.lock (его ставит TextLock), но
            // он пуст у всех 121 площадки — рук до текстов пока не доходило.
            // source отвечает на вопрос «из какой колонки текст», не «кто автор».
            'description'        => $description['text'] ?? null,
            'description_source' => $description['source'] ?? null,

            // Контакты площадки. Сегодня почти всегда пустой массив: в
            // source_meta лежит только трассировка происхождения, ссылок туда
            // никто не пишет — см. VenueContactLinks.
            //
            // НЕ путать с social_accounts ниже: links — контакты, которые место
            // о себе объявило, social_accounts — сообщества, по чьему адресу мы
            // это место в базе и завели. Даже когда ссылка ведёт на один и тот
            // же VK, утверждения разные.
            'links'           => VenueContactLinks::fromSourceMeta($this->source_meta),

            'address'         => $this->address !== null ? (string) $this->address : null,
            'street'          => $this->street !== null ? (string) $this->street : null,
            'house'           => $this->house !== null ? (string) $this->house : null,
            'house_fias_id'   => $this->house_fias_id !== null ? (string) $this->house_fias_id : null,

            'city' => $this->whenLoaded('city', fn () => [
                'id'   => (int) $this->city->id,
                'name' => (string) $this->city->name,
                'slug' => (string) ($this->city->slug ?? ''),
            ]),

            'lat'             => $this->latitude !== null ? (float) $this->latitude : null,
            'lng'             => $this->longitude !== null ? (float) $this->longitude : null,

            'cover_image_url' => $this->getAttribute('cover_image_url') ?: null,
            'avatar_url'      => $this->avatar_url !== null ? (string) $this->avatar_url : null,

            'events_count'    => (int) ($this->getAttribute('events_count') ?? 0),

            // Честное число ПРЕДСТОЯЩИХ видимых событий — то же, что в каталоге
            // (VenuesController::attachUpcoming), не архивный тотал.
            'upcoming_total'  => (int) ($this->getAttribute('upcoming_total') ?? 0),

            // Ближайшее предстоящее событие: {id,title,start_at,start_date,
            // time_precision,url} | null. Считает attachUpcoming() — тот же
            // батч, что для карточек каталога. url — путь на сайте, без хоста:
            // у событий нет slug, канонический адрес карточки — /events/{id}.
            'next_event'      => is_array($next)
                ? $next + ['url' => '/events/'.(int) $next['id']]
                : null,

            // Аккаунты места в соцсетях: сообщества, по HQ-адресу которых эта
            // площадка и появилась в базе. Поле НЕ называется sources намеренно
            // — оно не обещает провенанса афиши (разбор в socialAccounts() и
            // в VenuesController::loadSources). ВСЕГДА массив, никогда null:
            // блок либо есть, либо его нет, и фронту незачем различать «нет
            // аккаунтов» и «поле не пришло». Пуст у 55 площадок из 106.
            'social_accounts' => $this->socialAccounts(),

            // Ритм места: {events_per_month, last_event_at, is_dormant} | null.
            // null — если у площадки нет ни одного видимого события: тогда мы
            // не знаем ни частоты, ни того, спит ли она. См. attachRhythm().
            'rhythm'          => $this->getAttribute('rhythm'),

            // Серверный гейт блока «Здесь уже проходило»: сколько прошедших
            // событий отдаст /venues/{id}/past-events. Без него фронт узнавал
            // об этом только после гидрации, а блок нужен на SSR — из 61
            // площадки без будущей афиши прошлое есть у 41, и это всё, что у
            // страницы вообще имеется.
            //
            // Считается по СОБЫТИЯМ (event_group_id), как upcoming_total, а
            // /past-events листает СЕАНСЫ построчно: у квест-комнаты здесь
            // будет 5, а meta.total там — 754. Число для человека берётся
            // отсюда, длина списка — оттуда.
            'past_total'      => (int) ($this->getAttribute('past_total') ?? 0),

            // «Здесь бывает» — топ interest-тегов по всей истории событий
            // площадки. Пустой массив = профиля нет (мало истории), фронт
            // блок не рисует. Считается в VenuesController::genreProfile().
            'genre_profile'   => array_values($this->getAttribute('genre_profile') ?? []),
            // future_events идут через отдельный /web/events?venue_id=...
            // фронт сам делает второй запрос; в venue-detail держать список
            // events избыточно (rebroadcast той же sql-логики event-репо).
        ];
    }

    /**
     * Аккаунты места в соцсетях: сообщество, его живые ссылки и свежесть.
     * Форма — как у строки-атрибуции события (EventSourceLine): имя, «обновлено
     * N дней назад», прямая ссылка на оригинал; второй формы в проекте быть не
     * должно.
     *
     * ЧТО ЭТО ПОЛЕ ВПРАВЕ УТВЕРЖДАТЬ — и почему оно не называется sources.
     * Связь идёт через FK communities.venue_id, а его ставит парсер, когда
     * заводит площадку по HQ-адресу venue_host-сообщества. Это «аккаунт места»,
     * и только. Провенанс конкретного события лежит в events.community_id и
     * совпадает не всегда: из 51 площадки со связью у 4 названное сообщество не
     * дало ни одного события, а у 24 из 36 часть афиши пришла от сообществ, тут
     * не названных (Я.Афиша, Qtickets, сторонние организаторы). Слово
     * «Источник» на этом поле было бы ложью на полшага; настоящая пофактовая
     * атрибуция живёт на странице события, где мы действительно перепечатываем
     * конкретный пост. Ни «официальный», ни «проверено» тут тоже не появится:
     * мы знаем связь, а не статус.
     *
     * Массив, а не одиночный объект, хотя сегодня у всех 51 площадки ровно по
     * одному сообществу: FK допускает несколько, и модель данных не должна
     * врать про кардинальность — иначе второе сообщество у площадки потребует
     * ломать контракт фронта.
     *
     * Порядок детерминирован (свежие выше, при равенстве — по id): без явной
     * сортировки postgres волен вернуть строки в любом порядке, и блок начал
     * бы прыгать между одинаковыми запросами.
     *
     * @return list<array{community: array{id: int, name: string, avatar_url: string|null}, links: list<array{network: string, url: string, label: string}>, last_post_at: string|null}>
     */
    private function socialAccounts(): array
    {
        if (! $this->relationLoaded('communities')) {
            return [];
        }

        $out = [];
        foreach ($this->communities as $community) {
            $out[] = [
                'community' => [
                    'id'         => (int) $community->id,
                    'name'       => (string) ($community->name ?? ''),
                    // null → фронт рисует монограмму из имени; пустая строка
                    // отрисовалась бы битой картинкой, поэтому именно null
                    'avatar_url' => trim((string) ($community->avatar_url ?? '')) !== ''
                        ? (string) $community->avatar_url
                        : null,
                ],
                'links'        => $this->communityLinks($community),
                'last_post_at' => $this->lastPostDate($community->getAttribute('last_post_at')),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            // свежие сверху; источник без даты уходит в конец ('' меньше любой даты)
            $byDate = strcmp((string) $b['last_post_at'], (string) $a['last_post_at']);

            return $byDate !== 0 ? $byDate : $a['community']['id'] <=> $b['community']['id'];
        });

        return $out;
    }

    /**
     * Живые ссылки сообщества. Гейт качества применён на уровне запроса
     * (VenuesController::loadSources), здесь остаётся отсечь мусор в данных:
     * ссылку без url и сеть без слага показать всё равно нечем.
     *
     * @return list<array{network: string, url: string, label: string}>
     */
    private function communityLinks(mixed $community): array
    {
        if (! $community->relationLoaded('socialLinks')) {
            return [];
        }

        $links = [];
        foreach ($community->socialLinks as $link) {
            $url  = trim((string) ($link->url ?? ''));
            $slug = mb_strtolower(trim((string) ($link->socialNetwork->slug ?? '')), 'UTF-8');
            if ($url === '' || $slug === '') {
                continue;
            }

            $links[] = [
                'network' => $slug,
                'url'     => $url,
                'label'   => SocialNetworkLabel::for($slug, $link->socialNetwork->name ?? null),
            ];
        }

        return $links;
    }

    /**
     * Дата самого свежего поста источника, «YYYY-MM-DD».
     *
     * context_posts.published_at — timestamp БЕЗ таймзоны, и пишется он в UTC
     * (как created_at всего проекта). Отдаём московскую дату: весь остальной
     * ответ — календарь, ритм, ближайшее событие — считает дни по МСК, и
     * «обновлено вчера» не должно означать разные вчера в разных полях.
     */
    private function lastPostDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $at = $raw instanceof DateTimeInterface
            ? Carbon::instance($raw)
            : Carbon::parse((string) $raw, 'UTC');

        return $at->setTimezone('Europe/Moscow')->toDateString();
    }

    /**
     * Заготовка описания, которую когда-то проставили пачкой: «Сцена в городе.»,
     * «Площадка в городе.», «Клуб в городе.», «Парк в городе.» — тип места плюс
     * слово «город», без самого города. Ровно 23 активные площадки.
     *
     * Такой текст неотличим по description_source от написанного человеком, а
     * уезжает он в <p> страницы, в og:description и в schema.org/Place — то
     * есть в поисковую выдачу под видом описания места. Для страницы, которая
     * борется за индекс, это ровно тот «малополезный контент», из-за которого
     * её оттуда и убирают.
     */
    private const DESCRIPTION_STUB = '~^[^.]{1,40}\s+в\s+городе\.$~u';

    /**
     * Описание места и его происхождение. Текст из venues.description выигрывает
     * всегда: там же оседает ручная правка из админки, которую TextLock
     * защищает от перегенерации.
     *
     * Портрет — запасной вариант НА ВЫРОСТ: сегодня он не отдаётся ни разу. Все
     * 35 активных площадок с tg_portrait имеют и собственное описание, и оно
     * выигрывает; 69 страниц из 106 (включая все 23 с отсечённой ниже
     * заготовкой) остаются без текста именно потому, что портрета у них нет.
     * Ветка живёт ради дня, когда parser:tg:venue-portrait доберётся до места
     * без описания: он выбирает площадки по наличию программы, а не текста.
     *
     * Режем на выходе, а не в базе: строку видно в админке (её есть чем
     * заменить руками), повторная генерация в парсере снова её не протащит, и
     * решение обратимо одной строкой. Чистка самих 23 строк — задача парсеру,
     * там же, где живёт venues:describe.
     *
     * @return array{text: string, source: string}|null
     */
    private function describe(): ?array
    {
        $own = trim((string) ($this->description ?? ''));
        if ($own !== '' && preg_match(self::DESCRIPTION_STUB, $own) === 1) {
            $own = '';
        }
        if ($own !== '') {
            return ['text' => $own, 'source' => 'own'];
        }

        $portrait = trim((string) ($this->tg_portrait ?? ''));
        if ($portrait !== '') {
            return ['text' => $portrait, 'source' => 'portrait'];
        }

        return null;
    }
}
