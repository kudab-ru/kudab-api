<?php

namespace App\Console\Commands;

use App\Models\Interest;
use App\Models\InterestAlias;
use Illuminate\Console\Command;
use League\Csv\Reader;

class SyncInterestsFromCsv extends Command
{
    protected $signature = 'interests:sync {path=storage/app/full_interests.csv} {--aliases=storage/app/full_interest_aliases.csv}';
    protected $description = 'Sync interests by slug OR name (handles BOM), set parent_id, import aliases';

    private function norm(?string $s): string { return mb_strtolower(trim((string)$s)); }

    /** Приводим ключи строки к норме: lower + убираем BOM */
    private function normalizeRowKeys(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $k = preg_replace('/^\xEF\xBB\xBF/u', '', $k); // BOM
            $out[mb_strtolower(trim($k))] = $v;
        }
        return $out;
    }

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (!is_file($path)) { $this->error("File not found: {$path}"); return 1; }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $rows = [];
        foreach ($csv->getRecords() as $row) {
            $rows[] = $this->normalizeRowKeys($row);
        }

        $created = $updated = 0;
        $slugToId = [];

        // 1) upsert по slug ИЛИ name (чинит 'Музыка' -> 'music', поддерживает BOM)
        foreach ($rows as $r) {
            $slugCsv = $this->norm($r['slug'] ?? '');
            $nameCsv = trim((string)($r['name'] ?? ''));

            if (!$nameCsv) { continue; }                 // без имени не работаем
            if (!$slugCsv) {                              // если slug пуст из-за BOM/ошибки
                $this->warn("Empty slug for name='{$nameCsv}', row will be matched by name.");
            }

            $bySlug = $slugCsv
                ? Interest::query()->whereRaw('lower(slug) = ?', [$slugCsv])->first()
                : null;

            $byName = Interest::query()->whereRaw('lower(name) = ?', [$this->norm($nameCsv)])->first();

            $model = $bySlug ?: $byName;

            if (!$model) {
                $model = new Interest();
                $model->slug = $slugCsv ?: $this->norm($nameCsv); // slug по имени, если в CSV он пустой
                $model->name = $nameCsv;
                $model->save();
                $created++;
            } else {
                $dirty = false;
                if ($slugCsv && $this->norm($model->slug) !== $slugCsv) { $model->slug = $slugCsv; $dirty = true; }
                if (trim($model->name) !== $nameCsv)                     { $model->name = $nameCsv; $dirty = true; }
                if ($dirty) { $model->save(); $updated++; }
            }

            $slugToId[$this->norm($model->slug)] = $model->id;
        }

        // 2) второй проход — расставить parent_id
        $parentsUpdated = 0; $misses = 0;
        foreach ($rows as $r) {
            $slugCsv = $this->norm($r['slug'] ?? '');
            $parent  = $this->norm($r['parent_slug'] ?? '');
            if (!$slugCsv && isset($r['name'])) {
                // если slug пуст, попробуем найти по name
                $slugCsv = Interest::query()->whereRaw('lower(name)=?', [$this->norm($r['name'])])->value('slug') ?? '';
                $slugCsv = $this->norm($slugCsv);
            }
            if (!$slugCsv) continue;

            $childId  = $slugToId[$slugCsv] ?? null;
            $parentId = $parent ? ($slugToId[$parent] ?? null) : null;

            if (!$childId) continue;
            if ($parent && !$parentId) { $misses++; continue; }

            $parentsUpdated += Interest::where('id', $childId)->update(['parent_id' => $parentId]);
        }

        $this->info("Interests synced: +{$created} created, ~{$updated} updated, parents set: {$parentsUpdated}".($misses? ", misses: {$misses}":""));

        // 3) алиасы
        $apath = base_path($this->option('aliases'));
        if (is_file($apath)) {
            $acsv = Reader::createFromPath($apath); $acsv->setHeaderOffset(0);
            $added = 0;
            foreach ($acsv->getRecords() as $row) {
                $row = $this->normalizeRowKeys($row);
                $alias = $this->norm($row['alias'] ?? '');
                $aslug = $this->norm($row['interest_slug'] ?? '');
                $loc   = trim((string)($row['locale'] ?? '')) ?: null;
                if (!$alias || !$aslug) continue;
                $iid = $slugToId[$aslug] ?? Interest::query()->whereRaw('lower(slug)=?',[$aslug])->value('id');
                if (!$iid) continue;

                InterestAlias::query()->firstOrCreate(
                    ['alias'=>$alias],
                    ['interest_id'=>$iid, 'locale'=>$loc]
                ) && $added++;
            }
            $this->info("Aliases imported: +{$added}");
        }

        return 0;
    }
}
