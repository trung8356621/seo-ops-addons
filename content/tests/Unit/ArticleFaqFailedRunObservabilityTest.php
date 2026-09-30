<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use Tests\TestCase;

final class ArticleFaqFailedRunObservabilityTest extends TestCase
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
            'seo_project_runs',
            'article_meta',
            'prompt_results',
            'prompts',
            'articles',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
        Schema::dropIfExists('prompts');
        Schema::dropIfExists('prompt_results');
        parent::tearDown();
    }

    public function test_failed_result_is_linked_and_same_exception_context_survives(): void
    {
        [$article, $prompt, $result] = $this->fixtures('failed');
        $exception = new PromptRunException('AI_ROUTES_EXHAUSTED: 2 attempts failed', 0, null, [
            'prompt_result_id' => (int) $result->id,
            'failure_code' => 'FAQ_INVALID_JSON',
            'classification' => 'AI_ROUTES_EXHAUSTED',
            'retryable' => false,
            'routing_attempts' => [['result' => 'failed']],
        ]);

        try {
            $this->invokeFaqFailure($article, $prompt, $exception);
            self::fail('Expected PromptRunException');
        } catch (PromptRunException $caught) {
            self::assertSame($exception, $caught);
            self::assertSame((int) $result->id, $caught->context['prompt_result_id']);
            self::assertSame('FAQ_INVALID_JSON', $caught->context['failure_code']);
            self::assertSame('AI_ROUTES_EXHAUSTED', $caught->context['classification']);
        }

        $link = SeoPromptResultLink::query()->sole();
        self::assertSame((int) $result->id, (int) $link->prompt_result_id);
        self::assertSame((int) $article->id, (int) $link->article_id);
        self::assertSame('article_faq_generate', $link->source);
        self::assertSame('Generate FAQ (AI)', $link->workflow_step_title);
        self::assertSame('failed', $result->fresh()->status);
    }

    public function test_success_link_path_remains_idempotent(): void
    {
        [$article, $prompt, $result] = $this->fixtures('completed');
        $service = $this->faqService();
        $method = new \ReflectionMethod(ArticleFaqGeneratorService::class, 'linkPromptResultToArticle');

        $method->invoke($service, $article, $prompt, $result);
        $method->invoke($service, $article, $prompt, $result);

        self::assertSame(1, SeoPromptResultLink::query()->count());
        self::assertSame('completed', $result->fresh()->status);
    }

    public function test_controller_preserves_specific_ai_failure_payload(): void
    {
        $controller = (new \ReflectionClass(ArticleEditorFaqSnapshotController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ArticleEditorFaqSnapshotController::class, 'promptRunError');
        $exception = new PromptRunException('AI_ROUTES_EXHAUSTED: 3 attempts failed', 0, null, [
            'prompt_result_id' => 123,
            'classification' => 'AI_ROUTES_EXHAUSTED',
            'retryable' => false,
            'user_message' => 'Available AI routes failed.',
        ]);

        $response = $method->invoke($controller, $exception);
        $payload = $response->getData(true);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('AI_ROUTES_EXHAUSTED', $payload['error']);
        self::assertSame('Available AI routes failed.', $payload['message']);
        self::assertSame(123, $payload['prompt_result_id']);
        self::assertSame('AI_ROUTES_EXHAUSTED', $payload['classification']);
        self::assertFalse($payload['retryable']);
        self::assertNotSame('faq_generation_failed', $payload['error']);

        $structured = $method->invoke($controller, new PromptRunException(
            'FAQ_INVALID_JSON: provider returned malformed structured JSON.',
            0,
            null,
            ['prompt_result_id' => 124, 'failure_code' => 'FAQ_INVALID_JSON'],
        ))->getData(true);
        self::assertSame('FAQ_INVALID_JSON', $structured['error']);
        self::assertSame(124, $structured['prompt_result_id']);
    }

    public function test_linked_failed_faq_result_appears_in_article_history(): void
    {
        [$article, $prompt, $result] = $this->fixtures('failed');
        $result->update([
            'input_snapshot' => ['hook_key' => 'article.faq.generate'],
            'error_message' => 'AI_ROUTES_EXHAUSTED',
        ]);
        try {
            $this->invokeFaqFailure($article, $prompt, new PromptRunException('failed', 0, null, [
                'prompt_result_id' => (int) $result->id,
            ]));
        } catch (PromptRunException) {
        }

        $groups = (new ArticlePromptRunHistoryService())->build($article, []);
        $prompts = collect($groups)->flatMap(
            static fn (array $group): array => is_array($group['prompts'] ?? null) ? $group['prompts'] : [],
        );
        $entry = $prompts->firstWhere('result_id', (int) $result->id);

        self::assertIsArray($entry);
        self::assertSame('article.faq.generate', $entry['hook_key'] ?? null);
        self::assertSame('failed', $entry['status'] ?? null);
        self::assertSame((int) $result->id, $entry['result_id'] ?? null);
    }

    private function invokeFaqFailure(
        SeoArticle $article,
        SeoPrompt $prompt,
        PromptRunException $exception,
    ): never {
        $method = new \ReflectionMethod(ArticleFaqGeneratorService::class, 'rethrowLinkedPromptFailure');
        $method->invoke($this->faqService(), $article, $prompt, $exception);
        throw new \LogicException('Unreachable');
    }

    private function faqService(): ArticleFaqGeneratorService
    {
        $reflection = new \ReflectionClass(ArticleFaqGeneratorService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('promptResultLinks');
        $property->setValue($service, app(PromptResultLinkService::class));

        return $service;
    }

    /** @return array{SeoArticle, SeoPrompt, PromptResult} */
    private function fixtures(string $status): array
    {
        $now = now();
        DB::connection($this->connection)->table('articles')->insert([
            'id' => 77,
            'site_id' => 1,
            'title' => 'FAQ article',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('prompts')->insert([
            'id' => 88,
            'name' => 'FAQ Prompt',
            'hook_key' => 'article.faq.generate',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('prompt_results')->insert([
            'id' => 123,
            'prompt_id' => 88,
            'user_id' => 1,
            'site_id' => 1,
            'status' => $status,
            'input_snapshot' => json_encode(['hook_key' => 'article.faq.generate']),
            'error_message' => $status === 'failed' ? 'AI_ROUTES_EXHAUSTED' : null,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            SeoArticle::query()->findOrFail(77),
            SeoPrompt::query()->findOrFail(88),
            PromptResult::query()->findOrFail(123),
        ];
    }

    private function createSchema(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();
        });
        Schema::create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('hook_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('prompt_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prompt_id');
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->string('canonical_prompt_key')->nullable();
            $table->string('stage')->nullable();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('status')->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->json('token_usage')->nullable();
            $table->longText('output_text')->nullable();
            $table->text('error_message')->nullable();
            $table->string('compiled_prompt_hash')->nullable();
            $table->unsignedBigInteger('content_project_id')->nullable();
            $table->unsignedBigInteger('project_item_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('node_id')->nullable();
            $table->unsignedInteger('retry_attempt')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('failure_category')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_prompt_result_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('prompt_result_id');
            $table->unsignedBigInteger('project_run_id')->nullable();
            $table->unsignedBigInteger('project_task_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source');
            $table->string('workflow_node_id')->nullable();
            $table->string('workflow_step_title')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_project_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_project_run_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->timestamps();
        });
    }
}
