<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Tests\TestCase;

/**
 * Integration: Global Legacy excluded only when `$excludeGlobalLegacy = true`.
 * Requires SEO_TEST_USE_MYSQL=true + disposable SEO_TEST_DATABASE (*_test).
 */
final class ProjectsListMonthlyPlanningGlobalLegacyExclusionIntegrationTest extends TestCase
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

        foreach (['seo_projects', 'seo_project_tasks'] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $this->fail('planning_month column missing');
        }
    }

    public function test_global_legacy_excluded_from_projects_list_path_only(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $legacy = $this->createLegacyProject($month);
        $this->createTask($legacy, $siteId, $month, 'legacy-shell');

        $default = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        $scoped = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month, true);

        self::assertSame(1, $default['planned'], 'Default historical distribution still counts Global Legacy');
        self::assertSame(0, $scoped['planned'], 'Projects-list path excludes Global Legacy');
        self::assertSame([], $scoped['task_ids']);
    }

    public function test_normal_archived_project_still_counts_on_both_paths(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $project = $this->createProject($siteId, $month);
        $this->createTask($project, $siteId, $month, 'normal-archived');
        $project->forceFill([
            'status' => SeoProject::STATUS_COMPLETED,
            'archived_at' => now(),
        ])->save();

        $default = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        $scoped = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month, true);

        self::assertSame(1, $default['planned']);
        self::assertSame(1, $scoped['planned'], 'Non-legacy archived must still count on Projects-list path');
    }

    public function test_active_planning_unaffected_on_both_paths(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $project = $this->createProject($siteId, $month);
        $this->createTask($project, $siteId, $month, 'active-plan');

        $default = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        $scoped = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month, true);

        self::assertSame(1, $default['planned']);
        self::assertSame(1, $scoped['planned']);
    }

    public function test_mixed_legacy_and_active_only_drops_legacy_on_scoped_path(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';

        $legacy = $this->createLegacyProject($month);
        $this->createTask($legacy, $siteId, $month, 'legacy-a');
        $this->createTask($legacy, $siteId, $month, 'legacy-b');

        $active = $this->createProject($siteId, $month);
        $kept = $this->createTask($active, $siteId, $month, 'keep-me');

        $default = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        $scoped = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month, true);

        self::assertSame(3, $default['planned']);
        self::assertSame(1, $scoped['planned']);
        self::assertSame([(int) $kept->id], $scoped['task_ids']);
    }

    private function createLegacyProject(string $month): SeoProject
    {
        $this->seq++;

        return SeoProject::query()->create([
            'name' => 'Legacy articles '.$this->seq,
            'user_id' => 1,
            'site_id' => null,
            'month' => ContentProjectMonthContext::toDateString($month),
            'status' => SeoProject::STATUS_COMPLETED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
            'archived_at' => now(),
            'archived_by' => 1,
            'meta' => [
                'import_source' => ContentProjectGlobalLegacyArchive::META_IMPORT_SOURCE,
                'import_version' => 1,
                'import_multi_domain' => true,
            ],
        ]);
    }

    private function createProject(int $siteId, string $month): SeoProject
    {
        $this->seq++;

        return SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'plan-test-'.$this->seq,
            'month' => ContentProjectMonthContext::toDateString($month),
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function createTask(SeoProject $project, int $siteId, string $month, string $keyword): SeoProjectTask
    {
        $this->seq++;

        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => $keyword.' '.$this->seq,
            'keyword' => $keyword.' '.$this->seq,
            'title' => 'Title '.$this->seq,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => ContentProjectMonthContext::toDateString($month),
            'planning_month' => ContentProjectMonthContext::toDateString($month),
        ]);
    }

    private function uniqueSiteId(): int
    {
        $this->seq++;

        return 940000 + ($this->seq % 50000) + (int) (microtime(true) * 1000) % 1000;
    }
}
