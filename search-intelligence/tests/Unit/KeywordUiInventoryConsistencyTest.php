<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Tests\TestCase;

final class KeywordUiInventoryConsistencyTest extends TestCase
{
    private const SITE_ID = 66;

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
        $this->seedFixture();
    }

    public function test_dictionary_cluster_and_classification_share_same_inventory(): void
    {
        $variants = ['vi'];
        $inventory = app(KeywordUiInventoryQuery::class);
        $dictionaryTotal = $inventory->count(self::SITE_ID, $variants);
        $cluster = app(KeywordClusterQuery::class)->summary(self::SITE_ID, $variants);
        $classification = KeywordClassificationVisibility::summarize(self::SITE_ID, $variants);

        self::assertSame(3, $dictionaryTotal);
        self::assertSame($dictionaryTotal, (int) $cluster['total_keywords']);
        self::assertSame($dictionaryTotal, (int) $classification['total_raw']);
        self::assertSame(
            (int) $cluster['seo_eligible_keywords'],
            (int) $cluster['clustered'] + (int) $cluster['unclustered'],
        );
        self::assertSame(
            $dictionaryTotal,
            (int) $cluster['seo_eligible_keywords']
            + (int) $cluster['unclassified_keywords']
            + (int) $cluster['non_seo_keywords'],
        );
    }

    public function test_inventory_excludes_suggest_unlinked_other_language_and_one_word(): void
    {
        $ids = app(KeywordUiInventoryQuery::class)->keywordIds(self::SITE_ID, ['vi']);
        $phrases = Keyword::query()->whereIn('id', $ids)->orderBy('phrase')->pluck('phrase')->all();

        self::assertSame([
            'linked normal phrase',
            'linked seo phrase',
            'non seo sentence phrase',
        ], $phrases);
    }

    public function test_ui_inventory_query_is_ssot_for_consumers(): void
    {
        $listSrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');
        $dictSrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Support/KeywordWorkspace/KeywordDictionaryQuery.php');
        $clusterSrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterQuery.php');
        $visibilitySrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Support/KeywordIntelligence/KeywordClassificationVisibility.php');

        self::assertStringContainsString('KeywordDictionaryQuery', $listSrc);
        self::assertStringContainsString('KeywordUiInventoryQuery', $dictSrc);
        self::assertStringContainsString('KeywordUiInventoryQuery', $clusterSrc);
        self::assertStringContainsString('KeywordUiInventoryQuery', $visibilitySrc);
    }

    private function seedFixture(): void
    {
        $viArticle = $this->createArticle(self::SITE_ID, 'VI article', 'vi');
        $enArticle = $this->createArticle(self::SITE_ID, 'EN article', 'en');

        $linkedNormal = $this->createKeyword('linked normal phrase', Keyword::TYPE_NORMAL);
        $this->createLinkMap((int) $linkedNormal->id, $viArticle, $viArticle);

        $linkedSeo = $this->createKeyword('linked seo phrase', Keyword::TYPE_NORMAL);
        $this->createLinkMap((int) $linkedSeo->id, $viArticle, $viArticle);
        $this->classify((int) $linkedSeo->id, 'keyword_phrase', true, 'cluster_a');

        $nonSeo = $this->createKeyword('non seo sentence phrase', Keyword::TYPE_NORMAL);
        $this->createLinkMap((int) $nonSeo->id, $viArticle, $viArticle);
        $this->classify((int) $nonSeo->id, 'sentence', false, null);

        $suggest = $this->createKeyword('suggest linked phrase', Keyword::TYPE_SUGGEST);
        $this->createLinkMap((int) $suggest->id, $viArticle, $viArticle);

        $unlinked = $this->createKeyword('unlinked normal phrase', Keyword::TYPE_NORMAL);

        $otherLang = $this->createKeyword('english only phrase', Keyword::TYPE_NORMAL);
        $this->createLinkMap((int) $otherLang->id, $enArticle, $enArticle);

        $oneWord = $this->createKeyword('oneword', Keyword::TYPE_NORMAL);
        $this->createLinkMap((int) $oneWord->id, $viArticle, $viArticle);

        unset($unlinked);
    }

    private function createKeyword(string $phrase, string $type): Keyword
    {
        return Keyword::query()->create([
            'phrase' => $phrase,
            'type' => $type,
            'review_status' => 'active',
        ]);
    }

    private function classify(int $keywordId, string $kind, bool $isSeo, ?string $clusterKey): void
    {
        SeoKeywordClassification::query()->create([
            'keyword_id' => $keywordId,
            'normalized_text' => 'n'.$keywordId,
            'folded_text' => 'f'.$keywordId,
            'phrase_kind' => $kind,
            'seo_intent' => 'commercial',
            'cluster_key' => $clusterKey,
            'is_seo_keyword' => $isSeo,
            'classified_at' => now(),
        ]);
    }

    private function createArticle(int $siteId, string $title, string $language): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'language' => $language,
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

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
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
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('article_keyword', function (Blueprint $table): void {
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('keyword_id');
        });
    }
}
