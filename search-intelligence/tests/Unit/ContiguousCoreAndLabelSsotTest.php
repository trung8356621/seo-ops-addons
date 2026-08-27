<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Tests\TestCase;

final class ContiguousCoreAndLabelSsotTest extends TestCase
{
    private const SITE = 77;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'queue.default' => 'sync',
        ]);

        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_contiguous_core_matches_and_rejects_gapped_tokens(): void
    {
        $r = app(CanonicalClusterPhraseResolver::class);

        self::assertTrue($r->containsCanonicalCore('xưởng may balo dây rút', 'xưởng may balo'));
        self::assertTrue($r->containsCanonicalCore(
            'Hợp Phát - xưởng may balo giá rẻ uy tín chất lượng',
            'xưởng may balo',
        ));
        self::assertTrue($r->containsCanonicalCore('cách may balo da đơn giản', 'may balo da'));

        self::assertFalse($r->containsCanonicalCore(
            'Các kỹ thuật may chuyên dụng cho da nhân tạo',
            'may balo da',
        ));
        self::assertFalse($r->containsCanonicalCore(
            'các mẫu balo thời trang may',
            'may balo da',
        ));
        self::assertFalse($r->containsCanonicalCore(
            'cách kiểm hàng may mặc',
            'may balo da',
        ));
        self::assertFalse($r->containsCanonicalCore(
            'các loại vải dùng trong may mặc thời trang',
            'may balo da',
        ));
    }

    public function test_best_canonical_prefers_more_specific_contiguous_core(): void
    {
        $this->seedMeta('ck_balo', 'balo');
        $this->seedKeyword('balo', 'ck_balo');
        $this->seedMeta('ck_xmb', 'Xưởng may balo');
        $this->seedKeyword('Xưởng may balo', 'ck_xmb');
        $kid = $this->seedKeyword('xưởng may balo giá rẻ', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE);

        self::assertSame(
            'ck_xmb',
            SeoKeywordClassification::query()->where('keyword_id', $kid)->value('cluster_key'),
        );
    }

    public function test_polluted_member_detached_on_recluster(): void
    {
        $this->seedMeta('ck_mbd', 'may balo da');
        $this->seedKeyword('cách may balo da đơn giản', 'ck_mbd');
        $badId = $this->seedKeyword('Các kỹ thuật may chuyên dụng cho da nhân tạo', 'ck_mbd');
        $goodXuong = $this->seedKeyword('xưởng may balo dây rút', 'ck_xmb');
        $this->seedMeta('ck_xmb', 'Xưởng may balo');
        $this->seedKeyword('Xưởng may balo', 'ck_xmb');

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE);
        self::assertTrue($result->success);

        $badCk = SeoKeywordClassification::query()->where('keyword_id', $badId)->value('cluster_key');
        self::assertNotSame('ck_mbd', $badCk);

        self::assertSame(
            'ck_xmb',
            SeoKeywordClassification::query()->where('keyword_id', $goodXuong)->value('cluster_key'),
        );

        self::assertSame(
            'ck_mbd',
            SeoKeywordClassification::query()
                ->where('keyword_id', Keyword::query()->where('phrase', 'cách may balo da đơn giản')->value('id'))
                ->value('cluster_key'),
        );
    }

    public function test_semantic_fallback_rejects_generic_may_overlap(): void
    {
        $this->seedMeta('ck_mbd', 'may balo da');
        $this->seedKeyword('may balo da', 'ck_mbd');
        $a = $this->seedKeyword('cách kiểm hàng may mặc', null);
        $b = $this->seedKeyword('các loại vải dùng trong may mặc thời trang', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE);

        self::assertNotSame('ck_mbd', SeoKeywordClassification::query()->where('keyword_id', $a)->value('cluster_key'));
        self::assertNotSame('ck_mbd', SeoKeywordClassification::query()->where('keyword_id', $b)->value('cluster_key'));
    }

    public function test_cluster_index_and_detail_use_canonical_meta_not_member_phrase(): void
    {
        $this->seedMeta('ck_mbd', 'may balo da');
        $this->seedKeyword('Các kỹ thuật may chuyên dụng cho da nhân tạo', 'ck_mbd');
        $this->seedKeyword('may balo da', 'ck_mbd');

        $query = app(KeywordClusterQuery::class);
        $indexLabel = $query->displayLabel('ck_mbd', 'Các kỹ thuật may chuyên dụng cho da nhân tạo', self::SITE);
        self::assertSame('may balo da', $indexLabel);

        $detail = app(KeywordClusterDetailBuilder::class)->build(self::SITE, 'ck_mbd');
        self::assertNotNull($detail);
        self::assertSame('may balo da', $detail['label']);
        self::assertSame($indexLabel, $detail['label']);
    }

    public function test_legacy_label_fallback_uses_cluster_key_before_sample(): void
    {
        $this->seedKeyword('Các kỹ thuật may chuyên dụng cho da nhân tạo', 'may_balo_da');
        $label = app(KeywordClusterQuery::class)->displayLabel(
            'may_balo_da',
            'Các kỹ thuật may chuyên dụng cho da nhân tạo',
            self::SITE,
        );
        self::assertSame('May Balo Da', $label);
    }

    private function seedMeta(string $clusterKey, string $canonical): void
    {
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE,
            'cluster_key' => $clusterKey,
            'canonical_phrase' => $canonical,
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey($canonical),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
    }

    private function seedKeyword(string $phrase, ?string $clusterKey): int
    {
        $norm = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer::class)
            ->normalize($phrase);
        $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE,
            'title' => 'A '.$phrase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => $phrase, 'type' => Keyword::TYPE_NORMAL]);
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
