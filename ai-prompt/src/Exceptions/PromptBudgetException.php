<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Exceptions;

use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Pre-request budget / unsplittable failures — never cross-route fallback.
 */
final class PromptBudgetException extends PromptRunException
{
    public const CODE_UNSPLITTABLE = 'AI_PROMPT_UNSPLITTABLE';

    public const CODE_IMMUTABLE_TOO_LARGE = 'AI_PROMPT_IMMUTABLE_TOO_LARGE';

    public const CODE_CONTEXT_BUDGET = 'AI_CONTEXT_BUDGET_EXCEEDED';

    public const CODE_OUTPUT_CAPABILITY = 'AI_OUTPUT_CAPABILITY_EXCEEDED';

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function unsplittable(string $message, array $diagnostics = []): self
    {
        return new self(
            self::CODE_UNSPLITTABLE.': '.$message,
            0,
            null,
            [
                'classification' => AiFailureClass::ContextLimitExceeded->value,
                'retryable' => false,
                'budget_code' => self::CODE_UNSPLITTABLE,
                'budget' => $diagnostics,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function immutableTooLarge(string $message, array $diagnostics = []): self
    {
        return new self(
            self::CODE_IMMUTABLE_TOO_LARGE.': '.$message,
            0,
            null,
            [
                'classification' => AiFailureClass::ContextLimitExceeded->value,
                'retryable' => false,
                'budget_code' => self::CODE_IMMUTABLE_TOO_LARGE,
                'budget' => $diagnostics,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function contextBudget(string $message, array $diagnostics = []): self
    {
        return new self(
            self::CODE_CONTEXT_BUDGET.': '.$message,
            0,
            null,
            [
                'classification' => AiFailureClass::ContextLimitExceeded->value,
                'retryable' => false,
                'budget_code' => self::CODE_CONTEXT_BUDGET,
                'budget' => $diagnostics,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function outputCapabilityExceeded(string $message, array $diagnostics = []): self
    {
        return new self(
            self::CODE_OUTPUT_CAPABILITY.': '.$message,
            0,
            null,
            [
                'classification' => AiFailureClass::ContextLimitExceeded->value,
                'retryable' => false,
                'budget_code' => self::CODE_OUTPUT_CAPABILITY,
                'budget' => $diagnostics,
            ],
        );
    }
}
