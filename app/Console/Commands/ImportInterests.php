<?php

namespace App\Console\Commands;

use App\Models\Interest;
use App\Models\InterestAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use League\Csv\Reader;

class ImportInterests extends Command
{
    protected $signature = 'interests:import {path=storage/app/full_interests.csv} {--aliases=storage/app/full_interest_aliases.csv}';
    protected $description = 'Import interests (slug,name,description,parent_slug[,is_paid]) and aliases (alias,interest_slug,locale)';

    public function handle(): int
    {
        $pathArg = $this->argument('path');
        $path = base_path($pathArg);
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0);
        $rows = iterator_to_array($csv->getRecords(), false);

        // проверим, какие колонки реально есть в БД
        $hasDescription = Schema::hasColumn('interests', 'description');
        $hasIsPaid      = Schema::hasColumn('interests', 'is_paid');

        // 1) создать/обновить интересы без parent_id
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = mb_strtolower(trim($row['slug'] ?? ''));
            $name = trim($row['name'] ?? '');
            if ($slug === '' || $name === '') continue;

            $interest = Interest::firstOrNew(['slug' => $slug]);
            $interest->name = $name;

            if ($hasDescription) {
                $interest->description = trim((string)($row['description'] ?? '')) ?: null;
            }
            if ($hasIsPaid) {
                $paidRaw = strtolower(trim((string)($row['is_paid'] ?? '0')));
                $interest->is_paid = in_array($paidRaw, ['1','true','yes','y','да'], true) ? 1 : 0;
            }

            $interest->save();
            $bySlug[$slug] = (int)$interest->id;
        }

        // 2) второй проход — расставить parent_id
        $updatedParents = 0; $misses = 0;
        foreach ($rows as $row) {
            $slug = mb_strtolower(trim($row['slug'] ?? ''));
            if ($slug === '') continue;

            $parentSlug = mb_strtolower(trim($row['parent_slug'] ?? ''));
            if ($parentSlug === '') {
                $updatedParents += Interest::where('slug', $slug)->update(['parent_id' => null]);
                continue;
            }

            if (!isset($bySlug[$parentSlug])) {
                $misses++;
                $updatedParents += Interest::where('slug', $slug)->update(['parent_id' => null]);
                continue;
            }

            $updatedParents += Interest::where('slug', $slug)->update(['parent_id' => $bySlug[$parentSlug]]);
        }

        // 3) алиасы (если файл есть)
        $aliasesPathArg = $this->option('aliases');
        $aliasesPath = base_path($aliasesPathArg);
        if (is_file($aliasesPath)) {
            $acsv = Reader::createFromPath($aliasesPath, 'r');
            $acsv->setHeaderOffset(0);
            $added = 0;

            foreach ($acsv->getRecords() as $row) {
                $alias = mb_strtolower(trim($row['alias'] ?? ''));
                $interestSlug = mb_strtolower(trim($row['interest_slug'] ?? ''));
                $locale = trim((string)($row['locale'] ?? '')) ?: null;

                if ($alias === '' || $interestSlug === '' || !isset($bySlug[$interestSlug])) continue;

                $created = InterestAlias::firstOrCreate(
                    ['alias' => $alias],
                    ['interest_id' => $bySlug[$interestSlug], 'locale' => $locale]
                );

                if ($created->wasRecentlyCreated) $added++;
            }

            $this->info("Aliases imported: +{$added}");
        } else {
            $this->line("Aliases file not found, skipping: {$aliasesPath}");
        }

        $this->info("Interests imported: ".count($bySlug).", parents updated: {$updatedParents}".($misses? ", misses: {$misses}":""));
        return 0;
    }
}
