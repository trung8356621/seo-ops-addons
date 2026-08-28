<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler;
use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpGenerator;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordGroupCoverageBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class SiteMcpClusterTopicalProfileTest extends TestCase
{
    private const SITE_ID = 77;

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
        SiteMcpTopicalProfileStaleState::clear(self::SITE_ID);
    }

    public function test_a_weight_from_real_cluster_keyword_distribution(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 70, 'commercial');
        $this->seedCluster('ck_b', 'Cluster B', 30, 'informational');

        $profile = $this->builder()->build(self::SITE_ID);
        $byRef = $this->indexByRef($profile);

        // 1 published article per keyword in seed → article share mirrors former keyword share.
        self::assertSame(100, $profile['total_published_articles']);
        self::assertSame(70.0, $byRef['ck_a']['weight']);
        self::assertSame(30.0, $byRef['ck_b']['weight']);
        self::assertSame('Cluster A', $byRef['ck_a']['name']);
        self::assertSame('ck_a', $byRef['ck_a']['cluster_ref']);
    }

    public function test_zero_articles_means_zero_mcp_share(): void
    {
        $this->seedClusterMembersOnly('ck_empty', 'No Articles', 10);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_empty'],
            [
                'canonical_phrase' => 'No Articles',
                'normalized_canonical' => 'no articles',
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => 'auto',
            ],
        );
        $this->seedCluster('ck_with', 'Has Articles', 2);

        $profile = $this->builder()->build(self::SITE_ID);
        $byRef = $this->indexByRef($profile);

        self::assertSame(0.0, $byRef['ck_empty']['weight']);
        self::assertSame(0, $byRef['ck_empty']['article_count']);
        self::assertSame(100.0, $byRef['ck_with']['weight']);
        self::assertSame(2, $profile['total_published_articles']);
    }

    public function test_mcp_excluded_cluster_out_of_denominator(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 8);
        $this->seedCluster('ck_b', 'Cluster B', 2);
        SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_ID)
            ->where('cluster_key', 'ck_b')
            ->update(['mcp_excluded' => true]);

        $profile = $this->builder()->build(self::SITE_ID);
        $byRef = $this->indexByRef($profile);

        self::assertArrayHasKey('ck_a', $byRef);
        self::assertArrayNotHasKey('ck_b', $byRef);
        self::assertSame(100.0, $byRef['ck_a']['weight']);
        self::assertSame(0.0, $this->builder()->topicalShareMap(self::SITE_ID)['ck_b'] ?? 0.0);
    }

    public function test_seo_excluded_cluster_out_of_denominator(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 5);
        $this->seedCluster('ck_b', 'Cluster B', 5);
        SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_ID)
            ->where('cluster_key', 'ck_b')
            ->update(['seo_excluded' => true, 'mcp_excluded' => true]);

        $profile = $this->builder()->build(self::SITE_ID);
        $refs = array_column($profile['topics'], 'cluster_ref');

        self::assertContains('ck_a', $refs);
        self::assertNotContains('ck_b', $refs);
        self::assertSame(100.0, $this->indexByRef($profile)['ck_a']['weight']);
    }

    public function test_b_unclustered_excluded_from_denominator(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 70);
        $this->seedCluster('ck_b', 'Cluster B', 30);
        $this->seedUnclustered(100);

        $profile = $this->builder()->build(self::SITE_ID);

        self::assertSame(100, $profile['total_clustered_keywords']);
        self::assertCount(2, $profile['topics']);
        self::assertSame(70.0, $this->indexByRef($profile)['ck_a']['weight']);
    }

    public function test_c_rename_uses_current_canonical_phrase(): void
    {
        $this->seedCluster('ck_may', 'may', 10);
        SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_ID)
            ->where('cluster_key', 'ck_may')
            ->update([
                'canonical_phrase' => 'may balo',
                'normalized_canonical' => 'may balo',
            ]);

        $profile = $this->builder()->build(self::SITE_ID);

        self::assertSame('may balo', $this->indexByRef($profile)['ck_may']['name']);
        self::assertNotContains('may', SiteMcpClusterTopicalProfileBuilder::topicNames($profile));
    }

    public function test_d_membership_change_updates_weight(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 50);
        $this->seedCluster('ck_b', 'Cluster B', 50);
        $before = $this->indexByRef($this->builder()->build(self::SITE_ID));
        self::assertSame(50.0, $before['ck_a']['weight']);

        // Move 30 members from B → A by creating additional A keywords.
        $this->seedClusterMembers('ck_a', 'Cluster A', 30);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_a'],
            [
                'canonical_phrase' => 'Cluster A',
                'normalized_canonical' => 'cluster a',
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => 'auto',
            ],
        );

        $after = $this->indexByRef($this->builder()->build(self::SITE_ID));
        self::assertSame(80, $after['ck_a']['keyword_count']);
        self::assertSame(61.5, $after['ck_a']['weight']); // 80/130
        self::assertSame(38.5, $after['ck_b']['weight']); // 50/130
    }

    public function test_e_manual_empty_planned_cluster_included_with_zero_weight(): void
    {
        $this->seedCluster('ck_a', 'Cluster A', 10);
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_canvas',
            'canonical_phrase' => 'May túi canvas',
            'normalized_canonical' => 'may tui canvas',
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
        ]);

        $profile = $this->builder()->build(self::SITE_ID);
        $byRef = $this->indexByRef($profile);

        self::assertArrayHasKey('ck_canvas', $byRef);
        self::assertSame(0.0, $byRef['ck_canvas']['weight']);
        self::assertSame('planned', $byRef['ck_canvas']['state']);
        self::assertSame('high', $byRef['ck_canvas']['priority']);
        self::assertSame(SeoTopicClusterMeta::SOURCE_MANUAL, $byRef['ck_canvas']['source']);
        self::assertSame(10, $profile['total_clustered_keywords']);
    }

    public function test_f_auto_singleton_excluded(): void
    {
        $this->seedCluster('ck_real', 'Real Cluster', 5);
        $this->seedCluster('ck_single', 'Singleton Auto', 1);

        $profile = $this->builder()->build(self::SITE_ID);
        $refs = array_column($profile['topics'], 'cluster_ref');

        self::assertContains('ck_real', $refs);
        self::assertNotContains('ck_single', $refs);
    }

    public function test_g_generator_uses_cluster_profile_not_product_cat_duplicate(): void
    {
        $this->seedCluster('ck_may', 'may balo', 8);
        $profile = $this->builder()->build(self::SITE_ID);

        $draft = $this->generator()->buildFromDiscovery([
            'domain' => 'example.test',
            'website_type' => 'production',
            'discovery_strategy' => 'production_catalog',
            'site_title' => 'Shop',
            'brand' => 'Shop',
            'official' => [],
            'official_exists' => false,
            'product_categories' => [
                [
                    'url' => 'https://example.test/cat/may-balo/',
                    'title' => 'may balo',
                    'seo_title' => 'may balo',
                    'page_type' => 'product_category',
                    'focus_keyword' => 'may balo',
                    'taxonomy' => 'product_cat',
                    'term_id' => 10,
                    'parent_term_id' => 0,
                ],
            ],
            'products' => [],
            'counts' => ['post' => 0, 'page' => 0, 'product' => 0, 'product_cat' => 1],
            'availability' => ['product_cat_taxonomy' => 'available'],
        ], $profile);

        self::assertSame(['may balo'], $draft['keyword_context']['main_topics']);
        self::assertCount(1, $draft['keyword_context']['main_topics']);
        self::assertSame('keyword_cluster', $draft['keyword_context']['main_topic_records'][0]['source_type']);
        self::assertSame('ck_may', $draft['keyword_context']['main_topic_records'][0]['cluster_ref']);
        self::assertSame('keyword_clusters.v1', $draft['generation']['topical_source']);

        $preview = (new SiteMcpContextAssembler)->keywordContext($draft);
        self::assertStringContainsString('Topical profile:', $preview['text']);
        self::assertStringContainsString('may balo — 100%', $preview['text']);
        self::assertStringNotContainsString("\n- may balo\n- may balo", $preview['text']);
    }

    public function test_weight_helper_and_stale_flag(): void
    {
        self::assertSame(70.0, SiteMcpClusterTopicalProfileBuilder::weightForCount(70, 100));
        self::assertSame(0.0, SiteMcpClusterTopicalProfileBuilder::weightForCount(0, 100));

        SiteMcpTopicalProfileStaleState::mark(self::SITE_ID, 'recluster_completed');
        self::assertTrue(SiteMcpTopicalProfileStaleState::isStale(self::SITE_ID));
        SiteMcpTopicalProfileStaleState::clear(self::SITE_ID);
        self::assertFalse(SiteMcpTopicalProfileStaleState::isStale(self::SITE_ID));
    }

    private function builder(): SiteMcpClusterTopicalProfileBuilder
    {
        return new SiteMcpClusterTopicalProfileBuilder(
            app(KeywordClusterQuery::class),
            app(KeywordGroupCoverageBuilder::class),
            app()->bound(KeywordDnaService::class) ? app(KeywordDnaService::class) : null,
        );
    }

    private function generator(): SiteMcpGenerator
    {
        return app(SiteMcpGenerator::class);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexByRef(array $profile): array
    {
        $out = [];
        foreach ($profile['topics'] as $topic) {
            $out[(string) $topic['cluster_ref']] = $topic;
        }

        return $out;
    }

    private function seedCluster(string $key, string $label, int $count, string $intent = 'commercial'): void
    {
        $this->seedClusterMembers($key, $label, $count, $intent);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => $key],
            [
                'canonical_phrase' => $label,
                'normalized_canonical' => mb_strtolower($label),
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => SeoTopicClusterMeta::SOURCE_AUTO,
            ],
        );
    }

    private function seedClusterMembers(string $key, string $label, int $count, string $intent = 'commercial'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $phrase = $label.' '.$i.' '.uniqid('', true);
            $this->insertKeyword($phrase, $key, $intent, withArticle: true);
        }
    }

    private function seedClusterMembersOnly(string $key, string $label, int $count, string $intent = 'commercial'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $phrase = $label.' '.$i.' '.uniqid('', true);
            $this->insertKeyword($phrase, $key, $intent, withArticle: false);
        }
    }

    private function seedUnclustered(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->insertKeyword('unclustered '.$i.' '.uniqid('', true), null, 'informational', withArticle: true);
        }
    }

    private function insertKeyword(string $phrase, ?string $clusterKey, string $intent, bool $withArticle = true): void
    {
        $norm = app(KeywordNormalizer::class)->normalize($phrase);
        $keyword = Keyword::query()->create(['phrase' => $phrase, 'type' => Keyword::TYPE_NORMAL]);

        if ($withArticle) {
            $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
                'site_id' => self::SITE_ID,
                'title' => 'A '.$phrase,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
                'keyword_id' => $keyword->id,
                'source_article_id' => $articleId,
                'target_article_id' => $articleId,
                'anchor_text' => 'x',
                'link_type' => 'internal',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => $keyword->id,
                'meta_key' => 'site.'.self::SITE_ID.'.target_url',
                'meta_value' => 'https://example.test/'.md5($phrase),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        SeoKeywordClassification::query()->insert([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => $norm['normalized_text'],
            'folded_text' => $norm['folded_text'],
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => $intent,
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => 1,
            'is_ambiguous' => 0,
            'keyword_score' => 0.8,
            'input_hash' => hash('sha256', $phrase),
            'classification_hash' => hash('sha256', 'x'),
            'classified_at' => now(),
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
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('target_article_id')->nullable();
            $table->string('anchor_text')->nullable();
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_classifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id')->primary();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key', 120)->nullable()->index();
            $table->boolean('is_seo_keyword')->default(true);
            $table->boolean('is_ambiguous')->default(false);
            $table->float('keyword_score')->nullable();
            $table->string('input_hash')->nullable();
            $table->string('classification_hash')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('cluster_key', 120);
            $table->string('canonical_phrase', 255);
            $table->string('normalized_canonical', 255);
            $table->string('confidence', 20)->default('high');
            $table->boolean('needs_review')->default(false);
            $table->string('canonical_source', 16)->default('auto');
            $table->boolean('mcp_excluded')->default(false);
            $table->boolean('seo_excluded')->default(false);
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('cluster_key', 120);
            $table->string('alias_phrase', 255);
            $table->string('normalized_alias', 255);
            $table->timestamps();
            $table->unique(['site_id', 'normalized_alias']);
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('keyword_id');
            $table->string('cluster_key', 120);
            $table->string('value', 120);
            $table->string('normalized_value', 120);
            $table->string('facet_type', 32)->nullable();
            $table->string('confidence', 20)->nullable();
            $table->string('source', 32)->default('deterministic');
            $table->timestamps();
            $table->unique(['keyword_id', 'normalized_value']);
        });
    }
}
