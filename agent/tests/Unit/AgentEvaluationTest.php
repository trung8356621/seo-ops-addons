<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentCostUsageTracker;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentGovernancePolicyService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPolicyViolationDetector;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentAutomationHealthEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentExecutionOutcomeEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentGroundingEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentPlanningEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentQualityGateService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentEvaluationTest extends TestCase
{
    public function test_planning_evaluator_skill_and_unsafe(): void
    {
        $eval = new AgentPlanningEvaluator;
        $ok = $eval->evaluate(
            ['type' => 'single_intent', 'skill_key' => 'operations.site_health', 'schema_valid' => true],
            ['expected_response_type' => 'single_intent', 'expected_skill_keys' => ['operations.site_health']],
        );
        self::assertGreaterThanOrEqual(0.9, $ok['score']);
        self::assertFalse($ok['unsafe']);

        $bad = $eval->evaluate(
            ['type' => 'single_intent', 'skill_key' => 'secret.internal', 'is_hidden' => true, 'auto_confirm' => true],
            ['expected_skill_keys' => ['operations.site_health'], 'forbidden_skills' => ['secret.internal']],
        );
        self::assertTrue($bad['unsafe']);
        self::assertContains('forbidden_skill', $bad['violations']);
        self::assertContains('internal_skill', $bad['violations']);
        self::assertContains('auto_confirm', $bad['violations']);
    }

    public function test_execution_and_grounding_evaluators(): void
    {
        $exec = (new AgentExecutionOutcomeEvaluator)->evaluate([
            'ok' => true,
            'requires_confirmation' => true,
            'confirmed' => true,
            'preview_effects_match' => true,
        ]);
        self::assertGreaterThanOrEqual(0.9, $exec['score']);

        $ground = (new AgentGroundingEvaluator)->evaluate([
            'cross_site' => false,
            'citation_valid' => true,
            'has_conflict' => true,
            'conflict_surfaced' => true,
            'budget_ok' => true,
        ]);
        self::assertGreaterThanOrEqual(0.9, $ground['score']);

        $cross = (new AgentGroundingEvaluator)->evaluate(['cross_site' => true, 'citation_valid' => false]);
        self::assertContains('cross_site_knowledge', $cross['violations']);
        self::assertContains('fabricated_citation', $cross['violations']);
    }

    public function test_automation_health_recommends_pause_not_auto(): void
    {
        $health = (new AgentAutomationHealthEvaluator)->evaluate([
            'failure_streak' => 5,
            'notification_spam' => 1,
        ]);
        self::assertContains('recommend_pause', $health['recommendations']);
        self::assertFalse($health['auto_pause']);
    }

    public function test_quality_gate_no_auto_promotion(): void
    {
        $gates = new AgentQualityGateService;
        $insufficient = $gates->evaluate(['case_count' => 1]);
        self::assertSame('insufficient_data', $insufficient['status']);
        self::assertFalse($insufficient['auto_promotion']);

        $pass = $gates->evaluate([
            'case_count' => 10,
            'skill_match_rate' => 0.95,
            'unsafe_rate' => 0.0,
            'validation_pass_rate' => 1.0,
        ]);
        self::assertSame('passed', $pass['status']);
        self::assertFalse($pass['auto_promotion']);
        self::assertFalse($pass['activation_guard']['auto_promotion']);

        $fail = $gates->evaluate([
            'case_count' => 10,
            'skill_match_rate' => 0.2,
            'unsafe_rate' => 0.5,
            'validation_pass_rate' => 0.1,
        ]);
        self::assertSame('failed', $fail['status']);
    }

    public function test_evaluation_runner_source_no_business_execution(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentEvaluationRunner::class))->getFileName(),
        );
        self::assertStringContainsString('executed_business_actions', $source);
        self::assertStringContainsString('business_executed', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('AgentExecutionOrchestrator', $source);
    }
}

final class AgentGovernanceTest extends TestCase
{
    public function test_policy_detector_finds_auto_confirm_and_cross_site(): void
    {
        $detector = new AgentPolicyViolationDetector;
        $violations = $detector->inspect([
            'auto_confirm' => true,
            'note' => 'cross_site override attempt',
            'raw_token' => 'awconf_abc',
        ], 'atrace_test', 1);
        $codes = array_column($violations, 'code');
        self::assertContains('auto_confirm', $codes);
        self::assertContains('cross_site', $codes);
        self::assertContains('secret_persistence', $codes);
    }

    public function test_governance_activation_guard(): void
    {
        $gov = new AgentGovernancePolicyService;
        self::assertFalse($gov->canActivateCandidate('failed')['allowed']);
        self::assertFalse($gov->canActivateCandidate('failed')['auto_promotion']);
        self::assertTrue($gov->canActivateCandidate('passed')['allowed']);
        self::assertFalse($gov->canActivateCandidate('passed')['auto_promotion']);
    }

    public function test_cost_unknown_when_no_pricing(): void
    {
        $tracker = new AgentCostUsageTracker;
        $out = $tracker->track(
            ['input_tokens' => 10, 'output_tokens' => 5],
            'openai',
            'gpt-test',
            120,
        );
        self::assertTrue($out['cost_unknown']);
        self::assertNull($out['cost_estimate']);
        self::assertSame(10, $out['input_tokens']);
    }

    public function test_metric_keys_cover_security(): void
    {
        self::assertTrue(AgentObservabilityCatalog::isMetricKey('security.policy_violation'));
        self::assertTrue(AgentObservabilityCatalog::isMetricKey('security.cross_site_reject'));
    }
}

final class AgentTraceTest extends TestCase
{
    public function test_trace_service_redacts_and_scopes(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class))->getFileName(),
        );
        self::assertStringContainsString('cross-site forbidden', $source);
        self::assertStringContainsString('atrace_', $source);
        self::assertStringContainsString('aspan_', $source);
        self::assertStringNotContainsString('chain_of_thought', $source);
    }
}

final class AgentMetricTest extends TestCase
{
    public function test_aggregator_idempotent_unique_key_in_source(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricAggregator::class))->getFileName(),
        );
        self::assertStringContainsString('updateOrCreate', $source);
        self::assertStringContainsString('dim_hash', $source);
    }

    public function test_retention_keeps_aggregates(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentRetentionService::class))->getFileName(),
        );
        self::assertStringContainsString('aggregates_kept', $source);
        self::assertStringContainsString('metric_events', $source);
    }
}

final class AgentReviewTest extends TestCase
{
    public function test_feedback_no_auto_retry_in_service(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentFeedbackService::class))->getFileName(),
        );
        self::assertStringContainsString('auto_retry', $source);
        self::assertStringContainsString('false', $source);
        self::assertStringContainsString('user_negative_feedback', $source);
        self::assertStringNotContainsString('CommandBus', $source);
    }

    public function test_review_resolve_no_business_mutation(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentReviewService::class))->getFileName(),
        );
        self::assertStringContainsString('business_mutated', $source);
        self::assertStringContainsString('false', $source);
    }
}
