<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterResolverService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaDiagnosticsService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaExtractor;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicIdeaContentCoverageStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicIdeaCoverageService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterClusterKeyGenerator;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class TopicIdeaCoverageAndDnaQualityTest extends TestCase
{
    private const SITE_A = 70;

    private const SITE_B = 80;

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

    public function test_xuong_may_balo_dna_excludes_glue_and_cluster_echo(): void
    {
        $values = $this->dnaNorms(
            'Xưởng may balo học sinh tại xưởng may Hợp Phát',
            'Xưởng may balo',
        );

        self::assertContains('hoc sinh', $values);
        self::assertContains('hop phat', $values);
        self::assertNotContains('tai', $values);
        self::assertNotContains('xuong', $values);
        self::assertNotContains('may', $values);
        self::assertNotContains('xuong may', $values);
        self::assertFalse($this->anyContains($values, 'tai'));
    }

    public function test_location_dna_normalizes_to_place_not_wrapper(): void
    {
        $rows = app(KeywordDnaExtractor::class)->extract('Túi dây rút tại Hà Nội', 'Túi dây rút');
        $norms = array_map(static fn (array $r): string => $r['normalized_value'], $rows);
        $displays = array_map(static fn (array $r): string => mb_strtolower($r['value']), $rows);

        self::assertContains('ha noi', $norms);
        self::assertNotContains('tai', $norms);
        self::assertNotContains('tai ha noi', $norms);
        foreach ($displays as $display) {
            self::assertStringNotContainsString('tại hà nội', $display);
        }
    }

    public function test_dna_does_not_equal_canonical_cluster(): void
    {
        $rows = app(KeywordDnaExtractor::class)->extract('Túi dây rút', 'Túi dây rút');
        self::assertSame([], $rows);
    }

    public function test_case_normalization_aggregates_in_coverage(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', [
            'túi dây rút canvas',
            'túi dây rút Canvas',
        ]);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $key, 'Túi dây rút');

        $coverage = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_A, $key);
        self::assertNotNull($coverage);
        $canvasBranches = array_values(array_filter(
            $coverage['dna_branches'],
            static fn (array $b): bool => $b['normalized_value'] === 'canvas',
        ));
        self::assertCount(1, $canvasBranches);
        self::assertSame(2, $canvasBranches[0]['keyword_count']);
    }

    public function test_no_dna_keyword_stays_empty_and_counts_as_base(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', [
            'túi dây rút',
            'túi dây rút canvas',
        ]);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $key, 'Túi dây rút');

        $coverage = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_A, $key);
        self::assertNotNull($coverage);
        self::assertSame(1, $coverage['base_keyword_count']);
        self::assertGreaterThanOrEqual(1, $coverage['dna_branch_count']);
    }

    public function test_dna_article_count_uses_link_maps_and_uncovered_status(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', [
            'túi dây rút canvas',
            'túi dây rút học sinh',
        ], linkArticles: [true, false]);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $key, 'Túi dây rút');

        $coverage = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_A, $key);
        self::assertNotNull($coverage);

        $byNorm = [];
        foreach ($coverage['dna_branches'] as $branch) {
            $byNorm[$branch['normalized_value']] = $branch;
        }

        self::assertSame(1, $byNorm['canvas']['article_count']);
        self::assertSame(TopicIdeaContentCoverageStatus::LIGHT, $byNorm['canvas']['content_coverage']);
        self::assertSame(0, $byNorm['hoc sinh']['article_count']);
        self::assertSame(TopicIdeaContentCoverageStatus::UNCOVERED, $byNorm['hoc sinh']['content_coverage']);
    }

    public function test_domain_isolation_for_coverage(): void
    {
        $keyA = $this->seedCluster(self::SITE_A, 'Túi dây rút', ['túi dây rút canvas']);
        $keyB = $this->seedCluster(self::SITE_B, 'Túi dây rút', ['túi dây rút canvas', 'túi dây rút thể thao']);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $keyA, ['Túi dây rút']);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_B, $keyB, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $keyA, 'Túi dây rút');
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_B, $keyB, 'Túi dây rút');

        $a = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_A, $keyA);
        $b = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_B, $keyB);

        self::assertSame(1, $a['dna_branch_count']);
        self::assertSame(2, $b['dna_branch_count']);
        self::assertNotSame($keyA, $keyB);
    }

    public function test_dna_branch_count_dedupes_and_ignores_root_keywords(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', [
            'túi dây rút',
            'túi dây rút canvas',
            'túi dây rút canvas giá rẻ',
            'túi dây rút thể thao',
        ]);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $key, 'Túi dây rút');

        $coverage = app(TopicIdeaCoverageService::class)->forCluster(self::SITE_A, $key);
        self::assertNotNull($coverage);
        self::assertSame(1, $coverage['base_keyword_count']);

        $norms = array_map(
            static fn (array $b): string => (string) $b['normalized_value'],
            $coverage['dna_branches'],
        );
        sort($norms);
        self::assertSame(['canvas', 'gia re', 'the thao'], $norms);
        self::assertSame(3, $coverage['dna_branch_count']);
    }

    public function test_planning_compact_includes_article_coverage(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', [
            'túi dây rút',
            'túi dây rút canvas',
        ], linkArticles: [true, true]);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);
        app(KeywordDnaService::class)->rebuildForCluster(self::SITE_A, $key, 'Túi dây rút');

        $compact = app(TopicIdeaCoverageService::class)->planningCompact(self::SITE_A, ['Túi dây rút']);
        self::assertCount(1, $compact);
        self::assertSame('Túi dây rút', $compact[0]['cluster']);
        self::assertSame(1, $compact[0]['core_articles']);
        self::assertNotEmpty($compact[0]['dna']);
        self::assertArrayHasKey('articles', $compact[0]['dna'][0]);
        self::assertArrayHasKey('coverage', $compact[0]['dna'][0]);
    }

    public function test_planning_compact_empty_when_no_dna_rows(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', ['túi dây rút']);
        app(CanonicalClusterResolverService::class)->upsertClusterMeta(self::SITE_A, $key, ['Túi dây rút']);

        $compact = app(TopicIdeaCoverageService::class)->planningCompact(self::SITE_A, ['Túi dây rút']);
        self::assertCount(1, $compact);
        self::assertSame([], $compact[0]['dna']);
    }

    public function test_diagnostics_flags_suspicious_glue(): void
    {
        $key = $this->seedCluster(self::SITE_A, 'Túi dây rút', ['túi dây rút canvas']);
        SeoKeywordDna::query()->create([
            'site_id' => self::SITE_A,
            'keyword_id' => (int) SeoKeywordClassification::query()->where('cluster_key', $key)->value('keyword_id'),
            'cluster_key' => $key,
            'value' => 'tại',
            'normalized_value' => 'tai',
            'source' => 'test',
        ]);

        $report = app(KeywordDnaDiagnosticsService::class)->report(self::SITE_A);
        self::assertGreaterThan(0, $report['dna_suspicious']);
        self::assertNotEmpty($report['suspicious_samples']);
    }

    public function test_content_coverage_status_thresholds(): void
    {
        self::assertSame(TopicIdeaContentCoverageStatus::UNCOVERED, TopicIdeaContentCoverageStatus::fromArticleCount(0));
        self::assertSame(TopicIdeaContentCoverageStatus::LIGHT, TopicIdeaContentCoverageStatus::fromArticleCount(1));
        self::assertSame(TopicIdeaContentCoverageStatus::COVERED, TopicIdeaContentCoverageStatus::fromArticleCount(3));
        self::assertSame(TopicIdeaContentCoverageStatus::DENSE, TopicIdeaContentCoverageStatus::fromArticleCount(4));
    }

    /**
     * @return list<string>
     */
    private function dnaNorms(string $keyword, string $cluster): array
    {
        return array_map(
            static fn (array $r): string => $r['normalized_value'],
            app(KeywordDnaExtractor::class)->extract($keyword, $cluster),
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function anyContains(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if ($value === $needle || str_contains($value, $needle.' ') || str_contains($value, ' '.$needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $phrases
     * @param  list<bool>|null  $linkArticles
     */
    private function seedCluster(int $siteId, string $label, array $phrases, ?array $linkArticles = null): string
    {
        $ids = [];
        foreach ($phrases as $index => $phrase) {
            $norm = app(KeywordNormalizer::class)->normalize($phrase);
            $articleId = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
                'site_id' => $siteId,
                'title' => 'A '.$phrase,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $keyword = Keyword::query()->create(['phrase' => $phrase, 'type' => Keyword::TYPE_NORMAL]);
            $shouldLink = $linkArticles === null ? true : (bool) ($linkArticles[$index] ?? true);
            DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
                'keyword_id' => $keyword->id,
                'source_article_id' => $articleId,
                'target_article_id' => $shouldLink ? $articleId : null,
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
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ids[] = (int) $keyword->id;
        }

        sort($ids, SORT_NUMERIC);
        $key = app(TopicClusterClusterKeyGenerator::class)->generate($siteId, $label, $ids);
        SeoKeywordClassification::query()->whereIn('keyword_id', $ids)->update(['cluster_key' => $key]);

        return $key;
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
