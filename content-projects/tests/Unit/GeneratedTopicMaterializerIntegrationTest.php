<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\GeneratedTopicMaterializer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicDnaExtractor;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipReconcileService;
use RuntimeException;
use Tests\TestCase;

/**
 * PR4 correctness — real DB proofs for GeneratedTopicMaterializer + SplitDraft hook.
 *
 * Requires SEO_TEST_USE_MYSQL=true + disposable SEO_TEST_DATABASE (*_test) + Topic Core tables.
 */
final class GeneratedTopicMaterializerIntegrationTest extends TestCase
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

        if (! TopicReclusterService::tablesReady()) {
            $this->fail('Topic Core tables missing on omi_seo_ai.');
        }

        foreach ([
            'seo_projects',
            'seo_project_tasks',
            'seo_content_project_task_planning_attributions',
            'seo_content_project_item_origins',
            'seo_topic_keywords',
            'seo_topic_keyword_dna',
            'keywords',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->fail('Missing required table: '.$table);
            }
        }

        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            $this->fail('planning_reviewed_at column missing — required for SplitDraft.');
        }

        $this->bindWriterMonthlyCapacity(30);
    }

    public function test_successful_materialization_via_split_rewrites_candidate_to_topic(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));

        $siteId = $this->uniqueSiteId();
        $candidateKey = 'g'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
        $topicName = 'PR4 Success Topic '.$this->seq;
        $dnaPhrase = 'dna-success-'.$this->seq;
        $keyword = 'kw-success-'.$this->seq;

        $draft = $this->createDraft($siteId);
        $task = $this->createAttributedGeneratedTask(
            $draft,
            $siteId,
            $candidateKey,
            $topicName,
            $keyword,
            [$dnaPhrase],
            reviewed: true,
        );

        $beforeTopics = SeoTopic::query()->where('site_id', $siteId)->count();

        $result = app(SplitDraftContentProjectService::class)->split(
            $draft,
            SplitDraftContentProjectCommand::MODE_ALL,
            null,
            [],
            null,
            [881401],
        );

        self::assertSame(1, (int) ($result['moved_count'] ?? 0));
        self::assertArrayHasKey($candidateKey, $result['generated_topic_mapping'] ?? []);
        $topicId = (int) $result['generated_topic_mapping'][$candidateKey];
        self::assertGreaterThan(0, $topicId);

        self::assertSame($beforeTopics + 1, SeoTopic::query()->where('site_id', $siteId)->count());
        $topic = SeoTopic::query()->where('site_id', $siteId)->where('id', $topicId)->first();
        self::assertInstanceOf(SeoTopic::class, $topic);
        self::assertSame($topicName, (string) $topic->name);
        self::assertSame($siteId, (int) $topic->site_id);

        $membershipCount = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->count();
        self::assertGreaterThanOrEqual(1, $membershipCount);

        $dnaCount = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->count();
        self::assertGreaterThanOrEqual(1, $dnaCount);

        $attr = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', (int) $task->id)
            ->first();
        self::assertNotNull($attr);
        self::assertSame(TopicPlanningRef::encode($topicId), (string) $attr->cluster_ref);
        self::assertFalse(AuditNoteDnaNormalizer::isGeneratedRef((string) $attr->cluster_ref));
        self::assertSame($topicName, (string) $attr->cluster_name_snapshot);

        $freshTask = SeoProjectTask::query()->find((int) $task->id);
        self::assertInstanceOf(SeoProjectTask::class, $freshTask);
        self::assertSame($keyword, (string) $freshTask->keyword);
        self::assertNotSame((int) $draft->id, (int) $freshTask->project_id);

        self::assertSame(
            0,
            SeoContentProjectTaskPlanningAttribution::query()
                ->where('site_id', $siteId)
                ->where('cluster_ref', AuditNoteDnaNormalizer::generatedRef($candidateKey))
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function test_rollback_after_topic_create_leaves_generated_state_retryable(): void
    {
        $siteId = $this->uniqueSiteId();
        $candidateKey = 'g'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
        $topicName = 'PR4 Rollback Topic '.$this->seq;
        $dnaPhrase = 'dna-rollback-'.$this->seq;
        $keyword = 'kw-rollback-'.$this->seq;

        $draft = $this->createDraft($siteId);
        $task = $this->createAttributedGeneratedTask(
            $draft,
            $siteId,
            $candidateKey,
            $topicName,
            $keyword,
            [$dnaPhrase],
            reviewed: true,
        );

        $topicsBefore = SeoTopic::query()->where('site_id', $siteId)->where('name', $topicName)->count();
        $membershipBefore = SeoTopicKeyword::query()->where('site_id', $siteId)->count();
        $dnaBefore = SeoTopicKeywordDna::query()->where('site_id', $siteId)->count();

        $throwingDna = new class(app(TopicDnaExtractor::class)) extends TopicDnaService
        {
            public function rebuildForTopic(int $siteId, int $topicId, string $topicName, array $keywordIds): int
            {
                throw new RuntimeException('force_fail_after_topic_and_membership');
            }
        };

        $materializer = new GeneratedTopicMaterializer(
            dna: $throwingDna,
            reconcile: app(TopicMembershipReconcileService::class),
        );

        try {
            DB::connection('omi_seo_ai')->transaction(function () use ($materializer, $siteId, $task): void {
                $materializer->materializeForTasks($siteId, [(int) $task->id]);
            });
            self::fail('Expected RuntimeException from DNA rebuild.');
        } catch (RuntimeException $e) {
            self::assertSame('force_fail_after_topic_and_membership', $e->getMessage());
        }

        self::assertSame(
            $topicsBefore,
            SeoTopic::query()->where('site_id', $siteId)->where('name', $topicName)->count(),
            'Topic must roll back',
        );
        self::assertSame(
            $membershipBefore,
            SeoTopicKeyword::query()->where('site_id', $siteId)->count(),
            'Membership must roll back',
        );
        self::assertSame(
            $dnaBefore,
            SeoTopicKeywordDna::query()->where('site_id', $siteId)->count(),
            'DNA rows must roll back',
        );

        $attr = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', (int) $task->id)
            ->first();
        self::assertNotNull($attr);
        self::assertSame(
            AuditNoteDnaNormalizer::generatedRef($candidateKey),
            (string) $attr->cluster_ref,
            'generated attribution must remain retryable',
        );

        $freshTask = SeoProjectTask::query()->find((int) $task->id);
        self::assertInstanceOf(SeoProjectTask::class, $freshTask);
        self::assertSame((int) $draft->id, (int) $freshTask->project_id);
        self::assertSame($keyword, (string) $freshTask->keyword);
    }

    public function test_materialize_twice_is_idempotent(): void
    {
        $siteId = $this->uniqueSiteId();
        $candidateKey = 'g'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
        $topicName = 'PR4 Idempotent Topic '.$this->seq;
        $dnaPhrase = 'dna-idem-'.$this->seq;
        $keyword = 'kw-idem-'.$this->seq;

        $draft = $this->createDraft($siteId);
        $task = $this->createAttributedGeneratedTask(
            $draft,
            $siteId,
            $candidateKey,
            $topicName,
            $keyword,
            [$dnaPhrase],
            reviewed: true,
        );

        $materializer = app(GeneratedTopicMaterializer::class);

        $first = DB::connection('omi_seo_ai')->transaction(function () use ($materializer, $siteId, $task): array {
            return $materializer->materializeForTasks($siteId, [(int) $task->id]);
        });
        self::assertCount(1, $first['materialized']);
        $topicId = (int) $first['mapping'][$candidateKey];
        self::assertGreaterThan(0, $topicId);

        $topicsAfterFirst = SeoTopic::query()->where('site_id', $siteId)->where('name', $topicName)->count();
        $membershipAfterFirst = SeoTopicKeyword::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->count();
        $dnaAfterFirst = SeoTopicKeywordDna::query()
            ->where('site_id', $siteId)
            ->where('topic_id', $topicId)
            ->count();

        $second = DB::connection('omi_seo_ai')->transaction(function () use ($materializer, $siteId, $task): array {
            return $materializer->materializeForTasks($siteId, [(int) $task->id]);
        });
        self::assertSame([], $second['materialized']);
        self::assertSame([], $second['mapping']);

        self::assertSame(
            $topicsAfterFirst,
            SeoTopic::query()->where('site_id', $siteId)->where('name', $topicName)->count(),
        );
        self::assertSame(
            $membershipAfterFirst,
            SeoTopicKeyword::query()->where('site_id', $siteId)->where('topic_id', $topicId)->count(),
        );
        self::assertSame(
            $dnaAfterFirst,
            SeoTopicKeywordDna::query()->where('site_id', $siteId)->where('topic_id', $topicId)->count(),
        );

        $attr = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', (int) $task->id)
            ->first();
        self::assertSame(TopicPlanningRef::encode($topicId), (string) $attr?->cluster_ref);
    }

    public function test_same_candidate_key_across_drafts_does_not_cross_rewrite(): void
    {
        $siteId = $this->uniqueSiteId();
        $sharedKey = 'g'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());

        $draftA = $this->createDraft($siteId);
        $draftB = $this->createDraft($siteId);

        $taskA = $this->createAttributedGeneratedTask(
            $draftA,
            $siteId,
            $sharedKey,
            'Workspace A Topic '.$this->seq,
            'kw-a-'.$this->seq,
            ['dna-a-'.$this->seq],
            reviewed: true,
        );
        $taskB = $this->createAttributedGeneratedTask(
            $draftB,
            $siteId,
            $sharedKey,
            'Workspace B Topic '.$this->seq,
            'kw-b-'.$this->seq,
            ['dna-b-'.$this->seq],
            reviewed: true,
        );

        $result = app(GeneratedTopicMaterializer::class)->materializeForTasks($siteId, [(int) $taskA->id]);
        self::assertCount(1, $result['materialized']);
        $topicIdA = (int) $result['mapping'][$sharedKey];

        $attrA = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', (int) $taskA->id)
            ->first();
        $attrB = SeoContentProjectTaskPlanningAttribution::query()
            ->where('project_task_id', (int) $taskB->id)
            ->first();

        self::assertSame(TopicPlanningRef::encode($topicIdA), (string) $attrA?->cluster_ref);
        self::assertSame(
            AuditNoteDnaNormalizer::generatedRef($sharedKey),
            (string) $attrB?->cluster_ref,
            'Draft B must keep generated attribution for the same candidate_key',
        );

        self::assertSame(1, SeoTopic::query()->where('site_id', $siteId)->where('id', $topicIdA)->count());
        self::assertSame(
            0,
            SeoTopic::query()
                ->where('site_id', $siteId)
                ->where('name', 'Workspace B Topic '.$this->seq)
                ->count(),
        );
    }

    /**
     * @param  list<string>  $dnaPhrases
     */
    private function createAttributedGeneratedTask(
        SeoProject $draft,
        int $siteId,
        string $candidateKey,
        string $topicName,
        string $keyword,
        array $dnaPhrases,
        bool $reviewed = true,
    ): SeoProjectTask {
        $month = '2026-09';
        $task = SeoProjectTask::query()->create([
            'project_id' => (int) $draft->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => $keyword,
            'keyword' => $keyword,
            'title' => 'Title '.$keyword,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => ContentProjectMonthContext::toDateString($month),
            'planning_month' => ContentProjectMonthContext::toDateString($month),
            'planning_reviewed_at' => $reviewed ? now() : null,
        ]);

        $origin = SeoContentProjectItemOrigin::query()->create([
            'project_task_id' => (int) $task->id,
            'project_id' => (int) $draft->id,
            'planner_run_id' => null,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'source_finding_ids' => [],
            'reason_codes' => ['ai_new_content'],
            'source_fingerprint' => 'fp-'.$keyword,
        ]);

        $ref = AuditNoteDnaNormalizer::generatedRef($candidateKey);
        $writer = app(PlanningAttributionWriter::class);
        $attr = $writer->writeForTask($task, $origin, [
            'planning_month' => $month,
            'source_type' => SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $topicName,
            'dna_phrases' => $dnaPhrases,
            'allowed_cluster_refs' => [$ref],
        ]);
        self::assertNotNull($attr);
        self::assertSame($ref, (string) $attr->cluster_ref);

        return $task;
    }

    private function createDraft(int $siteId): SeoProject
    {
        $this->seq++;

        return SeoProject::query()->create([
            'name' => 'Draft PR4 '.$siteId.' '.$this->seq,
            'user_id' => 940000 + ($this->seq % 1000),
            'site_id' => $siteId,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function uniqueSiteId(): int
    {
        $this->seq++;

        return 930000 + ($this->seq % 50000) + ((int) (microtime(true) * 1000) % 1000);
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
        $this->app->forgetInstance(\Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService::class);
        try {
            cache()->forget('content_writer_monthly_capacity_settings.v1');
        } catch (\Throwable) {
            // ignore
        }
    }
}
