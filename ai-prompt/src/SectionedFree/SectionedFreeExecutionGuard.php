<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Process-local guard: when sectioned_free orchestration is active, legacy
 * whole-article length validation must not run.
 */
final class SectionedFreeExecutionGuard
{
    /** @var array{run_id: string, parent_prompt_result_id: ?int, strategy: string, entered_at: string}|null */
    private static ?array $active = null;

    /**
     * @param  array{run_id: string, parent_prompt_result_id?: ?int}  $context
     */
    public static function enter(array $context): void
    {
        self::$active = [
            'run_id' => (string) ($context['run_id'] ?? ''),
            'parent_prompt_result_id' => isset($context['parent_prompt_result_id'])
                ? (int) $context['parent_prompt_result_id']
                : null,
            'strategy' => 'sectioned_free',
            'entered_at' => gmdate('c'),
        ];
    }

    public static function leave(): void
    {
        self::$active = null;
    }

    public static function isActive(): bool
    {
        return self::$active !== null;
    }

    /**
     * @return array{run_id: string, parent_prompt_result_id: ?int, strategy: string, entered_at: string}|null
     */
    public static function context(): ?array
    {
        return self::$active;
    }

    /**
     * @throws \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException
     */
    public static function assertLegacyValidatorNotReached(string $classMethod, int $target, int $minimum): void
    {
        if (! self::isActive()) {
            return;
        }

        $ctx = self::$active ?? [];
        throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
            'SECTIONED_FREE_LEGACY_VALIDATOR_REACHED: whole-article length validator invoked during sectioned_free. '
            .'run_id='.(string) ($ctx['run_id'] ?? '')
            .' class/method='.$classMethod
            .' target='.$target
            .' minimum='.$minimum,
            0,
            null,
            [
                'failure_code' => 'SECTIONED_FREE_LEGACY_VALIDATOR_REACHED',
                'run_id' => $ctx['run_id'] ?? null,
                'parent_prompt_result_id' => $ctx['parent_prompt_result_id'] ?? null,
                'strategy' => 'sectioned_free',
                'class_method' => $classMethod,
                'target' => $target,
                'minimum' => $minimum,
                'retryable' => false,
            ],
        );
    }
}
