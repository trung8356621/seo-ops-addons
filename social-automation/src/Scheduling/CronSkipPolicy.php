<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Scheduling;

/**
 * Offline / shortfall policy for schedule triggers. Pure.
 */
final class CronSkipPolicy
{
    public const REASON_OFFLINE = 'skipped_offline';

    public const REASON_NO_ELIGIBLE = 'skipped_no_eligible_sessions';

    public const REASON_PARTIAL = 'partial_dispatch';

    /**
     * @param  list<array{online: bool}>  $agents
     * @return array{should_dispatch: bool, reason: ?string}
     */
    public function whenAllAgentsOffline(array $agents): array
    {
        if ($agents === []) {
            return ['should_dispatch' => false, 'reason' => self::REASON_OFFLINE];
        }

        foreach ($agents as $agent) {
            if (! empty($agent['online'])) {
                return ['should_dispatch' => true, 'reason' => null];
            }
        }

        return ['should_dispatch' => false, 'reason' => self::REASON_OFFLINE];
    }

    /**
     * Never create catch-up backlog. Shortfall is recorded, not deferred.
     *
     * @return array{
     *     target_count: int,
     *     dispatched_count: int,
     *     shortfall: int,
     *     outcome: string,
     *     catch_up: false
     * }
     */
    public function summarizeDispatch(int $targetCount, int $dispatchedCount): array
    {
        $target = max(0, $targetCount);
        $dispatched = max(0, min($dispatchedCount, $target));
        $shortfall = max(0, $target - $dispatched);

        $outcome = match (true) {
            $dispatched === 0 && $target > 0 => self::REASON_NO_ELIGIBLE,
            $shortfall > 0 => self::REASON_PARTIAL,
            default => 'full_dispatch',
        };

        return [
            'target_count' => $target,
            'dispatched_count' => $dispatched,
            'shortfall' => $shortfall,
            'outcome' => $outcome,
            'catch_up' => false,
        ];
    }

    public function triggerIdempotencyKey(string $scheduleUuid, string $fireAtIso): string
    {
        return hash('sha256', $scheduleUuid.'|'.$fireAtIso);
    }
}
