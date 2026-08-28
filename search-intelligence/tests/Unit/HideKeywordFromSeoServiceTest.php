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
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\HideKeywordFromSeoService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterEligibility;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\PruneAutoSingletonClustersService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Tests\TestCase;

final class HideKeywordFromSeoServiceTest extends TestCase
{
    private const SITE_ID = 91;

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

    public function test_a_hide_preserves_record_and_links(): void
    {
        [$keywordId, $clusterKey] = $this->seedClusteredKeyword('ba lô', 'ck_balo', 3);

        $beforeLinks = (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->where('keyword_id', $keywordId)
            ->count();
        self::assertGreaterThan(0, $beforeLinks);

        $result = $this->service()->hide($keywordId, self::SITE_ID);

        self::assertTrue($result['was_hidden']);
        self::assertTrue($this->service()->isHidden($keywordId));
        self::assertTrue(Keyword::query()->whereKey($keywordId)->exists());
        self::assertSame('ba lô', Keyword::query()->find($keywordId)?->phrase);
        self::assertSame($beforeLinks, (int) DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->where('keyword_id', $keywordId)
            ->count());
        self::assertSame('', (string) (SeoKeywordClassification::query()->whereKey($keywordId)->value('cluster_key') ?? ''));
        self::assertFalse(
            app(KeywordClusterEligibility::class)->isProposalCandidate(
                SeoKeywordClassification::query()->whereKey($keywordId)->first(),
            ),
        );
        unset($clusterKey);
    }

    public function test_b_hidden_excluded_from_counters(): void
    {
        // 2 + 2 clustered + 1 unclustered = 5 eligible
        $this->seedClusteredKeyword('kw a', 'ck_a', 1);
        $this->seedClusteredKeyword('kw b', 'ck_b', 1);
        $hiddenId = $this->insertKeyword('hidden one', null);

        $before = app(KeywordClusterEligibility::class)->summaryMetrics(self::SITE_ID);
        self::assertSame(5, $before['seo_eligible_keywords']);

        $this->service()->hide($hiddenId, self::SITE_ID);
        $after = app(KeywordClusterEligibility::class)->summaryMetrics(self::SITE_ID);

        self::assertSame(4, $after['seo_eligible_keywords']);
        self::assertSame(1, $after['hidden_keywords']);
        self::assertSame($before['clustered'], $after['clustered']);
    }

    public function test_c_hide_from_multi_member_cluster_keeps_cluster(): void
    {
        [$id1] = $this->seedClusteredKeyword('m1', 'ck_keep', 0);
        $this->insertKeyword('m2', 'ck_keep');
        $this->insertKeyword('m3', 'ck_keep');

        $this->service()->hide($id1, self::SITE_ID);

        $remaining = SeoKeywordClassification::query()
            ->where('cluster_key', 'ck_keep')
            ->count();
        self::assertSame(2, $remaining);
        self::assertTrue(
            SeoTopicClusterMeta::query()
                ->where('site_id', self::SITE_ID)
                ->where('cluster_key', 'ck_keep')
                ->exists(),
        );
    }

    public function test_d_auto_singleton_pruned_after_hide(): void
    {
        [$id1] = $this->seedClusteredKeyword('s1', 'ck_pair', 0);
        $id2 = $this->insertKeyword('s2', 'ck_pair');
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_pair'],
            [
                'canonical_phrase' => 'pair',
                'normalized_canonical' => 'pair',
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => SeoTopicClusterMeta::SOURCE_AUTO,
            ],
        );

        $result = $this->service()->hide($id1, self::SITE_ID);
        self::assertGreaterThan(0, (int) ($result['pruned'] ?? 0), 'expected singleton prune after hide: '.json_encode($result));

        self::assertSame('', (string) (SeoKeywordClassification::query()->whereKey($id2)->value('cluster_key') ?? ''));
        self::assertFalse(
            SeoTopicClusterMeta::query()
                ->where('site_id', self::SITE_ID)
                ->where('cluster_key', 'ck_pair')
                ->exists(),
        );
    }

    public function test_e_manual_cluster_preserved_after_hide_to_zero(): void
    {
        [$id] = $this->seedClusteredKeyword('only', 'ck_manual', 1);
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => 'ck_manual'],
            [
                'canonical_phrase' => 'May túi canvas',
                'normalized_canonical' => 'may tui canvas',
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => SeoTopicClusterMeta::SOURCE_MANUAL,
            ],
        );

        $this->service()->hide($id, self::SITE_ID);

        self::assertTrue(
            SeoTopicClusterMeta::query()
                ->where('site_id', self::SITE_ID)
                ->where('cluster_key', 'ck_manual')
                ->where('canonical_source', SeoTopicClusterMeta::SOURCE_MANUAL)
                ->exists(),
        );
    }

    public function test_f_restore_returns_to_eligible_pool(): void
    {
        $id = $this->insertKeyword('restorable', null);
        $this->service()->hide($id, self::SITE_ID);
        self::assertTrue($this->service()->isHidden($id));

        $this->service()->restore($id, self::SITE_ID);
        self::assertFalse($this->service()->isHidden($id));
        self::assertTrue(
            app(KeywordClusterEligibility::class)->isProposalCandidate(
                SeoKeywordClassification::query()->whereKey($id)->first(),
            ),
        );
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', $id)
            ->where('meta_key', KeywordMetaKey::SeoHidden->value)
            ->count());
    }

    public function test_ui_contracts_for_hide_and_summary_epoch(): void
    {
        $resource = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource.php');
        $clusters = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $index = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');

        self::assertStringContainsString('hide_keyword', $resource);
        self::assertStringContainsString('restore_hidden_keyword', $resource);
        self::assertStringContainsString('HideKeywordFromSeoService', $resource);
        self::assertStringContainsString('seo_hidden', $resource);
        self::assertStringContainsString('clusterDataEpoch', $clusters);
        self::assertStringContainsString('refreshClusterSummaryCounters', $clusters);
        self::assertStringContainsString('cluster-data-updated', $clusters);
        self::assertStringContainsString('topic-index-stats-{{ $this->clusterDataEpoch }}', $index);
    }

    private function service(): HideKeywordFromSeoService
    {
        return new HideKeywordFromSeoService(app(PruneAutoSingletonClustersService::class));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function seedClusteredKeyword(string $phrase, string $clusterKey, int $extraSiblings = 0): array
    {
        $id = $this->insertKeyword($phrase, $clusterKey);
        for ($i = 0; $i < $extraSiblings; $i++) {
            $this->insertKeyword($phrase.' sib '.$i, $clusterKey);
        }
        SeoTopicClusterMeta::query()->updateOrCreate(
            ['site_id' => self::SITE_ID, 'cluster_key' => $clusterKey],
            [
                'canonical_phrase' => $phrase,
                'normalized_canonical' => mb_strtolower($phrase),
                'confidence' => 'high',
                'needs_review' => false,
                'canonical_source' => SeoTopicClusterMeta::SOURCE_AUTO,
            ],
        );

        return [$id, $clusterKey];
    }

    private function insertKeyword(string $phrase, ?string $clusterKey): int
    {
        $norm = app(KeywordNormalizer::class)->normalize($phrase);
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
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => 1,
            'is_ambiguous' => 0,
            'keyword_score' => 0.8,
            'input_hash' => hash('sha256', $phrase.uniqid('', true)),
            'classification_hash' => hash('sha256', 'x'),
            'classified_at' => now(),
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
            $table->timestamps();
            $table->unique(['site_id', 'cluster_key']);
        });
    }
}
