<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use App\Models\Site;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class WpPostTypeMetaSyncTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    public function test_page_payload_persists_wp_post_type_meta_with_article_business_type(): void
    {
        $this->requireSeoDatabaseConnection();

        $site = Site::query()->create([
            'name' => 'WP post type test',
            'domain' => 'example-page.test',
            'user_id' => 1,
            'status' => 1,
        ]);

        app(SyncDomainContentService::class)->importItems($site, [[
            'wp_id' => 88001,
            'type' => 'article',
            'wp_post_type' => 'page',
            'wp_entity' => 'post',
            'title' => 'Liên hệ',
            'status' => 'publish',
        ]], forceOverwrite: true);

        $article = SeoArticle::query()
            ->where('site_id', $site->id)
            ->whereWpPostId(88001)
            ->first();

        self::assertInstanceOf(SeoArticle::class, $article);
        self::assertSame(ContentType::Page, ArticleContentClassification::for($article)->contentType());
        self::assertFalse(ArticleContentClassification::for($article)->isTerm());
        self::assertSame(
            'page',
            (string) $article->articleMetas()->where('meta_key', 'wp_post_type')->value('meta_value'),
        );
    }

    public function test_existing_record_updates_missing_wp_post_type_on_sync(): void
    {
        $this->requireSeoDatabaseConnection();

        $site = Site::query()->create([
            'name' => 'WP post type update test',
            'domain' => 'example-page-update.test',
            'user_id' => 1,
            'status' => 1,
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => $site->id,
            'wp_post_id' => 88002,
            'title' => 'Liên hệ',
            'status' => 'published',
            'type' => 'article',
        ]);

        self::assertNull(
            $article->articleMetas()->where('meta_key', 'wp_post_type')->value('meta_value'),
        );

        app(SyncDomainContentService::class)->importItems($site, [[
            'wp_id' => 88002,
            'type' => 'article',
            'wp_post_type' => 'page',
            'wp_entity' => 'post',
            'title' => 'Liên hệ',
            'status' => 'publish',
        ]]);

        $article->refresh();
        self::assertSame(ContentType::Page, ArticleContentClassification::for($article)->contentType());
        self::assertSame(
            'page',
            (string) $article->articleMetas()->where('meta_key', 'wp_post_type')->value('meta_value'),
        );
    }

    public function test_custom_post_type_slug_is_preserved_in_meta(): void
    {
        $this->requireSeoDatabaseConnection();

        $site = Site::query()->create([
            'name' => 'WP CPT test',
            'domain' => 'example-cpt.test',
            'user_id' => 1,
            'status' => 1,
        ]);

        app(SyncDomainContentService::class)->importItems($site, [[
            'wp_id' => 88003,
            'type' => 'article',
            'wp_post_type' => 'portfolio',
            'wp_entity' => 'post',
            'title' => 'Portfolio Item',
            'status' => 'publish',
        ]], forceOverwrite: true);

        $article = SeoArticle::query()
            ->where('site_id', $site->id)
            ->whereWpPostId(88003)
            ->first();

        self::assertInstanceOf(SeoArticle::class, $article);
        self::assertSame(ContentType::Post, ArticleContentClassification::for($article)->contentType());
        self::assertSame(
            'portfolio',
            (string) $article->articleMetas()->where('meta_key', 'wp_post_type')->value('meta_value'),
        );
    }
}
