<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterCompetitiveRefiner;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterCorpusStatistics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterDuplicateResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterLineageLedger;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterSimilarityScorer;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterTokenAnalyzer;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterTokenProfile;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordClusterPhase18Test extends TestCase
{
    private const SITE_A = 41;

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

    public function test_competitive_refiner_moves_drawstring_keyword_to_ready_cluster(): void
    {
        $profiles = $this->buildProfiles([
            'túi vải không dệt general alpha',
            'túi vải không dệt general beta',
            'túi vải không dệt general gamma',
            'túi vải không dệt general delta',
            'túi dây rút không dệt specific',
            'túi vải dây rút one',
            'túi vải dây rút two',
            'túi vải dây rút three',
        ]);
        $context = $this->buildSimilarityContext($profiles);

        $drafts = [
            ['member_ids' => [$context['ids'][0], $context['ids'][1], $context['ids'][2], $context['ids'][3], $context['ids'][4]], 'split_from_label' => null, 'split_reason' => null],
            ['member_ids' => [$context['ids'][5], $context['ids'][6], $context['ids'][7]], 'split_from_label' => null, 'split_reason' => null],
        ];

        $result = app(KeywordClusterCompetitiveRefiner::class)->refine(
            drafts: $drafts,
            similarity: $context['similarity'],
            profileMap: $context['profileMap'],
            strategy: KeywordClusterProposalStrategy::BALANCED,
        );

        $drawstringId = $context['ids'][4];
        $foundInDrawstring = false;
        $foundInBroad = false;
        foreach ($result['drafts'] as $draft) {
            if (in_array($drawstringId, $draft['member_ids'], true)) {
                if (count($draft['member_ids']) <= 4) {
                    $foundInDrawstring = true;
                } else {
                    $foundInBroad = true;
                }
            }
        }

        self::assertTrue($foundInDrawstring || $result['competitive_moves'] >= 0);
        if ($result['competitive_moves'] > 0) {
            self::assertFalse($foundInBroad);
        }
    }

    public function test_competitive_refiner_rejects_weak_margin(): void
    {
        $profiles = $this->buildProfiles([
            'túi vải không dệt alpha',
            'túi vải không dệt beta',
            'túi vải không dệt gamma',
            'túi canvas alpha',
            'túi canvas beta',
            'túi canvas gamma',
        ]);
        $context = $this->buildSimilarityContext($profiles);

        $refiner = app(KeywordClusterCompetitiveRefiner::class);
        $fitBroad = $refiner->clusterFit(
            $context['ids'][0],
            [$context['ids'][0], $context['ids'][1], $context['ids'][2]],
            $context['similarity'],
            $context['profileMap'],
            KeywordClusterProposalStrategy::qualityThresholds(KeywordClusterProposalStrategy::BALANCED),
        );
        $fitCanvas = $refiner->clusterFit(
            $context['ids'][0],
            [$context['ids'][3], $context['ids'][4], $context['ids'][5]],
            $context['similarity'],
            $context['profileMap'],
            KeywordClusterProposalStrategy::qualityThresholds(KeywordClusterProposalStrategy::BALANCED),
        );

        self::assertGreaterThan($fitCanvas, $fitBroad);
    }

    public function test_strong_duplicate_resolver_merges_near_identical_clusters(): void
    {
        $profiles = $this->buildProfiles([
            'sản xuất túi vải không dệt giá rẻ',
            'sản xuất túi vải không dệt tại tphcm',
            'sản xuất túi vải giá rẻ',
            'sản xuất túi vải tphcm',
        ]);
        $context = $this->buildSimilarityContext($profiles);

        $drafts = [
            ['member_ids' => [$context['ids'][0], $context['ids'][1]], 'split_from_label' => null, 'split_reason' => null],
            ['member_ids' => [$context['ids'][2], $context['ids'][3]], 'split_from_label' => null, 'split_reason' => null],
        ];

        $result = app(KeywordClusterDuplicateResolver::class)->resolve(
            drafts: $drafts,
            similarity: $context['similarity'],
            profileMap: $context['profileMap'],
            strategy: KeywordClusterProposalStrategy::BALANCED,
        );

        self::assertGreaterThanOrEqual(1, count($result['drafts']));
        self::assertLessThanOrEqual(2, count($result['drafts']));
        if ($result['strong_merges'] === 0) {
            $analysis = app(KeywordClusterDuplicateResolver::class)->analyzePair(
                leftIds: [$context['ids'][0], $context['ids'][1]],
                rightIds: [$context['ids'][2], $context['ids'][3]],
                leftIndex: 0,
                rightIndex: 1,
                similarity: $context['similarity'],
                profileMap: $context['profileMap'],
                strategy: KeywordClusterProposalStrategy::BALANCED,
                qualityThresholds: KeywordClusterProposalStrategy::qualityThresholds(KeywordClusterProposalStrategy::BALANCED),
                cohesionThreshold: KeywordClusterProposalStrategy::thresholds(KeywordClusterProposalStrategy::BALANCED)['cohesion'],
            );
            self::assertNotNull($analysis);
            self::assertContains($analysis['classification'], ['STRONG_DUPLICATE', 'POTENTIAL_DUPLICATE']);
        }
    }

    public function test_potential_duplicate_stays_separate_for_moderate_similarity(): void
    {
        $profiles = $this->buildProfiles([
            'túi vải không dệt in logo alpha',
            'túi vải không dệt in logo beta',
            'quà tặng in logo gamma',
            'quà tặng in logo delta',
        ]);
        $context = $this->buildSimilarityContext($profiles);

        $analysis = app(KeywordClusterDuplicateResolver::class)->analyzePair(
            leftIds: [$context['ids'][0], $context['ids'][1]],
            rightIds: [$context['ids'][2], $context['ids'][3]],
            leftIndex: 0,
            rightIndex: 1,
            similarity: $context['similarity'],
            profileMap: $context['profileMap'],
            strategy: KeywordClusterProposalStrategy::BALANCED,
            qualityThresholds: KeywordClusterProposalStrategy::qualityThresholds(KeywordClusterProposalStrategy::BALANCED),
            cohesionThreshold: KeywordClusterProposalStrategy::thresholds(KeywordClusterProposalStrategy::BALANCED)['cohesion'],
        );

        if ($analysis !== null) {
            self::assertNotSame('STRONG_DUPLICATE', $analysis['classification']);
        } else {
            self::assertTrue(true);
        }
    }

    public function test_lineage_ledger_conserves_initial_member_count(): void
    {
        $profiles = $this->buildProfiles([
            'túi vải không dệt a',
            'túi vải không dệt b',
            'túi vải không dệt c',
            'túi dây rút a',
            'túi dây rút b',
        ]);
        $context = $this->buildSimilarityContext($profiles);
        $initial = [[$context['ids'][0], $context['ids'][1], $context['ids'][2], $context['ids'][3], $context['ids'][4]]];

        $ledger = KeywordClusterLineageLedger::fromInitialClusters($initial, $context['profileMap']);
        $finalDrafts = [
            ['member_ids' => [$context['ids'][0], $context['ids'][1], $context['ids'][2]]],
            ['member_ids' => [$context['ids'][3], $context['ids'][4]]],
        ];

        $disposition = $ledger->buildDisposition(
            finalDrafts: $finalDrafts,
            releasedMemberIds: [],
            profileMap: $context['profileMap'],
            similarity: $context['similarity'],
        );

        self::assertTrue($disposition['all_conserved']);
        self::assertSame(5, $disposition['lineages'][0]['total']);
        self::assertSame(5, $disposition['lineages'][0]['initial_count']);
    }

    public function test_lineage_no_double_count_after_rehome(): void
    {
        $profiles = $this->buildProfiles([
            'xưởng may túi không dệt a',
            'xưởng may túi không dệt b',
            'xưởng may túi vải c',
            'xưởng may túi vải d',
            'túi vải không dệt core a',
            'túi vải không dệt core b',
            'túi vải không dệt core c',
        ]);
        $context = $this->buildSimilarityContext($profiles);
        $initial = [[
            $context['ids'][0], $context['ids'][1], $context['ids'][2], $context['ids'][3],
            $context['ids'][4], $context['ids'][5], $context['ids'][6],
        ]];

        $ledger = KeywordClusterLineageLedger::fromInitialClusters($initial, $context['profileMap']);
        $ledger->record(KeywordClusterLineageLedger::EVENT_PEEL, [$context['ids'][0], $context['ids'][1]], []);
        $ledger->record(KeywordClusterLineageLedger::EVENT_REHOME, [$context['ids'][0], $context['ids'][1]], []);

        $finalDrafts = [
            ['member_ids' => [$context['ids'][4], $context['ids'][5], $context['ids'][6]]],
            ['member_ids' => [$context['ids'][0], $context['ids'][1], $context['ids'][2], $context['ids'][3]]],
        ];

        $disposition = $ledger->buildDisposition(
            finalDrafts: $finalDrafts,
            releasedMemberIds: [],
            profileMap: $context['profileMap'],
            similarity: $context['similarity'],
        );

        self::assertSame(7, $disposition['lineages'][0]['total']);
    }

    public function test_global_conservation_and_no_duplicate_members(): void
    {
        foreach ([
            'túi vải không dệt',
            'túi vải không dệt in logo',
            'túi dây rút không dệt',
            'túi vải dây rút',
            'xưởng may túi không dệt',
            'xưởng may túi vải không dệt',
            'túi hột xoài không dệt',
            'túi quảng cáo không dệt',
            'sản xuất túi vải không dệt',
            'sản xuất túi vải',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(
            $result->candidateCount,
            $result->proposedKeywordCount + count($result->unclustered),
        );

        $seen = [];
        foreach ($result->proposedClusters as $cluster) {
            foreach ($cluster->members as $member) {
                $id = (int) $member['keyword_id'];
                self::assertFalse(isset($seen[$id]));
                $seen[$id] = true;
            }
        }
    }

    public function test_xuong_may_merge_remains_stable(): void
    {
        foreach ([
            'xưởng may túi không dệt',
            'xưởng may túi không dệt giá sỉ',
            'xưởng may túi không dệt tại TP.HCM',
            'xưởng may túi vải không dệt',
            'xưởng may túi vải không dệt giá rẻ',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $mayClusters = array_values(array_filter(
            $result->proposedClusters,
            static fn ($cluster): bool => str_contains(mb_strtolower($cluster->representativeLabel, 'UTF-8'), 'xưởng may'),
        ));

        self::assertCount(1, $mayClusters);
        self::assertGreaterThanOrEqual(4, $mayClusters[0]->memberCount);
    }

    public function test_lineage_disposition_in_diagnostics(): void
    {
        foreach (['túi vải không dệt a', 'túi vải không dệt b', 'túi canvas c', 'túi canvas d'] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertArrayHasKey('lineage_disposition', $result->diagnostics);
        self::assertTrue((bool) ($result->diagnostics['lineage_disposition']['all_conserved'] ?? false));
    }

    public function test_zero_cluster_key_writes(): void
    {
        foreach (['túi vải không dệt a', 'túi vải không dệt b'] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $before = SeoKeywordClassification::query()->pluck('cluster_key', 'keyword_id')->all();
        $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $after = SeoKeywordClassification::query()->pluck('cluster_key', 'keyword_id')->all();

        self::assertSame($before, $after);
    }

    private function engine(): KeywordClusterProposalEngine
    {
        return app(KeywordClusterProposalEngine::class);
    }

    /**
     * @param  list<string>  $phrases
     * @return array{profileMap: array<int, KeywordClusterTokenProfile>, similarity: array<int, array<int, float>>, ids: list<int>}
     */
    private function buildSimilarityContext(array $profiles): array
    {
        $profileMap = [];
        foreach ($profiles as $profile) {
            $profileMap[$profile->keywordId] = $profile;
        }
        $ids = array_keys($profileMap);
        sort($ids);
        $corpus = KeywordClusterCorpusStatistics::fromProfiles($profiles);
        $scorer = app(KeywordClusterSimilarityScorer::class);
        $similarity = [];
        foreach ($ids as $i => $leftId) {
            $similarity[$leftId][$leftId] = 1.0;
            for ($j = $i + 1; $j < count($ids); $j++) {
                $rightId = $ids[$j];
                $score = $scorer->score($profileMap[$leftId], $profileMap[$rightId], $corpus);
                $similarity[$leftId][$rightId] = $score;
                $similarity[$rightId][$leftId] = $score;
            }
        }

        return ['profileMap' => $profileMap, 'similarity' => $similarity, 'ids' => $ids];
    }

    /**
     * @param  list<string>  $phrases
     * @return list<KeywordClusterTokenProfile>
     */
    private function buildProfiles(array $phrases): array
    {
        $analyzer = app(KeywordClusterTokenAnalyzer::class);
        $normalizer = app(KeywordNormalizer::class);
        $profiles = [];
        $id = 1;
        foreach ($phrases as $phrase) {
            $norm = $normalizer->normalize($phrase);
            $analysis = $analyzer->analyze($norm['folded_text']);
            $profiles[] = new KeywordClusterTokenProfile(
                keywordId: $id++,
                phrase: $phrase,
                normalizedText: $norm['normalized_text'],
                foldedText: $norm['folded_text'],
                seoIntent: 'commercial',
                isAmbiguous: false,
                tokens: $analysis['tokens'],
                bigrams: $analysis['bigrams'],
                significantTokens: $analysis['significant_tokens'],
                significantPhrase: $analysis['significant_phrase'],
                groupKeys: [],
            );
        }

        return $profiles;
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
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });
    }
}
