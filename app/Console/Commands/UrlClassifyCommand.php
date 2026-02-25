<?php

namespace App\Console\Commands;

use App\Services\Url\UrlClassifier;
use Illuminate\Console\Command;

class UrlClassifyCommand extends Command
{
    protected $signature = 'url:classify {url}';
    protected $description = 'Classify URL: source (vk/tg/site), normalize, extract external id';

    public function handle(UrlClassifier $classifier): int
    {
        $url = (string)$this->argument('url');

        $res = $classifier->classify($url);

        $this->line(json_encode($res->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
