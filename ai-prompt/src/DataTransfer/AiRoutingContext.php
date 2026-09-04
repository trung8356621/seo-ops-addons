<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use App\Models\ApiConnection;

final class AiRoutingContext
{
    /**
     * @param  list<string>|null  $allowedFamilyKeys
     */
    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?ApiConnection $legacyConnection = null,
        public readonly bool $allowLegacyFallback = true,
        public readonly ?AiUsageMode $usageModeOverride = null,
        public readonly ?array $allowedFamilyKeys = null,
        public readonly ?AiCostPolicy $costPolicy = null,
        public readonly ?int $preferredModelId = null,
        public readonly bool $requirePreferredModel = false,
        public readonly ?string $itemGenerationMode = null,
        public readonly ?string $hookKey = null,
    ) {}

    public function costPolicy(): AiCostPolicy
    {
        return $this->costPolicy ?? AiCostPolicy::Default;
    }
}
