<?php

namespace App\Console\Commands;

use App\Models\Interest;
use App\Models\InterestAlias;
use Illuminate\Console\Command;
use League\Csv\Reader;

class ImportInterests extends Command
{
    protected $signature = 'interests:import {path=storage/app/full_interests.csv} {--aliases=storage/app/full_interest_aliases.csv}';
    protected $description = 'Import interests (slug,name,description,parent_slug[,is_paid]) and aliases (alias,interest_slug,locale)';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        if (!file_exists($path)) { $this->error("File not found: {$path}"); return 1; }

        $csv = Reader::createFromPath($path); $csv->setHeaderOffset(0);
        $rows = iterator_to_array($csv->getRecords(), false);

        // 1) создать/обновить интересы без parent_id
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = mb_strtolower(trim($row['slug'] ?? ''));
            $name = trim($row['name'] ?? '');
            if (!$slug || !$name) continue;

            $i = Interest::firstOrNew(['slug'=>$slug]);
            $i->name = $name;
            $i->save();
            $bySlug[$slug] = $i->id;
        }

        // 2) второй проход — расставить parent_id
        $updatedParents = 0; $misses = 0;
        foreach ($rows as $row) {
            $slug = mb_strtolower(trim($row['slug'] ?? ''));
            $p    = mb_strtolower(trim($row['parent_slug'] ?? ''));
            if (!$slug) continue;

            if ($p === '' || !isset($bySlug[$p])) {
                // корень или неизвестный родитель
                Interest::where('slug',$slug)->update(['parent_id'=>null]);
                if ($p && !isset($bySlug[$p])) $misses++;
                continue;
            }
            $updatedParents += Interest::where('slug',$slug)->update(['parent_id'=>$bySlug[$p]]);
        }

        // 3) алиасы (если есть)
        $apath = base_path($this->option('aliases'));
        if (file_exists($apath)) {
            $acsv = Reader::createFromPath($apath); $acsv->setHeaderOffset(0);
            $added = 0;
            foreach ($acsv->getRecords() as $row) {
                $alias = mb_strtolower(trim($row['alias'] ?? ''));
                $slug  = mb_strtolower(trim($row['interest_slug'] ?? ''));
                $loc   = trim($row['locale'] ?? '') ?: null;
                if (!$alias || !$slug || !isset($bySlug[$slug])) continue;

                InterestAlias::firstOrCreate(
                    ['alias'=>$alias],
                    ['interest_id'=>$bySlug[$slug], 'locale'=>$loc]
                ) && $added++;
            }
            $this->info("Aliases imported: +{$added}");
        }

        $this->info("Interests imported: ".count($bySlug).", parents updated: {$updatedParents}".($misses? ", misses: {$misses}":""));
        return 0;
    }
}
