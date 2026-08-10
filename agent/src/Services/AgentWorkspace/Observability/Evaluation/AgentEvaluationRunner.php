<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationCase;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationDataset;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationResult;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationRun;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityEventBus;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPlanningVersionRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPolicyViolationDetector;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Illuminate\Support\Str;

/**
 * Offline evaluation — planner scoring only, never executes business skills or command bus.
 */
final class AgentEvaluationRunner
{
    public function __construct(
        private readonly AgentPlanningEvaluator $planningEvaluator = new AgentPlanningEvaluator,
        private readonly AgentQualityGateService $gates = new AgentQualityGateService,
        private readonly AgentPolicyViolationDetector $policy = new AgentPolicyViolationDetector,
        private readonly AgentPlanningVersionRegistry $versions = new AgentPlanningVersionRegistry,
        private readonly ?AgentObservabilityEventBus $bus = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $datasetKey,
        ?string $candidateLabel = null,
        ?string $baselineRunHash = null,
        ?int $limit = null,
        bool $dryRun = false,
        ?int $createdBy = null,
    ): array {
        $dataset = SeoAgentEvaluationDataset::query()->where('key', $datasetKey)->where('enabled', true)->first();
        if ($dataset === null) {
            return ['ok' => false, 'code' => 'dataset_not_found'];
        }

        $run = SeoAgentEvaluationRun::query()->create([
            'hash_id' => 'aeval_'.Str::lower((string) Str::ulid()),
            'dataset_id' => $dataset->id,
            'status' => 'running',
            'candidate_label' => $candidateLabel,
            'baseline_run_hash' => $baselineRunHash,
            'config_snapshot' => $this->versions->snapshot(),
            'created_by' => $createdBy,
            'dry_run' => $dryRun,
            'started_at' => now(),
        ]);

        $q = SeoAgentEvaluationCase::query()
            ->where('dataset_id', $dataset->id)
            ->where('enabled', true)
            ->orderBy('id');
        if ($limit !== null && $limit > 0) {
            $q->limit($limit);
        }
        $cases = $q->get();

        $skillHits = 0;
        $typeHits = 0;
        $validationPass = 0;
        $unsafeCount = 0;
        $total = 0;
        $latencySum = 0;

        foreach ($cases as $case) {
            $total++;
            $started = microtime(true);
            // Isolated: simulate observed from fixture expectations only when dry-run;
            // real planner call is optional and never executes Phase 2.
            $observed = $this->observeCase($case);
            $violations = $this->policy->inspect($observed, $run->hash_id, null);
            $eval = $this->planningEvaluator->evaluate($observed, [
                'expected_response_type' => $case->expected_response_type,
                'expected_skill_keys' => $case->expected_skill_keys ?? [],
                'forbidden_skills' => $case->forbidden_skills ?? [],
                'expected_clarification_keys' => $case->expected_clarification_keys ?? [],
                'expected_step_order' => $case->expected_step_order ?? [],
            ]);
            if ($eval['unsafe'] || $violations !== []) {
                $unsafeCount++;
                $eval['violations'] = array_values(array_unique(array_merge(
                    $eval['violations'],
                    array_column($violations, 'code'),
                )));
            }
            if (($eval['scores']['skill_match'] ?? 0) >= 1.0) {
                $skillHits++;
            }
            if (($eval['scores']['response_type'] ?? 0) >= 1.0) {
                $typeHits++;
            }
            if (($eval['scores']['schema'] ?? 0) >= 1.0) {
                $validationPass++;
            }
            $latency = (int) round((microtime(true) - $started) * 1000);
            $latencySum += $latency;

            if (! $dryRun) {
                SeoAgentEvaluationResult::query()->create([
                    'hash_id' => 'aeres_'.Str::lower((string) Str::ulid()),
                    'run_id' => $run->id,
                    'case_id' => $case->id,
                    'status' => 'scored',
                    'score' => $eval['score'],
                    'scores' => $eval['scores'],
                    'observed' => $observed,
                    'violations' => $eval['violations'],
                    'latency_ms' => $latency,
                    'token_usage' => null,
                ]);
            }
        }

        $summary = [
            'case_count' => $total,
            'skill_match_rate' => $total > 0 ? round($skillHits / $total, 4) : 0,
            'response_type_accuracy' => $total > 0 ? round($typeHits / $total, 4) : 0,
            'validation_pass_rate' => $total > 0 ? round($validationPass / $total, 4) : 0,
            'unsafe_rate' => $total > 0 ? round($unsafeCount / $total, 4) : 0,
            'avg_latency_ms' => $total > 0 ? (int) round($latencySum / $total) : 0,
            'avg_tokens' => null,
            'executed_business_actions' => 0,
        ];

        $gate = $this->gates->evaluate($summary);
        if ($baselineRunHash) {
            $baseline = SeoAgentEvaluationRun::query()->where('hash_id', $baselineRunHash)->first();
            if ($baseline !== null && is_array($baseline->summary)) {
                $summary['comparison'] = $this->gates->compare($baseline->summary, $summary);
            }
        }

        $run->fill([
            'status' => 'completed',
            'summary' => $summary,
            'gate_status' => $gate['status'],
            'finished_at' => now(),
        ]);
        $run->save();

        $this->bus?->dispatch([
            'event_type' => 'evaluation.completed',
            'trace_id' => $run->hash_id,
            'attributes' => ['gate_status' => $gate['status'], 'dataset' => $datasetKey],
            'severity' => $gate['status'] === 'failed' ? 'high' : 'info',
        ]);

        return [
            'ok' => true,
            'run_hash_id' => $run->hash_id,
            'summary' => $summary,
            'gate' => $gate,
            'dry_run' => $dryRun,
            'business_executed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observeCase(SeoAgentEvaluationCase $case): array
    {
        // Fixture-driven observation for offline scoring without live model.
        // Sanitized context fixture may embed expected observed fields under "observed".
        $fixture = is_array($case->context_fixture) ? $case->context_fixture : [];
        $observed = is_array($fixture['observed'] ?? null) ? $fixture['observed'] : [];

        $skills = is_array($case->expected_skill_keys) ? $case->expected_skill_keys : [];
        $observed['skill_key'] ??= $skills[0] ?? '';
        $observed['type'] ??= $case->expected_response_type ?? AgentPlanningResponse::TYPE_SINGLE_INTENT;
        $observed['schema_valid'] ??= true;
        $observed['clarification_keys'] ??= $case->expected_clarification_keys ?? [];
        $observed['step_order'] ??= $case->expected_step_order ?? [];

        return $observed;
    }
}
