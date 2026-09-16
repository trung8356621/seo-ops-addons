<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectConsumedIdea;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateConsumptionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentClusterAttributionValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningDataBackfillService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Tests\TestCase;

/**
 * DB lifecycle: tombstone, attribution, Site Planning units, chunked backfill.
 * Requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai.
 */
final class IdeaConsumptionAndSitePlanningLifecycleIntegrationTest extends TestCase
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

        foreach ([
            'seo_projects',
            'seo_project_tasks',
            'seo_content_project_consumed_ideas',
            'seo_content_project_task_planning_attributions',
            'seo_content_project_item_origins',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }
    }

    public function test_tombstone_survives_keyword_edit_and_task_delete(): void
    {
        $siteId = $this->uniqueSiteId();
        $keywordId = 700000 + $this->seq;
        $consumption = app(IdeaCandidateConsumptionService::class);

        $claim = $consumption->claim(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
            'original phrase',
        );
        self::assertTrue($claim['claimed']);

        $dup = $consumption->claim(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
            'edited phrase totally different',
        );
        self::assertFalse($dup['claimed']);

        self::assertTrue($consumption->isConsumed(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
        ));

        $project = $this->createDraft($siteId);
        $task = $this->createTask($project, $siteId, 'original phrase', '2026-09');
        $consumption->attachTask((int) $claim['consumed_idea_id'], (int) $task->id);

        $task->forceFill(['keyword' => 'brand new keyword text', 'title' => 'New title'])->save();
        self::assertTrue($consumption->isConsumed(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
        ));

        $task->delete();
        self::assertTrue(
            SeoContentProjectConsumedIdea::query()
                ->where('site_id', $siteId)
                ->where('source_keyword_id', $keywordId)
                ->exists(),
        );
    }

    public function test_transaction_rollback_removes_tombstone(): void
    {
        $siteId = $this->uniqueSiteId();
        $keywordId = 710000 + $this->seq;
        $consumption = app(IdeaCandidateConsumptionService::class);

        try {
            DB::connection('omi_seo_ai')->transaction(function () use ($consumption, $siteId, $keywordId): void {
                $claim = $consumption->claim(
                    $siteId,
                    SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
                    $keywordId,
                    'rollback phrase',
                );
                self::assertTrue($claim['claimed']);
                throw new \RuntimeException('force_rollback_after_claim');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('force_rollback_after_claim', $e->getMessage());
        }

        self::assertFalse($consumption->isConsumed(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
        ));
    }

    public function test_multi_cluster_attribution_and_site_planning_counts(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $project = $this->createDraft($siteId, $month);
        $writer = app(PlanningAttributionWriter::class);
        $validator = new NewContentClusterAttributionValidator;

        $noteItems = [
            ['cluster_ref' => 'clu_a', 'cluster_name_snapshot' => 'Cluster A'],
            ['cluster_ref' => 'clu_b', 'cluster_name_snapshot' => 'Cluster B'],
        ];
        $candidates = [
            [
                'keyword' => 'kw-a-'.$this->seq,
                'suggested_title' => 'Title A',
                'cluster_ref' => 'clu_a',
                'dna_phrases' => ['dna-a-only'],
            ],
            [
                'keyword' => 'kw-b-'.$this->seq,
                'suggested_title' => 'Title B',
                'cluster_ref' => 'clu_b',
                'dna_phrases' => ['dna-b-only'],
            ],
        ];
        $gate = $validator->filter($candidates, $noteItems);
        self::assertCount(2, $gate['accepted']);
        self::assertSame([], $gate['rejected']);

        $taskIds = [];
        foreach ($gate['accepted'] as $candidate) {
            $task = $this->createTask(
                $project,
                $siteId,
                (string) $candidate['keyword'],
                $month,
                (string) $candidate['suggested_title'],
            );
            $origin = SeoContentProjectItemOrigin::query()->create([
                'project_task_id' => (int) $task->id,
                'project_id' => (int) $project->id,
                'planner_run_id' => 1,
                'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
                'source_finding_ids' => [],
                'reason_codes' => [],
                'source_fingerprint' => 'fp-'.(int) $task->id,
            ]);
            $attr = $writer->writeForTask($task, $origin, [
                'planning_month' => $month,
                'planner_run_id' => 1,
                'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
                'cluster_ref' => (string) $candidate['cluster_ref'],
                'cluster_name_snapshot' => $candidate['cluster_ref'] === 'clu_a' ? 'Cluster A' : 'Cluster B',
                'dna_phrases' => $candidate['dna_phrases'],
                'allowed_cluster_refs' => ['clu_a', 'clu_b'],
            ]);
            self::assertNotNull($attr);
            self::assertSame(
                SeoContentProjectTaskPlanningAttribution::STATUS_ATTRIBUTED,
                $attr->attribution_status,
            );
            self::assertSame((string) $candidate['cluster_ref'], (string) $attr->cluster_ref);
            $taskIds[] = (int) $task->id;
        }

        // Unknown cluster must not persist attributed.
        $badTask = $this->createTask($project, $siteId, 'bad-ref', $month);
        $badOrigin = SeoContentProjectItemOrigin::query()->create([
            'project_task_id' => (int) $badTask->id,
            'project_id' => (int) $project->id,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'source_fingerprint' => 'fp-bad',
        ]);
        $badAttr = $writer->writeForTask($badTask, $badOrigin, [
            'planning_month' => $month,
            'cluster_ref' => 'invented_cluster',
            'dna_phrases' => ['should-drop'],
            'allowed_cluster_refs' => ['clu_a', 'clu_b'],
        ]);
        self::assertSame(
            SeoContentProjectTaskPlanningAttribution::STATUS_UNATTRIBUTED,
            $badAttr?->attribution_status,
        );
        self::assertNull($badAttr?->cluster_ref);

        $agg = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertGreaterThanOrEqual(2, $agg['planned']);

        $byRef = [];
        foreach ($agg['clusters'] as $cluster) {
            $byRef[$cluster['cluster_ref']] = $cluster;
        }
        self::assertArrayHasKey('clu_a', $byRef);
        self::assertArrayHasKey('clu_b', $byRef);
        self::assertSame(1, (int) $byRef['clu_a']['article_count']);
        self::assertSame(1, (int) $byRef['clu_b']['article_count']);
        self::assertSame(1, (int) $byRef['clu_a']['dna_planned']);
        self::assertSame(1, (int) $byRef['clu_b']['dna_planned']);

        $attrA = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', $taskIds[0])
            ->first();
        $attrB = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', $taskIds[1])
            ->first();
        self::assertSame(['dna-a-only'], $attrA?->dna_phrases);
        self::assertSame(['dna-b-only'], $attrB?->dna_phrases);
        self::assertNotContains('dna-a-only', $attrB?->dna_phrases ?? []);

        // Archive project → leave active planning.
        $project->forceFill(['archived_at' => now()])->save();
        $afterArchive = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(0, $afterArchive['planned']);
    }

    public function test_draft_to_execution_counts_as_one_planning_unit(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $draft = $this->createDraft($siteId, $month);
        $task = $this->createTask($draft, $siteId, 'unit-one', $month);
        $origin = SeoContentProjectItemOrigin::query()->create([
            'project_task_id' => (int) $task->id,
            'project_id' => (int) $draft->id,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'source_fingerprint' => 'fp-unit',
        ]);
        app(PlanningAttributionWriter::class)->writeForTask($task, $origin, [
            'planning_month' => $month,
            'cluster_ref' => 'clu_unit',
            'cluster_name_snapshot' => 'Unit',
            'dna_phrases' => ['angle'],
            'allowed_cluster_refs' => ['clu_unit'],
        ]);

        $before = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(1, $before['planned']);

        $execution = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'exec '.$this->seq,
            'month' => ContentProjectMonthContext::toDateString($month),
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 1,
            'source_draft_project_id' => (int) $draft->id,
        ]);
        $task->forceFill([
            'project_id' => (int) $execution->id,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'article_id' => 1,
        ])->save();

        $afterMove = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(1, $afterMove['planned']);

        $execution->forceFill(['archived_at' => now()])->save();
        $afterArchive = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(0, $afterArchive['planned']);
    }

    public function test_backfill_processes_beyond_5000_idempotently(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $this->fail('planning_month column missing');
        }

        $siteId = $this->uniqueSiteId();
        $project = $this->createDraft($siteId, '2026-01');
        $total = 5501;
        $now = now();
        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = [
                'project_id' => (int) $project->id,
                'site_id' => $siteId,
                'type' => SeoProjectTask::TYPE_CREATE,
                'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                'source_content' => 'bf-'.$this->seq.'-'.$i,
                'keyword' => 'bf-'.$this->seq.'-'.$i,
                'status' => SeoProjectTask::STATUS_PENDING,
                'planning_month' => null,
                'created_at' => '2026-03-10 12:00:00',
                'target_date' => '2026-04-15',
                'updated_at' => $now,
            ];
            if (count($rows) >= 500) {
                DB::connection('omi_seo_ai')->table('seo_project_tasks')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::connection('omi_seo_ai')->table('seo_project_tasks')->insert($rows);
        }

        $svc = new PlanningDataBackfillService;
        $first = $svc->backfillPlanningMonths(500);
        self::assertGreaterThanOrEqual($total, $first['updated']);
        self::assertGreaterThan(10, $first['chunks']);

        $remaining = DB::connection('omi_seo_ai')->table('seo_project_tasks')
            ->where('project_id', (int) $project->id)
            ->whereNull('planning_month')
            ->count();
        self::assertSame(0, $remaining);

        $sample = DB::connection('omi_seo_ai')->table('seo_project_tasks')
            ->where('project_id', (int) $project->id)
            ->value('planning_month');
        // Draft SSOT: created_at month (2026-03), not target_date (2026-04).
        self::assertSame(
            ContentProjectMonthContext::toDateString('2026-03'),
            (string) $sample,
        );
        self::assertSame('2026-03', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'created_at' => '2026-03-10 12:00:00',
            'target_date' => '2026-04-15',
            'project_is_draft' => true,
        ]));

        $second = $svc->backfillPlanningMonths(500);
        self::assertSame(0, $second['updated']);
    }

    public function test_claim_returns_machine_readable_status(): void
    {
        $siteId = $this->uniqueSiteId();
        $keywordId = 720000 + $this->seq;
        $consumption = app(IdeaCandidateConsumptionService::class);

        $first = $consumption->claim(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
            'status phrase',
        );
        self::assertTrue($first['claimed']);
        self::assertSame(IdeaCandidateConsumptionService::STATUS_CLAIMED, $first['status']);
        self::assertNull($first['reason']);

        $dup = $consumption->claim(
            $siteId,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
            $keywordId,
            'status phrase again',
        );
        self::assertFalse($dup['claimed']);
        self::assertSame(IdeaCandidateConsumptionService::STATUS_ALREADY_CONSUMED, $dup['status']);
        self::assertSame('duplicate', $dup['reason']);

        $invalid = $consumption->claim(0, 'vocabulary_suggest', 0, 'x');
        self::assertFalse($invalid['claimed']);
        self::assertSame(IdeaCandidateConsumptionService::STATUS_INVALID_INPUT, $invalid['status']);
    }

    public function test_attribution_and_dna_survive_split_move_same_task_id(): void
    {
        $siteId = $this->uniqueSiteId();
        $month = '2026-09';
        $draft = $this->createDraft($siteId, $month);
        $task = $this->createTask($draft, $siteId, 'split-attr', $month);
        $taskId = (int) $task->id;
        $origin = SeoContentProjectItemOrigin::query()->create([
            'project_task_id' => $taskId,
            'project_id' => (int) $draft->id,
            'planner_run_id' => 9,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'source_fingerprint' => 'fp-split-attr',
        ]);
        app(PlanningAttributionWriter::class)->writeForTask($task, $origin, [
            'planning_month' => $month,
            'planner_run_id' => 9,
            'cluster_ref' => 'clu_keep',
            'cluster_name_snapshot' => 'Keep',
            'dna_phrases' => ['dna-keep'],
            'allowed_cluster_refs' => ['clu_keep'],
        ]);

        $execution = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'exec-attr-'.$this->seq,
            'month' => ContentProjectMonthContext::toDateString('2026-10'),
            'status' => SeoProject::STATUS_PENDING,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 1,
            'source_draft_project_id' => (int) $draft->id,
        ]);

        // Simulate Split move: same task id, remap project_id + origin.
        $task->forceFill([
            'project_id' => (int) $execution->id,
            'target_date' => '2026-10-01',
            'status' => SeoProjectTask::STATUS_PENDING,
        ])->save();
        SeoContentProjectItemOrigin::query()
            ->where('project_task_id', $taskId)
            ->update(['project_id' => (int) $execution->id]);

        $attr = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', $taskId)
            ->first();
        self::assertNotNull($attr);
        self::assertSame('clu_keep', (string) $attr->cluster_ref);
        self::assertSame(['dna-keep'], $attr->dna_phrases);
        self::assertSame($month, (string) $attr->planning_month);
        self::assertSame(9, (int) $attr->planner_run_id);

        // Planning month SSOT on task — unit still counts in Sep Site Planning after Oct execution move.
        $inSep = app(SitePlanningActiveUnitAggregator::class)->forSiteMonth($siteId, $month);
        self::assertSame(1, $inSep['planned']);
        $byRef = [];
        foreach ($inSep['clusters'] as $cluster) {
            $byRef[$cluster['cluster_ref']] = $cluster;
        }
        self::assertArrayHasKey('clu_keep', $byRef);
        self::assertSame(1, (int) $byRef['clu_keep']['dna_planned']);
    }

    public function test_overflow_origin_matches_target_project_id(): void
    {
        $siteId = $this->uniqueSiteId();
        $draft1 = $this->createDraft($siteId, '2026-09');
        $draft2 = $this->createDraft($siteId, '2026-09');

        $task = $this->createTask($draft2, $siteId, 'overflow-kw', '2026-09');
        $origin = SeoContentProjectItemOrigin::query()->create([
            'project_task_id' => (int) $task->id,
            'project_id' => (int) $draft2->id,
            'planner_run_id' => 42,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'source_fingerprint' => 'fp-overflow',
        ]);

        self::assertSame((int) $task->project_id, (int) $origin->project_id);
        self::assertSame((int) $draft2->id, (int) $origin->project_id);
        self::assertNotSame((int) $draft1->id, (int) $origin->project_id);
        self::assertSame(42, (int) $origin->planner_run_id);
    }

    private function createTask(
        SeoProject $project,
        int $siteId,
        string $keyword,
        string $month = '2026-09',
        ?string $title = null,
    ): SeoProjectTask {
        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => $keyword,
            'keyword' => $keyword,
            'title' => $title,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => ContentProjectMonthContext::toDateString($month),
            'planning_month' => ContentProjectMonthContext::toDateString($month),
        ]);
    }

    private function createDraft(int $siteId, string $month = '2026-09'): SeoProject
    {
        $this->seq++;

        return SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'draft-lifecycle-'.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function uniqueSiteId(): int
    {
        $this->seq++;

        return 920000 + ($this->seq % 50000) + (int) (microtime(true) * 1000) % 1000;
    }
}
