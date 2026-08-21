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
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class KeywordClusterProposalEngineTest extends TestCase
{
    private const SITE_A = 10;

    private const SITE_B = 20;

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

    public function test_canvas_family_groups_together_in_balanced_strategy(): void
    {
        $phrases = [
            'túi canvas',
            'túi vải canvas',
            'xưởng may túi canvas',
            'may túi canvas theo yêu cầu',
            'túi canvas giá rẻ',
        ];
        foreach ($phrases as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertGreaterThanOrEqual(1, count($result->proposedClusters));

        $canvasCluster = $this->findClusterContaining($result, 'túi canvas');
        self::assertNotNull($canvasCluster);
        self::assertGreaterThanOrEqual(4, $canvasCluster->memberCount);
    }

    public function test_generic_token_does_not_merge_unrelated_bag_types(): void
    {
        foreach (['túi canvas', 'túi dây rút', 'túi không dệt'] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $allThreeTogether = false;
        foreach ($result->proposedClusters as $cluster) {
            $phrases = array_map(static fn (array $m): string => (string) $m['phrase'], $cluster->members);
            if (in_array('túi canvas', $phrases, true)
                && in_array('túi dây rút', $phrases, true)
                && in_array('túi không dệt', $phrases, true)) {
                $allThreeTogether = true;
            }
        }

        self::assertFalse($allThreeTogether);
    }

    public function test_cross_intent_same_topic_stays_together(): void
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

    public function test_chain_protection_avoids_loose_three_way_cluster(): void
    {
        $this->seedCandidate(self::SITE_A, 'vai canvas premium');
        $this->seedCandidate(self::SITE_A, 'canvas premium hologram');
        $this->seedCandidate(self::SITE_A, 'hologram polyethylene special');

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        $allThree = false;
        foreach ($result->proposedClusters as $cluster) {
            $phrases = array_map(static fn (array $m): string => (string) $m['phrase'], $cluster->members);
            if (count(array_intersect($phrases, [
                'vai canvas premium',
                'canvas premium hologram',
                'hologram polyethylene special',
            ])) === 3) {
                $allThree = true;
            }
        }

        self::assertFalse($allThree);
    }

    public function test_singleton_remains_unclustered(): void
    {
        $this->seedCandidate(self::SITE_A, 'Polyethylene');

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame([], $result->proposedClusters);
        self::assertCount(1, $result->unclustered);
    }

    public function test_existing_clustered_keywords_are_not_in_proposals(): void
    {
        $protected = $this->seedCandidate(self::SITE_A, 'túi dây rút', clusterKey: 'tui_day_rut');
        $this->seedCandidate(self::SITE_A, 'túi dây rút giá rẻ');
        $this->seedCandidate(self::SITE_A, 'mua túi dây rút');

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertGreaterThanOrEqual(1, $result->protectedClusterCount);
        foreach ($result->proposedClusters as $cluster) {
            foreach ($cluster->members as $member) {
                self::assertNotSame((int) $protected->id, (int) $member['keyword_id']);
            }
        }
    }

    public function test_uses_full_candidate_pool_not_truncated(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            $this->seedCandidate(self::SITE_A, 'bulk candidate phrase alpha '.$i);
        }

        $result = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(150, $result->candidateCount);
    }

    public function test_site_isolation(): void
    {
        $this->seedCandidate(self::SITE_A, 'túi canvas');
        $this->seedCandidate(self::SITE_A, 'túi vải canvas');
        $this->seedCandidate(self::SITE_B, 'túi canvas');
        $this->seedCandidate(self::SITE_B, 'túi vải canvas');

        $resultA = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);
        self::assertSame(2, $resultA->candidateCount);
        $siteBIds = DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
            ->where('articles.site_id', self::SITE_B)
            ->pluck('seo_link_maps.keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        foreach ($resultA->proposedClusters as $cluster) {
            foreach ($cluster->members as $member) {
                self::assertNotContains((int) $member['keyword_id'], $siteBIds);
            }
        }
    }

    public function test_preview_is_deterministic(): void
    {
        foreach ([
            'túi canvas',
            'túi vải canvas',
            'xưởng may túi canvas',
            'túi dây rút',
            'túi không dệt',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase);
        }

        $first = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED)->toArray();
        $second = $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED)->toArray();

        self::assertSame($first, $second);
    }

    public function test_preview_makes_zero_database_mutations(): void
    {
        foreach ([
            'túi canvas',
            'túi vải canvas',
            'xưởng may túi canvas',
        ] as $phrase) {
            $this->seedCandidate(self::SITE_A, $phrase, clusterKey: 'existing_should_stay');
        }
        $this->seedCandidate(self::SITE_A, 'túi dây rút');

        $before = SeoKeywordClassification::query()->orderBy('keyword_id')->get()->map(static fn ($row): array => [
            'keyword_id' => (int) $row->keyword_id,
            'cluster_key' => $row->cluster_key,
            'seo_intent' => $row->seo_intent,
        ])->all();

        $this->engine()->previewForSite(self::SITE_A, KeywordClusterProposalStrategy::BALANCED);

        $after = SeoKeywordClassification::query()->orderBy('keyword_id')->get()->map(static fn ($row): array => [
            'keyword_id' => (int) $row->keyword_id,
            'cluster_key' => $row->cluster_key,
            'seo_intent' => $row->seo_intent,
        ])->all();

        self::assertSame($before, $after);
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
