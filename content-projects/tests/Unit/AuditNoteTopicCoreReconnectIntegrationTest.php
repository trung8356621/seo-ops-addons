<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterService;
use Tests\TestCase;

/**
 * Runtime reconnect: Audit Notes suggestions ← Topic Core.
 * Requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai Topic tables.
 */
final class AuditNoteTopicCoreReconnectIntegrationTest extends TestCase
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

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_topic_keyword_dna')) {
            $this->fail('Missing seo_topic_keyword_dna.');
        }
    }

    public function test_paginate_returns_topics_with_modern_refs(): void
    {
        $siteId = $this->uniqueSiteId();
        $auto = $this->createTopic($siteId, 'Audit Auto Topic '.$this->seq, TopicSource::AUTO);
        $manual = $this->createTopic($siteId, 'Audit Manual Topic '.$this->seq, TopicSource::MANUAL);

        $result = app(AuditNoteClusterSuggestionQuery::class)->paginate($siteId, ['filter' => 'all']);
        self::assertGreaterThanOrEqual(2, $result['total']);

        $byId = [];
        foreach ($result['rows'] as $row) {
            $byId[(int) $row['topic_id']] = $row;
        }

        self::assertArrayHasKey((int) $auto->id, $byId);
        self::assertArrayHasKey((int) $manual->id, $byId);
        self::assertSame(TopicPlanningRef::encode((int) $auto->id), $byId[(int) $auto->id]['cluster_ref']);
        self::assertSame(TopicPlanningRef::encode((int) $manual->id), $byId[(int) $manual->id]['cluster_ref']);
        self::assertSame('Audit Manual Topic '.$this->seq, $byId[(int) $manual->id]['cluster_name']);
    }

    public function test_search_by_name_and_dna_returns_parent_topic(): void
    {
        $siteId = $this->uniqueSiteId();
        $name = 'Unique Balo Cong Doan '.$this->seq;
        $dnaPhrase = 'dna-phrase-unique-'.$this->seq;
        $topic = $this->createTopic($siteId, $name, TopicSource::AUTO);
        $this->attachDna($siteId, (int) $topic->id, $dnaPhrase);

        $query = app(AuditNoteClusterSuggestionQuery::class);

        $byName = $query->paginate($siteId, ['search' => $name]);
        self::assertSame(1, $byName['total']);
        self::assertSame((int) $topic->id, (int) $byName['rows'][0]['topic_id']);

        $byDna = $query->paginate($siteId, ['search' => $dnaPhrase]);
        self::assertGreaterThanOrEqual(1, $byDna['total']);
        $ids = array_map(static fn (array $r): int => (int) $r['topic_id'], $byDna['rows']);
        self::assertContains((int) $topic->id, $ids);
    }

    public function test_find_suggestion_hydrates_dna_and_rejects_cross_site(): void
    {
        $siteA = $this->uniqueSiteId();
        $siteB = $this->uniqueSiteId();
        $topic = $this->createTopic($siteA, 'Hydrate Topic '.$this->seq, TopicSource::AUTO);
        $this->attachDna($siteA, (int) $topic->id, 'hydrate-dna-a');
        $this->attachDna($siteA, (int) $topic->id, 'hydrate-dna-b');

        $ref = TopicPlanningRef::encode((int) $topic->id);
        $query = app(AuditNoteClusterSuggestionQuery::class);

        $found = $query->findSuggestion($siteA, $ref);
        self::assertNotNull($found);
        self::assertSame((int) $topic->id, (int) $found['topic_id']);
        self::assertSame($ref, $found['cluster_ref']);
        self::assertGreaterThanOrEqual(2, count($found['cluster_dna']));
        self::assertSame(['phrase', 'weight'], array_keys($found['cluster_dna'][0]));

        self::assertNull($query->findSuggestion($siteB, $ref));
        self::assertSame([], $query->dnaPhrasesForCluster($siteB, $ref));
    }

    public function test_filters_mcp_low_and_focus_compat(): void
    {
        $siteId = $this->uniqueSiteId();
        $this->createTopic($siteId, 'Filter Topic '.$this->seq, TopicSource::AUTO);

        $query = app(AuditNoteClusterSuggestionQuery::class);
        $all = $query->paginate($siteId, ['filter' => 'all']);
        self::assertGreaterThanOrEqual(1, $all['total']);

        $low = $query->paginate($siteId, ['filter' => 'mcp_low']);
        foreach ($low['rows'] as $row) {
            self::assertLessThan(5.0, (float) $row['mcp_share']);
        }

        $noFocus = $query->paginate($siteId, ['filter' => 'no_focus']);
        foreach ($noFocus['rows'] as $row) {
            self::assertFalse((bool) $row['has_focus_article']);
            self::assertSame(0, (int) $row['article_count']);
        }
    }

    public function test_exact_normalized_name_match_uses_modern_ref(): void
    {
        $siteId = $this->uniqueSiteId();
        $topic = $this->createTopic($siteId, 'Exact Match Topic '.$this->seq, TopicSource::AUTO);

        $matches = app(AuditNoteClusterSuggestionQuery::class)
            ->findExactNormalizedNameMatches($siteId, 'Exact Match Topic '.$this->seq);

        self::assertNotEmpty($matches);
        self::assertSame(TopicPlanningRef::encode((int) $topic->id), $matches[0]['cluster_ref']);
        self::assertSame('Exact Match Topic '.$this->seq, $matches[0]['cluster_name']);
    }

    public function test_planning_attribution_resolves_topic_name_for_modern_ref(): void
    {
        $siteId = $this->uniqueSiteId();
        $topic = $this->createTopic($siteId, 'Attr Name Topic '.$this->seq, TopicSource::AUTO);
        $ref = TopicPlanningRef::encode((int) $topic->id);
        $task = $this->createDraftTask($siteId);

        $attr = app(PlanningAttributionWriter::class)->writeForTask($task, null, [
            'planning_month' => '2026-09',
            'cluster_ref' => $ref,
            // omit cluster_name_snapshot on purpose
        ]);

        self::assertNotNull($attr);
        self::assertSame($ref, (string) $attr->cluster_ref);
        self::assertSame('Attr Name Topic '.$this->seq, (string) $attr->cluster_name_snapshot);
    }

    public function test_manual_seed_ref_still_falls_back_to_ref_string(): void
    {
        $siteId = $this->uniqueSiteId();
        $task = $this->createDraftTask($siteId);
        $manualRef = 'manual:seed-'.$this->seq;

        $attr = app(PlanningAttributionWriter::class)->writeForTask($task, null, [
            'planning_month' => '2026-09',
            'cluster_ref' => $manualRef,
        ]);

        self::assertNotNull($attr);
        self::assertSame($manualRef, (string) $attr->cluster_ref);
        self::assertSame($manualRef, (string) $attr->cluster_name_snapshot);
    }

    private function createDraftTask(int $siteId, string $month = '2026-09'): SeoProjectTask
    {
        $project = SeoProject::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'name' => 'audit-reconnect-'.$this->seq,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'source_content' => 'kw-'.$this->seq,
            'keyword' => 'kw-'.$this->seq,
            'title' => 'T '.$this->seq,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => ContentProjectMonthContext::toDateString($month),
            'planning_month' => ContentProjectMonthContext::toDateString($month),
        ]);
    }

    private function uniqueSiteId(): int
    {
        $this->seq++;

        return 880000 + $this->seq;
    }

    private function createTopic(int $siteId, string $name, string $source): SeoTopic
    {
        return SeoTopic::query()->create([
            'site_id' => $siteId,
            'name' => $name,
            'source' => $source,
            'status' => TopicStatus::ACTIVE,
            'is_locked' => false,
        ]);
    }

    private function attachDna(int $siteId, int $topicId, string $value): void
    {
        SeoTopicKeywordDna::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => 900000 + (++$this->seq),
            'value' => $value,
            'facet_type' => 'branch',
            'placement' => 'after',
            'frequency' => 1,
            'source' => 'test',
        ]);
    }
}
