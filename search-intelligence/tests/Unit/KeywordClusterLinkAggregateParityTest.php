<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterDetailBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Tests\TestCase;

final class KeywordClusterLinkAggregateParityTest extends TestCase
{
    private const SITE_ID = 42;

    private const CLUSTER_KEY = 'tui_dung_my_pham';

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

    public function test_detail_reuses_member_link_stats_ssot(): void
    {
        $kw1 = $this->seedMember('túi đựng mỹ phẩm');
        $kw2 = $this->seedMember('túi đựng mỹ phẩm da');

        $articleA = $this->createArticle('Focus A');
        $articleB = $this->createArticle('Focus B');

        // 2 focus articles via distinct target_article_id; 3 link rows total (1 without target).
        $this->createLinkMap((int) $kw1->id, $articleA, $articleA);
        $this->createLinkMap((int) $kw1->id, $articleA, $articleB);
        $this->createLinkMap((int) $kw2->id, $articleB, null);

        $query = app(KeywordClusterQuery::class);
        $memberIds = $query->memberKeywordIds(self::SITE_ID, self::CLUSTER_KEY);
        $stats = $query->memberLinkStats($memberIds);

        self::assertCount(2, $memberIds);
        self::assertSame(2, $stats['article_count']);
        self::assertSame(3, $stats['internal_link_count']);

        $detail = app(KeywordClusterDetailBuilder::class)->build(self::SITE_ID, self::CLUSTER_KEY);
        self::assertNotNull($detail);
        self::assertSame($stats['article_count'], (int) $detail['article_count']);
        self::assertSame($stats['internal_link_count'], (int) $detail['internal_links']);
        self::assertSame($stats['internal_link_count'], (int) $detail['internal_link_count']);
        self::assertSame(2, (int) $detail['keyword_count']);
    }

    public function test_zero_focus_and_zero_links_still_surface_counts(): void
    {
        $this->seedMember('túi empty');

        $query = app(KeywordClusterQuery::class);
        $stats = $query->memberLinkStats($query->memberKeywordIds(self::SITE_ID, self::CLUSTER_KEY));
        self::assertSame(0, $stats['article_count']);
        self::assertSame(0, $stats['internal_link_count']);

        $detail = app(KeywordClusterDetailBuilder::class)->build(self::SITE_ID, self::CLUSTER_KEY);
        self::assertNotNull($detail);
        self::assertSame(0, (int) $detail['article_count']);
        self::assertSame(0, (int) $detail['internal_links']);
        self::assertSame(0, (int) $detail['internal_link_count']);
        self::assertSame(1, (int) $detail['keyword_count']);
    }

    private function seedMember(string $phrase): Keyword
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ]);

        // Site scope via meta — avoids polluting seo_link_maps focus/link counts.
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => (int) $keyword->id,
            'meta_key' => 'site.'.self::SITE_ID.'.owned',
            'meta_value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SeoKeywordClassification::query()->create([
            'keyword_id' => (int) $keyword->id,
            'normalized_text' => mb_strtolower($phrase, 'UTF-8'),
            'folded_text' => mb_strtolower($phrase, 'UTF-8'),
            'phrase_kind' => 'keyword_phrase',
            'seo_intent' => 'commercial',
            'cluster_key' => self::CLUSTER_KEY,
            'is_seo_keyword' => true,
            'classified_at' => now(),
        ]);

        return $keyword;
    }

    private function createArticle(string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => self::SITE_ID,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLinkMap(int $keywordId, int $sourceArticleId, ?int $targetArticleId): void
    {
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
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
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });
    }
}
