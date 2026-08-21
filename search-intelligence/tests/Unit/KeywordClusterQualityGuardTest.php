<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalEngine;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal\KeywordClusterProposalStrategy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterQualityMetrics;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordClusterQualityGuardTest extends TestCase
{
    private const SITE_A = 30;

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

    public function test_mega_family_with_substructure_splits_or_tightens(): void
    {
        foreach ([
            'túi dây rút không dệt',
            'túi vải dây rút',
            'xưởng may túi dây rút',
            'túi hột xoài không dệt',
            'túi hột xoài',
            'may túi hột xoài',
            'túi vải không dệt',
            'túi không dệt',
            'túi quảng cáo không dệt',
            'túi siêu thị không dệt',
            'túi dây kéo không dệt',
            'xưởng may túi không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertGreaterThanOrEqual(2, count($result->proposedClusters));

        $largest = $result->proposedClusters[0];
        self::assertLessThan(12, $largest->memberCount);
    }

    public function test_cohesive_large_cluster_is_not_split(): void
    {
        $phrases = [];
        for ($i = 1; $i <= 22; $i++) {
            $phrases[] = 'túi canvas in logo variant '.$i;
        }
        foreach ($phrases as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(1, count($result->proposedClusters));
        self::assertGreaterThanOrEqual(20, $result->proposedClusters[0]->memberCount);
        self::assertSame(0, (int) ($result->diagnostics['clusters_split_count'] ?? 0));
    }

    public function test_modifiers_do_not_over_split_canvas_family(): void
    {
        foreach ([
            'túi canvas',
            'túi vải canvas',
            'giá túi canvas',
            'mua túi canvas',
            'túi canvas in logo',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $canvasCluster = $this->findClusterContaining($result, 'túi canvas');
        self::assertNotNull($canvasCluster);
        self::assertGreaterThanOrEqual(4, $canvasCluster->memberCount);
    }

    public function test_cross_intent_stays_in_same_subgroup_after_guard(): void
    {
        foreach ([
            'túi canvas',
            'giá túi canvas',
            'mua túi canvas',
            'cách chọn túi canvas',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase, intent: match ($phrase) {
                'giá túi canvas' => 'commercial',
                'mua túi canvas' => 'transactional',
                'cách chọn túi canvas' => 'informational',
                default => 'commercial',
            });
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $cluster = $this->findClusterContaining($result, 'túi canvas');
        self::assertNotNull($cluster);
        self::assertGreaterThanOrEqual(4, $cluster->memberCount);
    }

    public function test_guard_is_deterministic(): void
    {
        foreach ([
            'túi dây rút không dệt',
            'túi vải dây rút',
            'túi hột xoài không dệt',
            'túi vải không dệt',
            'túi không dệt',
            'túi quảng cáo không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $first = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED)->toArray();
        $second = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED)->toArray();
        self::assertSame($first, $second);
    }

    public function test_quality_guard_makes_zero_database_mutations(): void
    {
        foreach ([
            'túi vải không dệt',
            'túi dây rút không dệt',
            'túi hột xoài không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase, clusterKey: 'protected_cluster');
        }
        $this->seedCandidate(self::SITE_A, 'túi canvas');
        $this->seedCandidate(self::SITE_A, 'túi vải canvas');

        $before = SeoKeywordClassification::query()->orderBy('keyword_id')->get()->map(static fn ($row): array => [
            'keyword_id' => (int) $row->keyword_id,
            'cluster_key' => $row->cluster_key,
        ])->all();

        $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);

        $after = SeoKeywordClassification::query()->orderBy('keyword_id')->get()->map(static fn ($row): array => [
            'keyword_id' => (int) $row->keyword_id,
            'cluster_key' => $row->cluster_key,
        ])->all();

        self::assertSame($before, $after);
    }

    public function test_diagnostics_include_guard_metrics(): void
    {
        foreach ([
            'túi canvas',
            'túi vải canvas',
            'túi dây rút',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertArrayHasKey('initial_cluster_count', $result->diagnostics);
        self::assertArrayHasKey('final_cluster_count', $result->diagnostics);
        self::assertArrayHasKey('loose_clusters_detected', $result->diagnostics);
        self::assertArrayHasKey('clusters_split_count', $result->diagnostics);
        self::assertArrayHasKey('phrase_kind_distribution', $result->diagnostics);
    }

    public function test_proposal_clusters_include_quality_metrics(): void
    {
        foreach (['túi canvas', 'túi vải canvas', 'xưởng may túi canvas'] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertNotEmpty($result->proposedClusters);
        $cluster = $result->proposedClusters[0];
        self::assertNotNull($cluster->quality);
        self::assertContains($cluster->quality->qualityState, [
            KeywordClusterQualityMetrics::STATE_COMPACT,
            KeywordClusterQualityMetrics::STATE_ACCEPTABLE,
            KeywordClusterQualityMetrics::STATE_LOOSE,
            KeywordClusterQualityMetrics::STATE_MEGA,
        ]);
    }

    private function engine(): KeywordClusterProposalEngine
    {
        return app(KeywordClusterProposalEngine::class);
    }

    /**
     * @param  \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalResult  $result
     */
    private function findClusterContaining($result, string $phrase): ?\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordClusterProposalCluster
    {
        foreach ($result->proposedClusters as $cluster) {
            foreach ($cluster->members as $member) {
                if (($member['phrase'] ?? '') === $phrase) {
                    return $cluster;
                }
            }
        }

        return null;
    }

    private function seedCandidate(
        int $siteId,
        string $phrase,
        string $intent = 'commercial',
        ?string $clusterKey = null,
    ): Keyword {
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
            'seo_intent' => $intent,
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => $clusterKey === null,
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
