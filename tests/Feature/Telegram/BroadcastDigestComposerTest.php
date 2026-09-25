<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\BroadcastDigestComposer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Композитор подборки: тема, состав, текст.
 *
 * Собирается перед самой отправкой — собранная заранее подборка показывает
 * пятую часть недели. Отбор обязан вычитать то, что канал уже показал, иначе
 * подборка становится оглавлением прочитанного.
 */
class BroadcastDigestComposerTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $interestId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $this->cityId = $this->city();
        $this->interestId = (int) DB::table('interests')->insertGetId([
            'name' => 'Театр', 'slug' => 'theatre', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_composes_a_theme_with_three_named_events(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out, 'шести событий на шести площадках хватает на тему');
        $this->assertSame('spektakli', $out['theme']['slug']);
        $this->assertCount(3, $out['event_ids'], 'называем три события');
        $this->assertSame(6, $out['total']);
        $this->assertStringContainsString('Спектакли недели', $out['caption']);
        $this->assertStringContainsString('Вся афиша спектаклей', $out['caption'],
            'подвал зовёт на лендинг и числа не обещает: недельного фильтра у лендинга нет');
        $this->assertStringNotContainsString('в разных местах', $out['caption'],
            'правило отбора — наша кухня, в тексте ему не место');
    }

    /** Гейт: меньше пяти событий — это не подборка, а слабая лента. */
    public function test_thin_theme_does_not_pass_the_gate(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 3) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $this->assertNull(app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now()));
    }

    /**
     * События, которые канал уже показывает, в подборку не попадают — иначе
     * она становится оглавлением уже прочитанного.
     */
    public function test_events_already_in_the_feed_are_subtracted(): void
    {
        $broadcast = $this->makeChannel();
        $events = [];
        foreach (range(1, 6) as $n) {
            $events[] = $this->themedEvent("Спектакль {$n}", $n);
        }

        // Первое уже стоит в ленте канала обычным постом.
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $events[0];
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($events[0], $out['event_ids']);
        $this->assertSame(5, $out['total'], 'занятое событие вычтено и из счётчика');
    }

    /**
     * Событие, которое в первой строке описания называет ЧУЖОЙ жанр, в тему не
     * идёт — какой бы тег ему ни поставила разметка.
     *
     * Живой случай: квест «Припять 36» попал в подборку концертов. Первичная
     * тема «музыка» проставлена ему 235 раз подряд, то есть переголосовать её
     * повторами нельзя — они ошибаются одинаково. Зато в первой строке описания
     * прямым текстом «квеста».
     */
    public function test_event_naming_a_foreign_genre_is_rejected(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $quest = $this->themedEvent('Припять 36', 6);
        DB::table('events')->where('id', $quest)->update([
            'description' => 'Главный герой квеста «Припять 36» в Воронеже — учёный Адрианов, '
                .'много лет изучавший аномалии зоны отчуждения и оставивший дневники.',
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($quest, $out['event_ids'], 'квест не место в подборке спектаклей');
        $this->assertSame(5, $out['total'], 'и в счётчике его тоже нет');
    }

    /**
     * Событие без площадки поимённо не называем.
     *
     * Такая строка не блокировалась правилом «одна площадка — одна строка» и
     * сама площадку не занимала: три события без места прошли бы все отсечки и
     * встали рядом. А читателю «название · дата» без места говорит половину.
     */
    public function test_event_without_a_venue_is_never_named(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        // Самое длинное описание — то есть первый кандидат по нынешнему отбору.
        $homeless = $this->themedEvent('Спектакль без места', 6);
        DB::table('events')->where('id', $homeless)->update([
            'venue_id' => null,
            'description' => str_repeat('очень длинное описание события. ', 20),
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($homeless, $out['event_ids']);
    }

    /**
     * Подпись не обещает числа названных вовсе.
     *
     * Гейт темы — пять событий, но отсечки «одна площадка, один день» могут
     * оставить меньше трёх, и текст обещал три, отправляясь молча. Теперь
     * счёта названных в тексте нет: он был пересказом внутреннего правила.
     */
    public function test_caption_never_promises_how_many_are_named(): void
    {
        $broadcast = $this->makeChannel();
        // Пять событий, но все в один день: отсечка по дню оставит одно.
        foreach (range(1, 5) as $n) {
            $id = $this->themedEvent("Спектакль {$n}", $n);
            DB::table('events')->where('id', $id)->update([
                'start_time' => Carbon::now()->addDays(2)->setTime(19, 0),
            ]);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertCount(1, $out['event_ids']);
        $this->assertStringNotContainsString('Одно', $out['caption']);
        $this->assertStringNotContainsString('Три', $out['caption']);
        $this->assertStringContainsString('Вся афиша спектаклей', $out['caption'], 'остальные — по ссылке');
    }

    /**
     * У каждого названного события есть строка «чем цепляет».
     *
     * Без неё подборка — список фактов из базы: название, дата, место, цена.
     * Берём готовый ТГ-анонс, если он есть, иначе первое предложение описания;
     * ничего не сочиняем.
     */
    public function test_named_events_get_a_hook_line(): void
    {
        $broadcast = $this->makeChannel();
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->themedEvent("Спектакль {$n}", $n);
        }
        DB::table('events')->whereIn('id', $ids)->update([
            'tg_description' => 'Трогательная история о семье и о том, как важно оставаться собой.',
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertStringContainsString('как важно оставаться собой', $out['caption']);
    }

    /**
     * Изюм снимается целиком, если пост перестаёт влезать в подпись альбома.
     *
     * Telegram режет подпись к картинкам на 1024 символах, и обрезанная на
     * полуслове строка хуже её отсутствия.
     */
    public function test_hooks_are_dropped_when_the_caption_grows_too_long(): void
    {
        $broadcast = $this->makeChannel();
        $ids = [];
        foreach (range(1, 6) as $n) {
            // Длинные названия и площадки: сам по себе изюм в 130 символов
            // подпись не переполняет, переполняет всё вместе. С короткими
            // фикстурами тест проходил бы и на сломанной проверке.
            $ids[] = $this->themedEvent(
                "Большой драматический спектакль в двух действиях с антрактом номер {$n}",
                $n,
            );
        }
        DB::table('events')->whereIn('id', $ids)->update([
            'tg_description' => str_repeat('очень длинный анонс события без единой точки ', 12),
        ]);
        DB::table('venues')->whereIn('id', function ($q) use ($ids) {
            $q->select('venue_id')->from('events')->whereIn('id', $ids);
        })->update(['name' => 'Воронежский государственный академический театр драмы имени Кольцова']);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertLessThanOrEqual(
            1024,
            mb_strlen(strip_tags($out['caption'])),
            'подпись обязана влезать в лимит альбома',
        );
    }

    /**
     * Не влезает одна строка — снимается одна, а не весь изюм.
     *
     * Правило «целиком или никак» стояло ради того, чтобы пост не выходил
     * обрезанным на полуслове. Резать строку и правда нельзя, но снимать ВСЕ
     * ради одной лишней — плата не за то: текст уже написан и оплачен, а без
     * него пост становится списком из базы.
     *
     * Предел подписи здесь подгоняется под фикстуру, а не наоборот: важно
     * поведение на границе, а не конкретное число из конфига.
     */
    public function test_only_the_overflowing_hook_is_dropped(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->addDay());
        $service = app(\App\Services\Telegram\TelegramChatBroadcastService::class);
        $composer = app(BroadcastDigestComposer::class);
        $draft = $composer->compose($broadcast, Carbon::now(), $digest);
        $service->applyDigestDraft($digest, $draft);

        // Длина подписи БЕЗ изюма — точка отсчёта.
        $base = \App\Support\Telegram\CaptionLength::visible(
            $composer->recompose($digest->refresh(), $broadcast, Carbon::now())['caption'],
        );

        $hooks = [];
        foreach ($draft['event_ids'] as $i => $eventId) {
            // mb_substr, а не str_pad: тот считает БАЙТЫ, и на кириллице
            // строка выходила вдвое короче задуманного — все три влезали,
            // и тест проходил бы на любой реализации.
            $hooks[(string) $eventId] = mb_substr(
                'Фраза номер '.($i + 1).' '.str_repeat('абвгде ', 30), 0, 100,
            );
        }

        $meta = (array) $digest->refresh()->digest_meta;
        $meta['hooks'] = $hooks;
        $meta['roster'] = $draft['event_ids'];
        $digest->digest_meta = $meta;
        $digest->save();

        // Предел такой, что две строки помещаются, а третья — нет.
        config(['broadcast_digest.caption_soft_limit' => $base + 2 * 102 + 5]);

        $caption = $composer->recompose($digest->refresh(), $broadcast, Carbon::now())['caption'];

        $this->assertStringContainsString('Фраза номер 1', $caption, 'первая строка осталась');
        $this->assertStringContainsString('Фраза номер 2', $caption, 'вторая тоже');
        $this->assertStringNotContainsString('Фраза номер 3', $caption, 'снялась только лишняя, с конца');
    }

    /** Названные события идут по датам — подборка про «что впереди». */
    public function test_named_events_are_ordered_by_date(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $starts = DB::table('events')->whereIn('id', $out['event_ids'])
            ->orderByRaw('array_position(ARRAY['.implode(',', $out['event_ids']).']::bigint[], id)')
            ->pluck('start_time')->map(fn ($t) => (string) $t)->all();

        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts, 'порядок в тексте — хронологический');
    }

    /** Заголовок не по теме в подборку не идёт, даже с нужным тегом. */
    public function test_stop_list_rejects_off_theme_titles(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 5) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        // День 6 — внутри недельного окна: с днём за окном тест не проверял бы
        // стоп-лист вовсе, событие отсекалось бы окном.
        $masterClass = $this->themedEvent('Мастер-класс по сценречи', 6);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertNotContains($masterClass, $out['event_ids']);
        $this->assertSame(5, $out['total']);
    }

    /**
     * «Собрать и править»: текст и состав записываются в саму запись, и
     * названные события сразу закрываются для обычных постов.
     */
    public function test_compose_now_fills_the_item_and_links_events(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = new TelegramChatBroadcastItem;
        $digest->broadcast_id = $broadcast->id;
        $digest->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $digest->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $digest->publish_at = Carbon::now()->addDay();
        $digest->save();

        $res = $this->postJson("/api/admin/broadcast/items/{$digest->id}/compose");

        $res->assertOk();
        $this->assertStringContainsString('Спектакли недели', (string) $res->json('data.caption'));
        $this->assertCount(3, $res->json('data.linked_events'), 'состав виден в форме');

        $this->assertSame(3, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->count(), 'события закрыты для лент');
        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->where('position', 0)->count(),
            'позиция 0 занята ведущим событием обычного поста — у подборки её нет');
    }

    /**
     * Подборка уходит в канал С КАРТИНКАМИ.
     *
     * Первая живая подборка ушла голым текстом: обложки названных событий
     * собирались только для показа в админке, а путь доставки о них не знал и
     * слал пустой список. Тест сторожит именно этот разрыв.
     */
    public function test_digest_goes_out_with_covers_of_named_events(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n, withImage: true);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subMinute());

        $service = app(\App\Services\Telegram\TelegramChatBroadcastService::class);

        // Первый тик выбирает состав и придерживает пост на время генерации —
        // задачи боту на нём ещё нет. Придержку снимает парсер; здесь снимаем
        // руками, чтобы проверить именно доставку картинок.
        $service->collectDueSingleRuns(Carbon::now());
        $digest->refresh();
        $digest->planned_at = Carbon::now()->subSecond();
        $digest->save();

        $tasks = $service->collectDueSingleRuns(Carbon::now());

        $task = collect($tasks)->firstWhere('item_id', $digest->id);

        $this->assertNotNull($task, 'подборка должна уйти в выдачу боту');
        $this->assertSame('digest', $task['kind']);
        $this->assertNotEmpty($task['photo_urls'], 'обложки названных событий обязаны доехать до бота');
        $this->assertNotNull($task['photo_url'], 'и обложка тоже');
    }

    /**
     * Повторная сборка той же записи выбирает ТОТ ЖЕ состав.
     *
     * `rejectAlreadyShown` вычитает всё, что канал вот-вот покажет, — а
     * показывает он ровно эту тройку. Без оговорки «кроме самой записи» второй
     * проход гарантированно выбирал другие события: кнопка «Собрать» была
     * неидемпотентной, а текст, написанный под первый состав, доставался не
     * тем событиям.
     */
    public function test_recomposing_the_same_item_keeps_its_own_events(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->addDay());

        $first = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        $this->assertNotNull($first);
        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($digest, $first);

        $second = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest->fresh());

        $this->assertNotNull($second, 'своя же тройка не должна обваливать гейт темы');
        $this->assertSame($first['event_ids'], $second['event_ids']);
    }

    /**
     * Пересборка по сохранённому составу: события те же, текст модели на месте.
     *
     * Именно этим собирается подпись после того, как модель написала текст.
     * Переизбирать состав здесь нельзя — оплаченные строки достались бы чужим
     * событиям.
     */
    public function test_recompose_uses_the_saved_roster_and_the_written_text(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->addDay());
        $draft = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($digest, $draft);

        $digest->refresh();
        $meta = (array) $digest->digest_meta;
        $meta['intro'] = 'Три вечера подряд сцена не пустует.';
        $meta['hooks'] = [(string) $draft['event_ids'][0] => 'Студенты играют без страховки.'];
        $meta['roster'] = $draft['event_ids'];
        $digest->digest_meta = $meta;
        $digest->save();

        $out = app(BroadcastDigestComposer::class)->recompose($digest, $broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertSame($draft['event_ids'], $out['event_ids'], 'состав переизбирать нельзя');
        $this->assertStringContainsString('Три вечера подряд сцена не пустует.', $out['caption']);
        $this->assertStringContainsString('Студенты играют без страховки.', $out['caption']);
    }

    /**
     * Строка модели сильнее нашего прежнего анонса.
     *
     * Анонс написан под ОТДЕЛЬНЫЙ пост, где под него отведён абзац; строка
     * модели написана под эту строку и знает соседей по посту.
     */
    public function test_written_line_wins_over_the_event_announce(): void
    {
        $broadcast = $this->makeChannel();
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->themedEvent("Спектакль {$n}", $n);
        }
        DB::table('events')->whereIn('id', $ids)->update([
            'tg_description' => 'Прежний анонс события, написанный под отдельный пост.',
        ]);

        $digest = $this->digestItem($broadcast, Carbon::now()->addDay());
        $draft = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($digest, $draft);

        $digest->refresh();
        $meta = (array) $digest->digest_meta;
        $meta['hooks'] = [];
        foreach ($draft['event_ids'] as $id) {
            $meta['hooks'][(string) $id] = 'Строка ведущего про событие '.$id.'.';
        }
        $meta['roster'] = $draft['event_ids'];
        $digest->digest_meta = $meta;
        $digest->save();

        $out = app(BroadcastDigestComposer::class)->recompose($digest, $broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertStringContainsString('Строка ведущего про событие', $out['caption']);
        $this->assertStringNotContainsString('Прежний анонс события', $out['caption']);
    }

    /**
     * Пресс-релизный зачин в пост не идёт.
     *
     * «Приглашаем вас на спектакль…» — это перепечатка, и читается она именно
     * так: по ней владелец и сказал, что текст «видно искусственный». Берём
     * следующее предложение, а нет живого — строки не будет вовсе.
     */
    public function test_press_release_opening_is_never_quoted(): void
    {
        $broadcast = $this->makeChannel();
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->themedEvent("Спектакль {$n}", $n);
        }

        // У всех шести одинаковое описание: какие бы три ни выбрал отбор,
        // проверка смотрит на одно и то же. С правкой одного события тест
        // проходил бы и на сломанном фильтре — если бы это событие не назвали.
        DB::table('events')->whereIn('id', $ids)->update([
            'description' => 'Приглашаем вас на спектакль, который пройдёт в нашем театре. '
                .'Декорации собраны из мебели, которую принесли сами зрители, и это видно со второго ряда. '
                .'Вход по билетам.',
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('Приглашаем вас', $out['caption']);
        $this->assertStringContainsString('Декорации собраны из мебели', $out['caption'],
            'живое предложение из описания взять можно и нужно');
    }

    /**
     * В момент слота подборка не уходит, а просит текст и придерживается.
     *
     * Это тот же путь, что у события: сначала известно, про что пишем, потом
     * модель пишет, и только потом пост уходит. Раньше подборка собиралась и
     * уезжала в ту же секунду — писать ей было некогда.
     */
    public function test_digest_asks_for_text_and_holds_instead_of_sending(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subMinute());

        $tasks = app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->collectDueSingleRuns(Carbon::now());

        $this->assertNull(collect($tasks)->firstWhere('item_id', $digest->id),
            'пост не должен уйти, пока ему пишут текст');

        $digest->refresh();
        $this->assertNotNull($digest->text_requested_at, 'заявка на текст поставлена');
        $this->assertTrue($digest->planned_at?->isFuture(), 'и придержка тоже');
        $this->assertNull($digest->caption, 'пустая подпись — сигнал собрать заново');
        $this->assertSame('spektakli', $digest->digestTheme(), 'тема сохранена для пересборки');
        $this->assertSame(3, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->count(), 'состав выбран — есть про что писать');
    }

    /**
     * Состав переизбрали в минуту слота — текст просят заново, пост ждёт.
     *
     * Если к моменту отправки прежний состав рассыпался (события удалили или
     * они начались), подборка собирается заново — и тройка получается ДРУГАЯ.
     * Раньше на этой ветке текст не заказывался вовсе: подборка уходила в
     * канал с описаниями, взятыми у сайтов-источников, потому что своих фраз
     * у новых событий не было.
     */
    public function test_repicked_roster_asks_for_text_instead_of_sending_raw_descriptions(): void
    {
        $broadcast = $this->makeChannel();
        // Двенадцать, а не шесть: тема живёт, пока в пуле есть min_events = 5.
        // Из трёх названных сделаем прошедшие, и остаток обязан набрать тему
        // заново — иначе подборка просто снимется и проверять будет нечего.
        foreach (range(1, 12) as $n) {
            $this->themedEvent("Спектакль {$n}", ($n - 1) % 7 + 1);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subMinute());
        $service = app(\App\Services\Telegram\TelegramChatBroadcastService::class);
        $draft = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        $service->applyDigestDraft($digest, $draft);

        // Текст написан под прежнюю тройку.
        $digest->refresh();
        $meta = (array) $digest->digest_meta;
        $meta['intro'] = 'Подводка про прежнюю тройку.';
        $meta['hooks'] = [];
        foreach ($draft['event_ids'] as $eventId) {
            $meta['hooks'][(string) $eventId] = 'Строка про событие '.$eventId.'.';
        }
        $meta['roster'] = $draft['event_ids'];
        $meta['text_asked'] = true;
        $digest->digest_meta = $meta;
        $digest->save();

        // Прежний состав рассыпался: названные события уже начались, и
        // recompose честно отказывается выпускать огрызок.
        DB::table('events')->whereIn('id', $draft['event_ids'])
            ->update(['start_time' => Carbon::now()->subHours(2), 'end_time' => Carbon::now()->subHour()]);

        $tasks = $service->collectDueSingleRuns(Carbon::now());

        $this->assertNull(collect($tasks)->firstWhere('item_id', $digest->id),
            'пост не уходит: текста про новую тройку ещё нет');

        $digest->refresh();
        $this->assertNotNull($digest->text_requested_at, 'текст заказан заново под новый состав');
        $this->assertNull($digest->digestIntro(), 'подводка про прежнюю тройку снята');

        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->pluck('event_id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotEmpty($roster, 'новый состав выбран');
        $this->assertEmpty(array_intersect($roster, $draft['event_ids']), 'и он действительно другой');
    }

    /**
     * Придержка кончилась, текст написан — пост уходит уже с ним.
     */
    public function test_digest_goes_out_with_the_written_text_after_the_hold(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subMinute());
        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->collectDueSingleRuns(Carbon::now());

        // Ровно то, что делает парсер: пишет текст и снимает придержку.
        $digest->refresh();
        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->orderBy('position')->pluck('event_id')
            ->map(fn ($v) => (int) $v)->all();
        $meta = (array) $digest->digest_meta;
        $meta['intro'] = 'Неделя, в которую сцена не пустует ни вечера.';
        $meta['hooks'] = [(string) $roster[0] => 'Декорации собирали всем залом.'];
        $meta['roster'] = $roster;
        $digest->digest_meta = $meta;
        $digest->planned_at = Carbon::now()->subSecond();
        $digest->save();

        $tasks = app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->collectDueSingleRuns(Carbon::now());

        $task = collect($tasks)->firstWhere('item_id', $digest->id);

        $this->assertNotNull($task, 'придержка снята — пост уходит');
        $this->assertStringContainsString('Неделя, в которую сцена не пустует', $task['caption']);
        $this->assertStringContainsString('Декорации собирали всем залом.', $task['caption']);
        $this->assertSame($roster, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->orderBy('position')->pluck('event_id')
            ->map(fn ($v) => (int) $v)->all(), 'состав не переизбран');
    }

    /**
     * Канал, отказавшийся от текстов ИИ, подборку не придерживает.
     *
     * Ждать нечего: текста не будет, а шесть минут простоя пост потерял бы зря.
     */
    public function test_channel_without_ai_text_sends_the_digest_at_once(): void
    {
        $broadcast = $this->makeChannel();
        $broadcast->settings = array_merge((array) $broadcast->settings, ['ai_text' => false]);
        $broadcast->save();

        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subMinute());

        $tasks = app(\App\Services\Telegram\TelegramChatBroadcastService::class)
            ->collectDueSingleRuns(Carbon::now());

        $this->assertNotNull(collect($tasks)->firstWhere('item_id', $digest->id));
        $this->assertNull($digest->fresh()->text_requested_at, 'заявку такому каналу не ставим');
    }

    /**
     * Сменился состав — подводка снимается, сироты уходят, свои остаются.
     *
     * Подводка написана про конкретную тройку («а в субботу…»), и с другим
     * составом ссылается на то, чего в посте уже нет.
     *
     * Строки привязаны к номеру события, поэтому судьба у них разная: строка
     * ОСТАВШЕГОСЯ события по-прежнему про него и переживает замену, а строка
     * УШЕДШЕГО становится сиротой. Сироту не видно в посте, но по ней запись
     * считается написанной — и заказ текста молчит для нового события.
     */
    public function test_intro_is_dropped_when_the_roster_changes(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->addDay());
        $service = app(\App\Services\Telegram\TelegramChatBroadcastService::class);
        $draft = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        $service->applyDigestDraft($digest, $draft);

        $digest->refresh();
        $meta = (array) $digest->digest_meta;
        $meta['intro'] = 'Подводка про прежнюю тройку.';
        $meta['hooks'] = [];
        foreach ($draft['event_ids'] as $eventId) {
            $meta['hooks'][(string) $eventId] = 'Строка про событие '.$eventId.'.';
        }
        $meta['roster'] = $draft['event_ids'];
        $digest->digest_meta = $meta;
        $digest->save();

        // Состав сменился: одно событие уехало, другое пришло.
        $other = array_values(array_diff(
            DB::table('events')->pluck('id')->map(fn ($v) => (int) $v)->all(),
            $draft['event_ids'],
        ));
        $changed = $draft['event_ids'];
        $changed[0] = $other[0];

        $service->applyDigestDraft($digest, [
            'caption' => 'x',
            'event_ids' => $changed,
            'theme' => $draft['theme'],
            'theme_slug' => $draft['theme_slug'],
        ]);

        $digest->refresh();
        $this->assertNull($digest->digestIntro(), 'подводка про другую тройку снимается');

        $gone = $draft['event_ids'][0];
        $stayed = $draft['event_ids'][1];

        $this->assertNull($digest->digestHook($gone),
            'строка ушедшего события не остаётся сиротой в мете');
        $this->assertSame('Строка про событие '.$stayed.'.', $digest->digestHook($stayed),
            'строка оставшегося события переживает замену: она привязана к id');
    }

    /**
     * Снятую подборку возвращают в ЕЁ слот, а не в первый свободный.
     *
     * Событийный планировщик отдаёт первый свободный слот горизонта — то есть
     * утро ближайшего дня. Подборка в утреннем вторнике теряет весь смысл
     * рубрики и при этом закрывает бронь настоящей следующей.
     */
    public function test_restored_digest_returns_to_its_own_weekday(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $broadcast = $this->makeChannel();
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'digest_weekday' => 1, // понедельник
            'digest_hour' => 19,
            'slots' => [10, 19],
        ]);
        $broadcast->save();

        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }

        $digest = $this->digestItem($broadcast, Carbon::now()->subDay());
        $draft = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now(), $digest);
        app(\App\Services\Telegram\TelegramChatBroadcastService::class)->applyDigestDraft($digest, $draft);
        $digest->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $digest->save();

        $this->postJson("/api/admin/broadcast/items/{$digest->id}/restore")->assertOk();

        $digest->refresh();
        $at = Carbon::parse($digest->publish_at)->setTimezone('Europe/Moscow');

        $this->assertSame(1, $at->isoWeekday(), 'подборка возвращается в свой день недели');
        $this->assertSame(19, $at->hour, 'и в свой час');
        $this->assertNull($digest->caption, 'состав к возврату протух — собирается заново');
        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $digest->id)->count(), 'прежний состав снят');
    }

    /**
     * Длина подписи считается по ВИДИМОМУ тексту, а не по разметке.
     *
     * Telegram меряет готовый текст: адрес ссылки в длину не входит вовсе. У
     * подборки с четырьмя ссылками разметка тянет вдвое больше видимого, и
     * правка живого поста отбивалась ошибкой «длиннее 1024» — то есть править
     * подборку было нельзя в принципе.
     */
    public function test_caption_length_counts_text_not_markup(): void
    {
        $html = '<b><a href="https://kudab.ru/events/428735?utm_source=tg&amp;utm_medium=digest&amp;utm_content=i162">Честный</a></b>';

        $this->assertSame(7, \App\Support\Telegram\CaptionLength::visible($html),
            'семь букв «Честный», а не сто десять символов разметки');
    }

    /** Эмодзи вне базовой плоскости Telegram считает за две единицы. */
    public function test_caption_length_counts_emoji_as_telegram_does(): void
    {
        $this->assertSame(2, \App\Support\Telegram\CaptionLength::visible('🎵'));
    }

    /**
     * Строка не обрывается на висящем союзе.
     *
     * «…„Разлетайтесь мыши" и…» — реальная строка из живого поста: союз перед
     * многоточием читается как обрыв связи, а не как продолжение.
     */
    public function test_hook_does_not_end_on_a_hanging_conjunction(): void
    {
        $broadcast = $this->makeChannel();
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->themedEvent("Спектакль {$n}", $n);
        }
        DB::table('events')->whereIn('id', $ids)->update([
            'tg_description' => 'Максимум энергии, яркие эмоции, все хиты и новые песни — «Лепесточек», '
                .'«Желаю», «Майами», «Прости мама», «Разлетайтесь мыши» и ещё десяток любимых вещей подряд.',
        ]);

        $out = app(BroadcastDigestComposer::class)->compose($broadcast, Carbon::now());

        $this->assertNotNull($out);
        $this->assertStringNotContainsString(' и…', $out['caption'],
            'союз перед многоточием — обрыв, а не продолжение');
    }

    private function digestItem(TelegramChatBroadcast $broadcast, Carbon $at): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = $at;
        $item->save();

        return $item;
    }

    private function themedEvent(string $title, int $n, bool $withImage = false): int
    {
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        $venueId = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId,
            'name' => 'Площадка '.$n.' '.uniqid(),
            'slug' => 'venue-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $this->cityId;
        $event->venue_id = $venueId;
        // Разные дни: отсечка «одна строка на день» иначе оставила бы одно.
        $event->start_time = Carbon::now()->addDays($n)->setTime(19, 0);
        $event->start_date = Carbon::now()->addDays($n)->toDateString();
        $event->end_time = Carbon::now()->addDays($n)->setTime(21, 0);
        // Длина описания РАСТЁТ с номером дня: отбор идёт по полноте карточки,
        // значит без сортировки на выходе получится обратный хронологии
        // порядок — иначе тест на порядок проходил бы сам собой.
        $event->description = str_repeat('описание события достаточной длины. ', 4 + $n);
        $event->price_min = 500 * $n;
        $event->save();

        if ($withImage) {
            // Картинки события лежат в event_sources.images — оттуда их берёт
            // и админка, и выдача задачи боту.
            DB::table('event_sources')->insert([
                'event_id' => $event->id,
                'social_link_id' => $this->socialLink($community->id),
                'source' => 'vk',
                'post_external_id' => 'post-'.uniqid(),
                'images' => json_encode(['https://example.test/cover-'.$n.'.jpg']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('event_interest')->insert([
            'event_id' => $event->id,
            'interest_id' => $this->interestId,
            'rank' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $event->id;
    }

    /** Источник постов сообщества — обязательная ссылка у event_sources. */
    private function socialLink(int $communityId): int
    {
        $networkId = DB::table('social_networks')->value('id')
            ?? DB::table('social_networks')->insertGetId([
                'name' => 'VK', 'slug' => 'vk', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('community_social_links')->insertGetId([
            'community_id' => $communityId,
            'social_network_id' => $networkId,
            'url' => 'https://vk.com/'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201999]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999088;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->cityId;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ])->load('chat.city');
    }

    private function city(): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-digest', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh-digest')->value('id');
    }
}
