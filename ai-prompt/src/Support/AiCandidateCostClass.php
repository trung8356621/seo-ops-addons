<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;

/**
 * Provider-agnostic free/paid classification SSOT for routing candidates.
 *
 * Derivation lives here (and in {@see OpenRouterModelEconomics} pricing metadata).
 * Business / planner code must consume this — not provider-name heuristics.
 */
enum AiCandidateCostClass: string
{
    case Free = 'free';
    case Paid = 'paid';

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public static function fromCapabilities(array $capabilities, string $rawModelName): self
    {
        return OpenRouterModelEconomics::isFree($capabilities, $rawModelName)
            ? self::Free
            : self::Paid;
    }

    public static function fromModel(SeoAiModel $model): self
    {
        return OpenRouterModelEconomics::modelIsFree($model)
            ? self::Free
            : self::Paid;
    }

    public function isFree(): bool
    {
        return $this === self::Free;
    }
}
