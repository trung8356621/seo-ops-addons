<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\KeywordMeta;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Tests\TestCase;

/**
 * Regression: Site Sync attach cleared legacy main_article_id on the keyword being
 * attached, orphan-deleted that keyword (TYPE_NORMAL upsert writes no other metas),
 * then inserted site.{siteId}.main_article_id → MySQL FK 1452.
 */
final class KeywordFocusAttachOrphanFkRegressionTest extends TestCase
{
    private const SITE_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('omi_seo_ai');
        DB::connection('omi_seo_ai')->getPdo()->exec('PRAGMA foreign_keys = ON');
        $this->ensureTables();
        Queue::fake();
    }

    public function test_reattach_with_legacy_only_main_article_meta_does_not_fk_or_orphan(): void
    {
        $phrase = 'cong ty balo tui xach orphan fk';
        $article = $this->createArticle(4390, 'Legacy focus article');
        $keywordId = $this->insertKeyword(971, $phrase);

        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keywordId,
            'meta_key' => KeywordMetaKey::MainArticleId->value,
            'meta_value' => (string) $article->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attachedId = KeywordFocusAttach::attachMainKeyword($article, self::SITE_ID, $phrase);

        $this->assertSame($keywordId, $attachedId);
        $this->assertTrue(Keyword::query()->whereKey($keywordId)->exists());
        $this->assertSame(
            (int) $article->id,
            app(KeywordMetaRepository::class)->getMainArticleIdForSite($keywordId, self::SITE_ID),
        );
        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => $keywordId,
            'meta_key' => KeywordMetaKey::siteMainArticleId(self::SITE_ID),
            'meta_value' => (string) $article->id,
        ], 'omi_seo_ai');
    }

    public function test_reattach_is_idempotent_for_site_main_article_id(): void
    {
        $phrase = 'idempotent focus phrase';
        $article = $this->createArticle(4400, 'Idempotent focus');

        $first = KeywordFocusAttach::attachMainKeyword($article, self::SITE_ID, $phrase);
        $second = KeywordFocusAttach::attachMainKeyword($article, self::SITE_ID, $phrase);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(
            1,
            KeywordMeta::query()
                ->where('keyword_id', $first)
                ->where('meta_key', KeywordMetaKey::siteMainArticleId(self::SITE_ID))
                ->count(),
        );
        $this->assertSame(
            (int) $article->id,
            app(KeywordMetaRepository::class)->getMainArticleIdForSite((int) $first, self::SITE_ID),
        );
    }

    public function test_set_main_article_for_missing_keyword_rejects_without_fk_violation(): void
    {
        $article = $this->createArticle(4401, 'Missing keyword guard');
        $missingId = 999991;
        $this->assertFalse(Keyword::query()->whereKey($missingId)->exists());

        $ok = app(KeywordMetaRepository::class)->setMainArticleIdForSite(
            $missingId,
            self::SITE_ID,
            (int) $article->id,
        );

        $this->assertFalse($ok);
        $this->assertSame(0, KeywordMeta::query()->where('keyword_id', $missingId)->count());
    }

    public function test_stale_other_keyword_is_cleared_and_canonical_receives_meta(): void
    {
        $article = $this->createArticle(4402, 'Replace focus');
        $staleId = $this->insertKeyword(2001, 'stale focus phrase');
        app(KeywordMetaRepository::class)->setMainArticleIdForSite($staleId, self::SITE_ID, (int) $article->id);

        $canonicalId = KeywordFocusAttach::attachMainKeyword($article, self::SITE_ID, 'canonical focus phrase');
        $this->assertNotNull($canonicalId);
        $this->assertNotSame($staleId, $canonicalId);

        $this->assertSame(
            (int) $article->id,
            app(KeywordMetaRepository::class)->getMainArticleIdForSite((int) $canonicalId, self::SITE_ID),
        );
        $this->assertNull(
            app(KeywordMetaRepository::class)->getMainArticleIdForSite($staleId, self::SITE_ID),
        );
    }

    public function test_keyword_meta_foreign_key_rejects_orphan_insert(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => 888888,
            'meta_key' => KeywordMetaKey::siteMainArticleId(self::SITE_ID),
            'meta_value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createArticle(int $id, string $title): SeoArticle
    {
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => $id,
            'site_id' => self::SITE_ID,
            'title' => $title,
            'body' => '<p>Content</p>',
            'status' => 'publish',
            'type' => 'article',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('wordpress_article_links')->insert([
            'article_id' => $id,
            'wp_post_id' => null,
        ]);

        $article = SeoArticle::query()->findOrFail($id);
        $this->assertInstanceOf(SeoArticle::class, $article);

        return $article;
    }

    private function insertKeyword(int $id, string $phrase): int
    {
        DB::connection('omi_seo_ai')->table('keywords')->insert([
            'id' => $id,
            'phrase' => Keyword::preparePhraseForStorage($phrase),
            'type' => Keyword::TYPE_NORMAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('site_id');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->unsignedInteger('wp_post_id')->nullable();
        });

        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('source')->nullable();
            $table->boolean('source_locked')->default(false);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['keyword_id', 'meta_key']);
            $table->foreign('keyword_id')
                ->references('id')
                ->on('keywords')
                ->onDelete('cascade');
        });

        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('keyword_id')->nullable();
            $table->unsignedInteger('source_article_id')->nullable();
            $table->timestamps();
        });
    }
}
