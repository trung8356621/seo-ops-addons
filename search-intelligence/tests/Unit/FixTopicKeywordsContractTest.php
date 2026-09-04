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
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Support\LegacyAddonPath;

final class FixTopicKeywordsContractTest extends TestCase
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
    }

    public function test_fix_and_rename_share_reevaluate_membership_method(): void
    {
        $method = new ReflectionMethod(UpdateClusterCanonicalService::class, 'reevaluateMembershipForCanonical');
        self::assertTrue($method->isPublic());

        $serviceSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/UpdateClusterCanonicalService.php',
        );
        self::assertStringContainsString('function setManualCanonical', $serviceSrc);
        self::assertStringContainsString('function reconcileMembership', $serviceSrc);
        self::assertSame(
            2,
            substr_count($serviceSrc, '$this->reevaluateMembershipForCanonical('),
            'rename + fix must both call shared reevaluateMembershipForCanonical',
        );

        $detailSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php',
        );
        self::assertStringContainsString('function fixTopicKeywords', $detailSrc);
        self::assertStringContainsString('->reconcileMembership(', $detailSrc);
        self::assertStringContainsString('->setManualCanonical(', $detailSrc);
        self::assertStringContainsString('canEditClusterCanonical', $detailSrc);
        self::assertStringContainsString('refreshClusterSummaryCounters', $detailSrc);
        self::assertStringContainsString('focusReconciler', $serviceSrc);
        self::assertStringContainsString('containsCanonicalCoreForTopic', $serviceSrc);
        // No duplicated matching heuristic in the Livewire page.
        self::assertStringNotContainsString('containsCanonicalCore', $detailSrc);
        self::assertStringNotContainsString('matchesCanonical', $detailSrc);
    }

    public function test_detail_blade_exposes_fix_keyword_left_of_dissolve(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));
        self::assertStringContainsString('wire:click="fixTopicKeywords"', $blade);
        self::assertStringContainsString('topic_fix_keywords_action', $blade);
        self::assertStringContainsString('topic_dissolve_action', $blade);
        $fixPos = strpos($blade, 'fixTopicKeywords');
        $dissolvePos = strpos($blade, 'dissolveTopicCluster');
        self::assertNotFalse($fixPos);
        self::assertNotFalse($dissolvePos);
        self::assertLessThan($dissolvePos, $fixPos);
    }

    public function test_reconcile_membership_matches_rename_reevaluate_behavior(): void
    {
        $this->seedKeyword('túi vải không dệt', 'ck_tvkd');
        $this->seedKeyword('túi vải không dệt dây rút', null);
        $this->seedKeyword('unrelated phrase xyz', 'ck_tvkd');

        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_tvkd',
            'canonical_phrase' => 'túi vải không dệt',
            'normalized_canonical' => app(CanonicalClusterPhraseResolver::class)->normalizedKey('túi vải không dệt'),
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => 'manual',
        ]);

        $viaFix = app(UpdateClusterCanonicalService::class)->reconcileMembership(self::SITE_ID, 'ck_tvkd');
        self::assertGreaterThanOrEqual(1, $viaFix['attached']);
        self::assertGreaterThanOrEqual(1, $viaFix['detached']);
        self::assertGreaterThan(0, $viaFix['checked']);
        self::assertSame($viaFix['attached'] + $viaFix['detached'], $viaFix['changed']);

        $attachedPhraseKey = SeoKeywordClassification::query()
            ->where('keyword_id', Keyword::query()->where('phrase', 'túi vải không dệt dây rút')->value('id'))
            ->value('cluster_key');
        self::assertSame('ck_tvkd', $attachedPhraseKey);

        $unrelatedKey = SeoKeywordClassification::query()
            ->where('keyword_id', Keyword::query()->where('phrase', 'unrelated phrase xyz')->value('id'))
            ->value('cluster_key');
        self::assertTrue($unrelatedKey === null || $unrelatedKey === '');
    }

    public function test_rename_still_calls_shared_reevaluate(): void
    {
        $this->seedKeyword('túi vải không dệt', 'ck_tvkd');
        $this->seedKeyword('túi vải không dệt mini', null);
        SeoTopicClusterMeta::query()->create([
            'site_id' => self::SITE_ID,
            'cluster_key' => 'ck_tvkd',
            'canonical_phrase' => 'old label',
            'normalized_canonical' => 'old label',
            'confidence' => 'high',
            'needs_review' => false,
            'canonical_source' => 'auto',
        ]);

        $result = app(UpdateClusterCanonicalService::class)
            ->setManualCanonical(self::SITE_ID, 'ck_tvkd', 'túi vải không dệt');

        self::assertArrayHasKey('attached', $result);
        self::assertArrayHasKey('detached', $result);
        self::assertArrayHasKey('checked', $result);
        self::assertSame(
            'ck_tvkd',
            SeoKeywordClassification::query()
                ->where('keyword_id', Keyword::query()->where('phrase', 'túi vải không dệt mini')->value('id'))
                ->value('cluster_key'),
        );
    }

    private function seedKeyword(string $phrase, ?string $clusterKey): int
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
            $table->unique(['keyword_id', 'normalized_value'], 'seo_kw_dna_kw_norm2');
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
