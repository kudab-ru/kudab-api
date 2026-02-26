<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CityToggleCommand extends Command
{
    protected $signature = 'city:toggle
        {city : City slug or id}
        {--set= : Force status (active|disabled|limited)}
        {--dry-run : Do not write changes}';

    protected $description = "Переключить cities.status (active <-> disabled) и заморозить/разморозить парсинг по городу";

    public function handle(): int
    {
        $arg = trim((string)$this->argument('city'));
        $set = $this->option('set') !== null ? trim((string)$this->option('set')) : null;
        $dry = (bool)$this->option('dry-run');

        $city = DB::table('cities')
            ->when(ctype_digit($arg), fn($q) => $q->where('id', (int)$arg), fn($q) => $q->where('slug', $arg))
            ->first();

        if (!$city) {
            $this->error("Город не найден: {$arg}");
            return self::FAILURE;
        }

        $current = (string)($city->status ?? 'active');

        $allowed = ['active', 'disabled', 'limited'];
        if ($set !== null && !in_array($set, $allowed, true)) {
            $this->error("Некорректный --set. Допустимо: " . implode(', ', $allowed));
            return self::FAILURE;
        }

        // toggle: active -> disabled, всё остальное -> active
        $next = $set ?? (($current === 'active') ? 'disabled' : 'active');

        $this->info("City {$city->name} ({$city->slug}, id={$city->id}): {$current} -> {$next}" . ($dry ? " [dry-run]" : ""));

        if ($dry) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($city, $next) {
            DB::table('cities')->where('id', (int)$city->id)->update([
                'status' => $next,
                'updated_at' => now(),
            ]);

            if ($next === 'active') {
                $this->unfreezeCityParsing((int)$city->id);
            } else {
                $this->freezeCityParsing((int)$city->id);
            }
        });

        return self::SUCCESS;
    }

    private function freezeCityParsing(int $cityId): void
    {
        // 1) Создаём строки parsing_statuses для всех links этого города (если их нет)
        DB::statement(
            "
insert into parsing_statuses
  (community_social_link_id, is_frozen, frozen_reason, unfreeze_at,
   last_error, last_error_code, total_failures, retry_count,
   created_at, updated_at)
select
  csl.id, true, 'city_inactive', null,
  null, null, 0, 0,
  now(), now()
from community_social_links csl
join communities c on c.id = csl.community_id
where c.city_id = ?
on conflict (community_social_link_id) do nothing
",
            [$cityId]
        );

        // 2) Замораживаем существующие (НЕ перетираем чужие причины — только если не было причины/не было freeze)
        $affected = DB::affectingStatement(
            "
update parsing_statuses ps
set
  is_frozen = true,
  frozen_reason = case
    when ps.is_frozen = false or ps.frozen_reason is null or ps.frozen_reason = 'city_inactive'
      then 'city_inactive'
    else ps.frozen_reason
  end,
  unfreeze_at = case
    when ps.is_frozen = false or ps.frozen_reason is null or ps.frozen_reason = 'city_inactive'
      then null
    else ps.unfreeze_at
  end,
  updated_at = now()
from community_social_links csl
join communities c on c.id = csl.community_id
where ps.community_social_link_id = csl.id
  and c.city_id = ?
",
            [$cityId]
        );

        $this->info("parsing_statuses: freeze applied (city_inactive), affected={$affected}");
    }

    private function unfreezeCityParsing(int $cityId): void
    {
        // Размораживаем ТОЛЬКО то, что было заморожено причиной city_inactive
        $affected = DB::affectingStatement(
            "
update parsing_statuses ps
set
  is_frozen = false,
  frozen_reason = null,
  unfreeze_at = null,
  last_error = null,
  last_error_code = null,
  retry_count = 0,
  total_failures = 0,
  updated_at = now()
from community_social_links csl
join communities c on c.id = csl.community_id
where ps.community_social_link_id = csl.id
  and c.city_id = ?
  and ps.frozen_reason = 'city_inactive'
",
            [$cityId]
        );

        $this->info("parsing_statuses: unfreeze city_inactive, affected={$affected}");
    }
}
