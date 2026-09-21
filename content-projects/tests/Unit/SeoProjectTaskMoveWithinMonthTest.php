<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Move item within month: same site/month, any writer; preserve task progress.
 */
final class SeoProjectTaskMoveWithinMonthTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }

        $this->bindWriterMonthlyCapacity(30);
    }

    public function test_target_options_include_same_month_other_writer(): void
    {
        $source = $this->createMonthly(77201, 76201, '2026-08-01', 'Src other-writer');
        $sameWriter = $this->createMonthly(77201, 76201, '2026-08-01', 'Same writer Aug');
        $otherWriter = $this->createMonthly(77202, 76201, '2026-08-01', 'Other writer Aug');

        $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source);
        $ids = array_map('intval', array_keys($options));

        self::assertContains((int) $sameWriter->id, $ids);
        self::assertContains((int) $otherWriter->id, $ids);
        self::assertNotContains((int) $source->id, $ids);
    }

    public function test_target_options_exclude_other_month_other_site_source_and_archived(): void
    {
        $source = $this->createMonthly(77211, 76211, '2026-08-01', 'Src filters');
        $otherMonth = $this->createMonthly(77211, 76211, '2026-09-01', 'Sep same writer');
        $otherSite = $this->createMonthly(77212, 76212, '2026-08-01', 'Aug other site');
        $archived = $this->createMonthly(77213, 76211, '2026-08-01', 'Archived Aug');
        $archived->forceFill(['archived_at' => now()])->save();
        $kindArchive = SeoProject::query()->create([
            'name' => 'Kind archive '.$this->seq,
            'user_id' => 77214,
            'site_id' => 76211,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_ARCHIVE,
            'total_tasks' => 0,
        ]);

        $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source);
        $ids = array_map('intval', array_keys($options));

        self::assertNotContains((int) $source->id, $ids);
        self::assertNotContains((int) $otherMonth->id, $ids);
        self::assertNotContains((int) $otherSite->id, $ids);
        self::assertNotContains((int) $archived->id, $ids);
        self::assertNotContains((int) $kindArchive->id, $ids);
    }

    public function test_move_to_other_writer_same_month_preserves_status_and_article(): void
    {
        $source = $this->createMonthly(77301, 76301, '2026-08-01', 'Move src');
        $target = $this->createMonthly(77302, 76301, '2026-08-01', 'Move tgt');
        $task = $this->createTask($source, [
            'status' => SeoProjectTask::STATUS_REVIEWING,
            'article_id' => 908877,
            'keyword' => 'keep-kw-'.(++$this->seq),
            'source_content' => 'keep-src-'.$this->seq,
            'planning_reviewed_at' => '2026-08-10 09:00:00',
            'planning_reviewed_by' => 77301,
        ]);

        $result = app(SeoProjectTaskMoveService::class)->moveTasksToProject(
            $source,
            $target,
            [(int) $task->id],
        );

        self::assertSame(1, $result['moved']);
        $fresh = $task->fresh();
        self::assertNotNull($fresh);
        self::assertSame((int) $target->id, (int) $fresh->project_id);
        self::assertSame(SeoProjectTask::STATUS_REVIEWING, (string) $fresh->status);
        self::assertSame(908877, (int) $fresh->article_id);
        self::assertSame('keep-kw-'.$this->seq, (string) $fresh->keyword);
        self::assertNotNull($fresh->planning_reviewed_at);
        self::assertSame(77301, (int) $fresh->planning_reviewed_by);
        self::assertSame('2026-08', $target->monthCarbon()->format('Y-m'));
    }

    public function test_move_to_same_writer_same_month_allowed(): void
    {
        $source = $this->createMonthly(77401, 76401, '2026-08-01', 'Same writer A');
        $target = $this->createMonthly(77401, 76401, '2026-08-01', 'Same writer B');
        $task = $this->createTask($source, [
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'article_id' => 908878,
        ]);

        $result = app(SeoProjectTaskMoveService::class)->moveTasksToProject(
            $source,
            $target,
            [(int) $task->id],
        );

        self::assertSame(1, $result['moved']);
        self::assertSame((int) $target->id, (int) $task->fresh()?->project_id);
        self::assertSame(SeoProjectTask::STATUS_COMPLETED, (string) $task->fresh()?->status);
        self::assertSame(908878, (int) $task->fresh()?->article_id);
    }

    public function test_move_rejects_other_month_and_other_site(): void
    {
        $source = $this->createMonthly(77501, 76501, '2026-08-01', 'Reject src');
        $otherMonth = $this->createMonthly(77501, 76501, '2026-09-01', 'Reject Sep');
        $otherSite = $this->createMonthly(77502, 76502, '2026-08-01', 'Reject site');
        $taskA = $this->createTask($source, ['status' => SeoProjectTask::STATUS_PENDING]);
        $taskB = $this->createTask($source, ['status' => SeoProjectTask::STATUS_PENDING]);

        try {
            app(SeoProjectTaskMoveService::class)->moveTasksToProject(
                $source,
                $otherMonth,
                [(int) $taskA->id],
            );
            self::fail('Expected month mismatch validation.');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors()['target_project_id'] ?? []);
        }

        try {
            app(SeoProjectTaskMoveService::class)->moveTasksToProject(
                $source,
                $otherSite,
                [(int) $taskB->id],
            );
            self::fail('Expected domain mismatch validation.');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors()['target_project_id'] ?? []);
        }

        self::assertSame((int) $source->id, (int) $taskA->fresh()?->project_id);
        self::assertSame((int) $source->id, (int) $taskB->fresh()?->project_id);
    }

    public function test_move_to_other_writer_still_respects_target_capacity(): void
    {
        $this->bindWriterMonthlyCapacity(1);
        $source = $this->createMonthly(77601, 76601, '2026-08-01', 'Cap src');
        $target = $this->createMonthly(77602, 76601, '2026-08-01', 'Cap tgt full');
        $this->createTask($target, ['status' => SeoProjectTask::STATUS_PENDING]);
        $task = $this->createTask($source, ['status' => SeoProjectTask::STATUS_PENDING]);

        try {
            app(SeoProjectTaskMoveService::class)->moveTasksToProject(
                $source,
                $target,
                [(int) $task->id],
            );
            self::fail('Expected capacity rejection for target writer.');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors()['target_project_id'] ?? []);
        }

        self::assertSame((int) $source->id, (int) $task->fresh()?->project_id);
    }

    private function bindWriterMonthlyCapacity(int $capacity): void
    {
        $capacitySettings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $mem = new \ReflectionProperty($capacitySettings, 'inMemorySettings');
        $mem->setAccessible(true);
        $mem->setValue($capacitySettings, [
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => $capacity,
        ]);
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $capacitySettings);
        $this->app->forgetInstance(ContentProjectWriterMonthlyCapacityService::class);
        $this->app->forgetInstance(WriterMonthlyCapacityGate::class);
        try {
            cache()->forget('content_writer_monthly_capacity_settings.v1');
        } catch (\Throwable) {
            // ignore
        }
    }

    private function createMonthly(int $userId, int $siteId, string $month, string $name): SeoProject
    {
        return SeoProject::query()->create([
            'name' => $name.' '.uniqid('', true),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $month,
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTask(SeoProject $project, array $overrides = []): SeoProjectTask
    {
        $seq = ++$this->seq;

        return SeoProjectTask::query()->create(array_merge([
            'project_id' => (int) $project->id,
            'site_id' => (int) ($project->site_id ?? 0) ?: null,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'move-item-'.$seq,
            'keyword' => 'move-item-'.$seq,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
        ], $overrides));
    }
}
