<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReconcileFocusArticleTopicsService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Tests\TestCase;

final class FocusArticleTopicInvariantTest extends TestCase
{
    private const SITE_ID = 501;

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

    public function test_focus_article_keyword_without_topic_gets_singleton(): void
    {
        $kid = $this->seedKeyword('Artistic Pop Unique Xyz', null, withFocus: true);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($result->success, (string) ($result->error ?? ''));

        $ck = trim((string) SeoKeywordClassification::query()->where('keyword_id', $kid)->value('cluster_key'));
        self::assertNotSame('', $ck);
        self::assertGreaterThanOrEqual(1, (int) ($result->metrics['focus_singletons_created'] ?? 0)
            + (int) ($result->metrics['focus_singletons_kept'] ?? 0)
            + (int) ($result->metrics['self_clusters_created'] ?? 0));
        self::assertSame(0, (int) ($result->metrics['focus_orphans_after'] ?? -1));
    }

    public function test_focus_article_keyword_attaches_to_existing_manual_topic_via_product_core(): void
    {
        $root = $this->seedKeyword('Túi Vải Không Dệt', 'ck_tui_vai', withFocus: true);
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_tui_vai',
            'canonical_phrase' => 'Túi Vải Không Dệt',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Túi Vải Không Dệt'),
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
        ]);
        // Extra member so topic survives as multi-member after rebuild paths.
        $this->seedKeyword('túi vải không dệt dây rút', 'ck_tui_vai', withFocus: false);

        $orphan = $this->seedKeyword('Đặt may túi vải không dệt', null, withFocus: true);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($result->success, (string) ($result->error ?? ''));

        $orphanCk = trim((string) SeoKeywordClassification::query()->where('keyword_id', $orphan)->value('cluster_key'));
        self::assertNotSame('', $orphanCk);

        $rootCk = trim((string) SeoKeywordClassification::query()->where('keyword_id', $root)->value('cluster_key'));
        self::assertNotSame('', $rootCk);
        self::assertSame($rootCk, $orphanCk, 'Service-intent Focus keyword should join product Topic via contiguous core');
        self::assertSame(0, (int) ($result->metrics['focus_orphans_after'] ?? -1));
    }

    public function test_manual_topic_and_canonical_preserved_after_recluster(): void
    {
        $solo = $this->seedKeyword('brand riêng biệt abc focus', null, withFocus: true);
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_manual_solo',
            'canonical_phrase' => 'brand riêng biệt abc focus',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('brand riêng biệt abc focus'),
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
        ]);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($result->success);

        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_ID)
            ->where('canonical_source', SeoTopicClusterMeta::SOURCE_MANUAL)
            ->where('canonical_phrase', 'brand riêng biệt abc focus')
            ->first();
        self::assertNotNull($meta);
        self::assertSame(
            (string) $meta->cluster_key,
            (string) SeoKeywordClassification::query()->where('keyword_id', $solo)->value('cluster_key'),
        );
    }

    public function test_recluster_does_not_orphan_focus_article_keyword(): void
    {
        $kid = $this->seedKeyword('khóa kéo ykk focus solo', null, withFocus: true);

        $first = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($first->success);
        $ck1 = trim((string) SeoKeywordClassification::query()->where('keyword_id', $kid)->value('cluster_key'));
        self::assertNotSame('', $ck1);

        $second = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($second->success);
        $ck2 = trim((string) SeoKeywordClassification::query()->where('keyword_id', $kid)->value('cluster_key'));
        self::assertNotSame('', $ck2);
        self::assertSame(0, (int) ($second->metrics['focus_orphans_after'] ?? -1));
    }

    public function test_topic_focus_article_count_uses_distinct_main_articles(): void
    {
        $a = $this->seedKeyword('túi đựng mỹ phẩm alpha', 'ck_focus_count', withFocus: false);
        $b = $this->seedKeyword('túi đựng mỹ phẩm beta', 'ck_focus_count', withFocus: false);
        $articleShared = $this->createArticle('Shared Focus');
        $articleB = $this->createArticle('Focus B');
        $this->setMainArticle($a, $articleShared);
        $this->setMainArticle($b, $articleB);
        // Same shared article also as link target on A — must not double-count.
        DB::connection('omi_seo_ai')->table('seo_link_maps')->where('keyword_id', $a)->update([
            'target_article_id' => $articleShared,
        ]);

        $stats = app(KeywordClusterQuery::class)->memberLinkStats([$a, $b]);
        self::assertSame(2, $stats['article_count'], 'Shared + B = 2 distinct');

        $counts = app(KeywordClusterQuery::class)->focusArticleCountsByClusterKey(
            ['ck_focus_count'],
            self::SITE_ID,
        );
        self::assertSame(2, (int) ($counts['ck_focus_count'] ?? 0));
    }

    public function test_after_normalization_no_focus_keyword_has_null_topic(): void
    {
        $this->seedKeyword('Túi Vải Không Dệt', 'ck_tvkd', withFocus: true);
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_tvkd',
            'canonical_phrase' => 'Túi Vải Không Dệt',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Túi Vải Không Dệt'),
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
        ]);
        $this->seedKeyword('Sản xuất túi vải không dệt', null, withFocus: true);
        $this->seedKeyword('Unique Focus Singleton Zzz', null, withFocus: true);
        $this->seedKeyword('no focus auto prune phrase', null, withFocus: false);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_ID);
        self::assertTrue($result->success);

        $orphanCount = count(app(ReconcileFocusArticleTopicsService::class)->loadOrphanFocusKeywords(self::SITE_ID));
        self::assertSame(0, $orphanCount);
        self::assertSame(0, (int) ($result->metrics['focus_orphans_after'] ?? -1));
    }

    private function seedKeyword(string $phrase, ?string $clusterKey, bool $withFocus): int
    {
        $norm = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer::class)
            ->normalize($phrase);
        $articleId = $this->createArticle('A '.$phrase);
        $keyword = Keyword::query()->create(['phrase' => $phrase, 'type' => Keyword::TYPE_NORMAL]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $keyword->id,
            'source_article_id' => $articleId,
            'target_article_id' => null,
            'anchor_text' => 'x',
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'raw_text' => $phrase,
            'normalized_text' => $norm['normalized_text'],
            'folded_text' => $norm['folded_text'],
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => true,
            'is_dirty' => false,
        ]);
        if ($withFocus) {
            $this->setMainArticle((int) $keyword->id, $articleId);
        }

        return (int) $keyword->id;
    }

    private function createArticle(string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE_ID,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setMainArticle(int $keywordId, int $articleId): void
    {
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keywordId,
            'meta_key' => KeywordMetaKey::MainArticleId->value,
            'meta_value' => (string) $articleId,
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
            $table->string('language')->nullable();
            $table->softDeletes();
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
            $table->string('raw_text')->nullable();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key')->nullable()->index();
            $table->boolean('is_seo_keyword')->nullable();
            $table->boolean('is_dirty')->nullable();
            $table->unsignedBigInteger('canonical_keyword_id')->nullable();
            $table->boolean('is_anchor_candidate')->nullable();
            $table->integer('anchor_priority')->nullable();
            $table->float('classification_confidence')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->string('input_hash')->nullable();
            $table->string('classification_hash')->nullable();
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
            $table->string('normalized_value')->index();
            $table->string('facet_type')->nullable();
            $table->string('confidence')->nullable();
            $table->string('source')->default('deterministic');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['keyword_id', 'normalized_value'], 'seo_kw_dna_kw_norm');
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
    }
}
