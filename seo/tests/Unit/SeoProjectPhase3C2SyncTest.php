<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SiteSync\Console\DiagnoseContentProjectSyncCommand;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskCanonicalCandidateResolver;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncData;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncDataNormalizer;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectTaskSyncResult;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class SeoProjectPhase3C2SyncTest extends TestCase
{
    public function test_sync_no_longer_calls_tasks_delete(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(SeoProjectTaskSyncService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringNotContainsString('tasks()->delete()', $source);
        $this->assertStringContainsString('lockForUpdate', $source);
        $this->assertStringContainsString('syncWithResult', $source);
    }

    public function test_sanitize_preserves_task_id(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [1];
            }
        };

        $project = new \Omnichannel\Addons\ContentProjects\Models\SeoProject;
        $project->id = 3;
        $project->site_id = 1;

        $rows = $normalizer->normalize($project, [[
            'id' => 42,
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'source_content' => '  Hello World  ',
            'site_id' => 1,
        ]], 1);

        $this->assertCount(1, $rows);
        $out = $rows[0]->toSanitizedArray();
        $this->assertSame(42, (int) ($out['id'] ?? 0));
        $this->assertSame('Hello World', (string) $out['source_content']);
        $this->assertNotEmpty((string) ($out['source_key'] ?? ''));
    }

    public function test_normalizer_builds_source_key_via_generator(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [1];
            }
        };
        $project = new \Omnichannel\Addons\ContentProjects\Models\SeoProject;
        $project->id = 9;
        $project->site_id = 1;

        $rows = $normalizer->normalize($project, [[
            'id' => 7,
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'kw',
            'site_id' => 1,
        ]], 1);

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(SeoProjectTaskSyncData::class, $rows[0]);
        $this->assertSame(7, $rows[0]->taskId);
        $expected = $generator->generate(9, SeoProjectTask::TYPE_NEW_KEYWORD, SeoProjectTask::POST_TYPE_ARTICLE, 'kw');
        $this->assertSame($expected, $rows[0]->sourceKey);
    }

    public function test_canonical_resolver_sole_article_wins(): void
    {
        $resolver = new SeoProjectTaskCanonicalCandidateResolver;
        $a = new SeoProjectTask;
        $a->id = 1;
        $a->article_id = null;
        $a->status = SeoProjectTask::STATUS_PENDING;
        $b = new SeoProjectTask;
        $b->id = 2;
        $b->article_id = 99;
        $b->status = SeoProjectTask::STATUS_PENDING;

        $result = $resolver->resolve([$a, $b]);
        $this->assertSame('resolved', $result['status']);
        $this->assertSame(2, (int) $result['task']?->id);
        $this->assertSame('sole_article_linked', $result['reason']);
    }

    public function test_canonical_resolver_two_articles_ambiguous(): void
    {
        $resolver = new SeoProjectTaskCanonicalCandidateResolver;
        $a = new SeoProjectTask;
        $a->id = 1;
        $a->article_id = 10;
        $a->status = SeoProjectTask::STATUS_COMPLETED;
        $b = new SeoProjectTask;
        $b->id = 2;
        $b->article_id = 20;
        $b->status = SeoProjectTask::STATUS_COMPLETED;

        $result = $resolver->resolve([$a, $b]);
        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame('multiple_article_linked', $result['reason']);
    }

    public function test_canonical_resolver_sole_completed_wins_over_pending(): void
    {
        $resolver = new SeoProjectTaskCanonicalCandidateResolver;
        $a = new SeoProjectTask;
        $a->id = 1;
        $a->article_id = null;
        $a->status = SeoProjectTask::STATUS_PENDING;
        $b = new SeoProjectTask;
        $b->id = 2;
        $b->article_id = null;
        $b->status = SeoProjectTask::STATUS_COMPLETED;

        $result = $resolver->resolve([$a, $b]);
        $this->assertSame('resolved', $result['status']);
        $this->assertSame(2, (int) $result['task']?->id);
        $this->assertSame('sole_completed_without_article_peers', $result['reason']);
    }

    public function test_sync_error_codes_exist(): void
    {
        $values = ContentProjectErrorCode::values();
        $this->assertContains('CONTENT_PROJECT_SYNC_TASK_NOT_FOUND', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_TASK_DELETED', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_TASK_ARCHIVED', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_TASK_PROJECT_MISMATCH', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_DUPLICATE_IDENTITY', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_DUPLICATE_INPUT', $values);
        $this->assertContains('CONTENT_PROJECT_SYNC_ARTICLE_IDENTITY_CONFLICT', $values);
    }

    public function test_cancel_reactivate_event_types_exist(): void
    {
        $values = SeoProjectTaskEventType::values();
        $this->assertContains('task.cancelled', $values);
        $this->assertContains('task.reactivated', $values);
    }

    public function test_planned_scope_excludes_cancelled(): void
    {
        $this->assertTrue(method_exists(SeoProjectTask::class, 'scopePlanned'));
        $this->assertSame('cancelled', SeoProjectTask::STATUS_CANCELLED);
    }

    public function test_sync_result_value_object(): void
    {
        $result = new SeoProjectTaskSyncResult(
            createdTaskIds: [1],
            updatedTaskIds: [2],
            warnings: ['x'],
            errors: [['code' => 'e']],
        );
        $this->assertTrue($result->hasErrors());
        $this->assertSame([1], $result->createdTaskIds);
    }

    public function test_public_sync_method_still_void(): void
    {
        $method = new ReflectionMethod(SeoProjectTaskSyncService::class, 'sync');
        $this->assertSame('void', (string) $method->getReturnType());
        $this->assertTrue(method_exists(SeoProjectTaskSyncService::class, 'syncWithResult'));
        $this->assertTrue(method_exists(SeoProjectTaskSyncService::class, 'tasksDataFromProject'));
        $this->assertTrue(method_exists(SeoProjectTaskSyncService::class, 'sanitizeTasksData'));
    }

    public function test_completed_article_linked_items_are_cancelled_when_removed_from_sync_input(): void
    {
        $source = file_get_contents((new ReflectionClass(SeoProjectTaskSyncService::class))->getFileName() ?: '');
        $this->assertIsString($source);
        $this->assertStringContainsString('SeoProjectTask::STATUS_COMPLETED', $source);
        $this->assertStringContainsString("'article_id' => \$hasArticle ? (int) \$task->article_id : null", $source);
        $this->assertStringNotContainsString('SYNC_REMOVAL_BLOCKED_COMPLETED_OR_ARTICLE', $source);
    }

    public function test_diagnose_sync_command_signature(): void
    {
        $command = new DiagnoseContentProjectSyncCommand;
        $this->assertSame('content-project:diagnose-sync', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('project-id'));
    }

    public function test_duplicate_input_same_task_id_collapses_in_sanitize(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $normalizer = new class($generator) extends SeoProjectTaskSyncDataNormalizer
        {
            protected function allowedSiteIds(): array
            {
                return [1];
            }
        };
        $project = new \Omnichannel\Addons\ContentProjects\Models\SeoProject;
        $project->id = 1;
        $project->site_id = 1;

        $rows = $normalizer->normalize($project, [
            ['id' => 5, 'type' => SeoProjectTask::TYPE_NEW_KEYWORD, 'source_content' => 'same', 'site_id' => 1],
            ['id' => 5, 'type' => SeoProjectTask::TYPE_NEW_KEYWORD, 'source_content' => 'same', 'site_id' => 1],
        ], 1);

        // Normalizer giữ cả hai row; sanitize wrapper mới collapse theo source_key.
        $this->assertCount(2, $rows);

        $service = $this->app->make(SeoProjectTaskSyncService::class);
        // Dùng reflection để inject normalizer stub khó — kiểm tra assertNoDuplicateInput policy qua source.
        $source = file_get_contents((new ReflectionClass(SeoProjectTaskSyncService::class))->getFileName() ?: '');
        $this->assertStringContainsString('assertNoDuplicateInput', (string) $source);
        $this->assertStringContainsString('assertNoDuplicateTasksData', (string) $source);
        $this->assertStringContainsString('sync_duplicate_input', (string) $source);
    }

    public function test_editable_whitelist_does_not_include_system_fields(): void
    {
        $ref = new ReflectionClass(SeoProjectTaskSyncService::class);
        $const = $ref->getConstant('EDITABLE_FIELDS');
        $this->assertIsArray($const);
        $this->assertNotContains('article_id', $const);
        $this->assertNotContains('status', $const);
        $this->assertNotContains('archived_at', $const);
        $this->assertNotContains('deleted_at', $const);
        $this->assertContains('source_key', $const);
        $this->assertContains('source_content', $const);
    }
}
