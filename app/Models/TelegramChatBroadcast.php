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

    // ---- удобные геттеры/сеттеры поверх JSON settings ----

    /**
     * Сколько постов держать в ленте канала одновременно.
     *
     * Раньше действовало жёсткое «одно событие в полёте»: пока висела любая
     * незакрытая запись, канал пропускался. Из-за этого одна отравленная
     * запись остановила рассылку Воронежа на 33 дня, и из-за этого же нельзя
     * было собрать план на неделю вперёд.
     *
     * Считается ТОЛЬКО по событийным записям. Портреты площадок живут своим
     * недельным каденсом и считаются отдельно — иначе заполненная лента
     * заблокировала бы их навсегда.
     */
    public function getFeedLimitAttribute(): int
    {
        $settings = $this->settings ?? [];
        $raw = $settings['feed_limit'] ?? null;

        // Умолчание — горизонт в днях, умноженный на число слотов: неделя
        // вперёд, по посту в каждый слот. Без слотов это прежние семь.
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
     * Часы публикации внутри дня, по Москве: например [10, 19].
     *
     * Пустой список — прежнее поведение: один пост в день, час берётся из
     * period. Так выкат ничего не меняет молча: пока владелец не задал слоты,
     * канал работает ровно как работал.
     *
     * Ключ слота — день плюс час, и именно он определяет «день занят»: при
     * двух слотах на один день встают два поста, и считать занятость по дате
     * больше нельзя.
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

    /**
     * На сколько дней вперёд собирается лента.
     *
     * Раньше это значило то же число, что и кап записей (feed_limit), и при
     * двух слотах смыслы разошлись бы вдвое: «7» либо укоротило бы ленту до
     * трёх с половиной дней, либо упёрлось бы в кап и молча недозаполнило.
     */
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
     * Как часто канал публикует портрет площадки, в днях.
     *
     * Каденс жил константой в коде: увидеть или сдвинуть его можно было только
     * деплоем, а состояние («когда следующий») нигде не хранится и выводится
     * запросом MAX(posted_at).
     *
     * Потолок частоты считается из пула: площадка возвращается в ротацию через
     * COOLDOWN_DAYS, значит пул должен быть не меньше, чем частота × 90/7.
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

    /**
     * Минимальный зазор между постами канала, в минутах.
     *
     * Жил константой в коде, а нужен разный: боевому каналу с двумя слотами
     * полтора часа в самый раз, тестовому — помеха, из-за которой проверка
     * отправки упирается в ожидание.
     */
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
     * День недели для подборки: 1 — понедельник, 7 — воскресенье. NULL — рубрика
     * выключена.
     *
     * Умолчание — ВЫКЛЮЧЕНО, и это принципиально: новая рубрика не должна
     * появиться в боевом канале сама, от одной выкатки. Включает человек в
     * настройках канала, там же выбирая день.
     *
     * Час берётся из последнего слота канала — по принятой раскладке недели
     * рубрики живут в вечернем слоте, а утренний всегда событие.
     */
    public function getDigestWeekdayAttribute(): ?int
    {
        $raw = ($this->settings ?? [])['digest_weekday'] ?? null;

        if (! is_numeric($raw)) {
            return null;
        }

        $day = (int) $raw;

        return $day >= 1 && $day <= 7 ? $day : null;
    }

    public function setDigestWeekdayAttribute(?int $weekday): void
    {
        $settings = $this->settings ?? [];
        $settings['digest_weekday'] = $weekday !== null && $weekday >= 1 && $weekday <= 7
            ? $weekday
            : null;
        $this->settings = $settings;
    }

    /** Час подборки: вечерний слот канала. Без слотов — час расписания. */
    public function getDigestHourAttribute(): int
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
     * За сколько минут до слота парсер пишет анонс посту ленты.
     *
     * Настройка канала, а не общая: у канала с двумя слотами в день час
     * упреждения в самый раз, а тестовому нужнее короткое — там пост правят и
     * отправляют сразу. Держать согласованным с llm_text.tg_lead_minutes в
     * kudab-parser: оттуда берётся умолчание, когда у канала ничего не задано.
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
     * Писать ли анонсы ИИ автоматически перед публикацией.
     *
     * Выключатель канала, а не поста: выключенный канал постит то, что пришло из
     * парсера, и денег на модель не тратит вовсе. Заявку из админки («написать
     * текст») выключатель НЕ отменяет — её подаёт человек осознанно.
     *
     * По умолчанию включено: так работали все каналы до появления настройки.
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

    public function items()
    {
        return $this->hasMany(TelegramChatBroadcastItem::class, 'broadcast_id');
    }
}
