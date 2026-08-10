<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunConsolidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SeoProjectRunConsolidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_it_merges_multiple_successful_runs_when_project_is_fully_completed(): void
    {
        $project = SeoProject::query()->create([
            'name' => 'Test project',
            'month' => '2026-06-01',
            'status' => SeoProject::STATUS_APPROVED,
            'total_tasks' => 2,
        ]);

        $taskA = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword A',
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ]);

        $taskB = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword B',
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ]);

        SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 1,
            'succeeded' => 1,
            'failed' => 0,
            'items' => [[
                'task_id' => $taskA->id,
                'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                'source_content' => 'Keyword A',
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'status' => 'success',
                'article_id' => 101,
            ]],
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
        ]);

        SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 1,
            'succeeded' => 1,
            'failed' => 0,
            'items' => [[
                'task_id' => $taskB->id,
                'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                'source_content' => 'Keyword B',
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'status' => 'success',
                'article_id' => 102,
            ]],
            'started_at' => now()->subMinutes(20),
            'finished_at' => now(),
        ]);

        $service = app(SeoProjectRunConsolidationService::class);
        $keeper = $service->maybeConsolidate($project->fresh());

        $this->assertInstanceOf(SeoProjectRun::class, $keeper);
        $this->assertSame(2, $project->runs()->count());
        $this->assertSame(1, $project->notConsolidatedRuns()->count());
        $this->assertNull($keeper->consolidated_into_run_id);
        $this->assertSame(SeoProjectRun::MODE_FULL, $keeper->mode);
        $this->assertSame(2, (int) $keeper->total);
        $this->assertSame(2, (int) $keeper->succeeded);
        $this->assertSame(
            1,
            $project->runs()->whereNotNull('consolidated_into_run_id')->count(),
        );
    }

    public function test_it_does_not_consolidate_when_pending_tasks_remain(): void
    {
        $project = SeoProject::query()->create([
            'name' => 'Pending project',
            'month' => '2026-06-01',
            'status' => SeoProject::STATUS_APPROVED,
            'total_tasks' => 1,
        ]);

        $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword A',
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'items' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $service = app(SeoProjectRunConsolidationService::class);

        $this->assertNull($service->maybeConsolidate($project->fresh()));
        $this->assertSame(1, $project->runs()->count());
        $this->assertTrue($service->hasRunnablePendingTasks($project->fresh()));
    }

    public function test_it_consolidates_when_all_keywords_succeeded_despite_stale_failed_tasks(): void
    {
        $project = SeoProject::query()->create([
            'name' => 'Stale failed project',
            'month' => '2026-06-01',
            'status' => SeoProject::STATUS_APPROVED,
            'total_tasks' => 2,
        ]);

        $failedTask = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword A',
            'status' => SeoProjectTask::STATUS_FAILED,
        ]);

        $completedTask = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword A',
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ]);

        $taskB = $project->tasks()->create([
            'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'Keyword B',
            'status' => SeoProjectTask::STATUS_COMPLETED,
        ]);

        SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 1,
            'succeeded' => 0,
            'failed' => 1,
            'items' => [[
                'task_id' => $failedTask->id,
                'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                'source_content' => 'Keyword A',
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'status' => 'failed',
            ]],
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(40),
        ]);

        SeoProjectRun::query()->create([
            'project_id' => $project->id,
            'user_id' => 1,
            'mode' => SeoProjectRun::MODE_TEST,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 2,
            'succeeded' => 2,
            'failed' => 0,
            'items' => [
                [
                    'task_id' => $completedTask->id,
                    'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                    'source_content' => 'Keyword A',
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'status' => 'success',
                    'article_id' => 201,
                ],
                [
                    'task_id' => $taskB->id,
                    'type' => SeoProjectTask::TYPE_NEW_KEYWORD,
                    'source_content' => 'Keyword B',
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'status' => 'success',
                    'article_id' => 202,
                ],
            ],
            'started_at' => now()->subMinutes(20),
            'finished_at' => now(),
        ]);

        $service = app(SeoProjectRunConsolidationService::class);

        $this->assertTrue($service->isProjectFullyCompleted($project->fresh()));
        $keeper = $service->maybeConsolidate($project->fresh());

        $this->assertInstanceOf(SeoProjectRun::class, $keeper);
        $this->assertSame(2, $project->runs()->count());
        $this->assertSame(1, $project->notConsolidatedRuns()->count());
        $this->assertSame(2, (int) $keeper->total);
        $this->assertSame(2, (int) $keeper->succeeded);
        $this->assertFalse($service->hasRunnablePendingTasks($project->fresh()));
    }
}
