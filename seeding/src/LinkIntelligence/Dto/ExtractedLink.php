<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\LinkIntelligence\Dto;

/**
 * @phpstan-type ExtractedLinkArray array{original_url: string, normalized_url: string, domain: string, source: string}
 */
final class ExtractedLink
{
    public function __construct(
        public readonly string $originalUrl,
        public readonly string $normalizedUrl,
        public readonly string $domain,
        public readonly string $source,
    ) {}

    /**
     * @return ExtractedLinkArray
     */
    public function toArray(): array
    {
        return [
            'original_url' => $this->originalUrl,
            'normalized_url' => $this->normalizedUrl,
            'domain' => $this->domain,
            'source' => $this->source,
        ];
    }
}
