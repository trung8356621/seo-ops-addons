<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\TopicClusterMergeService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaExtractor;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class CanonicalClusterAndDnaTest extends TestCase
{
    private const SITE_A = 50;

    private const SITE_B = 60;

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

    public function test_case1_boilerplate_phrase_derives_shorter_canonical(): void
    {
        $resolver = app(CanonicalClusterPhraseResolver::class);
        $canonical = $resolver->deriveCorePhrase('Tham khảo dịch vụ May Túi Vải Canvas');

        self::assertSame('May Túi Vải Canvas', $canonical);
    }

    public function test_case2_service_vs_product_intent_not_compatible(): void
    {
        $resolver = app(CanonicalClusterPhraseResolver::class);

        self::assertFalse($resolver->intentCompatible('May Túi Vải Canvas', 'Túi Vải Canvas'));
        self::assertFalse($resolver->isBoilerplateSuperset('May Túi Vải Canvas', 'Túi Vải Canvas'));
    }

    public function test_case5_canvas_dna(): void
    {
        $values = $this->dnaValues('túi dây rút canvas', 'Túi dây rút');
        self::assertSame(['canvas'], $values);
    }

    public function test_case6_the_thao_dna(): void
    {
        $values = $this->dnaValues('túi dây rút thể thao', 'Túi dây rút');
        self::assertSame(['thể thao'], $values);
    }

    public function test_case7_exact_cluster_has_no_dna(): void
    {
        $values = $this->dnaValues('túi dây rút', 'Túi dây rút');
        self::assertSame([], $values);
    }

    public function test_case12_multi_dna_canvas_hoc_sinh(): void
    {
        $values = $this->dnaValues('túi dây rút canvas cho học sinh', 'Túi dây rút');
        self::assertContains('canvas', $values);
        self::assertContains('học sinh', $values);
    }

    public function test_case13_xuong_may_balo_tui_xach(): void
    {
        $values = $this->dnaValues('Xưởng may balo túi xách', 'Xưởng may balo');
        self::assertNotEmpty($values);
        self::assertTrue(
            in_array('túi xách', $values, true) || in_array('tui xach', array_map('mb_strtolower', $values), true),
        );
    }

    public function test_overlong_incoming_attaches_to_existing_canonical(): void
    {
        $existingKey = $this->seedCluster(self::SITE_A, 'May Túi Vải Canvas', ['May Túi Vải Canvas']);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(
            self::SITE_A,
            $existingKey,
            ['May Túi Vải Canvas'],
        );

        $match = app(CanonicalClusterResolverService::class)->resolveMatch(
            self::SITE_A,
            'Tham khảo dịch vụ May Túi Vải Canvas',
        );

        self::assertNotNull($match);
        self::assertSame($existingKey, $match->clusterKey);
    }

    public function test_reverse_promotion_merge_consolidates_keywords(): void
    {
        $longKey = $this->seedCluster(
            self::SITE_A,
            'Tham khảo dịch vụ May Túi Vải Canvas',
            ['Tham khảo dịch vụ May Túi Vải Canvas'],
        );
        $shortKey = $this->seedCluster(self::SITE_A, 'May Túi Vải Canvas', ['May Túi Vải Canvas']);

        app(CanonicalClusterResolverService::class)->upsertClusterMeta(
            self::SITE_A,
            $longKey,
            ['Tham khảo dịch vụ May Túi Vải Canvas'],
        );
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(
            self::SITE_A,
            $shortKey,
            ['May Túi Vải Canvas'],
        );

        $survivor = count($this->memberIds($longKey)) >= count($this->memberIds($shortKey)) ? $longKey : $shortKey;
        $loser = $survivor === $longKey ? $shortKey : $longKey;

        app(TopicClusterMergeService::class)->merge(self::SITE_A, $survivor, $loser);

        self::assertSame(2, SeoKeywordClassification::query()->where('cluster_key', $survivor)->count());
        self::assertSame(0, SeoKeywordClassification::query()->where('cluster_key', $loser)->count());
    }

    public function test_domain_isolation(): void
    {
        $keyA = $this->seedCluster(self::SITE_A, 'Túi dây rút', ['túi dây rút']);
        $keyB = $this->seedCluster(self::SITE_B, 'Túi dây rút', ['túi dây rút']);

        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $keyA, ['túi dây rút']);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_B, $keyB, ['túi dây rút']);

        self::assertNotSame($keyA, $keyB);
    }

    public function test_recluster_idempotent_second_run(): void
    {
        $this->seedCluster(self::SITE_A, 'May Túi Vải Canvas', ['May Túi Vải Canvas', 'may tui vai canvas beta']);
        $service = app(ReclusterTopicClustersService::class);

        $first = $service->recluster(self::SITE_A);
        $second = $service->recluster(self::SITE_A);

        self::assertTrue($first->success, (string) ($first->error ?? 'unknown'));
        self::assertTrue($second->success, (string) ($second->error ?? 'unknown'));
        self::assertSame(
            $first->metrics['clusters_after'] ?? 0,
            $second->metrics['clusters_after'] ?? -1,
        );
        self::assertSame(0, $second->metrics['clusters_merged'] ?? -1);
    }

    public function test_dna_duplicate_prevention(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', ['túi dây rút canvas']);
        $kid = (int) SeoKeywordClassification::query()->where('cluster_key', $key)->value('keyword_id');
        $dna = app(KeywordDnaService::class);

        $dna->rebuildForKeyword(self::SITE_A, $kid, $key, 'túi dây rút canvas', 'Túi dây rút');
        $dna->rebuildForKeyword(self::SITE_A, $kid, $key, 'túi dây rút canvas', 'Túi dây rút');

        self::assertSame(1, SeoKeywordDna::query()->where('keyword_id', $kid)->count());
    }

    public function test_cluster_change_triggers_dna_rebuild(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'wrong cluster', ['túi dây rút canvas']);
        $kid = (int) SeoKeywordClassification::query()->where('cluster_key', $key)->value('keyword_id');
        $dna = app(KeywordDnaService::class);

        $dna->rebuildForKeyword(self::SITE_A, $kid, $key, 'túi dây rút canvas', 'wrong cluster');
        self::assertGreaterThan(0, SeoKeywordDna::query()->where('keyword_id', $kid)->count());

        $dna->rebuildForKeyword(self::SITE_A, $kid, $key, 'túi dây rút canvas', 'Túi dây rút');
        $values = SeoKeywordDna::query()->where('keyword_id', $kid)->pluck('normalized_value')->all();
        self::assertContains('canvas', $values);
    }

    /**
     * @return list<string>
     */
    private function dnaValues(string $keyword, string $cluster): array
    {
        $rows = app(KeywordDnaExtractor::class)->extract($keyword, $cluster);

        return array_map(static fn (array $r): string => (string) $r['value'], $rows);
    }

    /**
     * @param  list<string>  $phrases
     */
    private function seedCluster(int $siteId, string $label, array $phrases): string
    {
        $ids = [];
        foreach ($phrases as $phrase) {
            $norm = app(KeywordNormalizer::class)->normalize($phrase);
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
            SeoKeywordClassification::query()->insert([
                'keyword_id' => (int) $keyword->id,
                'normalized_text' => $norm['normalized_text'],
                'folded_text' => $norm['folded_text'],
                'phrase_kind' => 'keyword_phrase',
                'seo_intent' => 'commercial',
                'cluster_key' => null,
                'is_seo_keyword' => 1,
                'is_ambiguous' => 0,
                'keyword_score' => 0.8,
                'input_hash' => hash('sha256', $phrase),
                'classification_hash' => hash('sha256', 'x'),
                'classified_at' => now(),
            ]);
            $ids[] = (int) $keyword->id;
        }

        sort($ids, SORT_NUMERIC);
        $key = app(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterClusterKeyGenerator::class)
            ->generate($siteId, $label, $ids);

        SeoKeywordClassification::query()->whereIn('keyword_id', $ids)->update(['cluster_key' => $key]);

        return $key;
    }

    /**
     * @return list<int>
     */
    private function memberIds(string $clusterKey): array
    {
        return SeoKeywordClassification::query()
            ->where('cluster_key', $clusterKey)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
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
