
# Admin API (MVP contract)

Цель: зафиксировать минимальный контракт для админки и интеграций.
Swagger пока не используем.

## Base

- Base URL: `/api/admin`
- Auth: `Authorization: Bearer <token>`
- Response формат:
  - list: `{ "meta": {...}, "data": [...] }`
  - item: `{ "data": {...} }`
  - ok: `{ "ok": true }`
- Ошибки:
  - 401 Unauthorized (нет/неверный токен)
  - 403 Forbidden (нет роли/прав)
  - 404 Not found
  - 422 Validation error (Laravel стандарт)

## Soft delete / restore

Модели используют soft delete.

- `DELETE /resource/{id}` → soft delete
- `POST /resource/{id}/restore` → restore

Флаги в списках:
- `with_deleted=1` — включить удалённые в выдачу
- `only_deleted=1` — только удалённые (подразумевает `with_deleted`)

---

# Events

## List

`GET /events`

### Query params

Pagination:
- `page` (int >= 1)
- `per_page` (int 1..100, default 20)

Search / filters:
- `q` (string) — поиск по:
  - `events.title`
  - `events.description`
  - `community.name`
  - `community.description`
- `city_id` (int)
- `community_id` (int)
- `status` (string)

Date filters:
- `date_from` (date or datetime string)
- `date_to` (date or datetime string)

Правило сравнения дат:
- если у события есть `start_time` → сравниваем по `start_time`
- если `start_time` = null, но есть `start_date` → сравниваем по `start_date` (YYYY-MM-DD)

Price:
- `free` (boolean) — если `true`, то событие считается бесплатным когда:
  - `price_status = "free"`
  - ИЛИ (`price_min = 0` и `price_max IS NULL`)

Interests:
- `interests[]` (array<int>) — **ANY-логика**:
  событие подходит, если у него есть **хотя бы один** interest из списка.

Soft delete flags:
- `with_deleted` (boolean)
- `only_deleted` (boolean)

Sorting:
- `sort` ∈ `id | title | start_date | start_time | created_at | updated_at | price_min`
- `dir` ∈ `asc | desc`
- Особенности (Postgres):
  - для `start_date`, `start_time`, `price_min` используется `NULLS LAST`

### Example

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/events?per_page=10&page=1&city_id=14&q=театр&sort=start_date&dir=asc" | jq
````

### Response

```json
{
  "meta": { "current_page": 1, "per_page": 10, "total": 21, "last_page": 3 },
  "data": [
    {
      "id": 32,
      "title": "...",
      "start_time": "2026-01-21T13:00:00.000000Z",
      "start_date": "2026-01-21",
      "city": { "id": 14, "name": "Воронеж", "slug": "voronezh" },
      "community": { "id": 1, "name": "...", "city": "Воронеж", "avatar_url": null },
      "interests": []
    }
  ]
}
```

## Show

`GET /events/{id}`

* Возвращает событие, включая soft-deleted.

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/events/32" | jq
```

## Create

`POST /events`

* Body: поля события + `interests[]` (опционально).
* Возвращает созданное событие в формате `{ data: ... }`.

## Update

`PATCH /events/{id}`

* Частичное обновление.
* `interests[]` если передан — синхронизируется (replace).

## Delete (soft)

`DELETE /events/{id}`

## Restore

`POST /events/{id}/restore`

---

# Communities

## List

`GET /communities`

### Query params

Pagination:

* `page` (int >= 1)
* `per_page` (int 1..100, default 20)

Search / filters:

* `q` (string) — поиск по `name`, `description` (и другим полям, если добавлены)
* `city_id` (int)
* `verification_status` (string)

Soft delete flags:

* `with_deleted` (boolean)
* `only_deleted` (boolean)

Sorting:

* `sort` ∈ `id | name | created_at | updated_at | last_checked_at`
* `dir` ∈ `asc | desc`

## Show

`GET /communities/{id}`

* Возвращает community, включая soft-deleted.

## Create

`POST /communities`

## Update

`PATCH /communities/{id}`

## Delete (soft)

`DELETE /communities/{id}`

## Restore

`POST /communities/{id}/restore`

---

# Select endpoints

Формат ответа общий:

```json
{
  "data": [
    { "id": 14, "label": "Воронеж" }
  ]
}
```

## Cities

`GET /select/cities`

Query:

* `q` (string, optional)
* `limit` (int, default 10, max 50)
* Preload:

    * `id` или `ids` (например `ids=1,2,3`) — вернуть выбранные значения

Example:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/cities?q=вор&limit=10" | jq
```

## Communities

`GET /select/communities`

Query:

* `q` (string, optional)
* `limit` (int, default 10, max 50)
* `with_deleted` (boolean, optional)
* Preload:

    * `id` или `ids`

Example:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/communities?q=театр&limit=10&with_deleted=1" | jq
```

Label правило (UI-friendly):

* Пример: `"Никитинский театр · Воронеж"`

## Interests

`GET /select/interests`

Query:

* `q` (string, optional)
* `limit` (int, default 10, max 50)
* Preload:

    * `id` или `ids`

Example:

```bash
curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/interests?q=муз&limit=10" | jq
```
