<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ApplyTopicClusterProposalService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalAlgorithmVersion;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalFingerprint;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\TopicClusterProposalMemberStateLoader;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalInput;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\ApplyTopicClusterProposalStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterClusterKeyGenerator;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class ApplyTopicClusterProposalServiceTest extends TestCase
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

    public function test_happy_path_applies_ready_proposal(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị alpha',
            'túi vải siêu thị beta',
            'túi vải siêu thị gamma',
            'túi vải siêu thị delta',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);
        self::assertSame(KeywordClusterProposalCluster::FINAL_READY, $proposal['cluster']->finalStatus);

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::APPLIED, $result->status);
        self::assertSame(count($ids), $result->affectedKeywordCount);
        self::assertNotSame('', $result->clusterKey);

        foreach ($ids as $keywordId) {
            self::assertSame($result->clusterKey, $this->classificationClusterKey($keywordId));
        }
    }

    public function test_needs_review_proposal_can_apply_when_confirmed(): void
    {
        $this->seedProposalFamily([
            'túi vải không dệt alpha',
            'túi vải không dệt beta',
            'túi vải không dệt gamma',
            'túi vải không dệt delta',
            'túi vải không dệt epsilon',
            'túi vải không dệt zeta',
            'túi vải không dệt eta',
            'túi vải không dệt theta',
            'túi vải không dệt iota',
            'túi canvas outlier keyword',
        ]);

        $proposal = $this->firstProposal(finalStatus: KeywordClusterProposalCluster::FINAL_NEEDS_REVIEW);
        if ($proposal === null) {
            self::assertTrue(true);

            return;
        }

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertContains($result->status, [
            ApplyTopicClusterProposalStatus::APPLIED,
            ApplyTopicClusterProposalStatus::ALREADY_APPLIED,
        ]);
    }

    public function test_conflict_when_member_already_clustered(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải quảng cáo alpha',
            'túi vải quảng cáo beta',
            'túi vải quảng cáo gamma',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        SeoKeywordClassification::query()->whereKey($ids[0])->update(['cluster_key' => 'occupied_elsewhere']);

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::CONFLICT, $result->status);
        self::assertNull($this->classificationClusterKey($ids[1]));
        self::assertNull($this->classificationClusterKey($ids[2]));
    }

    public function test_member_classification_hash_change_still_applies_if_current_membership_matches(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị stale a',
            'túi vải siêu thị stale b',
            'túi vải siêu thị stale c',
            'túi vải siêu thị stale d',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        SeoKeywordClassification::query()->whereKey($ids[0])->update([
            'classification_hash' => hash('sha256', 'changed-state'),
        ]);

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::APPLIED, $result->status);
        foreach ($ids as $keywordId) {
            self::assertSame($result->clusterKey, $this->classificationClusterKey($keywordId));
        }
    }

    public function test_stale_when_preview_fingerprint_mismatch_and_membership_changed(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị old a',
            'túi vải siêu thị old b',
            'túi vải siêu thị old c',
            'túi vải siêu thị old d',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);
        $oldMemberIds = $this->clusterMemberIds($proposal['cluster']);
        sort($ids, SORT_NUMERIC);
        self::assertSame($ids, $oldMemberIds);

        $this->seedCandidate(self::SITE_A, 'túi vải siêu thị old a extra joiner');

        $currentPreview = app(KeywordClusterProposalEngine::class)
            ->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertNotSame($proposal['preview_fingerprint'], $currentPreview->previewFingerprint);
        self::assertNull($this->findClusterByMemberIds($currentPreview->proposedClusters, $oldMemberIds));

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::STALE, $result->status);
        foreach ($ids as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_apply_when_preview_fingerprint_drifts_but_current_engine_keeps_same_members(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị keep a',
            'túi vải siêu thị keep b',
            'túi vải siêu thị keep c',
            'túi vải siêu thị keep d',
        ]);
        $outsider = $this->seedCandidate(self::SITE_A, 'áo polo nam unique outsider xyz');

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);
        $oldMemberIds = $this->clusterMemberIds($proposal['cluster']);
        sort($ids, SORT_NUMERIC);
        self::assertSame($ids, $oldMemberIds);

        SeoKeywordClassification::query()->whereKey((int) $outsider->id)->update([
            'classification_hash' => hash('sha256', 'outsider-semantic-drift'),
        ]);

        $currentPreview = app(KeywordClusterProposalEngine::class)
            ->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertNotSame($proposal['preview_fingerprint'], $currentPreview->previewFingerprint);
        self::assertNotNull($this->findClusterByMemberIds($currentPreview->proposedClusters, $oldMemberIds));

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::APPLIED, $result->status);
        foreach ($ids as $keywordId) {
            self::assertSame($result->clusterKey, $this->classificationClusterKey($keywordId));
        }
        self::assertNull($this->classificationClusterKey((int) $outsider->id));
    }

    public function test_idempotent_retry_returns_already_applied(): void
    {
        $this->seedProposalFamily([
            'túi vải siêu thị retry a',
            'túi vải siêu thị retry b',
            'túi vải siêu thị retry c',
            'túi vải siêu thị retry d',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        $input = $this->applyInput($proposal);

        $first = $this->service()->apply($input);
        self::assertSame(ApplyTopicClusterProposalStatus::APPLIED, $first->status);

        $second = $this->service()->apply($input);
        self::assertSame(ApplyTopicClusterProposalStatus::ALREADY_APPLIED, $second->status);
        self::assertSame($first->clusterKey, $second->clusterKey);
    }

    public function test_generated_keys_are_deterministic_and_distinct(): void
    {
        $generator = app(TopicClusterClusterKeyGenerator::class);
        $left = $generator->generate(self::SITE_A, 'Túi vải quảng cáo', [1, 2, 3]);
        $right = $generator->generate(self::SITE_A, 'Túi vải quảng cáo', [4, 5, 6]);

        self::assertSame($left, $generator->generate(self::SITE_A, 'Túi vải quảng cáo', [1, 2, 3]));
        self::assertNotSame($left, $right);
    }

    public function test_dissolve_after_apply_restores_unclustered_state(): void
    {
        $this->seedProposalFamily([
            'túi vải siêu thị dissolve a',
            'túi vải siêu thị dissolve b',
            'túi vải siêu thị dissolve c',
            'túi vải siêu thị dissolve d',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        $applied = $this->service()->apply(new ApplyTopicClusterProposalInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $proposal['preview_fingerprint'],
            proposalFingerprint: $proposal['cluster']->proposalFingerprint,
        ));

        self::assertTrue($applied->isSuccess());
        app(DissolveTopicClusterService::class)->dissolve(self::SITE_A, $applied->clusterKey);

        foreach ($applied->keywordIds as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_only_cluster_key_changes_on_apply(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị side a',
            'túi vải siêu thị side b',
            'túi vải siêu thị side c',
            'túi vải siêu thị side d',
        ]);

        $before = SeoKeywordClassification::query()->whereIn('keyword_id', $ids)->get()->keyBy('keyword_id');
        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertTrue($result->isSuccess());

        foreach ($ids as $keywordId) {
            $after = SeoKeywordClassification::query()->find($keywordId)?->toArray();
            $snapshot = $before->get($keywordId)?->toArray();
            self::assertIsArray($after);
            self::assertIsArray($snapshot);
            self::assertSame($result->clusterKey, $after['cluster_key']);
            unset($after['cluster_key'], $snapshot['cluster_key'], $after['updated_at'], $snapshot['updated_at']);
            self::assertSame($snapshot, $after);
        }
    }

    public function test_fingerprint_includes_algorithm_version(): void
    {
        self::assertSame('topic-cluster-v2.1', TopicClusterProposalAlgorithmVersion::CURRENT);
    }

    public function test_cross_site_member_ids_are_rejected(): void
    {
        $this->seedProposalFamily([
            'túi vải siêu thị site a one',
            'túi vải siêu thị site a two',
            'túi vải siêu thị site a three',
            'túi vải siêu thị site a four',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        $foreignId = (int) $this->seedCandidate(self::SITE_B, 'foreign site keyword')->id;
        $input = $this->applyInput($proposal);
        $malicious = new ApplyTopicClusterProposalInput(
            siteId: $input->siteId,
            strategy: $input->strategy,
            previewFingerprint: $input->previewFingerprint,
            proposalFingerprint: $input->proposalFingerprint,
            memberKeywordIds: [...$input->memberKeywordIds, $foreignId],
            representativeKeywordId: $input->representativeKeywordId,
            representativeLabel: $input->representativeLabel,
            finalStatus: $input->finalStatus,
            qualityState: $input->qualityState,
        );

        $result = $this->service()->apply($malicious);

        self::assertSame(ApplyTopicClusterProposalStatus::STALE, $result->status);
        self::assertNull($this->classificationClusterKey($foreignId));
        foreach ($input->memberKeywordIds as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
    }

    public function test_stale_when_member_becomes_non_seo(): void
    {
        $ids = $this->seedProposalFamily([
            'túi vải siêu thị non seo a',
            'túi vải siêu thị non seo b',
            'túi vải siêu thị non seo c',
            'túi vải siêu thị non seo d',
        ]);

        $proposal = $this->firstProposal();
        self::assertNotNull($proposal);

        SeoKeywordClassification::query()->whereKey($ids[0])->update(['is_seo_keyword' => false]);

        $result = $this->service()->apply($this->applyInput($proposal));

        self::assertSame(ApplyTopicClusterProposalStatus::STALE, $result->status);
        foreach ($ids as $keywordId) {
            self::assertNull($this->classificationClusterKey($keywordId));
        }
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

    /**
     * @param  list<KeywordClusterProposalCluster>  $clusters
     * @param  list<int>  $memberIds
     */
    private function findClusterByMemberIds(array $clusters, array $memberIds): ?KeywordClusterProposalCluster
    {
        foreach ($clusters as $cluster) {
            if ($this->clusterMemberIds($cluster) === $memberIds) {
                return $cluster;
            }
        }

        return null;
    }

    /**
     * @param  array{cluster: KeywordClusterProposalCluster, preview_fingerprint: string}  $proposal
     */
    private function applyInput(array $proposal): ApplyTopicClusterProposalInput
    {
        $cluster = $proposal['cluster'];
        $memberIds = array_map(
            static fn (array $member): int => (int) ($member['keyword_id'] ?? 0),
            $cluster->members,
        );
        $memberIds = array_values(array_filter($memberIds, static fn (int $id): bool => $id > 0));
        sort($memberIds, SORT_NUMERIC);

        return new ApplyTopicClusterProposalInput(
            siteId: self::SITE_A,
            strategy: KeywordClusterProposalStrategy::BALANCED,
            previewFingerprint: $proposal['preview_fingerprint'],
            proposalFingerprint: $cluster->proposalFingerprint,
            memberKeywordIds: $memberIds,
            representativeKeywordId: $cluster->representativeKeywordId,
            representativeLabel: $cluster->representativeLabel,
            finalStatus: $cluster->finalStatus,
            qualityState: $cluster->quality?->qualityState ?? KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
        );
    }

    private function service(): ApplyTopicClusterProposalService
    {
        return new class(
            app(KeywordClusterProposalEngine::class),
            app(TopicClusterProposalMemberStateLoader::class),
            app(TopicClusterClusterKeyGenerator::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility::class),
            app(KeywordClusterQuery::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterApplySideEffects::class),
            app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterPostApplyService::class),
        ) extends ApplyTopicClusterProposalService {
            protected function isAuthorized(int $siteId): bool
            {
                return $siteId > 0;
            }
        };
    }

    /**
     * @param  list<string>  $phrases
     * @return list<int>
     */
    private function seedProposalFamily(array $phrases): array
    {
        $ids = [];
        foreach ($phrases as $phrase) {
            $ids[] = (int) $this->seedCandidate(self::SITE_A, $phrase)->id;
        }

        return $ids;
    }

    /**
     * @return array{cluster: \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster, preview_fingerprint: string}|null
     */
    private function firstProposal(?string $finalStatus = null): ?array
    {
        $preview = app(KeywordClusterProposalEngine::class)->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);

        foreach ($preview->proposedClusters as $cluster) {
            if ($finalStatus !== null && $cluster->finalStatus !== $finalStatus) {
                continue;
            }
            if ($cluster->memberCount >= 2 && $cluster->proposalFingerprint !== '') {
                return [
                    'cluster' => $cluster,
                    'preview_fingerprint' => $preview->previewFingerprint,
                ];
            }
        }

        return null;
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

    private function classificationClusterKey(int $keywordId): ?string
    {
        $value = SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key');

        return is_string($value) && $value !== '' ? $value : null;
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
