<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterTopicClustersJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Tests\TestCase;

final class FullDomainReclusterRepairTest extends TestCase
{
    private const SITE_A = 91;

    private const SITE_B = 92;

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

    public function test_xuong_may_balo_full_recluster_promotes_and_attaches(): void
    {
        $longKey = $this->seedKeyword(self::SITE_A, 'Xưởng may balo túi xách', 'xuong_may_balo_tui_xach');
        $this->seedKeyword(self::SITE_A, 'XƯỞNG MAY BALO TÚI XÁCH HỢP PHÁT', 'xuong_may_balo_tui_xach');
        $this->seedKeyword(self::SITE_A, 'XƯỞNG MAY BALO ANH NGỮ', null);
        $this->seedKeyword(self::SITE_A, 'XƯỞNG MAY BALO ANH VĂN', null);
        $this->seedKeyword(self::SITE_A, 'XƯỞNG MAY BALO ANH VĂN HỢP PHÁT', null);
        $this->seedKeyword(self::SITE_A, 'Xưởng May Balo Chuyên Sỉ', null);
        $this->seedKeyword(self::SITE_A, 'xưởng may balo dây rút', null);
        $this->seedKeyword(self::SITE_A, 'Xưởng May Balo Giá Rẻ', null);
        $this->seedKeyword(self::SITE_A, 'xưởng may balo giá rẻ', null);
        $this->seedKeyword(self::SITE_A, 'Hợp Phát - xưởng may balo giá rẻ uy tín chất lượng', null);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);
        self::assertTrue($result->success, (string) ($result->error ?? 'fail'));

        $canonicals = SeoTopicClusterMeta::query()
            ->where('site_id', self::SITE_A)
            ->pluck('canonical_phrase')
            ->map(static fn ($p): string => mb_strtolower(trim((string) $p)))
            ->all();

        $joined = implode(' | ', $canonicals);
        self::assertStringContainsString('xưởng may balo', $joined);
        self::assertStringNotContainsString('xưởng may balo túi xách', $joined);

        $nullCount = SeoKeywordClassification::query()
            ->where(function ($q): void {
                $q->whereNull('cluster_key')->orWhere('cluster_key', '');
            })
            ->count();
        self::assertSame(0, $nullCount, 'All eligible keywords must be clustered');

        $keys = SeoKeywordClassification::query()
            ->whereNotNull('cluster_key')
            ->where('cluster_key', '!=', '')
            ->pluck('cluster_key')
            ->unique()
            ->values()
            ->all();
        self::assertCount(1, $keys, 'All xuong-may-balo phrases should share one cluster');

        self::assertGreaterThanOrEqual(1, (int) ($result->metrics['attached_by_core_match'] ?? 0));

        $kid = (int) Keyword::query()->where('phrase', 'Xưởng may balo túi xách')->value('id');
        $dna = SeoKeywordDna::query()->where('keyword_id', $kid)->pluck('normalized_value')->all();
        self::assertNotEmpty($dna);
        self::assertTrue(
            in_array('tui xach', $dna, true) || in_array('túi xách', $dna, true),
            'DNA should contain túi xách residual, got: '.implode(',', $dna),
        );

        $dayRutId = (int) Keyword::query()->where('phrase', 'xưởng may balo dây rút')->value('id');
        $dayDna = SeoKeywordDna::query()->where('keyword_id', $dayRutId)->pluck('normalized_value')->all();
        self::assertNotEmpty($dayDna);

        foreach ($canonicals as $c) {
            self::assertNotSame('balo', $c);
        }

        unset($longKey);
    }

    public function test_core_containment_attaches_day_rut_to_existing_canonical(): void
    {
        $rootId = $this->seedKeyword(self::SITE_A, 'Xưởng may balo', 'ck_xuong_may_balo');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_xuong_may_balo',
            'canonical_phrase' => 'Xưởng may balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $this->seedKeyword(self::SITE_A, 'xưởng may balo dây rút', null);
        $this->seedKeyword(self::SITE_A, 'Xưởng May Balo Giá Rẻ', null);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);
        self::assertTrue($result->success);

        foreach (['xưởng may balo dây rút', 'Xưởng May Balo Giá Rẻ'] as $phrase) {
            $ck = SeoKeywordClassification::query()
                ->where('keyword_id', Keyword::query()->where('phrase', $phrase)->value('id'))
                ->value('cluster_key');
            self::assertSame('ck_xuong_may_balo', $ck, $phrase);
        }

        self::assertGreaterThanOrEqual(2, (int) ($result->metrics['attached_by_core_match'] ?? 0));
        unset($rootId);
    }

    public function test_longest_canonical_wins_over_bare_balo(): void
    {
        $this->seedKeyword(self::SITE_A, 'balo', 'ck_balo');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_balo',
            'canonical_phrase' => 'balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo', 'ck_xmb');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_xmb',
            'canonical_phrase' => 'Xưởng may balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $this->seedKeyword(self::SITE_A, 'xưởng may balo giá rẻ', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);

        $ck = SeoKeywordClassification::query()
            ->where('keyword_id', Keyword::query()->where('phrase', 'xưởng may balo giá rẻ')->value('id'))
            ->value('cluster_key');
        self::assertSame('ck_xmb', $ck);
    }

    public function test_promote_then_same_run_attaches_unclustered(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo túi xách', 'old_long');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'old_long',
            'canonical_phrase' => 'Xưởng may balo túi xách',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo túi xách'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $this->seedKeyword(self::SITE_A, 'xưởng may balo dây rút', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);

        $meta = SeoTopicClusterMeta::query()->where('site_id', self::SITE_A)->first();
        self::assertNotNull($meta);
        self::assertStringContainsString('xưởng may balo', mb_strtolower((string) $meta->canonical_phrase));
        self::assertStringNotContainsString('túi xách', mb_strtolower((string) $meta->canonical_phrase));

        $keys = SeoKeywordClassification::query()->pluck('cluster_key')->unique()->filter()->values()->all();
        self::assertCount(1, $keys);
    }

    public function test_manual_exclude_stays_unclustered(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo', 'ck_xmb');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_xmb',
            'canonical_phrase' => 'Xưởng may balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $excludedId = $this->seedKeyword(self::SITE_A, 'xưởng may balo dây rút', null);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $excludedId,
            'meta_key' => ReclusterTopicClustersService::META_MANUAL_EXCLUDE,
            'meta_value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);

        $ck = SeoKeywordClassification::query()->where('keyword_id', $excludedId)->value('cluster_key');
        self::assertTrue($ck === null || $ck === '');
    }

    public function test_incremental_assign_uses_core_containment(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo', 'ck_xmb');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_xmb',
            'canonical_phrase' => 'Xưởng may balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);
        $id = $this->seedKeyword(self::SITE_A, 'xưởng may balo dây kéo', null);

        $ck = app(ReclusterTopicClustersService::class)->assignKeyword(
            self::SITE_A,
            $id,
            'xưởng may balo dây kéo',
        );

        self::assertSame('ck_xmb', $ck);
        self::assertSame(
            'ck_xmb',
            SeoKeywordClassification::query()->where('keyword_id', $id)->value('cluster_key'),
        );
    }

    public function test_single_keyword_gets_self_cluster(): void
    {
        $this->seedKeyword(self::SITE_A, 'khóa kéo YKK', null);

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);
        self::assertTrue($result->success);
        self::assertSame(1, (int) ($result->metrics['self_clusters_created'] ?? 0));

        $ck = SeoKeywordClassification::query()->value('cluster_key');
        self::assertNotEmpty($ck);
    }

    public function test_service_vs_product_intent_not_merged(): void
    {
        $this->seedKeyword(self::SITE_A, 'May Túi Vải Canvas', null);
        $this->seedKeyword(self::SITE_A, 'Túi Vải Canvas', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);

        $keys = SeoKeywordClassification::query()
            ->orderBy('keyword_id')
            ->pluck('cluster_key')
            ->all();

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function test_domain_isolation(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo Anh Văn', null);
        $this->seedKeyword(self::SITE_B, 'Xưởng may balo chuyên sỉ', null);

        app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);

        $a = SeoKeywordClassification::query()
            ->whereIn('keyword_id', Keyword::query()->forSite(self::SITE_A)->pluck('id'))
            ->value('cluster_key');
        $b = SeoKeywordClassification::query()
            ->whereIn('keyword_id', Keyword::query()->forSite(self::SITE_B)->pluck('id'))
            ->value('cluster_key');

        self::assertNotEmpty($a);
        self::assertNull($b);
    }

    public function test_second_recluster_idempotent(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo túi xách', null);
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo Anh Văn', null);

        $svc = app(ReclusterTopicClustersService::class);
        $first = $svc->recluster(self::SITE_A);
        $second = $svc->recluster(self::SITE_A);

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertSame(0, (int) ($second->metrics['clusters_merged'] ?? -1));
        self::assertSame(0, (int) ($second->metrics['reassigned'] ?? -1));
        self::assertSame(
            (int) ($first->metrics['clusters_after'] ?? 0),
            (int) ($second->metrics['clusters_after'] ?? -1),
        );
    }

    public function test_job_writes_completed_cache(): void
    {
        $this->seedKeyword(self::SITE_A, 'khóa kéo YKK', null);
        Cache::forget(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A));

        (new ReclusterTopicClustersJob(self::SITE_A))->handle(app(ReclusterTopicClustersService::class));

        $cached = Cache::get(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A));
        self::assertIsArray($cached);
        self::assertSame('completed', $cached['status'] ?? null);
        self::assertArrayHasKey('metrics', $cached);
    }

    public function test_xuong_may_does_not_collapse_to_balo_via_phrase_resolver(): void
    {
        $resolver = app(CanonicalClusterPhraseResolver::class);
        self::assertTrue($resolver->hasServiceIntent('Xưởng may balo túi xách'));
        self::assertTrue($resolver->hasServiceIntent('Hợp Phát - xưởng may balo giá rẻ'));
        self::assertTrue($resolver->containsCanonicalCore('xưởng may balo dây rút', 'Xưởng may balo'));
        self::assertFalse($resolver->intentCompatible('Xưởng may balo', 'balo'));
    }

    public function test_unclassified_keyword_is_ensured_then_attached(): void
    {
        $this->seedKeyword(self::SITE_A, 'Xưởng may balo', 'ck_xmb');
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_A,
            'cluster_key' => 'ck_xmb',
            'canonical_phrase' => 'Xưởng may balo',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('Xưởng may balo'),
            'confidence' => 'high',
            'needs_review' => false,
        ]);

        // Keyword in site scope but WITHOUT classification row (Dictionary "—" case).
        $norm = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer::class)
            ->normalize('xưởng may balo dây rút');
        $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE_A,
            'title' => 'orphan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'xưởng may balo dây rút', 'type' => Keyword::TYPE_NORMAL]);
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
        unset($norm);

        // Minimal stub so ensureMissingClassifications can persist without full classifier columns.
        Schema::connection('omi_seo_ai')->table('seo_keyword_classifications', function ($table): void {
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'input_hash')) {
                $table->string('input_hash')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'classification_hash')) {
                $table->string('classification_hash')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'canonical_keyword_id')) {
                $table->unsignedBigInteger('canonical_keyword_id')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_anchor_candidate')) {
                $table->boolean('is_anchor_candidate')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'anchor_priority')) {
                $table->integer('anchor_priority')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'classification_confidence')) {
                $table->float('classification_confidence')->nullable();
            }
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'classified_at')) {
                $table->timestamp('classified_at')->nullable();
            }
        });

        $result = app(ReclusterTopicClustersService::class)->recluster(self::SITE_A);
        self::assertTrue($result->success, (string) ($result->error ?? ''));
        self::assertGreaterThanOrEqual(1, (int) ($result->metrics['classifications_ensured'] ?? 0));

        $ck = SeoKeywordClassification::query()->where('keyword_id', $keyword->id)->value('cluster_key');
        self::assertSame('ck_xmb', $ck);
    }

    private function seedKeyword(int $siteId, string $phrase, ?string $clusterKey): int
    {
        $norm = app(\Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer::class)->normalize($phrase);
        $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
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
