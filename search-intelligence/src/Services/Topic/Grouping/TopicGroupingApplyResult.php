<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Outcome of Preview or Apply against a persisted grouping run.
 *
 * @phpstan-type Metrics array<string, mixed>
 */
final class TopicGroupingApplyResult
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const ALREADY_APPLIED = 'already_applied';

    public const INVALID_STATE = 'invalid_state';

    public const APPLY_FAILED = 'apply_failed';

    /**
     * @param  Metrics  $metrics
     */
    public function __construct(
        public readonly string $status,
        public readonly ?TopicGroupingApplyPlan $plan = null,
        public readonly array $metrics = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    public static function okWithPlan(TopicGroupingApplyPlan $plan, array $metrics = []): self
    {
        return new self(self::OK, $plan, $metrics);
    }

    public static function stale(string $code, string $message, ?TopicGroupingApplyPlan $plan = null): self
    {
        return new self(self::STALE, $plan, [], $code, $message);
    }

    public static function alreadyApplied(array $metrics = []): self
    {
        return new self(self::ALREADY_APPLIED, null, $metrics, 'already_applied', 'Run already applied');
    }

    public static function invalidState(string $status): self
    {
        return new self(self::INVALID_STATE, null, [], 'invalid_state', 'Run status: '.$status);
    }

    public static function failed(string $code, string $message, ?TopicGroupingApplyPlan $plan = null): self
    {
        return new self(self::APPLY_FAILED, $plan, [], $code, $message);
    }
}
