<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTaskEvent;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskEventRecorder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoProjectRunItemSchemaPhase2Test extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_soft_deletes_trait_is_enabled_on_task_model_phase_3c1(): void
    {
        $uses = class_uses_recursive(SeoProjectTask::class);

        $this->assertContains(SoftDeletes::class, $uses);
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at'));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'source_key'));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'archived_at'));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items'));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('seo_project_task_events'));
    }

    public function test_can_create_run_item_with_relations_and_json_casts(): void
    {
        [$project, $task, $run] = $this->seedProjectTaskRun();

        $item = SeoProjectRunItem::query()->create([
            'run_id' => $run->id,
            'task_id' => $task->id,
            'article_id' => null,
            'action' => SeoProjectRunAction::ArticleCreate->value,
            'status' => SeoProjectRunItemStatus::Pending->value,
            'attempt' => 1,
            'idempotency_key' => hash('sha256', 'phase2-test-'.$run->id.'-'.$task->id),
            'input_snapshot' => ['source_content' => 'hello'],
            'output_snapshot' => ['steps' => []],
            'started_at' => now(),
        ]);

        $item->refresh();

        $this->assertTrue($item->run->is($run));
        $this->assertTrue($item->task->is($task));
        $this->assertSame(['source_content' => 'hello'], $item->input_snapshot);
        $this->assertSame(['steps' => []], $item->output_snapshot);
        $this->assertTrue($run->runItems()->whereKey($item->id)->exists());
        $this->assertTrue($task->runItems()->whereKey($item->id)->exists());
        $this->assertSame($project->id, (int) $run->project_id);
    }

    public function test_deleting_run_cascades_run_items(): void
    {
        [, $task, $run] = $this->seedProjectTaskRun();

        $item = SeoProjectRunItem::query()->create([
            'run_id' => $run->id,
            'task_id' => $task->id,
            'action' => SeoProjectRunAction::ArticleCreate->value,
            'status' => SeoProjectRunItemStatus::Success->value,
            'attempt' => 1,
            'idempotency_key' => hash('sha256', 'cascade-'.$run->id),
        ]);

        $run->delete();

        $this->assertDatabaseMissing('seo_project_run_items', [
            'id' => $item->id,
        ], 'omi_seo_ai');
    }

    public function test_hard_delete_task_nulls_run_item_task_id(): void
    {
        [, $task, $run] = $this->seedProjectTaskRun();

        $item = SeoProjectRunItem::query()->create([
            'run_id' => $run->id,
            'task_id' => $task->id,
            'action' => SeoProjectRunAction::ArticleCreate->value,
            'status' => SeoProjectRunItemStatus::Failed->value,
            'attempt' => 1,
            'idempotency_key' => hash('sha256', 'null-task-'.$task->id),
        ]);

        $task->delete();

        $item->refresh();
        $this->assertNull($item->task_id);
        $this->assertDatabaseMissing('seo_project_tasks', [
            'id' => $task->id,
        ], 'omi_seo_ai');
    }

    public function test_event_recorder_and_relations(): void
    {
        [, $task, $run] = $this->seedProjectTaskRun();

        $event = (new SeoProjectTaskEventRecorder)->record(
            task: $task,
            event: SeoProjectTaskEventType::TaskCreated,
            fromStatus: null,
            toStatus: SeoProjectTask::STATUS_PENDING,
            payload: ['phase' => 2],
            runId: (int) $run->id,
            createdBy: 1,
        );

        $this->assertInstanceOf(SeoProjectTaskEvent::class, $event);
        $this->assertSame(['phase' => 2], $event->fresh()->payload);
        $this->assertTrue($task->events()->whereKey($event->id)->exists());
        $this->assertSame((int) $run->id, (int) $event->run_id);
    }

    /**
     * @return array{0: SeoProject, 1: SeoProjectTask, 2: SeoProjectRun}
     */
    private function seedProjectTaskRun(): array
    {
        $project = SeoProject::query()->create([
            'name' => 'Phase2 schema project',
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_RUNNING,
            'total_tasks' => 1,
            'user_id' => 1,
        ]);

        $task = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'phase2 keyword',
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => '2026-07-01',
        ]);

        $run = SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 1,
            'succeeded' => 0,
            'failed' => 0,
            'items' => [],
            'started_at' => now(),
        ]);

        return [$project, $task, $run];
    }
}
