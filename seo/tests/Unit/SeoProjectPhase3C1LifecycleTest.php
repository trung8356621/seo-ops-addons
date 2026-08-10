<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectArchiveCommand;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArchiveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskLifecycleService;
use Illuminate\Database\Eloquent\SoftDeletes;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class SeoProjectPhase3C1LifecycleTest extends TestCase
{
    public function test_soft_deletes_trait_enabled(): void
    {
        $uses = class_uses_recursive(SeoProjectTask::class);
        $this->assertContains(SoftDeletes::class, $uses);
    }

    public function test_active_and_archived_scopes_exist(): void
    {
        $this->assertTrue(method_exists(SeoProjectTask::class, 'scopeActive'));
        $this->assertTrue(method_exists(SeoProjectTask::class, 'scopeArchived'));
        $this->assertSame('archived', SeoProjectTask::STATUS_ARCHIVED);
    }

    public function test_restore_status_policy_mapping(): void
    {
        $service = $this->app->make(SeoProjectTaskLifecycleService::class);

        $this->assertSame('completed', $service->resolveStatusAfterRestore('completed'));
        $this->assertSame('failed', $service->resolveStatusAfterRestore('failed'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('pending'));
        $this->assertSame('draft', $service->resolveStatusAfterRestore('draft'));
        $this->assertSame('cancelled', $service->resolveStatusAfterRestore('cancelled'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('writing'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('processing'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('reviewing'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('archived'));
        $this->assertSame('pending', $service->resolveStatusAfterRestore(null));
        $this->assertSame('pending', $service->resolveStatusAfterRestore('unknown-xyz'));
    }

    public function test_lifecycle_api_surface(): void
    {
        $ref = new ReflectionClass(SeoProjectTaskLifecycleService::class);
        $this->assertTrue($ref->hasMethod('archive'));
        $this->assertTrue($ref->hasMethod('restore'));
        $this->assertTrue($ref->hasMethod('softDelete'));
        $this->assertTrue($ref->hasMethod('resolveStatusAfterRestore'));
    }

    public function test_error_codes_include_archive_lifecycle(): void
    {
        $values = ContentProjectErrorCode::values();
        $this->assertContains('CONTENT_PROJECT_TASK_DELETED', $values);
        $this->assertContains('CONTENT_PROJECT_TASK_ALREADY_ARCHIVED', $values);
        $this->assertContains('CONTENT_PROJECT_TASK_NOT_ARCHIVED', $values);
        $this->assertContains('CONTENT_PROJECT_ARCHIVE_MIRROR_FAILED', $values);
        $this->assertContains('CONTENT_PROJECT_ARCHIVE_STATE_MISMATCH', $values);
        $this->assertContains('CONTENT_PROJECT_ARCHIVE_TASK_AMBIGUOUS', $values);
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function test_event_types_include_article_archive_restore(): void
    {
        $values = SeoProjectTaskEventType::values();
        $this->assertContains('task.archived', $values);
        $this->assertContains('task.restored', $values);
        $this->assertContains('task.deleted', $values);
        $this->assertContains('article.archive', $values);
        $this->assertContains('article.restore', $values);
    }

    public function test_archive_service_no_longer_hard_deletes_in_source(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(SeoProjectArchiveService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringNotContainsString('tasks()->delete()', $source);
        $this->assertStringNotContainsString('$task->delete()', $source);
        $this->assertStringNotContainsString('$linkedTask->delete()', $source);
        $this->assertStringContainsString('lifecycle->archive', $source);
        $this->assertStringContainsString('lifecycle->restore', $source);
    }

    public function test_unarchive_does_not_delete_tasks_by_article(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(SeoProjectArchiveService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString('resolveAndRestoreTaskForUnarchive', $source);
        $this->assertStringContainsString('ArchiveTaskAmbiguous', $source);
        $this->assertStringContainsString('lifecycle->restore', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/SeoProjectTask::query\(\)\s*->where\(\'article_id\'[^;]*->delete\(\)/s',
            $source,
        );
    }

    public function test_run_item_has_task_including_deleted_relation(): void
    {
        $this->assertTrue(method_exists(SeoProjectRunItem::class, 'taskIncludingDeleted'));
        $relation = (new SeoProjectRunItem)->taskIncludingDeleted();
        $this->assertTrue($relation->getQuery()->getModel() instanceof SeoProjectTask);
    }

    public function test_diagnose_archive_command_registered(): void
    {
        $command = new DiagnoseContentProjectArchiveCommand;
        $this->assertSame('content-project:diagnose-archive', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('site-id'));
    }

    public function test_task_status_enum_archived_value(): void
    {
        $this->assertSame('archived', SeoProjectTaskStatus::Archived->value);
        $this->assertTrue(SeoProjectTaskStatus::Archived->isTerminal());
    }

    public function test_public_livewire_archive_methods_unchanged(): void
    {
        $this->assertTrue(method_exists(
            \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive::class,
            'unarchiveItem',
        ));
        $viewRun = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php',
        );
        $this->assertStringNotContainsString('function archiveItem', $viewRun);
        $this->assertStringContainsString('getProjectWorkspaceUrl', $viewRun);
    }

    public function test_sync_service_no_longer_delete_all_recreate(): void
    {
        $source = file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService::class))->getFileName() ?: '',
        );
        $this->assertIsString($source);
        $this->assertStringNotContainsString('$project->tasks()->delete()', $source);
        $this->assertStringContainsString('syncWithResult', $source);
    }

    public function test_lifecycle_soft_delete_uses_model_delete_not_force_delete(): void
    {
        $method = new ReflectionMethod(SeoProjectTaskLifecycleService::class, 'softDelete');
        $filename = $method->getFileName();
        $start = $method->getStartLine() ?: 0;
        $end = $method->getEndLine() ?: 0;
        $lines = file((string) $filename);
        $body = implode('', array_slice((array) $lines, $start - 1, $end - $start + 1));
        $this->assertStringContainsString('->delete()', $body);
        $this->assertStringNotContainsString('forceDelete', $body);
    }
}
