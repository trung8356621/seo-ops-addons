<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;

/**
 * Sticky AI mode for Global Batch — snapshot once, never flip mid-batch.
 */
final class GlobalMonthlyAiPolicy
{
    public static function normalize(mixed $value): AiCostPolicy
    {
        return AiCostPolicy::tryFromMixed($value);
    }

    /**
     * Settings merged into every child Project Run.
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
