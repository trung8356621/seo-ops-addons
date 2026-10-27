<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;

/**
 * Sticky AI mode for Global Batch — snapshot once, never flip mid-batch.
 *
 * New batches use {@see self::AUTO_COST}. Legacy batches retain free_only / default.
 */
final class GlobalMonthlyAiPolicy
{
    /** Fixed mode for newly created Global Monthly batches. */
    public const AUTO_COST = 'auto_cost';

    public static function isAutoCost(mixed $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === self::AUTO_COST || $normalized === 'auto';
    }

    /**
     * Normalize batch-level policy token (auto_cost | free_only | default).
     */
    public static function normalizeBatchPolicy(mixed $value): string
    {
        if (self::isAutoCost($value)) {
            return self::AUTO_COST;
        }

        return AiCostPolicy::tryFromMixed($value)->value;
    }

    /** @deprecated Use normalizeBatchPolicy for batch tokens; kept for sticky Free/Paid child settings. */
    public static function normalize(mixed $value): AiCostPolicy
    {
        if (self::isAutoCost($value)) {
            // Auto Cost is not an Individual AiCostPolicy — callers must branch first.
            return AiCostPolicy::FreeOnly;
        }

        return AiCostPolicy::tryFromMixed($value);
    }

    /**
     * Settings merged into every child Project Run (sticky Free/Paid only).
     *
     * @return array<string, mixed>
     */
    public static function childRunSettings(AiCostPolicy $policy): array
    {
        return [
            AiCostPolicy::SETTING_KEY => $policy->value,
            'ai_generation_mode' => $policy->generationModeValue(),
        ];
    }

    public static function assertUnchanged(AiCostPolicy $batchPolicy, mixed $candidate): void
    {
        $normalized = self::normalize($candidate);
        if ($normalized !== $batchPolicy) {
            throw new \InvalidArgumentException(
                'AI policy sticky: batch='.$batchPolicy->value.' candidate='.$normalized->value
            );
        }
    }
}
