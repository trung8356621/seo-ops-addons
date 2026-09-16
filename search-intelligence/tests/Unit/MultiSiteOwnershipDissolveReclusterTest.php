<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\DissolveTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\DissolveTopicClusterResult;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReclusterTopicClustersService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMultiSiteOwnership;
use ReflectionClass;
use Tests\TestCase;

/**
 * Multi-site ownership: site-scoped dissolve/recluster must not clear global classification
 * while another site still owns the keyword.
 */
final class MultiSiteOwnershipDissolveReclusterTest extends TestCase
{
    private const SITE_A = 5010;

    private const SITE_B = 5020;

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

    public function test_services_use_keyword_multi_site_ownership(): void
    {
        $dissolve = (string) file_get_contents(
            (string) (new ReflectionClass(DissolveTopicClusterService::class))->getFileName(),
        );
        $recluster = (string) file_get_contents(
            (string) (new ReflectionClass(ReclusterTopicClustersService::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordMultiSiteOwnership', $dissolve);
        self::assertStringContainsString('KeywordMultiSiteOwnership', $recluster);
        self::assertStringContainsString('isSharedWithOtherSites', $dissolve);
        self::assertStringContainsString('isSharedWithOtherSites', $recluster);

        $reconcile = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ReconcileFocusArticleTopicsService::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordMultiSiteOwnership', $reconcile);
    }

    public function test_dissolve_site_a_keeps_classification_when_keyword_shared_with_site_b(): void
    {
        $keyword = $this->seedSharedKeyword('shared dissolve kw', 'shared_dissolve_cluster');

        DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')->insert([
            'site_id' => self::SITE_A,
            'cluster_key' => 'shared_dissolve_cluster',
            'canonical_phrase' => 'shared dissolve',
            'normalized_canonical' => 'shared dissolve',
            'confidence' => 'high',
            'needs_review' => 0,
            'canonical_source' => 'auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(DissolveTopicClusterService::class)->dissolve(self::SITE_A, 'shared_dissolve_cluster');

        self::assertFalse($result->success);
        self::assertSame(DissolveTopicClusterResult::REASON_SHARED_OWNERSHIP, $result->failureReason);
        self::assertSame('shared_dissolve_cluster', $this->classificationClusterKey((int) $keyword->id));
        self::assertTrue(KeywordMultiSiteOwnership::isSharedWithOtherSites((int) $keyword->id, self::SITE_A));
        // No half-mutation: derived meta must remain when dissolve is blocked.
        self::assertSame(1, (int) DB::connection('omi_seo_ai')->table('seo_topic_cluster_meta')
            ->where('site_id', self::SITE_A)
            ->where('cluster_key', 'shared_dissolve_cluster')
            ->count());
    }

    public function test_dissolve_last_owning_site_clears_global_classification(): void
    {
        $keyword = $this->seedSharedKeyword('last owner kw', 'last_owner_cluster');

        // Detach site B ownership (links + site meta) → only site A remains.
        $siteBArticleIds = DB::connection('omi_seo_ai')->table('articles')
            ->where('site_id', self::SITE_B)
            ->pluck('id')
            ->all();
        DB::connection('omi_seo_ai')->table('seo_link_maps')
            ->where('keyword_id', (int) $keyword->id)
            ->whereIn('source_article_id', $siteBArticleIds)
            ->delete();
        DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', (int) $keyword->id)
            ->where('meta_key', 'like', 'site.'.self::SITE_B.'.%')
            ->delete();

        self::assertFalse(KeywordMultiSiteOwnership::isSharedWithOtherSites((int) $keyword->id, self::SITE_A));

        $result = app(DissolveTopicClusterService::class)->dissolve(self::SITE_A, 'last_owner_cluster');

        self::assertTrue($result->success);
        self::assertNull($this->classificationClusterKey((int) $keyword->id));
    }

    public function test_recluster_wipe_skips_shared_keyword_global_key(): void
    {
        $keyword = $this->seedSharedKeyword('shared recluster kw', 'shared_recluster_cluster');

        $svc = app(ReclusterTopicClustersService::class);
        $method = new \ReflectionMethod($svc, 'wipeDerivedClusterState');
        $method->setAccessible(true);
        $method->invoke($svc, self::SITE_A, [
            [
                'keyword_id' => (int) $keyword->id,
                'phrase' => 'shared recluster kw',
                'cluster_key' => 'shared_recluster_cluster',
            ],
        ], []);

        self::assertSame('shared_recluster_cluster', $this->classificationClusterKey((int) $keyword->id));
    }

    private function seedSharedKeyword(string $phrase, string $clusterKey): Keyword
    {
        $articleA = $this->createArticle(self::SITE_A, 'A '.$phrase);
        $articleB = $this->createArticle(self::SITE_B, 'B '.$phrase);
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleA);
        $this->createLinkMap((int) $keyword->id, $articleB);

        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'informational',
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => true,
            'keyword_score' => 0.8,
            'classified_at' => now(),
        ]);

        // Site meta ownership markers (helper also checks link maps).
        foreach ([self::SITE_A, self::SITE_B] as $siteId) {
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => (int) $keyword->id,
                'meta_key' => 'site.'.$siteId.'.attached',
                'meta_value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $keyword;
    }

    private function classificationClusterKey(int $keywordId): ?string
    {
        $key = SeoKeywordClassification::query()->where('keyword_id', $keywordId)->value('cluster_key');

        return $key !== null && $key !== '' ? (string) $key : null;
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

    private function createLinkMap(int $keywordId, int $sourceArticleId): void
    {
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $keywordId,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $sourceArticleId,
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
