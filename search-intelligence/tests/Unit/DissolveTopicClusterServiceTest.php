<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use ReflectionClass;
use Tests\TestCase;

final class DissolveTopicClusterServiceTest extends TestCase
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

    public function test_cluster_exists_helper_distinguishes_missing_cluster(): void
    {
        $query = app(KeywordClusterQuery::class);
        self::assertFalse($query->clusterExists('missing_cluster_key'));
    }

    public function test_member_keyword_ids_without_site_scope_returns_cluster_members(): void
    {
        $this->seedClusteredKeyword(self::SITE_A, 'unscoped kw', 'unscoped_cluster', 'informational');

        $ids = app(KeywordClusterQuery::class)->memberKeywordIds(null, 'unscoped_cluster');

        self::assertCount(1, $ids);
    }

    public function test_only_cluster_key_field_is_cleared(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'field guard', 'field_guard_cluster', 'informational', [
            'phrase_kind' => 'keyword_phrase',
            'is_seo_keyword' => true,
            'keyword_score' => 0.91,
            'normalized_text' => 'field guard',
            'folded_text' => 'field guard',
        ]);

        $before = SeoKeywordClassification::query()->find((int) $keyword->id)?->toArray();
        self::assertIsArray($before);

        $this->service()->dissolve(self::SITE_A, 'field_guard_cluster');

        $after = SeoKeywordClassification::query()->find((int) $keyword->id)?->toArray();
        self::assertIsArray($after);
        self::assertNull($after['cluster_key']);
        unset($before['cluster_key'], $after['cluster_key'], $before['updated_at'], $after['updated_at']);
        self::assertSame($before, $after);
    }

    public function test_multi_keyword_cluster_updates_summary_counts_correctly(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->seedClusteredKeyword(self::SITE_A, 'bulk kw '.$i, 'bulk_cluster', 'informational');
        }
        $this->seedClusteredKeyword(self::SITE_A, 'solo kw', 'solo_cluster', 'transactional');

        $query = app(KeywordClusterQuery::class);
        $before = $query->summary(self::SITE_A);

        $this->service()->dissolve(self::SITE_A, 'bulk_cluster');

        $after = $query->summary(self::SITE_A);
        self::assertSame((int) $before['topic_clusters'] - 1, (int) $after['topic_clusters']);
        self::assertSame((int) $before['clustered'] - 8, (int) $after['clustered']);
        self::assertSame((int) $before['unclustered'] + 8, (int) $after['unclustered']);
        self::assertSame((int) $before['system_groups'], (int) $after['system_groups']);
    }

    public function test_dissolves_a_one_keyword_cluster(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'tui day rut', 'tui_day_rut', 'navigational');

        $result = $this->service()->dissolve(self::SITE_A, 'tui_day_rut');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->affectedKeywordCount);
        $this->assertNull($this->classificationClusterKey((int) $keyword->id));
        $this->assertSame('tui day rut', Keyword::query()->find($keyword->id)?->phrase);
    }

    public function test_dissolves_a_multi_keyword_cluster(): void
    {
        $first = $this->seedClusteredKeyword(self::SITE_A, 'tui day rut', 'tui_day_rut', 'navigational');
        $second = $this->seedClusteredKeyword(self::SITE_A, 'tui rut gia re', 'tui_day_rut', 'commercial');

        $result = $this->service()->dissolve(self::SITE_A, 'tui_day_rut');

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->affectedKeywordCount);
        $this->assertNull($this->classificationClusterKey((int) $first->id));
        $this->assertNull($this->classificationClusterKey((int) $second->id));
    }

    public function test_all_members_become_unclustered(): void
    {
        $this->seedClusteredKeyword(self::SITE_A, 'alpha', 'shared_cluster', 'informational');
        $this->seedClusteredKeyword(self::SITE_A, 'beta', 'shared_cluster', 'transactional');

        $this->service()->dissolve(self::SITE_A, 'shared_cluster');

        $clustered = SeoKeywordClassification::query()
            ->where('cluster_key', 'shared_cluster')
            ->count();
        $this->assertSame(0, $clustered);
    }

    public function test_keywords_themselves_are_not_deleted(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'keep me', 'keep_cluster', 'informational');

        $this->service()->dissolve(self::SITE_A, 'keep_cluster');

        $this->assertNotNull(Keyword::query()->find($keyword->id));
    }

    public function test_article_relationships_remain_intact(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'linked kw', 'link_cluster', 'informational');
        $articleId = $this->createArticle(self::SITE_A, 'Pillar article');
        $linkMapId = $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        $this->service()->dissolve(self::SITE_A, 'link_cluster');

        $this->assertDatabaseHas('seo_link_maps', ['id' => $linkMapId], 'omi_seo_ai');
    }

    public function test_internal_link_relationships_remain_intact(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'anchor kw', 'anchor_cluster', 'commercial');
        $sourceArticleId = $this->createArticle(self::SITE_A, 'Source');
        $targetArticleId = $this->createArticle(self::SITE_A, 'Target');
        $linkMapId = $this->createLinkMap((int) $keyword->id, $sourceArticleId, $targetArticleId);

        $this->service()->dissolve(self::SITE_A, 'anchor_cluster');

        $row = DB::connection('omi_seo_ai')->table('seo_link_maps')->find($linkMapId);
        $this->assertNotNull($row);
        $this->assertSame($sourceArticleId, (int) $row->source_article_id);
        $this->assertSame($targetArticleId, (int) $row->target_article_id);
    }

    public function test_keyword_intent_tags_and_classification_remain_unchanged(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'intent kw', 'intent_cluster', 'navigational', [
            'phrase_kind' => 'keyword_phrase',
            'is_seo_keyword' => true,
            'keyword_score' => 0.88,
        ]);

        $this->service()->dissolve(self::SITE_A, 'intent_cluster');

        $classification = SeoKeywordClassification::query()->find((int) $keyword->id);
        $this->assertInstanceOf(SeoKeywordClassification::class, $classification);
        $this->assertSame('navigational', (string) $classification->seo_intent);
        $this->assertSame('keyword_phrase', (string) $classification->phrase_kind);
        $this->assertTrue((bool) $classification->is_seo_keyword);
        $this->assertSame(0.88, (float) $classification->keyword_score);
    }

    public function test_cluster_from_another_site_cannot_be_modified(): void
    {
        $siteAKeyword = $this->seedClusteredKeyword(self::SITE_A, 'site a kw', 'cross_site_cluster', 'informational');
        $siteBKeyword = $this->seedClusteredKeyword(self::SITE_B, 'site b kw', 'cross_site_cluster', 'commercial');

        $result = $this->service()->dissolve(self::SITE_A, 'cross_site_cluster');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->affectedKeywordCount);
        $this->assertNull($this->classificationClusterKey((int) $siteAKeyword->id));
        $this->assertSame('cross_site_cluster', $this->classificationClusterKey((int) $siteBKeyword->id));
    }

    public function test_only_keywords_from_target_site_are_affected(): void
    {
        $siteA = $this->seedClusteredKeyword(self::SITE_A, 'only a', 'scoped_cluster', 'informational');
        $siteB = $this->seedClusteredKeyword(self::SITE_B, 'only b', 'scoped_cluster', 'informational');

        $this->service()->dissolve(self::SITE_B, 'scoped_cluster');

        $this->assertNull($this->classificationClusterKey((int) $siteB->id));
        $this->assertSame('scoped_cluster', $this->classificationClusterKey((int) $siteA->id));
    }

    public function test_action_is_idempotent(): void
    {
        $this->seedClusteredKeyword(self::SITE_A, 'once', 'idempotent_cluster', 'informational');

        $first = $this->service()->dissolve(self::SITE_A, 'idempotent_cluster');
        $second = $this->service()->dissolve(self::SITE_A, 'idempotent_cluster');

        $this->assertSame(1, $first->affectedKeywordCount);
        $this->assertTrue($second->wasAlreadyEmpty);
        $this->assertSame(0, $second->affectedKeywordCount);
    }

    public function test_dissolve_writes_manual_exclude_meta(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'exclude me', 'exclude_cluster', 'informational');

        $this->service()->dissolve(self::SITE_A, 'exclude_cluster');

        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => (int) $keyword->id,
            'meta_key' => ReclusterTopicClustersService::META_MANUAL_EXCLUDE,
            'meta_value' => '1',
        ], 'omi_seo_ai');
    }

    public function test_dissolve_purges_derived_cluster_artifacts(): void
    {
        $first = $this->seedClusteredKeyword(self::SITE_A, 'alpha kw', 'purge_cluster', 'informational');
        $second = $this->seedClusteredKeyword(self::SITE_A, 'beta kw', 'purge_cluster', 'commercial');

        DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')->insert([
            'site_id' => self::SITE_A,
            'cluster_key' => 'purge_cluster',
            'canonical_phrase' => 'purge',
            'normalized_canonical' => 'purge',
            'confidence' => 'high',
            'needs_review' => 0,
            'canonical_source' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('seo_topic_cluster_aliases')->insert([
            'site_id' => self::SITE_A,
            'cluster_key' => 'purge_cluster',
            'alias_phrase' => 'alpha kw',
            'normalized_alias' => 'alpha kw',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('seo_keyword_dna')->insert([
            'site_id' => self::SITE_A,
            'keyword_id' => (int) $first->id,
            'cluster_key' => 'purge_cluster',
            'value' => 'alpha',
            'normalized_value' => 'alpha',
            'source' => 'deterministic',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service()->dissolve(self::SITE_A, 'purge_cluster');

        self::assertTrue($result->success);
        self::assertSame(2, $result->affectedKeywordCount);
        self::assertNull($this->classificationClusterKey((int) $first->id));
        self::assertNull($this->classificationClusterKey((int) $second->id));
        self::assertNotNull(Keyword::query()->find($first->id));
        self::assertNotNull(Keyword::query()->find($second->id));
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')->where('cluster_key', 'purge_cluster')->count());
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('seo_topic_cluster_aliases')->where('cluster_key', 'purge_cluster')->count());
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('seo_keyword_dna')->where('cluster_key', 'purge_cluster')->count());
    }

    public function test_no_automatic_reclustering_is_triggered(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DissolveTopicClusterService::class))->getFileName(),
        );

        self::assertStringNotContainsString('KeywordClassificationService', $source);
        self::assertStringNotContainsString('KeywordClusterService', $source);
        self::assertStringNotContainsString('RecomputeKeyword', $source);
        self::assertStringNotContainsString('dispatch(', $source);
        self::assertStringNotContainsString('Job::', $source);
    }

    public function test_cluster_list_and_summary_reflect_removal(): void
    {
        $this->seedClusteredKeyword(self::SITE_A, 'one', 'summary_cluster', 'informational');
        $this->seedClusteredKeyword(self::SITE_A, 'two', 'summary_cluster', 'commercial');
        $this->seedClusteredKeyword(self::SITE_A, 'solo', 'other_cluster', 'transactional');

        $query = app(KeywordClusterQuery::class);
        $before = $query->summary(self::SITE_A);
        $this->assertSame(2, $before['topic_clusters']);
        $this->assertSame(3, $before['clustered']);

        $this->service()->dissolve(self::SITE_A, 'summary_cluster');

        $after = $query->summary(self::SITE_A);
        $this->assertSame(1, $after['topic_clusters']);
        $this->assertSame(1, $after['clustered']);
        $this->assertSame(2, $after['unclustered']);
    }

    public function test_no_persisted_cluster_entity_is_deleted(): void
    {
        $keyword = $this->seedClusteredKeyword(self::SITE_A, 'virtual cluster', 'virtual_cluster', 'informational');

        $this->service()->dissolve(self::SITE_A, 'virtual_cluster');

        $this->assertNotNull(Keyword::query()->find($keyword->id));
        $this->assertNotNull(SeoKeywordClassification::query()->find((int) $keyword->id));
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasTable('seo_topic_ui_clusters'));
    }

    private function service(): DissolveTopicClusterService
    {
        return app(DissolveTopicClusterService::class);
    }

    /**
     * @param  array<string, mixed>  $classificationOverrides
     */
    private function seedClusteredKeyword(
        int $siteId,
        string $phrase,
        string $clusterKey,
        string $intent,
        array $classificationOverrides = [],
    ): Keyword {
        $articleId = $this->createArticle($siteId, 'Scope article for '.$phrase);
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        SeoKeywordClassification::query()->create(array_merge([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => $intent,
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => true,
            'keyword_score' => 0.75,
            'classified_at' => now(),
        ], $classificationOverrides));

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
            $table->decimal('keyword_score', 5, 2)->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key');
            $table->string('canonical_phrase');
            $table->string('normalized_canonical');
            $table->string('confidence')->default('high');
            $table->boolean('needs_review')->default(false);
            $table->string('canonical_source')->default('auto');
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key');
            $table->string('alias_phrase');
            $table->string('normalized_alias');
            $table->timestamps();
            $table->unique(['site_id', 'normalized_alias']);
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('cluster_key')->index();
            $table->string('value');
            $table->string('normalized_value');
            $table->string('facet_type')->nullable();
            $table->string('confidence')->nullable();
            $table->string('source')->default('deterministic');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }
}
