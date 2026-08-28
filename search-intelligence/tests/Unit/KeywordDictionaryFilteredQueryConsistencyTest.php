<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Tests\TestCase;

final class KeywordDictionaryFilteredQueryConsistencyTest extends TestCase
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
        $this->seedFixture();
    }

    public function test_no_filter_matches_full_ui_inventory(): void
    {
        $query = app(KeywordDictionaryQuery::class);
        $ids = $query->keywordIds(self::SITE_ID, ['vi'], []);

        self::assertCount(5, $ids);
        self::assertSame(count($ids), (int) $query->filtered(self::SITE_ID, ['vi'], [])->count());
    }

    public function test_cluster_none_matches_pagination_total_and_classification(): void
    {
        $filters = ['cluster_key' => '_none'];
        $query = app(KeywordDictionaryQuery::class);
        $ids = $query->keywordIds(self::SITE_ID, ['vi'], $filters);
        $total = (int) $query->filtered(self::SITE_ID, ['vi'], $filters)->count();
        $summary = KeywordClassificationVisibility::summarizeForKeywordIds($ids);

        self::assertSame(2, $total);
        self::assertSame($total, count($ids));
        self::assertSame($total, (int) $summary['total_raw']);
        self::assertContains('unclustered seo one', $this->phrases($ids));
        self::assertContains('unclustered seo two', $this->phrases($ids));
    }

    public function test_specific_cluster_filter(): void
    {
        $ids = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], [
            'cluster_key' => 'cluster_a',
        ]);

        self::assertSame(['clustered seo phrase'], $this->phrases($ids));
    }

    public function test_language_filter_excludes_other_language(): void
    {
        $vi = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], []);
        $en = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['en'], []);

        self::assertNotContains('english only phrase', $this->phrases($vi));
        self::assertSame(['english only phrase'], $this->phrases($en));
    }

    public function test_search_plus_cluster_none(): void
    {
        $ids = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], [
            'cluster_key' => '_none',
            'search' => 'two',
        ]);

        self::assertSame(['unclustered seo two'], $this->phrases($ids));
    }

    public function test_status_errors_within_filtered_set(): void
    {
        $base = app(KeywordDictionaryQuery::class)->filtered(self::SITE_ID, ['vi'], [
            'cluster_key' => '_none',
        ]);
        $errors = (clone $base)->whereIn('review_status', ['danger', 'warning'])->count();

        self::assertSame(1, $errors);
        self::assertSame(
            (int) (clone $base)->count(),
            (int) (clone $base)->where('review_status', 'active')->count() + $errors,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function phrases(array $ids): array
    {
        return Keyword::query()
            ->whereIn('id', $ids)
            ->orderBy('phrase')
            ->pluck('phrase')
            ->all();
    }

    private function seedFixture(): void
    {
        $vi = $this->createArticle(self::SITE_ID, 'VI', 'vi');
        $en = $this->createArticle(self::SITE_ID, 'EN', 'en');

        $this->seedLinked('unclustered seo one', $vi, isSeo: true, cluster: null, review: 'active');
        $this->seedLinked('unclustered seo two', $vi, isSeo: true, cluster: null, review: 'danger');
        $this->seedLinked('clustered seo phrase', $vi, isSeo: true, cluster: 'cluster_a', review: 'active');
        $this->seedLinked('unclassified linked phrase', $vi, classify: false, review: 'active');
        $this->seedLinked('non seo sentence phrase', $vi, isSeo: false, kind: 'sentence', cluster: null, review: 'active');
        $this->seedLinked('english only phrase', $en, isSeo: true, cluster: null, review: 'active');
    }

    private function seedLinked(
        string $phrase,
        int $articleId,
        bool $isSeo = true,
        ?string $cluster = null,
        string $kind = 'keyword_phrase',
        bool $classify = true,
        string $review = 'active',
    ): void {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => $review,
        ]);
        $this->createLinkMap((int) $keyword->id, $articleId, $articleId);

        if (! $classify) {
            return;
        }

        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => $kind,
            'seo_intent' => 'commercial',
            'cluster_key' => $cluster,
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
