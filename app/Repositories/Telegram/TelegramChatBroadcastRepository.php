<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Models\TelegramChatBroadcast;
use DateTimeInterface;

class TelegramChatBroadcastRepository implements TelegramChatBroadcastRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findByChatId(int $chatId): ?TelegramChatBroadcast
    {
        return TelegramChatBroadcast::query()
            ->where('chat_id', $chatId)
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getOrCreateByChatId(int $chatId): TelegramChatBroadcast
    {
        $broadcast = $this->findByChatId($chatId);
        if ($broadcast) {
            return $broadcast;
        }

        $broadcast = new TelegramChatBroadcast();
        $broadcast->chat_id = $chatId;
        $broadcast->enabled = false;

        // Дефолтные настройки v1
        $broadcast->settings = [
            'period'        => 'off',
            'template_code' => 'basic',
        ];

        $broadcast->save();

        return $broadcast->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function updateSettingsByChatId(
        int $chatId,
        bool $enabled,
        ?string $period = null,
        ?string $templateCode = null,
    ): TelegramChatBroadcast {
        $broadcast = $this->getOrCreateByChatId($chatId);

        $broadcast->enabled = $enabled;

        if ($period !== null) {
            // Используем аксессор/мутатор period (JSON settings)
            $broadcast->period = $period;
        }

        if ($templateCode !== null) {
            // Используем аксессор/мутатор template_code (JSON settings)
            $broadcast->template_code = $templateCode;
        }

        $broadcast->save();

        return $broadcast->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function touchLastRunAt(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $broadcast = $this->findByChatId($chatId);
        if (!$broadcast) {
            return;
        }

        $broadcast->last_run_at = $moment ?: now();
        $broadcast->save();
    }

    /**
     * {@inheritdoc}
     */
    public function touchLastPreviewAt(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $broadcast = $this->findByChatId($chatId);
        if (!$broadcast) {
            return;
        }

        $broadcast->last_preview_at = $moment ?: now();
        $broadcast->save();
    }
}
