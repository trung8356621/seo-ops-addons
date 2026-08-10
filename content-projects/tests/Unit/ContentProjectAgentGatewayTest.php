<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Http\Controllers\Api\V1\ContentProjectAgentMcpController;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\AgentPlanDraftValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectNaturalLanguageAdapter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpServer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectAgentGatewayTest extends TestCase
{
    public function test_mcp_server_only_calls_gateway(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectMcpServer::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectAgentGateway', $source);
        self::assertStringContainsString('gateway->execute', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('Handler', $source);
        self::assertStringNotContainsString('SeoProjectRun', $source);
    }

    public function test_mcp_controller_does_not_touch_command_bus_or_run(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentMcpController::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectMcpServer', $source);
        self::assertStringContainsString('ContentProjectAgentGateway', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('SeoProjectRun', $source);
        self::assertStringNotContainsString('WordPress', $source);
    }

    public function test_gateway_dispatches_via_command_bus_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('commandBus->dispatch', $source);
        self::assertStringContainsString('CanonicalCapabilityRegistry', $source);
        self::assertStringNotContainsString('SeoProjectRun', $source);
        self::assertStringNotContainsString('startRun', $source);
    }

    public function test_policy_does_not_reference_seo_project_run(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentPolicy::class))->getFileName(),
        );

        self::assertStringNotContainsString('SeoProjectRun', $source);
    }

    public function test_registry_exposes_json_schema_and_hides_sync_items(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        $schema = $registry->jsonSchema('content_project.generate');

        self::assertIsArray($schema);
        self::assertSame('object', $schema['type'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? true);
        self::assertContains('project_ref', $schema['required'] ?? []);

        self::assertFalse($registry->isAgentWriteExposed('content_project.sync_items'));
        self::assertTrue($registry->isAgentWriteExposed('content_project.generate'));
        self::assertNotNull($registry->get('content_project.rerun_items'));
    }

    public function test_mcp_catalog_excludes_internal_tools(): void
    {
        $catalog = new ContentProjectMcpToolCatalog(new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        ));
        $names = array_map(static fn (array $t): string => (string) $t['name'], $catalog->listTools());

        self::assertContains('content_project.create', $names);
        self::assertContains('content_project.generate', $names);
        self::assertContains('content_project.rerun_items', $names);
        self::assertContains('content_project.get_operation', $names);
        self::assertContains('content_project.list_projects', $names);
        self::assertNotContains('content_project.sync_items', $names);
        self::assertNotContains('content_project.process_scheduled_publish', $names);
        self::assertNotContains('content_project.stop_execution', $names);
        self::assertNotContains('content_project.rerun', $names);
    }

    public function test_public_ref_site_rejects_numeric(): void
    {
        $ref = ContentProjectPublicRef::site(7);
        self::assertStringStartsWith('cps_', $ref);
        self::assertSame(7, ContentProjectPublicRef::resolveSiteIdStrict($ref));

        $this->expectException(\InvalidArgumentException::class);
        ContentProjectPublicRef::resolveSiteIdStrict('7');
    }

    public function test_agent_context_requires_tenant_and_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentExecutionContext::fromArray([
            'actor_ref' => 'actor_1',
            'request_ref' => 'req_1',
        ]);
    }

    public function test_agent_context_to_actor_uses_agent_prefix_idempotency(): void
    {
        $ctx = AgentExecutionContext::fromArray([
            'actor_ref' => 'actor_1',
            'tenant_ref' => 'tenant:cps_x',
            'site_ref' => 'cps_x',
            'request_ref' => 'req_1',
            'idempotency_key' => 'abc',
            'dry_run' => true,
            'scopes' => ['content-project:write'],
        ])->withResolved(3, 9);

        $actor = $ctx->toActorContext();
        self::assertSame('agent', $actor->actorType);
        self::assertSame(3, $actor->siteId);
        self::assertSame('agent:abc', $actor->idempotencyKey);
        self::assertTrue($actor->dryRun);
        self::assertSame('req_1', $actor->correlationId);
    }

    public function test_plan_rejects_unknown_capability_and_limits_steps(): void
    {
        $registry = new ContentProjectCapabilityRegistry;

        $invalid = AgentPlanDraftValidator::validatePlan([
            'steps' => [
                ['capability' => 'content_project.generate', 'input' => []],
                ['capability' => 'execute_sql', 'input' => []],
            ],
        ], $registry);
        self::assertNotSame([], $invalid);

        $tooMany = ['steps' => []];
        for ($i = 0; $i < 21; $i++) {
            $tooMany['steps'][] = ['capability' => 'content_project.get_status', 'input' => []];
        }
        $limited = AgentPlanDraftValidator::validatePlan($tooMany, $registry);
        self::assertNotSame([], $limited);
    }

    public function test_natural_language_adapter_does_not_guess_site(): void
    {
        $adapter = new ContentProjectNaturalLanguageAdapter;
        $parsed = $adapter->parseIntent('Tạo project về cafe', []);

        self::assertArrayHasKey('status', $parsed);
        self::assertArrayHasKey('missing_fields', $parsed);
        self::assertContains('site_ref', $parsed['missing_fields'] ?? []);
        self::assertStringNotContainsString('SeoProjectRun', (string) file_get_contents(
            (new ReflectionClass(ContentProjectNaturalLanguageAdapter::class))->getFileName(),
        ));
    }

    public function test_agent_docs_referenced_in_gateway_package(): void
    {
        $gateway = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );
        self::assertStringContainsString('orchestration only', $gateway);
    }
}
