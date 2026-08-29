<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordClassificationVisibility;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Tests\TestCase;

final class KeywordDictionaryExcludeFromSeoVisibilityTest extends TestCase
{
    private const SITE_ID = 88;

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

    public function test_default_dictionary_includes_excluded_keywords(): void
    {
        $query = app(KeywordDictionaryQuery::class);
        $ids = $query->keywordIds(self::SITE_ID, ['vi'], []);
        $phrases = $this->phrases($ids);

        self::assertCount(4, $ids);
        self::assertContains('active phrase', $phrases);
        self::assertContains('excluded phrase', $phrases);
        self::assertContains('danger phrase', $phrases);
        self::assertContains('both excluded and danger', $phrases);
    }

    public function test_search_finds_excluded_keyword_without_filter_switch(): void
    {
        $ids = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], [
            'search' => 'excluded phrase',
        ]);

        self::assertSame(['excluded phrase'], $this->phrases($ids));
    }

    public function test_all_active_underperforming_counters(): void
    {
        $base = app(KeywordDictionaryQuery::class)->filtered(self::SITE_ID, ['vi'], []);
        $dictionaryQuery = app(KeywordDictionaryQuery::class);

        $total = (clone $base)->count();
        $active = $dictionaryQuery->applyActiveSeoKeywords(clone $base)->count();
        $underperforming = $dictionaryQuery->applyUnderperformingReview(clone $base)->count();

        self::assertSame(4, $total);
        // Only "active phrase" remains Active (excluded must leave Active).
        self::assertSame(1, $active);
        // danger + excluded + both (union, once each) = 3
        self::assertSame(3, $underperforming);
    }

    public function test_underperforming_list_includes_excluded_once(): void
    {
        $ids = app(KeywordDictionaryQuery::class)
            ->applyUnderperformingReview(
                app(KeywordDictionaryQuery::class)->filtered(self::SITE_ID, ['vi'], []),
            )
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $phrases = $this->phrases($ids);
        self::assertCount(3, $phrases);
        self::assertContains('excluded phrase', $phrases);
        self::assertContains('danger phrase', $phrases);
        self::assertContains('both excluded and danger', $phrases);
        self::assertNotContains('active phrase', $phrases);
    }

    public function test_seo_excluded_summary_counts_hidden_meta(): void
    {
        $ids = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], []);
        $summary = KeywordClassificationVisibility::summarizeForKeywordIds($ids);

        self::assertSame(2, (int) $summary['seo_excluded']);
        self::assertSame(4, (int) $summary['total_raw']);
    }

    public function test_seo_hidden_false_filter_hides_excluded(): void
    {
        $ids = app(KeywordDictionaryQuery::class)->keywordIds(self::SITE_ID, ['vi'], [
            'seo_hidden' => false,
        ]);

        self::assertSame(['active phrase', 'danger phrase'], $this->phrases($ids));
    }

    public function test_contracts_blank_filter_does_not_hide(): void
    {
        $querySrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Support/KeywordWorkspace/KeywordDictionaryQuery.php');
        $resourceSrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource.php');
        $listSrc = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');

        self::assertStringContainsString('if ($seoHidden === null)', $querySrc);
        self::assertStringContainsString('applyUnderperformingReview', $querySrc);
        self::assertStringContainsString('applyActiveSeoKeywords', $querySrc);
        self::assertStringContainsString('blank: fn (Builder $query): Builder => $query,', $resourceSrc);
        self::assertStringContainsString('applyUnderperformingReview', $listSrc);
        self::assertStringContainsString('applyActiveSeoKeywords', $listSrc);
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
        $articleId = $this->createArticle(self::SITE_ID, 'VI', 'vi');

        $this->seedLinked('active phrase', $articleId, review: 'active', hidden: false);
        $this->seedLinked('excluded phrase', $articleId, review: 'active', hidden: true);
        $this->seedLinked('danger phrase', $articleId, review: 'danger', hidden: false);
        $this->seedLinked('both excluded and danger', $articleId, review: 'danger', hidden: true);
    }

    private function seedLinked(string $phrase, int $articleId, string $review, bool $hidden): void
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => $review,
        ]);
        $keywordId = (int) $keyword->id;
        $this->createLinkMap($keywordId, $articleId, $articleId);

        SeoKeywordClassification::query()->create([
            'keyword_id' => $keywordId,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => null,
            'is_seo_keyword' => true,
            'classified_at' => now(),
        ]);

        if ($hidden) {
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => $keywordId,
                'meta_key' => KeywordMetaKey::SeoHidden->value,
                'meta_value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
