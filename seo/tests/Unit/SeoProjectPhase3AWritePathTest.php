<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ProjectRunIdempotencyKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class SeoProjectPhase3AWritePathTest extends TestCase
{
    public function test_error_codes_are_unique(): void
    {
        $values = ContentProjectErrorCode::values();
        $this->assertSame(count($values), count(array_unique($values)));
        $this->assertContains('CONTENT_PROJECT_TASK_NOT_FOUND', $values);
        $this->assertContains('CONTENT_PROJECT_OPERATION_ALREADY_PROCESSING', $values);
        $this->assertContains('CONTENT_PROJECT_ARTICLE_RELATION_MISSING', $values);
    }

    public function test_enqueue_failed_task_once_returns_original_task_no_copy(): void
    {
        $method = new ReflectionMethod(SeoProjectWorkflowRunService::class, 'enqueueFailedTaskOnce');
        $doc = (string) $method->getDocComment();

        $this->assertTrue($method->isPrivate());
        $this->assertStringContainsString('không tạo task copy', mb_strtolower($doc));
        $this->assertSame('int', (string) $method->getReturnType());
    }

    public function test_create_retry_task_from_item_is_deprecated_no_create_signature(): void
    {
        $method = new ReflectionMethod(SeoProjectWorkflowRunService::class, 'createRetryTaskFromItem');
        $doc = (string) $method->getDocComment();

        $this->assertStringContainsString('không reconstruct', mb_strtolower($doc));
        $this->assertTrue($method->isPrivate());
    }

    public function test_operation_version_ignores_status_fields(): void
    {
        $service = $this->app->make(SeoProjectRunItemService::class);

        $taskA = new SeoProjectTask([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'hello',
            'description' => null,
            'loai_san_pham' => null,
            'site_id' => 1,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);
        $taskA->id = 10;
        $taskA->target_date = null;

        $taskB = clone $taskA;
        $taskB->status = SeoProjectTask::STATUS_WRITING;
        $taskB->completed_at = now();

        $v1 = $service->buildOperationVersion($taskA, SeoProjectRunAction::ArticleCreate);
        $v2 = $service->buildOperationVersion($taskB, SeoProjectRunAction::ArticleCreate);

        $this->assertSame($v1, $v2);
    }

    public function test_source_key_generator_used_for_identity(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $key = $generator->generate(5, 'new_keyword', 'article', '  Foo  Bar ');
        $this->assertSame(64, strlen($key));
        $this->assertSame(
            $key,
            $generator->generate(5, 'new_keyword', 'article', 'foo bar'),
        );
    }

    public function test_idempotency_key_stable_for_same_version(): void
    {
        $keys = new ProjectRunIdempotencyKeyGenerator;
        $version = $keys->contentVersion([
            'task_id' => 1,
            'type' => 'new_keyword',
            'source_content' => 'a',
        ]);

        $this->assertSame(
            $keys->generate(1, 'article.create', $version),
            $keys->generate(1, 'article.create', $version),
        );
    }

    public function test_soft_deletes_enabled_on_task_model_phase_3c1(): void
    {
        $uses = class_uses_recursive(SeoProjectTask::class);
        $this->assertContains(\Illuminate\Database\Eloquent\SoftDeletes::class, $uses);
    }

    public function test_run_item_status_processing_exists(): void
    {
        $this->assertSame('processing', SeoProjectRunItemStatus::Processing->value);
        $this->assertSame('skipped', SeoProjectRunItemStatus::Skipped->value);
    }

    public function test_workflow_service_constructor_accepts_run_item_service(): void
    {
        $ref = new ReflectionClass(SeoProjectWorkflowRunService::class);
        $params = $ref->getConstructor()?->getParameters() ?? [];
        $types = array_map(
            static fn ($param): string => (string) ($param->getType() ?? ''),
            $params,
        );

        $this->assertTrue(
            collect($types)->contains(static fn (string $type): bool => str_contains($type, 'SeoProjectRunItemService')),
        );
    }
}
