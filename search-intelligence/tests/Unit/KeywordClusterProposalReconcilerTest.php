<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalReconciler;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterTokenProfile;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterCorpusStatistics;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterSimilarityScorer;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterTokenAnalyzer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordClusterProposalReconcilerTest extends TestCase
{
    private const SITE_A = 40;

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

    public function test_rehomes_duplicate_xuong_may_subgroup_into_existing_proposal(): void
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

    public function test_does_not_merge_unrelated_subgroups_into_mega_cluster(): void
    {
        foreach ([
            'túi dây rút không dệt',
            'túi vải dây rút',
            'túi hột xoài không dệt',
            'túi hột xoài',
            'túi quảng cáo không dệt',
            'túi vải không dệt',
            'túi không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $allTogether = false;
        foreach ($result->proposedClusters as $cluster) {
            $phrases = array_map(static fn (array $m): string => (string) $m['phrase'], $cluster->members);
            if (in_array('túi dây rút không dệt', $phrases, true)
                && in_array('túi hột xoài không dệt', $phrases, true)
                && in_array('túi quảng cáo không dệt', $phrases, true)) {
                $allTogether = true;
            }
        }

        self::assertFalse($allTogether);
    }

    public function test_candidate_conservation_invariant_holds(): void
    {
        foreach ([
            'túi canvas',
            'túi vải canvas',
            'túi dây rút',
            'túi vải không dệt',
            'xưởng may túi không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(
            $result->candidateCount,
            $result->proposedKeywordCount + count($result->unclustered),
        );
    }

    public function test_no_duplicate_member_across_proposals(): void
    {
        foreach ([
            'túi vải không dệt',
            'túi vải không dệt in logo',
            'túi dây rút không dệt',
            'túi dây rút vải không dệt',
            'xưởng may túi không dệt',
            'xưởng may túi vải không dệt',
            'túi hột xoài không dệt',
            'túi quảng cáo không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $seen = [];
        foreach ($result->proposedClusters as $cluster) {
            foreach ($cluster->members as $member) {
                $id = (int) $member['keyword_id'];
                self::assertFalse(isset($seen[$id]), 'Duplicate member across proposals: '.$id);
                $seen[$id] = true;
            }
        }
        self::assertSame($result->candidateCount, $result->proposedKeywordCount + count($result->unclustered));
    }

    public function test_release_can_return_weak_members_to_unclustered(): void
    {
        foreach ([
            'túi vải không dệt alpha',
            'túi vải không dệt beta',
            'túi vải không dệt gamma',
            'túi vải không dệt delta',
            'túi vải không dệt epsilon',
            'túi vải không dệt zeta',
            'túi vải không dệt eta',
            'túi vải không dệt theta',
            'túi vải không dệt iota',
            'túi vải không dệt kappa',
            'Polyethylene outlier keyword',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(
            $result->candidateCount,
            $result->proposedKeywordCount + count($result->unclustered),
        );
    }

    public function test_reconciler_merges_direct_fixture(): void
    {
        $profiles = $this->buildProfiles([
            'xưởng may túi không dệt',
            'xưởng may túi không dệt giá sỉ',
            'xưởng may túi vải không dệt',
            'xưởng may túi vải không dệt giá rẻ',
        ]);
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

        $drafts = [
            ['member_ids' => [$ids[0], $ids[1]], 'split_from_label' => null, 'split_reason' => null],
            ['member_ids' => [$ids[2], $ids[3]], 'split_from_label' => 'túi vải không dệt', 'split_reason' => 'test peel'],
        ];

        $result = app(KeywordClusterProposalReconciler::class)->reconcile(
            drafts: $drafts,
            similarity: $similarity,
            profileMap: $profileMap,
            strategy: KeywordClusterProposalStrategy::BALANCED,
        );

        self::assertSame(1, $result['subgroups_rehomed']);
        self::assertCount(1, $result['drafts']);
        self::assertCount(4, $result['drafts'][0]['member_ids']);
    }

    private function engine(): KeywordClusterProposalEngine
    {
        return app(KeywordClusterProposalEngine::class);
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
