<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationWriteException;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;
use Omnichannel\Addons\AiPrompt\Contracts\FirstAttemptableAiRouteResolver;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderAdapter;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilities;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderResponse;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\RenderedPromptRequest;
use Omnichannel\Addons\AiPrompt\Services\ArticleGenerationExecutionPlanner;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptResultOwnershipResolver;
use Omnichannel\Addons\AiPrompt\Services\GenerationShapeResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionPersistence;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use App\Models\ApiConnection;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\Content\Services\ArticleWritingInputFormatter;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\MarkdownOutlineParser;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use Omnichannel\Addons\SearchFoundation\Support\MarkdownSemanticKeywordsParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Full-path writing persist: production PromptHook → PromptResult → PromptResultLink
 * → guarded body publish → run-item markSuccess. No manual makePromptResult/linkPromptResult.
 */
final class ArticleWritingFullPathPersistIntegrationTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->nullable();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->nullable();
        });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Config::set('seo-content-ai.prompt_hooks.experimental_allowlist', [
            'article.content.generate',
            'article.content.rewrite',
        ]);
        $this->stubPaidRoutePlanner();
    }

    private function stubPaidRoutePlanner(): void
    {
        $connection = new ApiConnection([
            'id' => 1,
            'name' => 'Test',
            'provider' => 'openrouter',
        ]);
        $connection->id = 1;
        $candidate = new RoutedAiCandidate(
            profile: 'text.longform',
            connection: $connection,
            provider: 'openrouter',
            model: 'test-paid',
            capabilities: ['text.generate'],
            priority: 1,
            isFree: false,
        );
        $router = $this->createMock(FirstAttemptableAiRouteResolver::class);
        $router->method('resolveFirstAttemptable')->willReturn($candidate);
        app()->instance(FirstAttemptableAiRouteResolver::class, $router);
        app()->instance(
            ArticleGenerationExecutionPlanner::class,
            new ArticleGenerationExecutionPlanner($router, new GenerationShapeResolver($router)),
        );
    }

    protected function tearDown(): void
    {
        foreach ([
            'seo_prompt_result_links',
            'seo_project_run_items',
            'prompt_results',
            'article_meta',
            'wordpress_article_links',
            'articles',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_full_path_success_creates_prompt_result_link_body_and_clears_run_errors(): void
    {
        $article = $this->makeArticle(8553, '<p>OLD body</p>');
        $markdown = $this->deterministicWriting();
        $provider = new PersistingFakePromptProviderAdapter(
            text: $markdown,
            ownership: [
                'article_id' => 8553,
                'content_project_id' => 900,
                'project_item_id' => 8799,
                'run_id' => 289,
            ],
        );
        $executor = $this->executor($provider);

        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 501,
            'hook_key' => 'article.content.generate',
            'hook_version' => '0.1.0',
            'markdown_content' => 'Prompt {{input}}',
        ]);

        // Production PromptHook path — PromptResult + SeoPromptResultLink created here.
        $hook = $executor->execute(
            $prompt,
            [
                'input' => "Outline\nVocabulary planning for full-path writing.",
                'keyword' => 'test keyword',
                'language' => 'vi',
                'article_length' => 300,
            ],
            [
                'site_id' => 1,
                'article_id' => 8553,
                'locale' => 'vi',
            ],
        );

        self::assertCount(1, $provider->calls);
        $promptResultId = (int) ($hook['prompt_result_id'] ?? 0);
        self::assertGreaterThan(0, $promptResultId);

        $pr = PromptResult::query()->find($promptResultId);
        self::assertNotNull($pr);
        self::assertSame('article.content.generate', (string) $pr->canonical_prompt_key);
        self::assertSame('writing', (string) $pr->stage);
        self::assertSame(8799, (int) $pr->project_item_id);
        self::assertSame(900, (int) $pr->content_project_id);

        $link = SeoPromptResultLink::query()
            ->where('prompt_result_id', $promptResultId)
            ->where('article_id', 8553)
            ->first();
        self::assertNotNull($link, 'SeoPromptResultLink must be created by ExplicitBindingExecutor');
        self::assertSame('prompt_hook_explicit_binding', (string) $link->source);

        self::assertTrue(
            app(ArticlePromptResultOwnershipResolver::class)->isOwned(8553, $promptResultId, []),
            'Article AI History ownership must discover the PromptResult',
        );

        $publisher = $this->realPublisher(successBody: true);
        $service = $this->writingServiceWithPublisher($publisher);
        $persist = (new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted'));
        $persist->setAccessible(true);

        $gate = $persist->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => (string) $hook['output'],
                'result_id' => $promptResultId,
            ],
        ], $article->fresh(), [
            'focus_keyword' => 'test keyword',
        ]);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_APPLIED, $gate['status']);
        $fresh = SeoArticle::query()->find(8553);
        self::assertNotNull($fresh);
        self::assertSame(
            (string) ($gate['expected_content_hash'] ?? ''),
            (string) ($gate['persisted_content_hash'] ?? ''),
        );
        self::assertNotSame('<p>OLD body</p>', (string) $fresh->body);

        $runItem = $this->makeRunItem(804, [
            'status' => 'failed',
            'error_code' => 'stale_deepseek',
            'error_message' => 'old error must clear',
        ]);
        // Production markSuccess — do not manually null error fields before assert.
        app(SeoProjectRunItemService::class)->markSuccess(
            $runItem,
            8553,
            'Writing applied',
            ['persist_status' => 'applied'],
            lock: false,
        );
        $runItem = $runItem->fresh();
        self::assertSame('success', (string) $runItem->status);
        self::assertNull($runItem->error_code);
        self::assertNull($runItem->error_message);
    }

    public function test_full_path_conflict_keeps_history_zero_ancillary_and_is_terminal(): void
    {
        $manual = '<p>MANUAL_NEWER_CONTENT</p>';
        $article = $this->makeArticle(8553, $manual);
        $article->articleMetas()->create([
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => 'MANUAL_KW',
        ]);
        $article->articleMetas()->create([
            'meta_key' => 'seo_meta_description',
            'meta_value' => 'MANUAL_META',
        ]);

        $markdown = $this->deterministicWriting();
        $provider = new PersistingFakePromptProviderAdapter(
            text: $markdown,
            ownership: [
                'article_id' => 8553,
                'content_project_id' => 900,
                'project_item_id' => 8799,
                'run_id' => 289,
            ],
        );
        $executor = $this->executor($provider);
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 502,
            'hook_key' => 'article.content.generate',
            'hook_version' => '0.1.0',
            'markdown_content' => 'Prompt {{input}}',
        ]);

        $hook = $executor->execute(
            $prompt,
            [
                'input' => "Outline\nVocabulary planning for conflict path.",
                'keyword' => 'AI_KW',
                'language' => 'vi',
                'article_length' => 300,
            ],
            [
                'site_id' => 1,
                'article_id' => 8553,
                'locale' => 'vi',
            ],
        );

        $promptResultId = (int) ($hook['prompt_result_id'] ?? 0);
        self::assertGreaterThan(0, $promptResultId);
        self::assertNotNull(
            SeoPromptResultLink::query()
                ->where('prompt_result_id', $promptResultId)
                ->where('article_id', 8553)
                ->first(),
        );

        $publisher = $this->realPublisher(successBody: false);
        $service = $this->writingServiceWithPublisher($publisher);
        $persist = (new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted'));
        $persist->setAccessible(true);

        $gate = $persist->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => (string) $hook['output'],
                'result_id' => $promptResultId,
            ],
        ], $article->fresh(), [
            'focus_keyword' => 'AI_KW_SHOULD_NOT_APPLY',
        ]);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_IGNORED_STALE, $gate['status']);
        self::assertSame('skipped', $gate['ancillary_status'] ?? null);
        self::assertCount(1, $provider->calls, 'No second provider generation on conflict');

        $fresh = SeoArticle::query()->find(8553);
        self::assertSame($manual, (string) ($fresh?->body ?? ''));
        self::assertSame(
            'MANUAL_KW',
            (string) ($fresh?->articleMetas()->where('meta_key', 'seo_focus_keyword')->value('meta_value') ?? ''),
        );
        self::assertSame(
            'MANUAL_META',
            (string) ($fresh?->articleMetas()->where('meta_key', 'seo_meta_description')->value('meta_value') ?? ''),
        );

        self::assertTrue(
            app(ArticlePromptResultOwnershipResolver::class)->isOwned(8553, $promptResultId, []),
        );
        self::assertTrue(ContentProjectExecutionStatus::isTerminal('ignored_stale'));
        self::assertContains('ignored_stale', ContentProjectExecutionStatus::terminalStatuses());
    }

    private function deterministicWriting(): string
    {
        return "# Test Article\n\n".str_repeat('This is deterministic generated body. ', 80);
    }

    private function executor(PromptProviderAdapter $provider): PromptHookExplicitBindingExecutor
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();
        $registry = new PromptHookRuntimeRegistry($loader);
        $engine = new PromptHookRuntimeEngine(
            $registry,
            new PromptHookEnvelopeValidator,
            new PromptHookRuntimeLocaleResolver,
            new PromptHookRuntimeSettingsResolver,
            new PromptHookDeterministicTemplateRenderer,
            new PromptProviderCapabilityResolver,
            $provider,
            new PromptHookRuntimeOutputPipeline,
            new InMemoryPromptHookBudgetGuard(new InMemoryPromptBudgetStore, 100, 1_000_000),
            new PromptHookAuditRecorder,
            new PromptHookMigrationFlags,
            new PromptHookShadowParityRecorder,
        );
        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturn('LEGACY COMPILED ARTICLE PROMPT {{input}}');

        return new PromptHookExplicitBindingExecutor(
            $engine,
            $registry,
            new PromptHookMigrationFlags,
            $runner,
            new ArticleWritingLegacyRewriteAdapter(new ArticleWritingInputFormatter),
        );
    }

    private function realPublisher(bool $successBody): PromptTestPublishService
    {
        putenv('AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true');
        $_ENV['AUTOMATION_MIGRATION_EMERGENCY_LEGACY'] = 'true';

        $publisher = new PromptTestPublishService(
            new MarkdownOutlineParser,
            new MarkdownSemanticKeywordsParser,
            app(ArticleMarkdownToHtmlService::class),
            (new ReflectionClass(ProjectArticleContentCallerBridge::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectArticleSeoMetaCallerBridge::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ActionRunner::class))->newInstanceWithoutConstructor(),
            new AutomationMigrationFlags,
            new ArticleContentConflictGuard,
        );

        if ($successBody) {
            $publisher->interceptBodyWriteForTests(
                static function (
                    array $input,
                    array $state,
                    callable $legacyWrite,
                ): array {
                    unset($input, $state);

                    return $legacyWrite();
                },
            );
        } else {
            $publisher->interceptBodyWriteForTests(
                static function (): never {
                    throw new AutomationMigrationWriteException(
                        AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE,
                        'Article body hash mismatch; refusing silent overwrite (conflict_content_hash).',
                        ActionResult::failure('conflict_content_hash', 'conflict_content_hash'),
                    );
                },
            );
        }

        return $publisher;
    }

    private function writingServiceWithPublisher(ArticleBodyPublishPort $publisher): ArticleWritingExecutionService
    {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);
        /** @var ArticleWritingExecutionService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('publisher');
        $prop->setAccessible(true);
        $prop->setValue($service, $publisher);

        return $service;
    }

    private function createSchema(): void
    {
        foreach ([
            'seo_prompt_result_links',
            'seo_project_run_items',
            'prompt_results',
            'article_meta',
            'wordpress_article_links',
            'articles',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }

        Schema::connection($this->connection)->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->longText('body')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($this->connection)->create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id')->nullable();
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('status')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('canonical_prompt_key')->nullable();
            $table->string('stage')->nullable();
            $table->string('compiled_prompt_hash')->nullable();
            $table->unsignedBigInteger('content_project_id')->nullable();
            $table->unsignedBigInteger('project_item_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('node_id')->nullable();
            $table->unsignedInteger('retry_attempt')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('failure_category')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('seo_prompt_result_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_result_id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('project_run_id')->nullable();
            $table->unsignedBigInteger('project_task_id')->nullable();
            $table->string('workflow_node_id')->nullable();
            $table->string('workflow_step_title')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('seo_project_run_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('status')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('message')->nullable();
            $table->json('output_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    private function makeArticle(int $id, string $body): SeoArticle
    {
        $article = new SeoArticle;
        $article->setConnection($this->connection);
        $article->forceFill([
            'id' => $id,
            'site_id' => null,
            'title' => 'Article '.$id,
            'body' => $body,
            'status' => 'draft',
        ]);
        $article->save();

        return $article->fresh() ?? $article;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeRunItem(int $id, array $attrs): SeoProjectRunItem
    {
        $row = new SeoProjectRunItem;
        $row->setConnection($this->connection);
        $row->forceFill(array_merge([
            'id' => $id,
            'run_id' => 289,
            'task_id' => 8799,
            'article_id' => 8553,
        ], $attrs));
        $row->save();

        return $row;
    }
}

/**
 * Mimics PromptRunnerProviderAdapter meta contract: persist PromptResult then return prompt_result_id.
 * Used so ExplicitBindingExecutor can link via production PromptResultLinkService.
 */
final class PersistingFakePromptProviderAdapter implements PromptProviderAdapter
{
    /** @var list<RenderedPromptRequest> */
    public array $calls = [];

    /**
     * @param  array{
     *   article_id: int,
     *   content_project_id: int,
     *   project_item_id: int,
     *   run_id: int
     * }  $ownership
     */
    public function __construct(
        private readonly string $text,
        private readonly array $ownership,
    ) {}

    public function capabilities(): PromptProviderCapabilities
    {
        return new PromptProviderCapabilities(
            textGeneration: true,
            jsonMode: true,
            nativeStructuredOutput: false,
            systemMessage: true,
            temperature: true,
            maxTokens: true,
        );
    }

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse
    {
        unset($strategy);
        $this->calls[] = $request;

        $pr = new PromptResult;
        $pr->forceFill([
            'prompt_id' => null,
            'status' => 'completed',
            'output_text' => $this->text,
            'canonical_prompt_key' => 'article.content.generate',
            'stage' => 'writing',
            'content_project_id' => $this->ownership['content_project_id'],
            'project_item_id' => $this->ownership['project_item_id'],
            'run_id' => $this->ownership['run_id'],
            'started_at' => now(),
            'finished_at' => now(),
            'input_snapshot' => [
                'article_id' => $this->ownership['article_id'],
                'hook_key' => 'article.content.generate',
                'variables' => [
                    'article_id' => $this->ownership['article_id'],
                    'hook_key' => 'article.content.generate',
                ],
            ],
            'token_usage' => ['in' => 1, 'out' => 1],
        ]);
        // Avoid PromptExecutionPersistence→prompts lookup; columns already set explicitly.
        $pr->save();

        return new PromptProviderResponse(
            text: $this->text,
            refused: false,
            truncated: false,
            inputTokens: 1,
            outputTokens: 1,
            totalTokens: 2,
            usageSource: 'provider',
            provider: 'fake',
            model: 'fake',
            attempts: 1,
            meta: [
                'prompt_result_id' => (int) $pr->id,
                'retry_owner' => 'PromptRunner/AiModelRouter',
            ],
        );
    }
}
