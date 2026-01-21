
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

* `with_deleted` (bool)
* `only_deleted` (bool)

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

* `with_deleted` (bool)
* `only_deleted` (bool)

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
* `limit` работает как верхняя граница
* для `communities` часто нужен `with_deleted=1`, чтобы выбранное удаленное значение тоже вернулось

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
* `with_deleted` (bool)
* `only_deleted` (bool)

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
"http://127.0.0.1:8088/api/admin/select/communities?id=5&with_deleted=1" | jq

curl -sS -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
"http://127.0.0.1:8088/api/admin/select/communities?ids=5,3&limit=2&with_deleted=1" | jq
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

