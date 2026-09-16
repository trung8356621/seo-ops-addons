<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTopicHistory;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\TopicHistoryReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\TopicHistoryWriter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Tests\TestCase;

/**
 * Required scenarios: distribution count + Topic History write/read/idempotency.
 * Requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai (incl. topic_histories).
 */
final class SitePlanningDistributionAndTopicHistoryIntegrationTest extends TestCase
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

        foreach (['seo_projects', 'seo_project_tasks', 'seo_content_project_topic_histories'] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }
    }

    public function test_completed_published_archived_still_count_in_matrix(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-07';
        $project = $this->createProject($siteId, $month);

        for ($i = 0; $i < 5; $i++) {
            $this->createTask($project, $siteId, $month, 't-'.$i);
        }

        $agg = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(5, $agg['planned']);

        SeoProjectTask::query()
            ->where('project_id', (int) $project->id)
            ->update([
                'status' => SeoProjectTask::STATUS_COMPLETED,
                'completed_at' => now(),
                'publish_published_at' => now(),
            ]);
        $project->forceFill([
            'status' => SeoProject::STATUS_COMPLETED,
            'archived_at' => now(),
        ])->save();

        $after = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(5, $after['planned'], 'Completed/published/archived must remain in monthly distribution');
    }

    public function test_cancelled_and_soft_deleted_excluded(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-07';
        $project = $this->createProject($siteId, $month);

        for ($i = 0; $i < 5; $i++) {
            $this->createTask($project, $siteId, $month, 'ok-'.$i);
        }
        $cancelled = $this->createTask($project, $siteId, $month, 'cancel');
        $cancelled->forceFill(['status' => SeoProjectTask::STATUS_CANCELLED])->save();
        $deleted = $this->createTask($project, $siteId, $month, 'del');
        $deleted->delete();

        $agg = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(5, $agg['planned']);
    }

    public function test_topic_history_create_on_plan(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-08';
        $runId = 900000 + (++$this->seq);

        $rows = app(TopicHistoryWriter::class)->recordForPlannerRun($siteId, $month, $runId, [
            [
                'cluster_ref' => 'clu_balo_cong_doan',
                'cluster_name_snapshot' => 'BALO CÔNG ĐOÀN',
                'target_dna_count' => 9,
                'source_type' => 'cluster',
            ],
        ]);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($siteId, (int) $row->site_id);
        self::assertSame(
            ContentProjectMonthContext::toDateString($month),
            ContentProjectMonthContext::toDateString((string) $row->planning_month),
        );
        self::assertSame('clu_balo_cong_doan', (string) $row->topic_ref);
        self::assertSame('BALO CÔNG ĐOÀN', (string) $row->topic_name);
        self::assertSame(9, (int) $row->planned_article_count);
        self::assertSame($runId, (int) $row->planner_run_id);
    }

    public function test_topic_history_retry_idempotent(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-08';
        $runId = 900100 + (++$this->seq);
        $payload = [[
            'cluster_ref' => 'clu_balo_cong_doan',
            'cluster_name_snapshot' => 'BALO CÔNG ĐOÀN',
            'target_dna_count' => 9,
            'source_type' => 'cluster',
        ]];

        $writer = app(TopicHistoryWriter::class);
        $writer->recordForPlannerRun($siteId, $month, $runId, $payload);
        $writer->recordForPlannerRun($siteId, $month, $runId, $payload);

        $total = (int) SeoContentProjectTopicHistory::query()
            ->where('site_id', $siteId)
            ->where('planner_run_id', $runId)
            ->where('topic_ref', 'clu_balo_cong_doan')
            ->sum('planned_article_count');

        self::assertSame(9, $total);
        self::assertSame(1, SeoContentProjectTopicHistory::query()
            ->where('planner_run_id', $runId)
            ->where('topic_ref', 'clu_balo_cong_doan')
            ->count());
    }

    public function test_new_plan_same_topic_sums_in_month_detail(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-08';
        $ref = 'clu_balo_cong_doan_'.$this->seq;
        $writer = app(TopicHistoryWriter::class);

        $writer->recordForPlannerRun($siteId, $month, 910000 + (++$this->seq), [[
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => 'BALO CÔNG ĐOÀN',
            'target_dna_count' => 9,
            'source_type' => 'cluster',
        ]]);
        $writer->recordForPlannerRun($siteId, $month, 910000 + (++$this->seq), [[
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => 'BALO CÔNG ĐOÀN',
            'target_dna_count' => 3,
            'source_type' => 'cluster',
        ]]);

        $detail = app(SitePlanningReadModel::class)->cellDetail($siteId, $month);
        $byRef = [];
        foreach ($detail['topics'] as $topic) {
            $byRef[(string) ($topic['topic_ref'] ?? '')] = (int) ($topic['planned_article_count'] ?? 0);
        }
        self::assertSame(12, $byRef[$ref] ?? 0);
    }

    public function test_legacy_without_history_month_detail_empty(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-07';
        $project = $this->createProject($siteId, $month);
        $this->createTask($project, $siteId, $month, 'legacy');

        $detail = app(SitePlanningReadModel::class)->cellDetail($siteId, $month);
        self::assertSame([], $detail['topics']);
        self::assertArrayNotHasKey('groups', $detail);
        self::assertArrayNotHasKey('unattributed', $detail);
    }

    public function test_suggest_notes_planned_history_overlay(): void
    {
        $siteId = $this->uniqueSiteId();
        $ref = 'clu_suggest_'.$this->seq;
        app(TopicHistoryWriter::class)->recordForPlannerRun($siteId, '2026-08', 920000 + (++$this->seq), [[
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => 'Balo laptop',
            'target_dna_count' => 5,
            'source_type' => 'cluster',
        ]]);
        app(TopicHistoryWriter::class)->recordForPlannerRun($siteId, '2026-09', 920000 + (++$this->seq), [[
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => 'Balo laptop',
            'target_dna_count' => 7,
            'source_type' => 'cluster',
        ]]);

        $counts = app(TopicHistoryReadModel::class)->plannedCountsByTopicRef($siteId);
        self::assertSame(12, $counts[$ref] ?? 0);
    }

    private function createProject(int $siteId, string $month): SeoProject
    {
        $this->seq++;

        return SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'dist-test-'.$this->seq,
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

        return 930000 + ($this->seq % 50000) + (int) (microtime(true) * 1000) % 1000;
    }
}
