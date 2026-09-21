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
 * Within-month move: any eligible execution project; task.site_id preserved.
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

    public function test_target_options_include_cross_site_and_cross_writer_same_month(): void
    {
        $source = $this->createMonthly(77201, null, '2026-08-01', 'Src');
        $this->createTask($source, ['site_id' => 7]);

        $sameWriter = $this->createMonthly(77201, null, '2026-08-01', 'Same writer');
        $otherWriterOtherSite = $this->createMonthly(77202, null, '2026-08-01', 'Other writer site8');
        $this->createTask($otherWriterOtherSite, ['site_id' => 8]);
        $legacySite = $this->createMonthly(77203, 99, '2026-08-01', 'Legacy site99');

        $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source);
        $ids = array_map('intval', array_keys($options));

        self::assertContains((int) $sameWriter->id, $ids);
        self::assertContains((int) $otherWriterOtherSite->id, $ids);
        self::assertContains((int) $legacySite->id, $ids);
        self::assertNotContains((int) $source->id, $ids);
    }

    public function test_target_options_exclude_other_month_draft_archive_source(): void
    {
        $source = $this->createMonthly(77211, null, '2026-08-01', 'Src filters');
        $this->createTask($source, ['site_id' => 7]);

        $otherMonth = $this->createMonthly(77212, null, '2026-09-01', 'Sep');
        $draft = SeoProject::query()->create([
            'name' => 'Draft '.$this->seq,
            'user_id' => 77213,
            'site_id' => null,
            'month' => '2026-08-01',
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        $archived = $this->createMonthly(77214, null, '2026-08-01', 'Archived');
        $archived->forceFill(['archived_at' => now()])->save();

        $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source);
        $ids = array_map('intval', array_keys($options));

        self::assertNotContains((int) $source->id, $ids);
        self::assertNotContains((int) $otherMonth->id, $ids);
        self::assertNotContains((int) $draft->id, $ids);
        self::assertNotContains((int) $archived->id, $ids);
    }

    public function test_cross_writer_move_preserves_site_status_article(): void
    {
        $source = $this->createMonthly(77301, null, '2026-08-01', 'Move src');
        $target = $this->createMonthly(77302, null, '2026-08-01', 'Move tgt');
        $this->createTask($target, ['site_id' => 8]);

        $task = $this->createTask($source, [
            'site_id' => 7,
            'status' => SeoProjectTask::STATUS_REVIEWING,
            'article_id' => 908877,
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
        self::assertSame(7, (int) $fresh->site_id);
        self::assertSame(SeoProjectTask::STATUS_REVIEWING, (string) $fresh->status);
        self::assertSame(908877, (int) $fresh->article_id);
        self::assertNotNull($fresh->planning_reviewed_at);
    }

    public function test_move_rejects_other_month(): void
    {
        $source = $this->createMonthly(77501, null, '2026-08-01', 'Reject src');
        $otherMonth = $this->createMonthly(77502, null, '2026-09-01', 'Reject Sep');
        $task = $this->createTask($source, ['site_id' => 7, 'status' => SeoProjectTask::STATUS_PENDING]);

        try {
            app(SeoProjectTaskMoveService::class)->moveTasksToProject(
                $source,
                $otherMonth,
                [(int) $task->id],
            );
            self::fail('Expected month mismatch validation.');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors()['target_project_id'] ?? []);
        }

        self::assertSame((int) $source->id, (int) $task->fresh()?->project_id);
        self::assertSame(7, (int) $task->fresh()?->site_id);
    }

    public function test_move_to_other_writer_still_respects_target_capacity(): void
    {
        $this->bindWriterMonthlyCapacity(1);
        $source = $this->createMonthly(77601, null, '2026-08-01', 'Cap src');
        $target = $this->createMonthly(77602, null, '2026-08-01', 'Cap tgt full');
        $this->createTask($target, ['site_id' => 8, 'status' => SeoProjectTask::STATUS_PENDING]);
        $task = $this->createTask($source, ['site_id' => 7, 'status' => SeoProjectTask::STATUS_PENDING]);

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
        self::assertSame(7, (int) $task->fresh()?->site_id);
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

    private function createMonthly(int $userId, ?int $siteId, string $month, string $name): SeoProject
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
            'site_id' => (int) ($project->site_id ?? 0) ?: 1,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'move-item-'.$seq,
            'keyword' => 'move-item-'.$seq,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
        ], $overrides));
    }
}
