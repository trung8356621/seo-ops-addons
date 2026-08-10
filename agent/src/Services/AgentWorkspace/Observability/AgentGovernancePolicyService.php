<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

/**
 * Governance policies — does not override capability confirmation policy.
 */
final class AgentGovernancePolicyService
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @return list<string>
     */
    public function allowedModels(): array
    {
        $models = $this->config['allowed_models'] ?? [];

        return is_array($models) ? array_values(array_map('strval', $models)) : [];
    }

    public function planningDailyLimit(): int
    {
        return (int) ($this->config['planning_daily_limit'] ?? 200);
    }

    public function contextBudgetTokens(): int
    {
        return (int) ($this->config['context_budget_tokens'] ?? 8000);
    }

    public function minKnowledgeTrust(): string
    {
        return (string) ($this->config['min_knowledge_trust'] ?? 'unverified');
    }

    public function maxActiveAutomationsPerSite(): int
    {
        return (int) ($this->config['max_active_automations_per_site'] ?? 20);
    }

    /**
     * @return array{skill_match_min: float, unsafe_max: float, validation_pass_min: float}
     */
    public function evaluationGates(): array
    {
        $g = is_array($this->config['evaluation_gates'] ?? null) ? $this->config['evaluation_gates'] : [];

        return [
            'skill_match_min' => (float) ($g['skill_match_min'] ?? 0.7),
            'unsafe_max' => (float) ($g['unsafe_max'] ?? 0.05),
            'validation_pass_min' => (float) ($g['validation_pass_min'] ?? 0.9),
        ];
    }

    /**
     * @return array{metric_events_days: int, traces_days: int, aggregates_days: int, evaluations_days: int, reviews_days: int, feedback_days: int}
     */
    public function retentionDays(): array
    {
        $r = is_array($this->config['retention_days'] ?? null) ? $this->config['retention_days'] : [];

        return [
            'metric_events_days' => (int) ($r['metric_events_days'] ?? 14),
            'traces_days' => (int) ($r['traces_days'] ?? 30),
            'aggregates_days' => (int) ($r['aggregates_days'] ?? 365),
            'evaluations_days' => (int) ($r['evaluations_days'] ?? 180),
            'reviews_days' => (int) ($r['reviews_days'] ?? 180),
            'feedback_days' => (int) ($r['feedback_days'] ?? 180),
        ];
    }

    public function canAccessDiagnostics(string $role, array $scopes): bool
    {
        if (in_array($role, ['admin', 'manager', 'owner'], true)) {
            return true;
        }

        return in_array('agent:diagnostics', $scopes, true)
            || in_array('agent:observability', $scopes, true);
    }

    public function canExport(string $role, array $scopes): bool
    {
        return $this->canAccessDiagnostics($role, $scopes);
    }

    public function reviewThresholdForNegativeFeedback(): string
    {
        return (string) ($this->config['negative_feedback_review_severity'] ?? 'warning');
    }

    /**
     * Public guard for admin activation — never auto-promotes.
     *
     * @return array{allowed: bool, reason: string, auto_promotion: false}
     */
    public function canActivateCandidate(string $gateStatus): array
    {
        if ($gateStatus === 'passed') {
            return ['allowed' => true, 'reason' => 'gate_passed', 'auto_promotion' => false];
        }

        return ['allowed' => false, 'reason' => 'gate_'.$gateStatus, 'auto_promotion' => false];
    }
}
