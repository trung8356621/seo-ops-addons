<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;

/**
 * Pure eligibility ranking for V1 dispatch (arrays in / arrays out).
 *
 * @phpstan-type AgentRow array{
 *     agent_id: string|int,
 *     online: bool,
 *     availability: string,
 *     active_jobs: int,
 *     max_concurrency: int,
 *     capabilities?: list<string>,
 *     runtime_version?: string|null
 * }
 * @phpstan-type SessionRow array{
 *     session_id: string|int,
 *     agent_id: string|int,
 *     ready_status: string,
 *     busy: bool,
 *     domain?: string|null,
 *     platform?: string|null,
 *     account?: array<string, mixed>|null,
 *     account_locked?: bool,
 *     capabilities?: list<string>
 * }
 * @phpstan-type Criteria array{
 *     platform?: string|null,
 *     domain?: string|null,
 *     required_capabilities?: list<string>,
 *     runtime_min?: string|null,
 *     target_count: int
 * }
 */
final class EligibilitySelector
{
    public function __construct(
        private readonly AccountDeduplicator $deduplicator = new AccountDeduplicator,
    ) {}

    /**
     * @param  list<AgentRow>  $agents
     * @param  list<SessionRow>  $sessions
     * @param  Criteria  $criteria
     * @return array{
     *     selected: list<SessionRow>,
     *     dispatched_count: int,
     *     target_count: int,
     *     shortfall: int,
     *     skip_reasons: list<string>
     * }
     */
    public function select(array $agents, array $sessions, array $criteria): array
    {
        $target = max(0, (int) ($criteria['target_count'] ?? 0));
        $skipReasons = [];
        $agentsById = [];
        foreach ($agents as $agent) {
            $agentsById[(string) $agent['agent_id']] = $agent;
        }

        $eligible = [];
        foreach ($sessions as $session) {
            $agentId = (string) $session['agent_id'];
            $agent = $agentsById[$agentId] ?? null;
            if ($agent === null) {
                $skipReasons[] = 'agent_missing';
                continue;
            }
            if (! ($agent['online'] ?? false)) {
                $skipReasons[] = 'agent_offline';
                continue;
            }
            $availability = (string) ($agent['availability'] ?? '');
            if (in_array($availability, [
                AgentAvailability::Paused->value,
                AgentAvailability::Disabled->value,
                AgentAvailability::Error->value,
            ], true)) {
                $skipReasons[] = 'agent_unavailable';
                continue;
            }
            if ((int) ($agent['active_jobs'] ?? 0) >= max(1, (int) ($agent['max_concurrency'] ?? 1))) {
                $skipReasons[] = 'agent_at_capacity';
                continue;
            }
            if (($session['ready_status'] ?? '') !== SessionReadyStatus::Ready->value) {
                $skipReasons[] = 'session_not_ready';
                continue;
            }
            if (! empty($session['busy'])) {
                $skipReasons[] = 'session_busy';
                continue;
            }
            if (! empty($session['account_locked'])) {
                $skipReasons[] = 'account_locked';
                continue;
            }

            $account = is_array($session['account'] ?? null) ? $session['account'] : [];
            if (AccountIdentityKey::strongKey($account) === null) {
                // Domain/platform alone is never enough to distribute work.
                $skipReasons[] = 'identity_incomplete';
                continue;
            }

            $wantPlatform = strtolower(trim((string) ($criteria['platform'] ?? $criteria['domain'] ?? '')));
            if ($wantPlatform !== '') {
                $have = strtolower(trim((string) ($session['platform'] ?? $session['domain'] ?? $account['platform'] ?? $account['domain'] ?? '')));
                if ($have !== $wantPlatform) {
                    $skipReasons[] = 'platform_mismatch';
                    continue;
                }
            }

            $requiredCaps = $criteria['required_capabilities'] ?? [];
            if (is_array($requiredCaps) && $requiredCaps !== []) {
                $haveCaps = array_map('strval', $session['capabilities'] ?? $agent['capabilities'] ?? []);
                foreach ($requiredCaps as $cap) {
                    if (! in_array((string) $cap, $haveCaps, true)) {
                        $skipReasons[] = 'capability_mismatch';
                        continue 2;
                    }
                }
            }

            $runtimeMin = trim((string) ($criteria['runtime_min'] ?? ''));
            $runtimeHave = trim((string) ($agent['runtime_version'] ?? ''));
            if ($runtimeMin !== '' && $runtimeHave !== '' && version_compare($runtimeHave, $runtimeMin, '<')) {
                $skipReasons[] = 'runtime_incompatible';
                continue;
            }

            $eligible[] = $session;
        }

        $unique = $this->deduplicator->uniqueByStrongIdentity($eligible);

        // Up to remaining agent capacity slots — not "1 browser = 1 account".
        $slotsLeft = [];
        foreach ($agentsById as $aid => $agent) {
            $max = max(1, (int) ($agent['max_concurrency'] ?? 1));
            $active = max(0, (int) ($agent['active_jobs'] ?? 0));
            $slotsLeft[$aid] = max(0, $max - $active);
        }

        $selected = [];
        foreach ($unique as $row) {
            $aid = (string) $row['agent_id'];
            if (($slotsLeft[$aid] ?? 0) < 1) {
                $skipReasons[] = 'agent_at_capacity';
                continue;
            }
            $slotsLeft[$aid]--;
            $selected[] = $row;
            if (count($selected) >= $target) {
                break;
            }
        }

        $dispatched = count($selected);

        return [
            'selected' => $selected,
            'dispatched_count' => $dispatched,
            'target_count' => $target,
            'shortfall' => max(0, $target - $dispatched),
            'skip_reasons' => array_values(array_unique($skipReasons)),
        ];
    }
}
