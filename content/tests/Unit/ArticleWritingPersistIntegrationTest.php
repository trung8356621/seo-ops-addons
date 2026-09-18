<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Behavioral persist/history gate tests (fake publisher, sqlite schema, no WP/provider).
 */
final class ArticleWritingPersistIntegrationTest extends TestCase
{
    private string $connection = 'omi_seo_ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'seo_prompt_result_links',
            'seo_project_run_items',
            'prompt_results',
            'wordpress_article_links',
            'article_meta',
            'articles',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_ai_success_late_persists_and_verifies_canonical_hash(): void
    {
        $article = $this->makeArticle(8553, '<p>OLD body Emivest balo</p>');
        $oldHash = hash('sha256', trim((string) $article->body));
        $markdown = "# New writing\n\nCanonical body for article 8553.";
        $intendedHtml = '<p>Canonical body for article 8553.</p>';
        $intendedHash = hash('sha256', trim($intendedHtml));

        $publisher = $this->publisherStub(
            prepare: [
                'markdown' => $markdown,
                'html' => $intendedHtml,
                'content_hash' => $intendedHash,
                'faqs' => [],
                'meta_description' => null,
                'h1_title' => '',
            ],
            publish: function (SeoArticle $a) use ($intendedHtml, $intendedHash): array {
                $a->body = $intendedHtml;
                $a->save();

                return [
                    'success' => true,
                    'message' => 'ok',
                    'expected_content_hash' => $intendedHash,
                    'persisted_content_hash' => hash('sha256', trim($intendedHtml)),
                    'body_length' => strlen($intendedHtml),
                ];
            },
        );

        $service = $this->serviceWithPublisher($publisher);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => $markdown,
                'result_id' => 91001,
            ],
        ], $article->fresh(), []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_APPLIED, $result['status']);
        self::assertSame($intendedHash, $result['expected_content_hash']);
        self::assertSame($intendedHash, $result['persisted_content_hash']);

        $fresh = SeoArticle::query()->find(8553);
        self::assertNotNull($fresh);
        self::assertNotSame($oldHash, hash('sha256', trim((string) $fresh->body)));
        self::assertSame($intendedHtml, (string) $fresh->body);

        $pr = $this->makePromptResult(91001, [
            'canonical_prompt_key' => 'article.content.generate',
            'stage' => 'writing',
            'project_item_id' => 8799,
            'content_project_id' => 900,
            'run_id' => 289,
            'output_text' => $markdown,
        ]);
        app(PromptResultLinkService::class)->linkPromptResult(
            promptResultId: (int) $pr->id,
            articleId: 8553,
            source: 'prompt_hook_explicit_binding',
            runId: null,
            taskId: null,
            workflowNodeId: 'writing',
            meta: ['hook_key' => 'article.content.generate', 'stage' => 'writing'],
        );

        $link = SeoPromptResultLink::query()
            ->where('prompt_result_id', 91001)
            ->where('article_id', 8553)
            ->first();
        self::assertNotNull($link);

        $runItem = $this->makeRunItem(804, [
            'status' => 'failed',
            'error_code' => 'old',
            'error_message' => 'stale DeepSeek error',
        ]);
        $runItem->fill([
            'status' => 'success',
            'error_code' => null,
            'error_message' => null,
        ]);
        $runItem->save();
        $runItem = $runItem->fresh();
        self::assertSame('success', (string) $runItem->status);
        self::assertNull($runItem->error_code);
        self::assertNull($runItem->error_message);
    }

    public function test_content_step_missing_output_is_persist_failed(): void
    {
        $article = $this->makeArticle(1, '<p>old</p>');
        $service = $this->serviceWithPublisher($this->publisherStub(
            prepare: ['markdown' => '', 'html' => '', 'content_hash' => '', 'faqs' => [], 'meta_description' => null, 'h1_title' => ''],
            publish: static fn (): array => ['success' => false, 'message' => 'should not run'],
        ));
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => '',
            ],
        ], $article, []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_FAILED, $result['status']);
        self::assertStringContainsString('could not be resolved', (string) $result['message']);
    }

    public function test_missing_article_is_persist_failed(): void
    {
        $service = $this->serviceWithPublisher($this->publisherStub(
            prepare: ['markdown' => 'x', 'html' => '<p>x</p>', 'content_hash' => 'h', 'faqs' => [], 'meta_description' => null, 'h1_title' => ''],
            publish: static fn (): array => ['success' => true],
        ));
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => 'generated writing content long enough',
            ],
        ], null, []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_FAILED, $result['status']);
        self::assertStringContainsString('article could not be resolved', (string) $result['message']);
    }

    public function test_late_publish_reject_keeps_old_body_and_fails(): void
    {
        $old = '<p>OLD body remains</p>';
        $article = $this->makeArticle(42, $old);
        $markdown = 'NEW markdown that should not apply';
        $intendedHtml = '<p>NEW html</p>';
        $intendedHash = hash('sha256', trim($intendedHtml));

        $publisher = $this->publisherStub(
            prepare: [
                'markdown' => $markdown,
                'html' => $intendedHtml,
                'content_hash' => $intendedHash,
                'faqs' => [],
                'meta_description' => null,
                'h1_title' => '',
            ],
            publish: static fn (): array => [
                'success' => false,
                'message' => 'writer rejected',
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => hash('sha256', trim($old)),
                'body_length' => strlen($old),
            ],
        );

        $service = $this->serviceWithPublisher($publisher);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => $markdown,
            ],
        ], $article, []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_FAILED, $result['status']);
        self::assertSame($old, (string) SeoArticle::query()->find(42)?->body);
    }

    public function test_late_publish_conflict_maps_to_ignored_stale(): void
    {
        $old = '<p>manual newer edit</p>';
        $article = $this->makeArticle(77, $old);
        $markdown = 'AI output after manual edit';
        $intendedHtml = '<p>AI html</p>';
        $intendedHash = hash('sha256', trim($intendedHtml));

        $publisher = $this->publisherStub(
            prepare: [
                'markdown' => $markdown,
                'html' => $intendedHtml,
                'content_hash' => $intendedHash,
                'faqs' => [],
                'meta_description' => null,
                'h1_title' => '',
            ],
            publish: static fn (): array => [
                'success' => false,
                'message' => 'Article body hash mismatch; refusing silent overwrite.',
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => hash('sha256', trim($old)),
                'body_length' => strlen($old),
                'conflict' => true,
            ],
        );

        $service = $this->serviceWithPublisher($publisher);
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            [
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
                'output' => $markdown,
            ],
        ], $article, []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_IGNORED_STALE, $result['status']);
        self::assertSame($old, (string) SeoArticle::query()->find(77)?->body);
    }

    public function test_no_content_step_allows_early_applied(): void
    {
        $article = $this->makeArticle(9, '<p>x</p>');
        $service = $this->serviceWithPublisher($this->publisherStub(
            prepare: ['markdown' => '', 'html' => '', 'content_hash' => '', 'faqs' => [], 'meta_description' => null, 'h1_title' => ''],
            publish: static fn (): array => ['success' => false, 'message' => 'should not run'],
        ));
        $method = new ReflectionMethod(ArticleWritingExecutionService::class, 'ensureGeneratedContentPersisted');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            ['status' => 'completed', 'hook_key' => 'article.outline.structure.generate', 'output' => 'outline'],
        ], $article, []);

        self::assertSame(ArticleWritingExecutionResult::PERSIST_APPLIED, $result['status']);
    }

    private function createSchema(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_prompt_result_links');
        Schema::connection($this->connection)->dropIfExists('seo_project_run_items');
        Schema::connection($this->connection)->dropIfExists('prompt_results');
        Schema::connection($this->connection)->dropIfExists('wordpress_article_links');
        Schema::connection($this->connection)->dropIfExists('article_meta');
        Schema::connection($this->connection)->dropIfExists('articles');

        Schema::connection($this->connection)->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->timestamps();
        });
    }

    private function makeArticle(int $id, string $body): SeoArticle
    {
        $article = new SeoArticle;
        $article->setConnection($this->connection);
        $article->forceFill([
            'id' => $id,
            'site_id' => 1,
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
    private function makePromptResult(int $id, array $attrs): PromptResult
    {
        $row = new PromptResult;
        $row->setConnection($this->connection);
        $row->forceFill(array_merge([
            'id' => $id,
            'status' => 'completed',
            'input_snapshot' => ['article_id' => 8553],
        ], $attrs));
        $row->save();

        return $row;
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

    /**
     * @param  array<string, mixed>  $prepare
     * @param  callable(SeoArticle, string, array): array  $publish
     */
    private function publisherStub(array $prepare, callable $publish): ArticleBodyPublishPort
    {
        return new class($prepare, $publish) implements ArticleBodyPublishPort
        {
            /**
             * @param  array<string, mixed>  $prepare
             * @param  callable  $publish
             */
            public function __construct(
                private readonly array $prepare,
                private readonly mixed $publish,
            ) {}

            public function prepareArticleContent(SeoArticle $article, string $aiOutput): array
            {
                return $this->prepare;
            }

            public function contentHash(string $body): string
            {
                return hash('sha256', trim($body));
            }

            public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array
            {
                return ($this->publish)($article, $aiOutput, $variables);
            }
        };
    }

    private function serviceWithPublisher(ArticleBodyPublishPort $publisher): ArticleWritingExecutionService
    {
        $ref = new ReflectionClass(ArticleWritingExecutionService::class);
        /** @var ArticleWritingExecutionService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('publisher');
        $prop->setAccessible(true);
        $prop->setValue($service, $publisher);

        return $service;
    }
}
