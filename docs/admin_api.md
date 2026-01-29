# Admin API (минимальная документация)

---

## База

Все эндпоинты ниже начинаются с:

- `/api/admin/...`

Формат ответов: JSON.

---

## Авторизация

Обязательна для всех запросов.

Заголовки:

- `Accept: application/json`
- `Authorization: Bearer <TOKEN>`

Если токена нет или он неверный:

```json
{ "message": "Unauthenticated." }
````

---

## Общий формат ответов

### Список с пагинацией

```json
{
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 123,
    "last_page": 7
  },
  "data": [ ... ]
}
```

### Одна сущность

```json
{ "data": { ... } }
```

### Селекты

```json
{
  "data": [
    { "id": 1, "label": "..." }
  ]
}
```

### Мягкое удаление (soft delete)

* `DELETE` не удаляет запись физически, а проставляет `deleted_at`
* `POST .../restore` восстанавливает

Ответ на удаление:

```json
{ "ok": true }
```

---

## Ошибки

### Валидация (422)

Laravel вернет 422 с описанием ошибок (формат стандартный для Laravel).

### Не найдено (404)

Если запись не найдена.

### Доступ запрещен (403)

Если токен валидный, но у пользователя нет нужной роли/прав.

---

## Events (события)

### GET `/api/admin/events`

Список событий с фильтрами и сортировкой.

Параметры:

* `page` (int, >= 1)
* `per_page` (int, 1..100, по умолчанию 20)

Поиск:

* `q` (string) поиск по `title`, `description`, а также по сообществу (`community.name`, `community.description`)

Фильтры:

* `city_id` (int)
* `community_id` (int)
* `status` (string)
* `date_from` (date) рекомендуемый формат `YYYY-MM-DD`
* `date_to` (date) рекомендуемый формат `YYYY-MM-DD`
* `free` (bool) если `true`, то событие считается бесплатным если:

    * `price_status = "free"`
    * или `price_min = 0` и `price_max = null`
* `interests[]` (array<int>) фильтр по интересам работает как ANY:
  событие подходит, если у него есть хотя бы один интерес из списка

Удаленные:

* `with_deleted` (bool) включает удаленные (возвращает и живые, и удаленные)
* `only_deleted` (bool) возвращает только удаленные

Правило приоритета:

* если `only_deleted=true`, он имеет приоритет над `with_deleted=true`

Сортировка:

* `sort` one of: `id`, `title`, `start_date`, `start_time`, `created_at`, `updated_at`, `price_min`
* `dir` one of: `asc`, `desc`

Особенности сортировки:

* Для `start_date`, `start_time`, `price_min` используется `NULLS LAST` (Postgres).

Пример:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/events?per_page=5&page=1&city_id=14&free=1&interests[]=2&interests[]=7" | jq
```

### GET `/api/admin/events/{id}`

Показывает событие по id, включая мягко удаленные.

### POST `/api/admin/events`

Создает событие. (Тело запроса соответствует полям модели, включая `interests[]`.)

### PATCH `/api/admin/events/{id}`

Обновляет событие.

### DELETE `/api/admin/events/{id}`

Мягко удаляет событие. Ответ:

```json
{ "ok": true }
```

### POST `/api/admin/events/{id}/restore`

Восстанавливает мягко удаленное событие.

---

## Communities (сообщества)

### GET `/api/admin/communities`

Список сообществ с фильтрами и сортировкой.

Параметры:

* `page` (int, >= 1)
* `per_page` (int, 1..100, по умолчанию 20)

Поиск:

* `q` (string) поиск по `name`, `description`
  Примечание: если в таблице нет `external_id`, то по нему не ищем.

Фильтры:

* `city_id` (int)
* `verification_status` (string)

Удаленные:

* `with_deleted` (bool) включает удаленные (возвращает и живые, и удаленные)
* `only_deleted` (bool) возвращает только удаленные

Правило приоритета:

* если `only_deleted=true`, он имеет приоритет над `with_deleted=true`

Сортировка:

* `sort` one of: `id`, `name`, `created_at`, `updated_at`, `last_checked_at`
* `dir` one of: `asc`, `desc`

### GET `/api/admin/communities/{id}`

Показывает сообщество по id, включая мягко удаленные.

### POST `/api/admin/communities`

Создает сообщество.

### PATCH `/api/admin/communities/{id}`

Обновляет сообщество.

### DELETE `/api/admin/communities/{id}`

Мягко удаляет сообщество. Ответ:

```json
{ "ok": true }
```

### POST `/api/admin/communities/{id}/restore`

Восстанавливает мягко удаленное сообщество.

---

## Select (селекты для форм)

Назначение: быстрые выпадающие списки. Ответ всегда:

```json
{ "data": [ { "id": 1, "label": "..." } ] }
```

Общие параметры для всех select-эндпоинтов:

* `q` (string) строка поиска
* `limit` (int, 1..50, по умолчанию 20)

### Preload режим (чтобы подставить уже выбранные значения)

Во всех селектах можно вместо `q` передать:

* `id=<число>` для одного значения
* `ids=1,2,3` списком через запятую
* `ids[]=1&ids[]=2` массивом

Правила:

* если передан `id` или `ids`, параметр `q` игнорируется
* в preload сервер старается вернуть **все переданные ids** (даже если `limit` меньше), но с разумным капом
* `limit` в preload — “желательное”, а не жесткий потолок
* для `communities` удаленные выбранные значения в preload возвращаются автоматически (чтобы форма могла их подставить)

    * `with_deleted/only_deleted` актуальны в основном для обычного поиска (когда ids не переданы)

---

### GET `/api/admin/select/cities`

Возвращает города.

Label:

* просто название города

Пример:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/cities?q=вор&limit=10" | jq
```

---

### GET `/api/admin/select/communities`

Возвращает сообщества.

Доп. параметры:

* `city_id` (int) фильтр по городу (удобно в форме события)
* `with_deleted` (bool) включает удаленные (поиск/список)
* `only_deleted` (bool) возвращает только удаленные (поиск/список)

Правило приоритета:

* если `only_deleted=true`, он имеет приоритет над `with_deleted=true`

Поиск:

* по `name` и `description` (без `external_id`)

Label:

* базово: `name`
* если у сообщества есть город и название города не встречается в `name`, добавляем ` · <Город>`
* если `deleted_at` не null, добавляем ` (deleted)`

Пример:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/communities?q=театр&limit=10&with_deleted=1" | jq
```

Preload примеры:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/communities?id=5" | jq

curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/communities?ids=5,3&limit=2" | jq
```

---

### POST `/api/admin/communities/import`

Импортирует сообщество по ссылке и (опционально) ставит задачу автоверификации.

Тело запроса:

* `url` (string, обязателен) ссылка на VK / Telegram / сайт
* `auto_verify` (bool, опционально) если `true`, то сразу создается запись в outbox на автоверификацию

Что делает импорт:

* нормализует URL:

    * если нет схемы — добавит `https://`
    * уберет `www.`
    * уберет хвостовой `/`
* определяет источник по host:

    * VK → `vk.com`, `vkontakte.ru`
    * Telegram → `t.me`, `telegram.me`
    * иначе → `site`
* пытается извлечь `external_community_id`:

    * VK: `.../club123`, `.../public123`, `.../event123`, а также `wall-123...`
    * Telegram: `t.me/<channel>` (инвайт-ссылки вида `joinchat/...` и `+...` не дают id)
* если VK настроен (token+version), то может подтянуть профиль:

    * `name`, `description`, `avatar_url`, `image_url`, `canonical_url`, `external_community_id`
* идемпотентность:

    * если ссылка уже есть — вернет существующую запись (`ingest_exists`) или обновит поля (`ingest_updated`)
    * если ссылки нет — создаст `зcommunity` и `community_social_link` (`ingest_created`/`ingest_resolved`)

Ответ:

* `200` если ссылка уже существовала
* `201` если создана новая запись

Формат ответа:

```json
{
  "community_id": 10,
  "social_link_id": 13,
  "status": "ingest_created",
  "external_community_id": "123",
  "url": "https://vk.com/...",
  "verify": {
    "status": "verify_queued",
    "outbox_id": 55
  }
}
```

Поле `status`:

* `ingest_created` — создано без онлайн-резолва (например, VK не настроен или это не VK)
* `ingest_resolved` — создано и заполнено из онлайн-резолва (VK настроен)
* `ingest_exists` — ссылка уже была, изменений почти нет
* `ingest_updated` — ссылка/поля обновлены

Поле `verify`:

* присутствует только если `auto_verify=true`
* `status` возвращается как `verify_<...>`:

    * `verify_queued` — задача поставлена
    * `verify_already_queued` — уже стоит в очереди
    * `verify_requeued` — переочередили старую
    * `verify_dedup_conflict_not_found` — редкий кейс гонки (почти не должен происходить)

Пример:

```bash
curl -sS -X POST "http://127.0.0.1:8088/api/admin/communities/import" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://vk.com/popcorn.drama","auto_verify":true}' | jq
```

---

### POST `/api/admin/communities/{id}/verify`

Ставит задачу автоверификации сообщества (через outbox). Сам запрос **не выполняет** верификацию синхронно.

Тело запроса:

* `sources` (array<string>, опционально) источники:

    * `["vk"]`, `["tg"]`, `["site"]`
    * `["vk","tg"]` и т.п.
    * спец-режимы: `["auto"]` (1 источник по приоритету), `["all"]` (все ссылки)
    * если не передано — по умолчанию `["vk"]`
* `limit_per_source` (int, опционально, по умолчанию 30)
* `overwrite` (bool, опционально, по умолчанию false) — перезаписывать `city/street/house`, даже если уже заполнено
* `clear_aggregator` (bool, опционально, по умолчанию false) — очищать `street/house` если итоговый `kind=aggregator`

Ответ: `202 Accepted`

```json
{
  "ok": true,
  "status": "verify_queued",
  "outbox_id": 55,
  "community_id": 10
}
```

`status` возвращается как `verify_<...>`:

* `verify_queued`
* `verify_already_queued`
* `verify_requeued`
* `verify_dedup_conflict_not_found`

Важное:

* Дедупликация идет по сообществу: одновременно активно обычно только одно задание на `community_id`.
* Если сообщество вручную помечено `approved/rejected` (в `verification_meta.manual_final=true`),
  то автопроверка **не перезаписывает** `verification_status` и `is_verified`,
  но может обновлять `verification_meta.final` и `_auto_*` (для прозрачности авто-результата).

Пример:

```bash
curl -sS -X POST "http://127.0.0.1:8088/api/admin/communities/10/verify" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"sources":["vk"],"limit_per_source":20,"overwrite":false,"clear_aggregator":false}' | jq
```

---

### GET `/api/admin/select/interests`

Возвращает интересы.

Label:

* просто название интереса

Пример:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/interests?q=муз&limit=10" | jq
```

Preload пример:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/interests?ids[]=2&ids[]=7" | jq
```
