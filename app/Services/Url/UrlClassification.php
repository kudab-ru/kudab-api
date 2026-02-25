<?php

namespace App\Services\Url;

final class UrlClassification
{
    public function __construct(
        public readonly string $inputUrl,
        public readonly string $normalizedUrl,
        public readonly string $host,
        public readonly string $path,
        public readonly string $query,
        public readonly string $sourceKey,
        public readonly array $slugCandidates,
        public readonly ?string $externalCommunityId,
        public readonly array $warnings = [],
    ) {}

    public function toArray(): array
    {
        return [
            'input_url' => $this->inputUrl,
            'normalized_url' => $this->normalizedUrl,
            'host' => $this->host,
            'path' => $this->path,
            'query' => $this->query,
            'source_key' => $this->sourceKey,
            'slug_candidates' => $this->slugCandidates,
            'external_community_id' => $this->externalCommunityId,
            'warnings' => $this->warnings,
        ];
    }
}
