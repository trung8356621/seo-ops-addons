<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicServiceApiWriteService;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\ContentProjectDraftIntakeToolHandler;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\SeoAuditListToolHandler;
use Omnichannel\Addons\Seo\Services\Tools\Handlers\TopicCreateToolHandler;
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
        config(['database.connections.omi_seo_ai' => config('database.connections.sqlite')]);
        $this->validator = new SeoToolInputValidator();
    }

    private function buildRegistry(
        ?SeoAuditAgentReadService $auditService = null,
        ?ServiceApiDraftIntakeService $draftService = null,
        ?TopicServiceApiWriteService $topicService = null
    ): SeoToolRegistry {
        return SeoToolRegistry::buildDefault(
            new SeoAuditListToolHandler($auditService ?? $this->createMock(SeoAuditAgentReadService::class)),
            new ContentProjectDraftIntakeToolHandler($draftService ?? $this->createMock(ServiceApiDraftIntakeService::class)),
            new TopicCreateToolHandler($topicService ?? $this->createMock(TopicServiceApiWriteService::class))
        );
    }

    private function bootTopicSchema(): void
    {
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->default('example.test');
            $table->timestamps();
            $table->softDeletes();
        });
        \App\Models\Site::query()->forceCreate(['id' => 1, 'domain' => 'site1.test']);
        \App\Models\Site::query()->forceCreate(['id' => 2, 'domain' => 'site2.test']);

        Schema::connection('omi_seo_ai')->dropIfExists('seo_topics');
        Schema::connection('omi_seo_ai')->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source')->default('manual');
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_topic_keywords');
        Schema::connection('omi_seo_ai')->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_site_keywords');
        Schema::connection('omi_seo_ai')->create('seo_site_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('keyword_id');
            $table->boolean('is_seo_keyword')->default(true);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('keywords');
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_link_maps');
        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('articles');
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('keyword_meta');
        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_topic_keyword_dna');
        Schema::connection('omi_seo_ai')->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->string('phrase')->nullable();
            $table->timestamps();
        });
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
            requiredContext: ['site_ref'],
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
        $this->assertSame(['site_ref'], $public['required_context']);
        $this->assertSame('none', $public['confirmation_policy']);
        $this->assertArrayNotHasKey('is_exposed', $public);

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

    public function test_definition_rejects_unsupported_required_context(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SeoToolDefinition(
            key: 'test.context', name: 'Test', description: 'Test', module: 'test',
            kind: SeoToolDefinition::KIND_READ, scopes: [], requiredContext: ['site'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE, inputSchema: []
        );
    }

    public function test_recursive_schema_rejects_nested_unknowns_and_constraints(): void
    {
        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['items'],
            'properties' => ['items' => [
                'type' => 'array', 'minItems' => 1, 'maxItems' => 1,
                'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['type'],
                    'properties' => ['type' => ['type' => 'string', 'enum' => ['new']]],
                ],
            ]],
        ];

        $errors = $this->validator->validate($schema, ['items' => [['type' => 'rewrite', 'site_id' => 9]]]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid enum value', implode(' ', $errors));
        $this->assertStringContainsString('Unknown field', implode(' ', $errors));
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
            requiredContext: ['site_ref'],
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

    public function test_disabled_tool_is_neither_discoverable_nor_executable(): void
    {
        $handler = $this->createMock(SeoToolHandlerInterface::class);
        $handler->method('getToolKey')->willReturn('test.disabled');
        $registry = new SeoToolRegistry();
        $registry->register(new SeoToolDefinition(
            key: 'test.disabled', name: 'Disabled', description: 'Disabled', module: 'test',
            kind: SeoToolDefinition::KIND_READ, scopes: ['a'], requiredContext: [],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE, inputSchema: [], enabled: false
        ), $handler);
        $context = new SeoToolContext(actorRef: 'actor', scopes: ['*']);

        $this->assertSame([], $registry->listForContext($context));
        $result = (new SeoToolExecutor($registry, $this->validator))->execute('test.disabled', $context);
        $this->assertSame('tool_not_exposed', $result->getErrorCode());
    }

    public function test_required_tool_scopes_use_all_semantics_and_wildcard(): void
    {
        $context = new SeoToolContext(actorRef: 'actor', scopes: ['scope:a']);
        $this->assertFalse($context->satisfiesAllScopes(['scope:a', 'scope:b']));
        $wildcard = new SeoToolContext(actorRef: 'actor', scopes: ['*']);
        $this->assertTrue($wildcard->satisfiesAllScopes(['scope:a', 'scope:b']));
    }

    public function test_default_registry_builds_canonical_tools(): void
    {
        $registry = $this->buildRegistry();

        $this->assertTrue($registry->has('seo_audit.list'));
        $this->assertTrue($registry->has('draft.intake'));
        $this->assertTrue($registry->has('topic.create'));
        $this->assertFalse($registry->has('content_project.draft_intake'));

        $auditDef = $registry->getDefinition('seo_audit.list');
        $this->assertNotNull($auditDef);
        $this->assertSame('read', $auditDef->kind);
        $this->assertSame('none', $auditDef->confirmationPolicy);

        $draftDef = $registry->getDefinition('draft.intake');
        $this->assertNotNull($draftDef);
        $this->assertSame('write', $draftDef->kind);
        $this->assertSame('draft', $draftDef->module);
        $this->assertSame('required', $draftDef->confirmationPolicy);
        $this->assertSame(['content-projects:draft:write'], $draftDef->scopes);

        $topicDef = $registry->getDefinition('topic.create');
        $this->assertNotNull($topicDef);
        $this->assertSame('write', $topicDef->kind);
        $this->assertSame('topic', $topicDef->module);
        $this->assertSame('required', $topicDef->confirmationPolicy);
        $this->assertSame(['topics:write'], $topicDef->scopes);
    }

    public function test_registry_filters_tools_for_caller_context_scopes(): void
    {
        $registry = $this->buildRegistry();

        // Caller with only seo:read
        $seoContext = new SeoToolContext(
            actorRef: 'test:actor',
            scopes: ['seo:read']
        );
        $visibleSeo = $registry->listForContext($seoContext);
        $keys = array_column($visibleSeo, 'key');
        $this->assertContains('seo_audit.list', $keys);
        $this->assertNotContains('draft.intake', $keys);
        $this->assertNotContains('topic.create', $keys);
        $this->assertFalse($visibleSeo[0]['availability']['available']);
        $this->assertSame('missing_site_context', $visibleSeo[0]['availability']['reason']);

        // Caller with only topics:write
        $topicContext = new SeoToolContext(
            actorRef: 'test:topic-actor',
            scopes: ['topics:write']
        );
        $visibleTopic = $registry->listForContext($topicContext);
        $topicKeys = array_column($visibleTopic, 'key');
        $this->assertContains('topic.create', $topicKeys);
        $this->assertNotContains('draft.intake', $topicKeys);
        $this->assertNotContains('seo_audit.list', $topicKeys);

        // Caller with wildcard *
        $adminContext = new SeoToolContext(
            actorRef: 'test:admin',
            scopes: ['*']
        );
        $visibleAdmin = $registry->listForContext($adminContext);
        $adminKeys = array_column($visibleAdmin, 'key');
        $this->assertContains('seo_audit.list', $adminKeys);
        $this->assertContains('draft.intake', $adminKeys);
        $this->assertContains('topic.create', $adminKeys);
        $this->assertNotContains('content_project.draft_intake', $adminKeys);

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
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        // Call seo_audit.list without seo:read scope
        $context = new SeoToolContext(
            actorRef: 'test:actor',
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute('seo_audit.list', $context, ['site_id' => 1]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('scope_denied', $result->getErrorCode());
        $this->assertSame(403, $result->getHttpStatus());
    }

    public function test_executor_enforces_required_context(): void
    {
        $registry = $this->buildRegistry();
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
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write'],
            idempotencyKey: 'idem-123'
        );

        // draft.intake requires 'items' to be an array
        $result = $executor->execute(
            'draft.intake',
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
        $draftIntakeService->expects($this->never())->method('intake');

        $registry = $this->buildRegistry(draftService: $draftIntakeService);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write'],
            idempotencyKey: 'idem-123'
        );

        $result = $executor->execute(
            'draft.intake',
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

        $registry = $this->buildRegistry(auditService: $auditReadService);
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
            ->with(
                $this->callback(static fn (array $payload): bool => $payload['site_id'] === 1 && !isset($payload['input'])),
                'idem-123'
            )
            ->willReturn($draftIntakeResult);

        $registry = $this->buildRegistry(draftService: $draftIntakeService);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test:actor',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write'],
            requestRef: 'request-must-not-be-idempotency',
            idempotencyKey: 'idem-123'
        );

        $result = $executor->execute(
            'draft.intake',
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

    public function test_schema_enforces_audit_limit_and_draft_item_types(): void
    {
        $registry = $this->buildRegistry();
        $audit = $registry->getDefinition('seo_audit.list');
        $draft = $registry->getDefinition('draft.intake');
        $this->assertNotEmpty($this->validator->validate($audit->inputSchema, ['limit' => 101]));
        $this->assertNotEmpty($this->validator->validate($draft->inputSchema, ['items' => [['keyword_id' => 'bad']]]));
    }

    public function test_unexpected_handler_errors_are_sanitized(): void
    {
        $audit = $this->createMock(SeoAuditAgentReadService::class);
        $audit->method('listArticles')->willThrowException(new \RuntimeException('database secret'));
        $result = (new SeoAuditListToolHandler($audit))->execute(
            new SeoToolContext(actorRef: 'actor', resolvedSiteId: 1, scopes: ['seo:read']), []
        );
        $this->assertSame('execution_failed', $result->getErrorCode());
        $this->assertStringNotContainsString('database secret', (string) $result->getErrorMessage());
    }

    // =========================================================================
    // SECTION J: ARCHITECTURE & WRITE NAMESPACE INVARIANT TESTS
    // =========================================================================

    public function test_architecture_write_namespaces_strictly_enforced(): void
    {
        // 1. READ tool outside draft/topic is valid: seo_audit.list
        $readDef = new SeoToolDefinition(
            key: 'seo_audit.list',
            name: 'SEO Audit List',
            description: 'Audit list',
            module: 'seo_audit',
            kind: SeoToolDefinition::KIND_READ,
            scopes: ['seo:read'],
            requiredContext: ['site_ref'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE,
            inputSchema: []
        );
        $this->assertSame('seo_audit.list', $readDef->key);

        // 2. WRITE tool accepted: draft.intake
        $draftDef = new SeoToolDefinition(
            key: 'draft.intake',
            name: 'Draft Intake',
            description: 'Draft intake',
            module: 'draft',
            kind: SeoToolDefinition::KIND_WRITE,
            scopes: ['content-projects:draft:write'],
            requiredContext: ['site_ref'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_REQUIRED,
            inputSchema: []
        );
        $this->assertSame('draft.intake', $draftDef->key);

        // 3. WRITE tool accepted: topic.create
        $topicDef = new SeoToolDefinition(
            key: 'topic.create',
            name: 'Topic Create',
            description: 'Topic create',
            module: 'topic',
            kind: SeoToolDefinition::KIND_WRITE,
            scopes: ['topics:write'],
            requiredContext: ['site_ref'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_REQUIRED,
            inputSchema: []
        );
        $this->assertSame('topic.create', $topicDef->key);

        // 4. WRITE tool rejected: content_project.add_items
        // 5. WRITE tool rejected: article.update
        // 6. WRITE tool rejected: wordpress.publish
        // 7. WRITE tool rejected: site.sync
        // 8. WRITE tool rejected: serp.collect
        $forbiddenWriteKeys = [
            'content_project.add_items',
            'article.update',
            'wordpress.publish',
            'site.sync',
            'serp.collect',
        ];

        foreach ($forbiddenWriteKeys as $forbiddenKey) {
            try {
                new SeoToolDefinition(
                    key: $forbiddenKey,
                    name: 'Forbidden Write',
                    description: 'Should throw',
                    module: explode('.', $forbiddenKey)[0],
                    kind: SeoToolDefinition::KIND_WRITE,
                    scopes: ['*'],
                    requiredContext: ['site_ref'],
                    confirmationPolicy: SeoToolDefinition::CONFIRMATION_REQUIRED,
                    inputSchema: []
                );
                $this->fail("Expected InvalidArgumentException for forbidden write key '{$forbiddenKey}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('forbidden namespace', $e->getMessage());
            }
        }
    }

    public function test_architecture_all_write_tools_require_confirmation_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Write tool 'draft.intake' must have confirmation policy 'required'.");

        new SeoToolDefinition(
            key: 'draft.intake',
            name: 'Draft Without Confirmation',
            description: 'Should throw',
            module: 'draft',
            kind: SeoToolDefinition::KIND_WRITE,
            scopes: ['content-projects:draft:write'],
            requiredContext: ['site_ref'],
            confirmationPolicy: SeoToolDefinition::CONFIRMATION_NONE,
            inputSchema: []
        );
    }

    public function test_architecture_old_public_key_is_not_in_registry_or_discovery(): void
    {
        $registry = $this->buildRegistry();

        $this->assertFalse($registry->has('content_project.draft_intake'));
        $this->assertNull($registry->getDefinition('content_project.draft_intake'));

        $context = new SeoToolContext(actorRef: 'admin', scopes: ['*']);
        $discoveredKeys = array_column($registry->listForContext($context), 'key');

        $this->assertNotContains('content_project.draft_intake', $discoveredKeys);
        $this->assertContains('draft.intake', $discoveredKeys);
        $this->assertContains('topic.create', $discoveredKeys);
        $this->assertContains('seo_audit.list', $discoveredKeys);
    }

    public function test_architecture_scopes_are_strictly_bounded(): void
    {
        $registry = $this->buildRegistry();

        // 11. Draft tool still uses: content-projects:draft:write
        $draftDef = $registry->getDefinition('draft.intake');
        $this->assertNotNull($draftDef);
        $this->assertSame(['content-projects:draft:write'], $draftDef->scopes);

        // 12. Topic tool uses single canonical Topic write scope: topics:write
        $topicDef = $registry->getDefinition('topic.create');
        $this->assertNotNull($topicDef);
        $this->assertSame(['topics:write'], $topicDef->scopes);

        // 13. Read-tool behavior and seo:read remain unchanged
        $auditDef = $registry->getDefinition('seo_audit.list');
        $this->assertNotNull($auditDef);
        $this->assertSame(['seo:read'], $auditDef->scopes);
        $this->assertSame(SeoToolDefinition::KIND_READ, $auditDef->kind);
    }

    // =========================================================================
    // SECTION K: DRAFT API TESTS
    // =========================================================================

    public function test_draft_intake_requires_site_context(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: null,
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute('draft.intake', $context, [
            'items' => [['keyword' => 'test', 'type' => 'new']],
        ], true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_context', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_draft_intake_rejects_missing_confirmation(): void
    {
        $mockDraft = $this->createMock(ServiceApiDraftIntakeService::class);
        $mockDraft->expects($this->never())->method('intake');

        $registry = $this->buildRegistry(draftService: $mockDraft);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        $result = $executor->execute('draft.intake', $context, [
            'items' => [['keyword' => 'test', 'type' => 'new']],
        ], confirmed: false);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('confirmation_required', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_draft_intake_rejects_unknown_fields_and_item_additional_properties(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        // Unknown top-level field in input
        $resTop = $executor->execute('draft.intake', $context, [
            'items' => [['keyword' => 'test', 'type' => 'new']],
            'unknown_field' => 'bad',
        ], true);
        $this->assertSame('validation_failed', $resTop->getErrorCode());

        // Unknown field inside item
        $resItem = $executor->execute('draft.intake', $context, [
            'items' => [['keyword' => 'test', 'type' => 'new', 'injected_field' => 'bad']],
        ], true);
        $this->assertSame('validation_failed', $resItem->getErrorCode());
    }

    public function test_draft_intake_site_identity_cannot_be_overridden_through_input(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write']
        );

        // site_id passed in input must be rejected by schema additionalProperties=false
        $result = $executor->execute('draft.intake', $context, [
            'site_id' => 999,
            'items' => [['keyword' => 'test', 'type' => 'new']],
        ], true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('validation_failed', $result->getErrorCode());
    }

    public function test_draft_intake_delegates_to_canonical_service_and_preserves_idempotency(): void
    {
        $mockDraft = $this->createMock(ServiceApiDraftIntakeService::class);
        $mockDraft->expects($this->once())
            ->method('intake')
            ->with(
                $this->callback(function (array $payload): bool {
                    return ($payload['site_id'] ?? null) === 1
                        && isset($payload['items'])
                        && count($payload['items']) === 1;
                }),
                'idem-key-999'
            )
            ->willReturn(new ServiceApiDraftIntakeResult(
                draftRef: 'project:77',
                siteRef: 'site:1',
                submitted: 1,
                added: 1,
                alreadyInDraft: 0,
                failed: 0,
                items: []
            ));

        $registry = $this->buildRegistry(draftService: $mockDraft);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['content-projects:draft:write'],
            idempotencyKey: 'idem-key-999'
        );

        $result = $executor->execute('draft.intake', $context, [
            'items' => [['keyword' => 'good keyword', 'type' => 'new']],
        ], true);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('project:77', $result->getData()['draft_ref']);
    }

    // =========================================================================
    // SECTION L: TOPIC API TESTS
    // =========================================================================

    public function test_topic_create_requires_site_context(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: null,
            scopes: ['topics:write']
        );

        $result = $executor->execute('topic.create', $context, ['name' => 'Balo laptop'], true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('missing_context', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_topic_create_requires_topic_write_scope(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        // Caller has seo:read instead of topics:write
        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['seo:read']
        );

        $result = $executor->execute('topic.create', $context, ['name' => 'Balo laptop'], true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('scope_denied', $result->getErrorCode());
        $this->assertSame(403, $result->getHttpStatus());
    }

    public function test_topic_create_requires_confirmation(): void
    {
        $mockTopicService = $this->createMock(TopicServiceApiWriteService::class);
        $mockTopicService->expects($this->never())->method('create');

        $registry = $this->buildRegistry(topicService: $mockTopicService);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['topics:write']
        );

        $result = $executor->execute('topic.create', $context, ['name' => 'Balo laptop'], confirmed: false);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('confirmation_required', $result->getErrorCode());
        $this->assertSame(422, $result->getHttpStatus());
    }

    public function test_topic_create_rejects_empty_name(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['topics:write']
        );

        $resEmpty = $executor->execute('topic.create', $context, ['name' => ''], true);
        $this->assertFalse($resEmpty->isSuccess());
        $this->assertSame('validation_failed', $resEmpty->getErrorCode());

        $resSpaces = $executor->execute('topic.create', $context, ['name' => '   '], true);
        $this->assertFalse($resSpaces->isSuccess());
        $this->assertSame('validation_failed', $resSpaces->getErrorCode());
    }

    public function test_topic_create_rejects_unknown_fields_and_site_id_in_input(): void
    {
        $registry = $this->buildRegistry();
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['topics:write']
        );

        // Unknown field
        $resUnknown = $executor->execute('topic.create', $context, [
            'name' => 'Balo laptop',
            'description' => 'extra info',
        ], true);
        $this->assertSame('validation_failed', $resUnknown->getErrorCode());

        // site_id in input
        $resSiteId = $executor->execute('topic.create', $context, [
            'name' => 'Balo laptop',
            'site_id' => 999,
        ], true);
        $this->assertSame('validation_failed', $resSiteId->getErrorCode());

        // topic_id in input
        $resTopicId = $executor->execute('topic.create', $context, [
            'name' => 'Balo laptop',
            'topic_id' => 123,
        ], true);
        $this->assertSame('validation_failed', $resTopicId->getErrorCode());
    }

    public function test_topic_create_creates_or_reuses_via_canonical_topic_service(): void
    {
        $this->bootTopicSchema();

        $manualCreateService = app(TopicManualCreateService::class);
        $topicWriteService = new TopicServiceApiWriteService($manualCreateService);

        $registry = $this->buildRegistry(topicService: $topicWriteService);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['topics:write']
        );

        // 1. Create fresh topic
        $res1 = $executor->execute('topic.create', $context, ['name' => 'Balo laptop'], true);
        $this->assertTrue($res1->isSuccess());
        $data1 = $res1->getData();
        $this->assertArrayHasKey('topic_ref', $data1);
        $this->assertArrayHasKey('topic_id', $data1);
        $this->assertSame('Balo laptop', $data1['topic_name']);
        $this->assertFalse($data1['reused']);
        $this->assertSame('topic:' . $data1['topic_id'], $data1['topic_ref']);
        $this->assertIsArray($data1['reconcile']);

        // 2. Reuse same topic on same site
        $res2 = $executor->execute('topic.create', $context, ['name' => 'balo laptop'], true);
        $this->assertTrue($res2->isSuccess());
        $data2 = $res2->getData();
        $this->assertSame($data1['topic_id'], $data2['topic_id']);
        $this->assertTrue($data2['reused']);

        // 3. Different site gets its own topic
        $contextSite2 = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 2,
            scopes: ['topics:write']
        );
        $res3 = $executor->execute('topic.create', $contextSite2, ['name' => 'Balo laptop'], true);
        $this->assertTrue($res3->isSuccess());
        $data3 = $res3->getData();
        $this->assertNotSame($data1['topic_id'], $data3['topic_id']);
        $this->assertFalse($data3['reused']);
    }

    public function test_topic_create_does_not_create_synthetic_keyword(): void
    {
        $this->bootTopicSchema();

        $initialKeywordCount = DB::connection('omi_seo_ai')->table('keywords')->count();
        $this->assertSame(0, $initialKeywordCount);

        $manualCreateService = app(TopicManualCreateService::class);
        $topicWriteService = new TopicServiceApiWriteService($manualCreateService);

        $registry = $this->buildRegistry(topicService: $topicWriteService);
        $executor = new SeoToolExecutor($registry, $this->validator);

        $context = new SeoToolContext(
            actorRef: 'test',
            resolvedSiteId: 1,
            scopes: ['topics:write']
        );

        $res = $executor->execute('topic.create', $context, ['name' => 'Thiết kế website'], true);
        $this->assertTrue($res->isSuccess());

        // Ensure zero keyword rows were created in keywords table
        $afterKeywordCount = DB::connection('omi_seo_ai')->table('keywords')->count();
        $this->assertSame(0, $afterKeywordCount);

        // Ensure the topic row was created in seo_topics
        $topicCount = DB::connection('omi_seo_ai')->table('seo_topics')->count();
        $this->assertSame(1, $topicCount);
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
            \Omnichannel\Addons\Seo\Services\Tools\Handlers\TopicCreateToolHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicServiceApiWriteService::class,
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
