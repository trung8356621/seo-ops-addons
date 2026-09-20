<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\Models\ApiConnection;
use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Ai\Transport\RemoteHttpAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityHandler;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBindingRunner;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationShape;
use Omnichannel\Addons\AiPrompt\System\ArticleContentGenerateCapabilityHandler;
use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 2 — System boundary mandatory for article.content.generate (all transports).
 */
final class ArticleContentSystemBoundaryPhase2Test extends TestCase
{
    public function test_t1_legacy_mode_enters_system_ai_client_local_transport(): void
    {
        Config::set('system.capabilities', ['article.content.generate' => 'legacy']);
        Config::set('system.modules.ai', 'legacy');

        $handlerCalls = 0;
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: ArticleContentGenerateCapabilityHandler::KEY,
            owner: 'ai-prompt',
            handler: new class($handlerCalls) implements SystemCapabilityHandler
            {
                public function __construct(private int &$calls) {}

                public function handle(array $input, array $context = []): array
                {
                    $this->calls++;

                    return [
                        'output' => 'legacy-body',
                        'raw' => 'legacy-body',
                        'value' => 'legacy-body',
                        'hook_key' => ArticleContentGenerateCapabilityHandler::KEY,
                        'execution_source' => 'test_legacy_handler',
                        'prompt_result_id' => 101,
                    ];
                }
            },
            sideEffectFree: true,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: new RemoteHttpAiTransport('http://system-ai.test', 'token'),
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: ArticleContentGenerateCapabilityHandler::KEY,
            input: ['prompt_id' => 1],
        ));

        self::assertSame('completed', $result->status);
        self::assertSame(1, $handlerCalls);
        self::assertSame('legacy_local', $result->meta['mode'] ?? null);
        self::assertSame('legacy-body', $result->output['value'] ?? $result->output['output'] ?? null);
    }

    public function test_t2_remote_failure_is_fail_closed_without_local_fallback(): void
    {
        Config::set('system.capabilities', ['article.content.generate' => 'remote']);

        $handlerCalls = 0;
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: ArticleContentGenerateCapabilityHandler::KEY,
            owner: 'ai-prompt',
            handler: new class($handlerCalls) implements SystemCapabilityHandler
            {
                public function __construct(private int &$calls) {}

                public function handle(array $input, array $context = []): array
                {
                    $this->calls++;

                    return ['output' => 'should-not-run'];
                }
            },
            sideEffectFree: true,
        ));

        Http::fake([
            'http://system-ai.test/*' => Http::response(['error' => ['code' => 'boom', 'message' => 'upstream down']], 500),
        ]);

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: new RemoteHttpAiTransport('http://system-ai.test', 'token', 5),
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: ArticleContentGenerateCapabilityHandler::KEY,
            input: ['prompt_id' => 1],
        ));

        self::assertSame('failed', $result->status);
        self::assertSame(0, $handlerCalls);
        self::assertSame('remote_http', $result->meta['transport'] ?? null);
    }

    public function test_t3_via_http_api_forces_local_once_no_remote_recursion(): void
    {
        Config::set('system.capabilities', ['article.content.generate' => 'remote']);

        $handlerCalls = 0;
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: ArticleContentGenerateCapabilityHandler::KEY,
            owner: 'ai-prompt',
            handler: new class($handlerCalls) implements SystemCapabilityHandler
            {
                public function __construct(private int &$calls) {}

                public function handle(array $input, array $context = []): array
                {
                    $this->calls++;

                    return [
                        'output' => 'reentry',
                        'via_http_api' => (bool) ($context['via_http_api'] ?? false),
                    ];
                }
            },
            sideEffectFree: true,
        ));

        Http::fake(); // must not be called

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
            remote: new RemoteHttpAiTransport('http://system-ai.test', 'token'),
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: ArticleContentGenerateCapabilityHandler::KEY,
            input: ['prompt_id' => 1],
            context: ['via_http_api' => true],
        ));

        self::assertSame('completed', $result->status);
        self::assertSame(1, $handlerCalls);
        self::assertTrue((bool) ($result->meta['via_http_api'] ?? false));
        Http::assertNothingSent();
    }

    public function test_t4_editor_direct_generate_uses_binding_runner_not_hook_execution(): void
    {
        $captured = [];
        $binding = new class($captured) implements PromptHookBindingRunner
        {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function execute(
                SeoPrompt $prompt,
                array $variables = [],
                array $contextExtras = [],
                array $previousOutputs = [],
            ): array {
                $this->captured = [
                    'article_id' => (int) ($contextExtras['article_id'] ?? 0),
                    'stage' => (string) ($contextExtras['stage'] ?? ''),
                    'source' => (string) ($contextExtras['source'] ?? ''),
                    'source_type' => (string) ($variables['source_type'] ?? ''),
                    'focus_keyword' => (string) ($variables['focus_keyword'] ?? ''),
                    'calls' => (($this->captured['calls'] ?? 0) + 1),
                ];

                return [
                    'output' => "# Rewritten\n\n".str_repeat('Body word. ', 80),
                    'raw' => "# Rewritten\n\n".str_repeat('Body word. ', 80),
                    'value' => "# Rewritten\n\n".str_repeat('Body word. ', 80),
                    'prompt_result_id' => 555,
                    'execution_source' => 'system_ai',
                    'system_ai_execution_id' => 'ai_test',
                ];
            }
        };

        $service = $this->writingServiceWithBinding($binding, $this->publisherNever());
        $article = $this->articleStub(77, 'Old body');
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 5, 'hook_key' => 'article.content.generate', 'hook_version' => '0.1.0']);

        $result = $this->invokeDirectGenerate(
            $service,
            ArticleWritingInput::fromExistingArticleBody(
                bodyMarkdown: 'Old body',
                title: 'Title',
                keyword: 'KW',
                articleId: 77,
            ),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: 1,
                taskContext: $this->taskContext($article),
                persistArticle: false,
            ),
            [
                'type' => ArticleWritingPromptOwnerType::SettingsBinding,
                'prompt_id' => 5,
                'owner_id' => 'settings',
                'prompt' => $prompt,
            ],
            [
                'input' => 'Old body',
                'article_length' => 800,
            ],
        );

        self::assertSame(1, (int) ($captured['calls'] ?? 0));
        self::assertSame(77, (int) ($captured['article_id'] ?? 0));
        self::assertSame('writing', (string) ($captured['stage'] ?? ''));
        self::assertSame('editor_direct_generate', (string) ($captured['source'] ?? ''));
        self::assertSame('existing_article', (string) ($captured['source_type'] ?? ''));
        self::assertSame('KW', (string) ($captured['focus_keyword'] ?? ''));
        self::assertTrue($result->success);
        self::assertSame(ArticleWritingExecutionResult::PERSIST_SKIPPED, $result->persistStatus);
        self::assertSame(555, $result->historyMetadata['prompt_result_id'] ?? null);
        self::assertSame('system_ai', $result->historyMetadata['execution_source'] ?? null);
    }

    public function test_t5_direct_generate_persist_applied(): void
    {
        $markdown = "# Rewritten\n\n".str_repeat('Persist body word. ', 80);
        $binding = $this->bindingReturning($markdown, promptResultId: 9);
        $publishCalls = 0;
        $publisher = $this->publisherCounting($publishCalls, [
            'success' => true,
            'conflict' => false,
            'message' => 'ok',
            'expected_content_hash' => 'abc',
            'persisted_content_hash' => 'abc',
        ]);

        $service = $this->writingServiceWithBinding($binding, $publisher);
        $article = $this->articleStub(88, 'Old');
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 5, 'hook_key' => 'article.content.generate']);

        $result = $this->invokeDirectGenerate(
            $service,
            ArticleWritingInput::fromExistingArticleBody('Old', 'T', 'K', articleId: 88),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: 1,
                taskContext: $this->taskContext($article),
                persistArticle: true,
            ),
            [
                'type' => ArticleWritingPromptOwnerType::SettingsBinding,
                'prompt_id' => 5,
                'owner_id' => 'settings',
                'prompt' => $prompt,
            ],
            ['input' => 'Old', 'article_length' => 800],
        );

        self::assertSame(1, $publishCalls);
        self::assertSame(ArticleWritingExecutionResult::PERSIST_APPLIED, $result->persistStatus);
        self::assertTrue($result->success);
    }

    public function test_t6_persist_article_false_skips_publisher(): void
    {
        $publishCalls = 0;
        $publisher = $this->publisherCounting($publishCalls, ['success' => true, 'conflict' => false, 'message' => 'ok']);
        $service = $this->writingServiceWithBinding(
            $this->bindingReturning("# X\n\n".str_repeat('word ', 60), 3),
            $publisher,
        );
        $article = $this->articleStub(1, 'Old');
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 5, 'hook_key' => 'article.content.generate']);

        $result = $this->invokeDirectGenerate(
            $service,
            ArticleWritingInput::fromExistingArticleBody('Old', 'T', 'K', articleId: 1),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: 1,
                taskContext: $this->taskContext($article),
                persistArticle: false,
            ),
            [
                'type' => ArticleWritingPromptOwnerType::SettingsBinding,
                'prompt_id' => 5,
                'owner_id' => 'settings',
                'prompt' => $prompt,
            ],
            ['input' => 'Old'],
        );

        self::assertSame(0, $publishCalls);
        self::assertSame(ArticleWritingExecutionResult::PERSIST_SKIPPED, $result->persistStatus);
    }

    public function test_t7_stale_human_edit_ignored(): void
    {
        $publishCalls = 0;
        $publisher = $this->publisherCounting($publishCalls, ['success' => true, 'conflict' => false, 'message' => 'ok']);
        $service = $this->writingServiceWithBinding(
            $this->bindingReturning("# X\n\n".str_repeat('word ', 60), 4),
            $publisher,
        );
        $expected = now()->subMinutes(5)->toIso8601String();
        $article = $this->articleStub(2, 'Old', updatedAtIso: now()->toIso8601String());
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 5, 'hook_key' => 'article.content.generate']);

        $result = $this->invokeDirectGenerate(
            $service,
            ArticleWritingInput::fromExistingArticleBody('Old', 'T', 'K', articleId: 2),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: 1,
                taskContext: $this->taskContext($article),
                persistArticle: true,
                expectedUpdatedAt: $expected,
            ),
            [
                'type' => ArticleWritingPromptOwnerType::SettingsBinding,
                'prompt_id' => 5,
                'owner_id' => 'settings',
                'prompt' => $prompt,
            ],
            ['input' => 'Old'],
        );

        self::assertSame(0, $publishCalls);
        self::assertSame(ArticleWritingExecutionResult::PERSIST_IGNORED_STALE, $result->persistStatus);
    }

    public function test_t8_prompt_result_id_surfaced_once_in_history(): void
    {
        $service = $this->writingServiceWithBinding(
            $this->bindingReturning("# X\n\n".str_repeat('word ', 60), 777),
            $this->publisherNever(),
        );
        $article = $this->articleStub(3, 'Old');
        $prompt = new SeoPrompt;
        $prompt->forceFill(['id' => 5, 'hook_key' => 'article.content.generate']);

        $result = $this->invokeDirectGenerate(
            $service,
            ArticleWritingInput::fromExistingArticleBody('Old', 'T', 'K', articleId: 3),
            new ArticleWritingExecutionContext(
                mode: ArticleWritingExecutionMode::DirectGenerate,
                promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                siteId: 1,
                taskContext: $this->taskContext($article),
                persistArticle: false,
            ),
            [
                'type' => ArticleWritingPromptOwnerType::SettingsBinding,
                'prompt_id' => 5,
                'owner_id' => 'settings',
                'prompt' => $prompt,
            ],
            ['input' => 'Old'],
        );

        self::assertSame(777, $result->historyMetadata['prompt_result_id'] ?? null);
    }

    public function test_t9_t10_route_cost_shape_unchanged(): void
    {
        $paid = $this->candidate('paid-model', false, 1);
        $free = $this->candidate('free-model', true, 1);

        $routerPaid = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $routerPaid->method('resolveFirstAttemptable')->willReturn($paid);
        [, $snapPaid] = (new ArticleGenerationExecutionPlanner(
            $routerPaid,
            new GenerationShapeResolver($routerPaid),
        ))->plan('text.longform', new \Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext(userId: 1), []);
        self::assertSame(ArticleGenerationShape::SinglePass, $snapPaid->generationShape);

        $routerFree = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $routerFree->method('resolveFirstAttemptable')->willReturn($free);
        [, $snapFree] = (new ArticleGenerationExecutionPlanner(
            $routerFree,
            new GenerationShapeResolver($routerFree),
        ))->plan('text.longform', new \Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext(userId: 1), []);
        self::assertSame(ArticleGenerationShape::Sectioned, $snapFree->generationShape);
    }

    public function test_t11_execution_service_delegates_writing_hooks_to_binding_runner(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptHookExecutionService::class))->getFileName() ?: ''
        );
        self::assertStringContainsString('executeWritingViaSystemBoundary', $src);
        self::assertStringContainsString('ArticleContentGenerationHooks::matches', $src);
        self::assertStringContainsString('PromptHookBindingRunner', $src);

        $awes = (string) file_get_contents(
            (new ReflectionClass(ArticleWritingExecutionService::class))->getFileName() ?: ''
        );
        self::assertStringContainsString('PromptHookBindingRunner', $awes);
        self::assertStringContainsString('editor_direct_generate', $awes);
        self::assertStringNotContainsString('PromptHookExecutionService', $awes);
    }

    public function test_explicit_binding_gate_no_longer_checks_remote_shadow_mode(): void
    {
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'shouldExecuteViaSystemAi');
        $method->setAccessible(true);
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();

        // Re-entry guard
        self::assertFalse($method->invoke($executor, 'article.content.generate', ['via_system_ai' => true]));
        // Non-writing
        self::assertFalse($method->invoke($executor, 'article.outline.structure.generate', []));
    }

    /**
     * @param  array{type: ArticleWritingPromptOwnerType, prompt_id: ?int, owner_id: ?string, prompt: ?SeoPrompt}  $owner
     * @param  array<string, mixed>  $variables
     */
    private function invokeDirectGenerate(
        ArticleWritingExecutionService $service,
        ArticleWritingInput $writing,
        ArticleWritingExecutionContext $context,
        array $owner,
        array $variables,
    ): ArticleWritingExecutionResult {
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'executeDirectGenerate');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            $writing,
            $context,
            $context->taskContext,
            $owner,
            $variables,
        );
    }

    private function writingServiceWithBinding(
        PromptHookBindingRunner $binding,
        ArticleBodyPublishPort $publisher,
    ): ArticleWritingExecutionService {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);
        /** @var ArticleWritingExecutionService $service */
        $service = $ref->newInstanceWithoutConstructor();
        foreach ([
            'hookBinding' => $binding,
            'publisher' => $publisher,
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($service, $value);
        }

        return $service;
    }

    private function bindingReturning(string $markdown, int $promptResultId): PromptHookBindingRunner
    {
        return new class($markdown, $promptResultId) implements PromptHookBindingRunner
        {
            public function __construct(private string $markdown, private int $promptResultId) {}

            public function execute(
                SeoPrompt $prompt,
                array $variables = [],
                array $contextExtras = [],
                array $previousOutputs = [],
            ): array {
                return [
                    'output' => $this->markdown,
                    'raw' => $this->markdown,
                    'value' => $this->markdown,
                    'prompt_result_id' => $this->promptResultId,
                    'execution_source' => 'system_ai',
                ];
            }
        };
    }

    private function publisherNever(): ArticleBodyPublishPort
    {
        $unused = 0;

        return $this->publisherCounting($unused, [], true);
    }

    /**
     * @param  array<string, mixed>  $publishResult
     */
    private function publisherCounting(int &$calls, array $publishResult = [], bool $throwOnPublish = false): ArticleBodyPublishPort
    {
        return new class($calls, $publishResult, $throwOnPublish) implements ArticleBodyPublishPort
        {
            /**
             * @param  array<string, mixed>  $publishResult
             */
            public function __construct(
                private int &$calls,
                private array $publishResult,
                private bool $throwOnPublish,
            ) {}

            public function prepareArticleContent(SeoArticle $article, string $aiOutput): array
            {
                return [
                    'markdown' => $aiOutput,
                    'html' => $aiOutput,
                    'content_hash' => hash('sha256', $aiOutput),
                    'faqs' => [],
                    'meta_description' => null,
                    'h1_title' => 'T',
                ];
            }

            public function contentHash(string $body): string
            {
                return hash('sha256', trim($body));
            }

            public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array
            {
                if ($this->throwOnPublish) {
                    throw new \RuntimeException('publisher must not be called');
                }
                $this->calls++;

                return $this->publishResult !== []
                    ? $this->publishResult
                    : ['success' => true, 'conflict' => false, 'message' => 'ok'];
            }
        };
    }

    private function taskContext(SeoArticle $article): TaskTestContext
    {
        return new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: null,
            variables: [],
            summary: 'phase2-test',
            siteId: 1,
        );
    }

    private function articleStub(int $id, string $body, ?string $updatedAtIso = null): SeoArticle
    {
        $updated = $updatedAtIso !== null
            ? \Illuminate\Support\Carbon::parse($updatedAtIso)
            : now();

        $article = new class extends SeoArticle
        {
            public function refresh(): static
            {
                return $this;
            }
        };
        $article->forceFill([
            'id' => $id,
            'site_id' => 1,
            'title' => 'T',
            'body' => $body,
            'updated_at' => $updated,
        ]);
        $article->syncOriginal();

        return $article;
    }

    private function candidate(string $model, bool $isFree, int $priority): RoutedAiCandidate
    {
        $connection = new ApiConnection([
            'name' => $isFree ? 'Free' : 'Paid',
            'provider' => 'openrouter',
        ]);
        $connection->id = $priority;

        return new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: $model,
            capabilities: ['text.generate'],
            priority: $priority,
            isFree: $isFree,
        );
    }
}
