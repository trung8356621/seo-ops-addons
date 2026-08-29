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
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReconcileFocusArticleTopicsService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use Tests\TestCase;

final class TopicMembershipIntentGateTest extends TestCase
{
    private const SITE_ID = 88;

    private const TOPIC_KEY = 'ck_tui_vai_khong_det';

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

    public function test_contains_canonical_core_for_topic_ignores_service_product_intent(): void
    {
        $resolver = app(CanonicalClusterPhraseResolver::class);
        $canonical = 'Túi Vải Không Dệt';

        self::assertFalse($resolver->intentCompatible('Đặt may túi vải không dệt', $canonical));
        self::assertFalse($resolver->containsCanonicalCore('Đặt may túi vải không dệt', $canonical));
        self::assertTrue($resolver->containsCanonicalCoreForTopic('Đặt may túi vải không dệt', $canonical));
        self::assertTrue($resolver->containsCanonicalCoreForTopic(
            'Sản xuất túi vải không dệt tại tp.hcm',
            $canonical,
        ));
    }

    public function test_service_intent_focus_keyword_joins_product_topic_via_fix(): void
    {
        $this->seedTopic('Túi Vải Không Dệt');
        $this->seedKeyword('túi vải không dệt', self::TOPIC_KEY, withFocus: true);
        $orphan = $this->seedKeyword('Đặt may túi vải không dệt', null, withFocus: true);

        $result = app(UpdateClusterCanonicalService::class)
            ->reconcileMembership(self::SITE_ID, self::TOPIC_KEY);

        self::assertSame(
            self::TOPIC_KEY,
            (string) SeoKeywordClassification::query()->where('keyword_id', $orphan)->value('cluster_key'),
        );
        self::assertSame(0, (int) ($result['focus_orphans_after'] ?? -1));
        self::assertGreaterThanOrEqual(1, (int) $result['attached']);
    }

    public function test_local_modifier_service_phrase_joins_topic(): void
    {
        $this->seedTopic('Túi Vải Không Dệt');
        $this->seedKeyword('túi vải không dệt', self::TOPIC_KEY, withFocus: false);
        $orphan = $this->seedKeyword('Sản xuất túi vải không dệt tại tp.hcm', null, withFocus: true);

        app(UpdateClusterCanonicalService::class)->reconcileMembership(self::SITE_ID, self::TOPIC_KEY);

        self::assertSame(
            self::TOPIC_KEY,
            (string) SeoKeywordClassification::query()->where('keyword_id', $orphan)->value('cluster_key'),
        );
    }

    public function test_unrelated_focus_keyword_gets_some_topic_not_null(): void
    {
        $this->seedTopic('Túi Vải Không Dệt');
        $this->seedKeyword('túi vải không dệt', self::TOPIC_KEY, withFocus: true);
        $unrelated = $this->seedKeyword('Artistic Pop Unique Focus Zzz', null, withFocus: true);

        $result = app(UpdateClusterCanonicalService::class)
            ->reconcileMembership(self::SITE_ID, self::TOPIC_KEY);

        $ck = trim((string) SeoKeywordClassification::query()->where('keyword_id', $unrelated)->value('cluster_key'));
        self::assertNotSame('', $ck);
        self::assertNotSame(self::TOPIC_KEY, $ck, 'Unrelated phrase must not join wrong umbrella Topic');
        self::assertSame(0, (int) ($result['focus_orphans_after'] ?? -1));
    }

    public function test_hard_invariant_zero_orphan_focus_after_reconcile(): void
    {
        $this->seedTopic('Túi Vải Không Dệt');
        $this->seedKeyword('túi vải không dệt', self::TOPIC_KEY, withFocus: true);
        $this->seedKeyword('Đặt may túi vải không dệt', null, withFocus: true);
        $this->seedKeyword('Sản xuất túi vải không dệt', null, withFocus: true);
        $this->seedKeyword('Xưởng May Túi Vải Không Dệt', null, withFocus: true);
        $this->seedKeyword('Unique Focus Singleton Abc', null, withFocus: true);

        app(UpdateClusterCanonicalService::class)->reconcileMembership(self::SITE_ID, self::TOPIC_KEY);

        $orphans = app(ReconcileFocusArticleTopicsService::class)->loadOrphanFocusKeywords(self::SITE_ID);
        self::assertSame(0, count($orphans));
    }

    public function test_manual_canonical_preserved_after_reconcile(): void
    {
        $this->seedTopic('Túi Vải Không Dệt', manual: true);
        $this->seedKeyword('túi vải không dệt', self::TOPIC_KEY, withFocus: true);
        $this->seedKeyword('Đặt may túi vải không dệt', null, withFocus: true);

        app(UpdateClusterCanonicalService::class)->reconcileMembership(self::SITE_ID, self::TOPIC_KEY);

        $meta = SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_ID)
            ->where('cluster_key', self::TOPIC_KEY)
            ->first();
        self::assertNotNull($meta);
        self::assertSame(SeoTopicClusterMeta::SOURCE_MANUAL, (string) $meta->canonical_source);
        self::assertSame('Túi Vải Không Dệt', (string) $meta->canonical_phrase);
    }

    private function seedTopic(string $canonical, bool $manual = true): void
    {
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => self::TOPIC_KEY,
            'canonical_phrase' => $canonical,
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey($canonical),
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => $manual ? SeoTopicClusterMeta::SOURCE_MANUAL : SeoTopicClusterMeta::SOURCE_AUTO,
        ]);
    }

    private function seedKeyword(string $phrase, ?string $clusterKey, bool $withFocus): int
    {
        $norm = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer::class)
            ->normalize($phrase);
        $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE_ID,
            'title' => 'A '.$phrase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => (int) $keyword->id,
                'meta_key' => KeywordMetaKey::MainArticleId->value,
                'meta_value' => (string) $articleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $keyword->id;
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
            $table->unique(['keyword_id', 'normalized_value'], 'seo_kw_dna_kw_norm3');
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
