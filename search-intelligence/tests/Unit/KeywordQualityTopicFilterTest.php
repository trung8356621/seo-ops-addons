<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSiteKeywordService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Tests\TestCase;

final class KeywordQualityTopicFilterTest extends TestCase
{
    private const SITE_A = 41;

    private const SITE_B = 42;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_120000_create_seo_keyword_quality_tables.php')->up();
    }

    public function test_scan_summary_manual_hold_and_topic_filter(): void
    {
        $clean = $this->keyword('balo học sinh', self::SITE_A);
        $cta = $this->keyword('mua ngay', self::SITE_A);
        $copy = $this->keyword('balo học sinh', self::SITE_A);
        $other = $this->keyword('mua ngay', self::SITE_B);

        $service = app(KeywordQualityService::class);
        $service->scanPhrases(self::SITE_A, [
            $clean => 'balo học sinh',
            $cta => 'mua ngay',
            $copy => 'balo học sinh',
        ]);
        $service->scanPhrases(self::SITE_B, [$other => 'mua ngay']);

        self::assertSame([], $service->codesFor(self::SITE_A, $clean));
        self::assertContains(KeywordQualityCode::CTA_ANCHOR, $service->codesFor(self::SITE_A, $cta));
        self::assertContains(KeywordQualityCode::DUPLICATE_VARIANT, $service->codesFor(self::SITE_A, $copy));
        self::assertNotContains(KeywordQualityCode::DUPLICATE_VARIANT, $service->codesFor(self::SITE_A, $clean));

        $summary = $service->summary(self::SITE_A);
        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['unique_tagged']);
        self::assertSame(0, $summary['unique_excluded']);
        $ctaRow = collect($summary['tags'])->firstWhere('code', KeywordQualityCode::CTA_ANCHOR);
        self::assertSame(1, $ctaRow['count']);
        self::assertSame(33.3, $ctaRow['percent']);
        $dictionary = app(KeywordDictionaryQuery::class);
        self::assertSame(
            $summary['unique_tagged'],
            $dictionary->filteredForSummary(self::SITE_A, null, ['quality_scope' => 'tagged'])->count(),
        );
        $duplicateCount = (int) collect($summary['tags'])->firstWhere('code', KeywordQualityCode::DUPLICATE_VARIANT)['count'];
        self::assertSame(
            $duplicateCount,
            $dictionary->filteredForSummary(self::SITE_A, null, [
                'quality_codes' => [KeywordQualityCode::DUPLICATE_VARIANT],
            ])->count(),
        );
        self::assertSame(
            0,
            $dictionary->filteredForSummary(self::SITE_A, null, [
                'quality_codes' => [KeywordQualityCode::ENCODING_ERROR],
            ])->count(),
        );
        $service->syncManual(self::SITE_A, $cta, [
            KeywordQualityCode::CTA_ANCHOR,
            KeywordQualityCode::MALFORMED_TEXT,
        ]);
        $withTwoTags = $service->summary(self::SITE_A);
        self::assertSame(2, $withTwoTags['unique_tagged']);
        self::assertSame(
            2,
            $dictionary->filteredForSummary(self::SITE_A, null, ['quality_scope' => 'tagged'])->count(),
        );
        $service->restoreAutomatic(self::SITE_A, $cta);

        $this->membership(self::SITE_A, 7, $clean);
        $this->membership(self::SITE_A, 7, $cta);
        $this->membership(self::SITE_B, 8, $other);
        $before = SeoTopicKeyword::query()->count();

        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(2, $counts[7]);

        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, true);
        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(1, $counts[7] ?? 0);
        self::assertSame($before, SeoTopicKeyword::query()->count());
        $siteB = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_B, [8]);
        self::assertSame(1, $siteB[8]);

        $candidates = app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A);
        $candidateIds = array_column($candidates, 'keyword_id');
        self::assertNotContains($cta, $candidateIds);
        self::assertContains($clean, $candidateIds);

        $service->setExcluded(self::SITE_A, KeywordQualityCode::CTA_ANCHOR, false);
        $counts = app(TopicMembershipQuery::class)->keywordCountsByTopicIds(self::SITE_A, [7]);
        self::assertSame(2, $counts[7]);
        self::assertContains(
            $cta,
            array_column(app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A), 'keyword_id'),
        );

        $service->syncManual(self::SITE_A, $cta, []);
        $service->scanPhrases(self::SITE_A, [
            $clean => 'balo học sinh',
            $cta => 'mua ngay',
            $copy => 'balo học sinh',
        ]);
        self::assertSame([], $service->codesFor(self::SITE_A, $cta));
        self::assertSame($before, SeoTopicKeyword::query()->count());
    }

    private function keyword(string $phrase, int $siteId): int
    {
        $id = (int) Keyword::query()->create([
            'phrase' => $phrase,
            'type' => 'normal',
            'review_status' => 'active',
        ])->id;
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => 1000 + $id,
            'site_id' => $siteId,
            'title' => 'Article '.$id,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $id,
            'meta_key' => KeywordMetaKey::siteMainArticleId($siteId),
            'meta_value' => (string) (1000 + $id),
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $id,
            'source_article_id' => 1000 + $id,
            'status' => 'active',
        ]);

        return $id;
    }

    private function membership(int $siteId, int $topicId, int $keywordId): void
    {
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => $keywordId,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => false,
        ]);
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
            $table->timestamps();
        });
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->string('status')->nullable();
        });
        $schema->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->default('auto');
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
    }
}
