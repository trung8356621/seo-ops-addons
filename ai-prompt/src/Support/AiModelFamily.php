<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

final readonly class AiModelFamily
{
    /**
     * @param  list<string>  $memberModelIds  Exact provider IDs, operational preference order
     */
    public function __construct(
        public string $familyKey,
        public string $displayName,
        public string $provider,
        public string $modality,
        public array $memberModelIds,
        public int $costTier,
        public int $qualityTier,
        public int $speedTier,
        public string $lifecycle = 'operational',
    ) {}
}
