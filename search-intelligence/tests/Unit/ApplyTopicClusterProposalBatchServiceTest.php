<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalBatchService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberStateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchMode;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalBatchStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterApplySideEffects;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterClusterKeyGenerator;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class ApplyTopicClusterProposalBatchServiceTest extends TestCase
{
    private const SITE_A = 50;

    private const SITE_B = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_selected_ready_happy_path_applies_three_clusters_atomically(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị batch a1', 'túi vải siêu thị batch a2', 'túi vải siêu thị batch a3']);
        $this->seedProposalFamily(['túi vải quảng cáo batch b1', 'túi vải quảng cáo batch b2', 'túi vải quảng cáo batch b3']);
        $this->seedProposalFamily(['túi vải in logo batch c1', 'túi vải in logo batch c2', 'túi vải in logo batch c3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters, 3);
        if (count($ready) < 3) {
            self::markTestSkipped('Need at least 3 READY clusters for batch happy path.');
        }

        $picked = array_slice($ready, 0, 3);
        $fingerprints = array_map(static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint, $picked);
        $expectedMembers = [];
        foreach ($picked as $cluster) {
            foreach ($this->clusterMemberIds($cluster) as $id) {
                $expectedMembers[$id] = true;
            }
        }

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: $fingerprints,
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::APPLIED, $result->status);
        self::assertSame(3, $result->proposalCount);
        self::assertSame(count($expectedMembers), $result->keywordCount);
        self::assertCount(3, array_unique($result->clusterKeys));

        foreach (array_keys($expectedMembers) as $keywordId) {
            self::assertNotNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_all_ready_excludes_needs_review(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị all a1', 'túi vải siêu thị all a2', 'túi vải siêu thị all a3']);
        $this->seedProposalFamily(['túi vải quảng cáo all b1', 'túi vải quảng cáo all b2', 'túi vải quảng cáo all b3']);
        $this->seedNeedsReviewFamily();

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters);
        $review = $this->reviewClusters($preview->proposedClusters);
        if ($ready === [] || $review === []) {
            self::markTestSkipped('Need READY and NEEDS_REVIEW clusters.');
        }

        $reviewMemberIds = [];
        foreach ($review as $cluster) {
            foreach ($this->clusterMemberIds($cluster) as $id) {
                $reviewMemberIds[$id] = true;
            }
        }

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::ALL_READY,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame(count($ready), $result->proposalCount);

        foreach (array_keys($reviewMemberIds) as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_selected_needs_review_is_rejected(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị reject a1', 'túi vải siêu thị reject a2', 'túi vải siêu thị reject a3']);
        $this->seedNeedsReviewFamily();

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->firstReadyCluster($preview->proposedClusters);
        $review = $this->firstReviewCluster($preview->proposedClusters);
        if ($ready === null || $review === null) {
            self::markTestSkipped('Need READY and NEEDS_REVIEW clusters.');
        }

        $before = $this->countClusteredMembers($preview);

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: [$ready->proposalFingerprint, $review->proposalFingerprint],
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::INVALID_SELECTION, $result->status);
        self::assertSame(0, $this->countClusteredMembers($preview));
    }

    public function test_one_stale_member_invalidates_entire_batch(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị stale b1', 'túi vải siêu thị stale b2', 'túi vải siêu thị stale b3']);
        $this->seedProposalFamily(['túi vải quảng cáo stale c1', 'túi vải quảng cáo stale c2', 'túi vải quảng cáo stale c3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters, 2);
        if (count($ready) < 2) {
            self::markTestSkipped('Need 2 READY clusters.');
        }

        $memberIds = $this->clusterMemberIds($ready[0]);
        SeoKeywordClassification::query()->whereKey($memberIds[0])->update([
            'classification_hash' => hash('sha256', 'batch-stale-member'),
        ]);

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: array_map(
                static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint,
                $ready,
            ),
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::STALE, $result->status);
        self::assertSame(0, $this->countClusteredMembers($preview));
    }

    public function test_one_occupied_member_invalidates_entire_batch(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị occ b1', 'túi vải siêu thị occ b2', 'túi vải siêu thị occ b3']);
        $this->seedProposalFamily(['túi vải quảng cáo occ c1', 'túi vải quảng cáo occ c2', 'túi vải quảng cáo occ c3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters, 2);
        if (count($ready) < 2) {
            self::markTestSkipped('Need 2 READY clusters.');
        }

        $memberIds = $this->clusterMemberIds($ready[0]);
        SeoKeywordClassification::query()->whereKey($memberIds[0])->update(['cluster_key' => 'occupied_elsewhere']);

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: array_map(
                static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint,
                $ready,
            ),
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::STALE, $result->status);
        foreach ($this->clusterMemberIds($ready[1]) as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
        foreach (array_slice($this->clusterMemberIds($ready[0]), 1) as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
        self::assertSame('occupied_elsewhere', $this->classificationClusterKey($memberIds[0]));
    }

    public function test_cross_site_fingerprint_in_selection_fails_closed(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị site a1', 'túi vải siêu thị site a2', 'túi vải siêu thị site a3']);
        $foreign = $this->seedCandidate(self::SITE_B, 'foreign batch keyword');

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->firstReadyCluster($preview->proposedClusters);
        self::assertNotNull($ready);

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: [$ready->proposalFingerprint, hash('sha256', 'fake-foreign')],
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::INVALID_SELECTION, $result->status);
        self::assertNull($this->classificationClusterKey((int) $foreign->id));
        foreach ($this->clusterMemberIds($ready) as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_idempotent_batch_retry_returns_already_applied(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị idem a1', 'túi vải siêu thị idem a2', 'túi vải siêu thị idem a3']);
        $this->seedProposalFamily(['túi vải quảng cáo idem b1', 'túi vải quảng cáo idem b2', 'túi vải quảng cáo idem b3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters, 2);
        if (count($ready) < 2) {
            self::markTestSkipped('Need 2 READY clusters.');
        }

        $input = new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: array_map(
                static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint,
                $ready,
            ),
        );

        self::assertSame(ApplyTopicClusterProposalBatchStatus::APPLIED, $this->service()->apply($input)->status);
        self::assertSame(ApplyTopicClusterProposalBatchStatus::ALREADY_APPLIED, $this->service()->apply($input)->status);
    }

    public function test_partial_already_applied_batch_returns_conflict(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị partial a1', 'túi vải siêu thị partial a2', 'túi vải siêu thị partial a3']);
        $this->seedProposalFamily(['túi vải quảng cáo partial b1', 'túi vải quảng cáo partial b2', 'túi vải quảng cáo partial b3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $ready = $this->readyClusters($preview->proposedClusters, 2);
        if (count($ready) < 2) {
            self::markTestSkipped('Need 2 READY clusters.');
        }

        $single = new ApplyTopicClusterProposalInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            proposalFingerprint: $ready[0]->proposalFingerprint,
            memberKeywordIds: $this->clusterMemberIds($ready[0]),
            representativeKeywordId: $ready[0]->representativeKeywordId,
            representativeLabel: $ready[0]->representativeLabel,
            finalStatus: $ready[0]->finalStatus,
            qualityState: $ready[0]->quality?->qualityState ?? KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
        );
        $this->singleApplyService()->apply($single);

        $batch = new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::SELECTED,
            selectedProposalFingerprints: array_map(
                static fn (KeywordClusterProposalCluster $c): string => $c->proposalFingerprint,
                $ready,
            ),
        );

        self::assertSame(ApplyTopicClusterProposalBatchStatus::CONFLICT, $this->service()->apply($batch)->status);
        foreach ($this->clusterMemberIds($ready[1]) as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_preview_fingerprint_mismatch_is_stale_for_all_ready(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị old all a1', 'túi vải siêu thị old all a2', 'túi vải siêu thị old all a3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: hash('sha256', 'stale-preview-batch'),
            mode: ApplyTopicClusterProposalBatchMode::ALL_READY,
        ));

        self::assertSame(ApplyTopicClusterProposalBatchStatus::STALE, $result->status);
        self::assertSame(0, $this->countClusteredMembers($preview));
    }

    public function test_same_representative_different_member_sets_get_distinct_keys(): void
    {
        $generator = app(TopicClusterClusterKeyGenerator::class);
        $left = $generator->generate(self::SITE_A, 'Túi vải quảng cáo', [1, 2, 3]);
        $right = $generator->generate(self::SITE_A, 'Túi vải quảng cáo', [4, 5, 6], [$left => true]);

        self::assertNotSame($left, $right);
    }

    public function test_batch_applies_exactly_snapshot_ready_count_not_recursive(): void
    {
        $this->seedProposalFamily(['túi vải siêu thị snap a1', 'túi vải siêu thị snap a2', 'túi vải siêu thị snap a3']);
        $this->seedProposalFamily(['túi vải quảng cáo snap b1', 'túi vải quảng cáo snap b2', 'túi vải quảng cáo snap b3']);

        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $readyCount = count($this->readyClusters($preview->proposedClusters));
        if ($readyCount < 1) {
            self::markTestSkipped('Need READY clusters.');
        }

        $expectedKeywords = 0;
        foreach ($this->readyClusters($preview->proposedClusters) as $cluster) {
            $expectedKeywords += count($this->clusterMemberIds($cluster));
        }

        $result = $this->service()->apply(new ApplyTopicClusterProposalBatchInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $preview->previewFingerprint,
            mode: ApplyTopicClusterProposalBatchMode::ALL_READY,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame($readyCount, $result->proposalCount);
        self::assertSame($expectedKeywords, $result->keywordCount);
        self::assertSame($expectedKeywords, $this->countClusteredMembers($preview));

        $afterPreview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $newReady = count($this->readyClusters($afterPreview->proposedClusters));
        if ($newReady > 0) {
            self::assertLessThan($readyCount + $newReady, $this->countClusteredMembers($afterPreview));
        }
    }

    private function service(): ApplyTopicClusterProposalBatchService
    {
        return new class(
            app(KeywordClusterProposalEngine::class),
            app(TopicClusterProposalMemberStateLoader::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalFingerprint::class),
            app(TopicClusterClusterKeyGenerator::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility::class),
            app(KeywordClusterQuery::class),
            app(TopicClusterApplySideEffects::class),
        ) extends ApplyTopicClusterProposalBatchService {
            protected function isAuthorized(int $siteId): bool
            {
                return $siteId > 0;
            }
        };
    }

    private function singleApplyService(): ApplyTopicClusterProposalService
    {
        return new class(
            app(KeywordClusterProposalEngine::class),
            app(TopicClusterProposalMemberStateLoader::class),
            app(TopicClusterClusterKeyGenerator::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility::class),
            app(KeywordClusterQuery::class),
            app(TopicClusterApplySideEffects::class),
        ) extends ApplyTopicClusterProposalService {
            protected function isAuthorized(int $siteId): bool
            {
                return $siteId > 0;
            }
        };
    }

    /**
     * @param  list<string>  $phrases
     */
    private function seedProposalFamily(array $phrases): void
    {
        foreach ($phrases as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }
    }

    private function seedNeedsReviewFamily(): void
    {
        $this->seedProposalFamily([
            'túi vải không dệt nr alpha',
            'túi vải không dệt nr beta',
            'túi vải không dệt nr gamma',
            'túi vải không dệt nr delta',
            'túi vải không dệt nr epsilon',
            'túi vải không dệt nr zeta',
            'túi vải không dệt nr eta',
            'túi vải không dệt nr theta',
            'túi vải không dệt nr iota',
            'túi canvas outlier nr keyword',
        ]);
    }

    private function seedCandidate(int $siteId, string $phrase): Keyword
    {
        $norm = app(KeywordNormalizer::class)->normalize($phrase);
        $articleId = $this->createArticle($siteId, 'Article for '.$phrase);
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => $norm['normalized_text'],
            'folded_text' => $norm['folded_text'],
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => null,
            'is_seo_keyword' => true,
            'is_ambiguous' => false,
            'keyword_score' => 0.8,
            'input_hash' => hash('sha256', $norm['normalized_text'].'|'),
            'classification_hash' => hash('sha256', 'keyword_phrase|commercial|'.$norm['folded_text']),
            'classified_at' => now(),
        ]);

        return $keyword;
    }

    /**
     * @param  list<KeywordClusterProposalCluster>  $clusters
     * @return list<KeywordClusterProposalCluster>
     */
    private function readyClusters(array $clusters, ?int $limit = null): array
    {
        $ready = [];
        foreach ($clusters as $cluster) {
            if ($cluster->finalStatus === KeywordClusterProposalCluster::FINAL_READY && $cluster->proposalFingerprint !== '') {
                $ready[] = $cluster;
            }
        }

        return $limit === null ? $ready : array_slice($ready, 0, $limit);
    }

    /**
     * @param  list<KeywordClusterProposalCluster>  $clusters
     * @return list<KeywordClusterProposalCluster>
     */
    private function reviewClusters(array $clusters): array
    {
        $review = [];
        foreach ($clusters as $cluster) {
            if ($cluster->finalStatus === KeywordClusterProposalCluster::FINAL_NEEDS_REVIEW) {
                $review[] = $cluster;
            }
        }

        return $review;
    }

    /**
     * @param  list<KeywordClusterProposalCluster>  $clusters
     */
    private function firstReadyCluster(array $clusters): ?KeywordClusterProposalCluster
    {
        return $this->readyClusters($clusters)[0] ?? null;
    }

    /**
     * @param  list<KeywordClusterProposalCluster>  $clusters
     */
    private function firstReviewCluster(array $clusters): ?KeywordClusterProposalCluster
    {
        return $this->reviewClusters($clusters)[0] ?? null;
    }

    /**
     * @return list<int>
     */
    private function clusterMemberIds(KeywordClusterProposalCluster $cluster): array
    {
        $ids = array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            $cluster->members,
        );
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function countClusteredMembers(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalResult $preview): int
    {
        return SeoKeywordClassification::query()
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->count();
    }

    private function classificationClusterKey(int $keywordId): ?string
    {
        $value = SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function createArticle(int $siteId, string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLinkMap(int $keywordId, int $sourceArticleId, ?int $targetArticleId): int
    {
        return (int) DB::connection('omi_seo_ai')->table('seo_link_maps')->insertGetId([
            'keyword_id' => $keywordId,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $targetArticleId,
            'anchor_text' => 'anchor',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->text('anchor_text');
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_classifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id')->primary();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key')->nullable()->index();
            $table->boolean('is_seo_keyword')->nullable();
            $table->boolean('is_ambiguous')->nullable();
            $table->decimal('keyword_score', 5, 2)->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->string('classification_hash', 64)->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });
    }
}
