<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentGovernancePolicyService;

final class AgentQualityGateService
{
    public function __construct(
        private readonly AgentGovernancePolicyService $governance = new AgentGovernancePolicyService,
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @return array{status: string, checks: array<string, mixed>, auto_promotion: false}
     */
    public function evaluate(array $summary): array
    {
        $gates = $this->governance->evaluationGates();
        $cases = (int) ($summary['case_count'] ?? 0);
        if ($cases < 3) {
            return [
                'status' => 'insufficient_data',
                'checks' => ['case_count' => $cases],
                'auto_promotion' => false,
            ];
        }

        $skillMatch = (float) ($summary['skill_match_rate'] ?? 0);
        $unsafe = (float) ($summary['unsafe_rate'] ?? 0);
        $validation = (float) ($summary['validation_pass_rate'] ?? 0);

        $checks = [
            'skill_match' => ['value' => $skillMatch, 'min' => $gates['skill_match_min'], 'ok' => $skillMatch >= $gates['skill_match_min']],
            'unsafe' => ['value' => $unsafe, 'max' => $gates['unsafe_max'], 'ok' => $unsafe <= $gates['unsafe_max']],
            'validation' => ['value' => $validation, 'min' => $gates['validation_pass_min'], 'ok' => $validation >= $gates['validation_pass_min']],
        ];

        $failed = 0;
        $warnings = 0;
        foreach ($checks as $c) {
            if (! ($c['ok'] ?? false)) {
                $failed++;
            }
        }
        if ($skillMatch < ($gates['skill_match_min'] + 0.05) && $skillMatch >= $gates['skill_match_min']) {
            $warnings++;
        }

        $status = $failed > 0 ? 'failed' : ($warnings > 0 ? 'warning' : 'passed');

        return [
            'status' => $status,
            'checks' => $checks,
            'auto_promotion' => false,
            'activation_guard' => $this->governance->canActivateCandidate($status),
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function compare(array $baseline, array $candidate): array
    {
        $keys = ['skill_match_rate', 'response_type_accuracy', 'validation_pass_rate', 'unsafe_rate', 'avg_latency_ms', 'avg_tokens'];
        $delta = [];
        foreach ($keys as $key) {
            $b = (float) ($baseline[$key] ?? 0);
            $c = (float) ($candidate[$key] ?? 0);
            $delta[$key] = round($c - $b, 4);
        }

        return [
            'baseline' => $baseline,
            'candidate' => $candidate,
            'delta' => $delta,
            'auto_promotion' => false,
        ];
    }
}
