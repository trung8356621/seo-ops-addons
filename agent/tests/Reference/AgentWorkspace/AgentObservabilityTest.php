<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Console\AgentEvaluateCommand;
use Omnichannel\Addons\Agent\Jobs\RunAgentEvaluationJob;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityEventBus;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityRedactor;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentAutomationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentKnowledgeRetriever;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\ObservabilitySkills;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentObservabilityTest extends TestCase
{
    public function test_observability_skills_registered(): void
    {
        $keys = array_column(BuiltinSkillCatalog::definitions(), 'key');
        foreach ([
            'observability.health',
            'observability.metrics',
            'observability.trace',
            'observability.review',
            'observability.run_evaluation',
            'observability.evaluation_status',
            'observability.policy_violations',
            'observability.automation_health',
        ] as $key) {
            self::assertContains($key, $keys);
        }
        $slash = array_column(ObservabilitySkills::definitions(), 'slash_command');
        self::assertContains('/agent-health', $slash);
        self::assertContains('/run-evaluation', $slash);
    }

    public function test_span_and_metric_allowlists(): void
    {
        self::assertTrue(AgentObservabilityCatalog::isSpanType('planning'));
        self::assertFalse(AgentObservabilityCatalog::isSpanType('hack'));
        self::assertTrue(AgentObservabilityCatalog::isMetricKey('planning.count'));
        self::assertFalse(AgentObservabilityCatalog::isMetricKey('user_id_12345'));
        self::assertTrue(AgentObservabilityCatalog::isEventType('policy.violation'));
        self::assertFalse(AgentObservabilityCatalog::isEventType('browser.custom'));
    }

    public function test_event_bus_rejects_unknown_type(): void
    {
        $bus = new AgentObservabilityEventBus;
        $result = $bus->dispatch([
            'event_type' => 'not_allowed',
            'trace_id' => 'atrace_test',
        ]);
        self::assertFalse($result['accepted']);
        self::assertFalse($result['persisted_claimed']);
    }

    public function test_redactor_strips_secrets_and_prompts(): void
    {
        $redactor = new AgentObservabilityRedactor;
        $out = $redactor->redact([
            'message' => 'hello',
            'api_token' => 'secret-value',
            'raw_prompt' => 'SYSTEM: do not leak',
            'nested' => ['password' => 'x'],
        ]);
        self::assertSame('hello', $out['message']);
        self::assertSame('[redacted]', $out['api_token']);
        self::assertSame('[redacted]', $out['raw_prompt']);
        self::assertSame('[redacted]', $out['nested']['password']);
    }

    public function test_high_cardinality_dimensions_filtered(): void
    {
        $redactor = new AgentObservabilityRedactor;
        $dims = $redactor->filterDimensions([
            'skill_key' => 'operations.site_health',
            'user_email' => 'a@b.c',
            'message_id' => '999',
            'provider' => 'openai',
        ]);
        self::assertArrayHasKey('skill_key', $dims);
        self::assertArrayHasKey('provider', $dims);
        self::assertArrayNotHasKey('user_email', $dims);
        self::assertArrayNotHasKey('message_id', $dims);
    }

    public function test_decorators_preserve_interfaces_and_fail_open_pattern(): void
    {
        foreach ([
            ObservingAgentPlanningOrchestrator::class,
            ObservingAgentExecutionOrchestrator::class,
            ObservingAgentKnowledgeRetriever::class,
            ObservingAgentAutomationRunner::class,
        ] as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            self::assertStringContainsString('private readonly', $source);
            self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        }
    }

    public function test_application_routes_observability(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        self::assertStringContainsString('handleObservability', $source);
        self::assertStringContainsString('OBSERVABILITY_CAPABILITIES', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_evaluation_job_never_command_bus(): void
    {
        $job = (string) file_get_contents((new ReflectionClass(RunAgentEvaluationJob::class))->getFileName());
        self::assertStringContainsString('AgentEvaluationRunner', $job);
        self::assertStringNotContainsString('ContentProjectCommandBus', $job);
        self::assertStringNotContainsString('AgentGateway', $job);

        $cmd = (string) file_get_contents((new ReflectionClass(AgentEvaluateCommand::class))->getFileName());
        self::assertStringContainsString('agent:evaluate', $cmd);
        self::assertStringContainsString('no business execution', strtolower($cmd));
    }

    public function test_migration_phase6_tables(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_28_250000_phase6_agent_observability.php');
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        foreach ([
            'seo_agent_traces',
            'seo_agent_trace_spans',
            'seo_agent_metric_events',
            'seo_agent_metric_aggregates',
            'seo_agent_evaluation_datasets',
            'seo_agent_evaluation_cases',
            'seo_agent_evaluation_runs',
            'seo_agent_evaluation_results',
            'seo_agent_reviews',
            'seo_agent_feedback',
        ] as $table) {
            self::assertStringContainsString($table, $body);
        }
    }
}
