<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentErrorCategory;
use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionContextUpdater;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionIdempotencyFactory;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanOutputBinder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\AgentErrorRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\AgentResultRendererRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\ContentProjectResultRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\GenericAgentResultRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\KeywordResultRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\SerpResultRenderer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentResultRendererTest extends TestCase
{
    public function test_content_project_renderer_supports_capability_prefix(): void
    {
        $result = $this->makeExecutionResult('content_project.create', true, ['project_ref' => 'cp_1']);
        $renderer = new ContentProjectResultRenderer;
        self::assertTrue($renderer->supports($result));
        $rendered = $renderer->render($result);
        self::assertStringContainsString('Content Project', $rendered['title']);
        self::assertTrue($rendered['hide_envelope'] ?? false);
        self::assertSame([], $rendered['links'] ?? []);
    }

    public function test_project_list_renderer_is_user_facing_business_text(): void
    {
        $result = $this->makeExecutionResult('content_project.list_projects', true, [
            'projects' => [[
                'project_id' => 31,
                'name' => 'Blog thÃ¡ng 8',
                'month' => '2026-08-01',
                'member_name' => 'Nguyá»…n VÄƒn A',
                'archived' => false,
                'stats' => ['total_items' => 20],
                'site_ref' => 'cps_should_not_appear',
            ]],
        ], message: 'Read successful.');
        $rendered = (new ContentProjectResultRenderer)->render($result);

        self::assertTrue($rendered['hide_envelope'] ?? false);
        self::assertStringContainsString('CONTENT PROJECTS', $rendered['summary']);
        self::assertStringContainsString('[31] Blog thÃ¡ng 8', $rendered['summary']);
        self::assertStringContainsString('Status: Active | Items: 20', $rendered['summary']);
        self::assertStringNotContainsString('Member:', $rendered['summary']);
        self::assertStringNotContainsString('site_ref', $rendered['summary']);
        self::assertStringNotContainsString('cps_should_not_appear', $rendered['summary']);
        self::assertStringNotContainsString('Read successful', $rendered['summary']);
        self::assertStringNotContainsString('tenant_ref', $rendered['summary']);
        self::assertSame([], $rendered['badges'] ?? []);
    }

    public function test_keyword_and_serp_renderers(): void
    {
        $keyword = $this->makeExecutionResult('keyword.analyze', true, ['workspace_ref' => 'kw_1']);
        self::assertTrue((new KeywordResultRenderer)->supports($keyword));
        $serp = $this->makeExecutionResult('serp.collect', true, ['serp_workspace_ref' => 'sp_1']);
        self::assertTrue((new SerpResultRenderer)->supports($serp));
    }

    public function test_generic_fallback_and_error_renderer(): void
    {
        $ok = $this->makeExecutionResult('operations.daily_report', true);
        $registry = new AgentResultRendererRegistry;
        $rendered = $registry->render($ok);
        self::assertArrayHasKey('summary', $rendered);

        $fail = $this->makeExecutionResult('content_project.publish_now', false, [], AgentErrorCategory::PermissionDenied);
        $errorRendered = (new AgentErrorRenderer)->render($fail);
        self::assertFalse($errorRendered['details']['retryable']);
        self::assertTrue($errorRendered['details']['open_settings']);
    }

    public function test_renderers_do_not_query_business_models(): void
    {
        $dir = ProjectRoot::addonsPath().'/agent/src/Services/AgentWorkspace/Execution/Rendering';
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('SeoProject::', $source, basename($file));
            self::assertStringNotContainsString('::query()', $source, basename($file));
            self::assertStringNotContainsString('DB::', $source, basename($file));
        }
    }

    public function test_context_updater_allowlist(): void
    {
        self::assertContains('project_ref', AgentExecutionContextUpdater::ALLOWED_KEYS);
        self::assertNotContains('site_ref', AgentExecutionContextUpdater::ALLOWED_KEYS);
        self::assertNotContains('api_key', AgentExecutionContextUpdater::ALLOWED_KEYS);
    }

    public function test_plan_output_binder_allowlist_only(): void
    {
        $binder = new AgentPlanOutputBinder;
        $out = $binder->bind([
            'project_ref' => 'cp_1',
            'site_ref' => 'should_ignore',
            'api_key' => 'secret',
            'selected_item_refs' => ['i1', ''],
        ], ['foo' => 'bar']);

        self::assertSame('cp_1', $out['project_ref']);
        self::assertSame('bar', $out['foo']);
        self::assertArrayNotHasKey('site_ref', $out);
        self::assertArrayNotHasKey('api_key', $out);
        self::assertSame(['i1'], $out['selected_item_refs']);
    }

    public function test_idempotency_factory_unique_per_call(): void
    {
        $factory = new AgentExecutionIdempotencyFactory;
        $a = $factory->make('aex_1', 1);
        $b = $factory->make('aex_1', 1);
        self::assertNotSame($a, $b);
        self::assertStringStartsWith('awex:aex_1:a1:', $a);
        self::assertStringContainsString('â€¦', $factory->mask($a));
    }

    public function test_orchestrator_does_not_call_command_bus(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentExecutionOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('AgentGateway', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('SeoProject::create', $source);
    }

    public function test_page_does_not_invent_confirmation_token(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        self::assertStringNotContainsString("'agent-ui-confirmed'", $source);
        self::assertStringContainsString('confirmExecution', $source);
        self::assertStringContainsString('cancelPendingExecution', $source);
        self::assertStringContainsString('retryExecution', $source);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeExecutionResult(
        string $capability,
        bool $ok,
        array $data = [],
        ?AgentErrorCategory $category = null,
        string $message = '',
    ): AgentExecutionResult {
        return new AgentExecutionResult(
            executionRef: 'aex_test',
            status: $ok ? AgentExecutionStatus::Succeeded : AgentExecutionStatus::Failed,
            ok: $ok,
            code: $ok ? 'ok' : 'fail',
            message: $message !== '' ? $message : ($ok ? 'OK' : 'Fail'),
            skillKey: 'skill',
            capabilityKey: $capability,
            data: $data,
            errorCategory: $category,
        );
    }
}
