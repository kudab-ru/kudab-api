<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Внешний источник не ответил.
 *
 * Отдельный тип нужен, чтобы сборщик отчёта отличал «Метрика молчит» от ошибки
 * в собственном коде: первое превращается в null-секцию и строку в meta.errors,
 * второе обязано дойти до логов целиком.
 */
final class AnalyticsUnavailable extends \RuntimeException {}
