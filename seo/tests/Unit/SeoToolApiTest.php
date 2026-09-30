<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\ContentProjectDraftIntakeToolHandler;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\SeoAuditListToolHandler;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolContext;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolDefinition;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutionResult;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutor;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolHandlerInterface;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolInputValidator;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolRegistry;
use Tests\TestCase;

final class SeoToolApiTest extends TestCase
{
    private SeoToolInputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SeoToolInputValidator();
    }

    public function test_definition_validates_safe_properties_and_serializes_publicly(): void
    {
        $definition = new SeoToolDefinition(
            key: 'test.tool',
            name: 'Test Tool',
            description: 'A test tool for unit tests.',
            module: 'seo',
            kind: SeoToolDefinition::KIND_READ,
            scopes: ['seo:read'],
            requiredContext: ['site'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE,
            isExposed: true,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
            ]
        );

        $public = $definition->toPublicArray();

        $this->assertSame('test.tool', $public['key']);
        $this->assertSame('Test Tool', $public['name']);
        $this->assertSame('seo', $public['module']);
        $this->assertSame('read', $public['kind']);
        $this->assertSame(['seo:read'], $public['scopes']);
        $this->assertSame(['site'], $public['required_context']);
        $this->assertSame('none', $public['confirmation_policy']);
        $this->assertTrue($public['is_exposed']);

        // Must not leak internal PHP handler or private metadata
        $this->assertArrayNotHasKey('handler', $public);
        $this->assertArrayNotHasKey('class', $public);
    }

    public function test_definition_rejects_unsafe_keys_or_invalid_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SeoToolDefinition(
            key: 'Invalid Key With Spaces',
            name: 'Invalid',
            description: 'Invalid',
            module: 'seo',
            kind: 'unsupported_kind',
            scopes: ['seo:read'],
            requiredContext: [],
            confirmationPolicy: 'none',
            inputSchema: []
        );
    }

    public function test_registry_prevents_duplicate_keys_and_mismatched_handlers(): void
    {
        $registry = new SeoToolRegistry();

        $def = new SeoToolDefinition(
            key: 'seo_audit.list',
            name: 'SEO Audit List',
            description: 'Audit list',
            module: 'seo',
            kind: SeoToolDefinition::KIND_READ,
            scopes: ['seo:read'],
            requiredContext: ['site'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE,
            inputSchema: []
        );

        $handler = $this->createMock(SeoToolHandlerInterface::class);
        $handler->method('getToolKey')->willReturn('seo_audit.list');

        $registry->register($def, $handler);

        // Duplicate registration must throw
        $this->expectException(InvalidArgumentException::class);
        $registry->register($def, $handler);
    }

    public function test_default_registry_builds_canonical_tools(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);

        $auditHandler = new SeoAuditListToolHandler($auditReadService);
        $draftHandler = new ContentProjectDraftIntakeToolHandler($draftIntakeService);

        $registry = SeoToolRegistry::buildDefault($auditHandler, $draftHandler);

        $this->assertTrue($registry->has('seo_audit.list'));
        $this->assertTrue($registry->has('content_project.draft_intake'));

        $auditDef = $registry->getDefinition('seo_audit.list');
        $this->assertNotNull($auditDef);
        $this->assertSame('read', $auditDef->kind);
        $this->assertSame('none', $auditDef->confirmationPolicy);

        $draftDef = $registry->getDefinition('content_project.draft_intake');
        $this->assertNotNull($draftDef);
        $this->assertSame('write', $draftDef->kind);
        $this->assertSame('required', $draftDef->confirmationPolicy);
    }

    public function test_registry_filters_tools_for_caller_context_scopes(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditReadService),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );

        // Caller with only seo:read
        $seoContext = new SeoToolContext(
            actorRef: 'test:actor',
            scopes: ['seo:read']
        );
        $visibleSeo = $registry->listForContext($seoContext);
        $keys = array_column($visibleSeo, 'key');
        $this->assertContains('seo_audit.list', $keys);
        $this->assertNotContains('content_project.draft_intake', $keys);

        // Caller with wildcard *
        $adminContext = new SeoToolContext(
            actorRef: 'test:admin',
            scopes: ['*']
        );
        $visibleAdmin = $registry->listForContext($adminContext);
        $adminKeys = array_column($visibleAdmin, 'key');
        $this->assertContains('seo_audit.list', $adminKeys);
        $this->assertContains('content_project.draft_intake', $adminKeys);

        // Caller with no matching scopes
        $unrelatedContext = new SeoToolContext(
            actorRef: 'test:unrelated',
            scopes: ['media:read']
        );
        $visibleNone = $registry->listForContext($unrelatedContext);
        $this->assertEmpty($visibleNone);
    }

    public function test_executor_rejects_unknown_tool_with_404(): void
    {
        $registry = new SeoToolRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(actorRef: 'test:actor', scopes: ['*']);
        $result = $executor->execute('nonexistent.tool', $context);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('tool_not_found', $result->getErrorCode());
        $this->assertSame(404, $result->getHttpStatus());
    }

    public function test_executor_enforces_caller_scope(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditReadService),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        // Call seo_audit.list without seo:read scope
        $context = new SeoToolContext(
            actorRef: 'test:actor',
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute('seo_audit.list', $context, ['site_id' => 1]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('forbidden_scope', $result->getErrorCode());
        $this->assertSame(403, $result->getHttpStatus());
    }

    public function test_executor_enforces_required_context(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditReadService),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        // Missing site context
        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: null,
            scopes: ['seo:read']
        );

        $result = $executor->execute('seo_audit.list', $context, []);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_context', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_executor_enforces_input_schema(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditReadService),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        // content_project.draft_intake requires 'items' to be an array
        $result = $executor->execute(
            'content_project.draft_intake',
            $context,
            ['items' => 'not-an-array'],
            true
        );

        $this->assertFalse($result->isSuccess());
        $this->assertSame('validation_failed', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_executor_blocks_unconfirmed_write_actions(): void
    {
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        // Ensure canonical service intake is NEVER called when unconfirmed
        $draftIntakeService->expects($this->never())->method('intake');

        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($this->createMock(SeoAuditAgentReadService::class)),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute(
            'content_project.draft_intake',
            $context,
            [
                'items' => [
                    ['keyword' => 'test keyword', 'type' => 'new'],
                ],
            ],
            confirmed: false
        );

        $this->assertFalse($result->isSuccess());
        $this->assertSame('confirmation_required', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
        $this->assertSame('required', $result->getMeta()['confirmation_policy'] ?? null);
    }

    public function test_read_action_executes_without_confirmation(): void
    {
        $auditReadService = $this->createMock(SeoAuditAgentReadService::class);
        $auditReadService->expects($this->once())
            ->method('listArticles')
            ->willReturn([
                'items' => [
                    [
                        'article_ref' => 'article:123',
                        'title' => 'Test Article',
                        'score' => 65,
                    ],
                ],
                'total' => 1,
                'post_type' => null,
            ]);

        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditReadService),
            new ContentProjectDraftIntakeToolHandler($this->createMock(ServiceApiDraftIntakeService::class))
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['seo:read']
        );

        $result = $executor->execute(
            'seo_audit.list',
            $context,
            ['limit' => 10],
            confirmed: false
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->getData()['total'] ?? null);
        $this->assertCount(1, $result->getData()['items'] ?? []);
    }

    public function test_confirmed_write_action_delegates_to_canonical_draft_intake(): void
    {
        $draftIntakeService = $this->createMock(ServiceApiDraftIntakeService::class);
        $draftIntakeResult = new ServiceApiDraftIntakeResult(
            draftRef: 'project:10',
            siteRef: 'site:1',
            submitted: 1,
            added: 1,
            alreadyInDraft: 0,
            failed: 0,
            items: []
        );

        $draftIntakeService->expects($this->once())
            ->method('intake')
            ->willReturn($draftIntakeResult);

        $registry = SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($this->createMock(SeoAuditAgentReadService::class)),
            new ContentProjectDraftIntakeToolHandler($draftIntakeService)
        );
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute(
            'content_project.draft_intake',
            $context,
            [
                'items' => [
                    ['keyword' => 'fresh keyword', 'type' => 'new'],
                ],
            ],
            confirmed: true
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('project:10', $result->getData()['draft_ref'] ?? null);
        $this->assertSame(1, $result->getData()['added'] ?? null);
    }

    public function test_architecture_guard_no_legacy_tables_or_duplicate_algorithms(): void
    {
        $toolClasses = [
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolContext::class,
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolDefinition::class,
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutionResult::class,
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutor::class,
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolRegistry::class,
            \Omnichannel\Addons\Seo\Services\Tools\SeoToolInputValidator::class,
            \Omnichannel\Addons\Seo\Services\Tools\Handlers\SeoAuditListToolHandler::class,
            \Omnichannel\Addons\Seo\Services\Tools\Handlers\ContentProjectDraftIntakeToolHandler::class,
            \Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoToolApiController::class,
        ];

        foreach ($toolClasses as $class) {
            $ref = new \ReflectionClass($class);
            $fileName = $ref->getFileName();
            $this->assertIsString($fileName);
            $content = file_get_contents($fileName);
            $this->assertIsString($content);

            // Must NOT refer to retired legacy tables
            $this->assertStringNotContainsString('seo_agent_', $content);
            // Must NOT contain direct DB table queries
            $this->assertStringNotContainsString("DB::table('seo_articles')", $content);
            $this->assertStringNotContainsString("DB::table('articles')", $content);
        }
    }
}
