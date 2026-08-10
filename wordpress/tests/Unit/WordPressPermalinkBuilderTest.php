<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\Site;
use Mockery;
use Tests\TestCase;

final class WordPressPermalinkBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_detects_plain_permalink_urls(): void
    {
        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?p=10597'));
        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?page_id=12'));
        $this->assertFalse($builder->isPlainPermalinkUrl('https://example.com/my-post.html'));
    }

    public function test_builds_pretty_url_from_postname_html_structure(): void
    {
        $site = new Site([
            'domain' => 'maybalotuixachgiare.com',
            'ssl' => true,
        ]);
        $site->setRelation('metas', collect());

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')
            ->andReturn([
                'permalink' => [
                    'structure' => '/%postname%.html',
                    'category_base' => 'category',
                    'tag_base' => 'tag',
                ],
            ]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $url = $builder->resolveFromSyncItem($site, [
            'permalink' => 'https://maybalotuixachgiare.com/?p=10597',
            'slug' => 'vai-oxford-may-balo-thoi-trang',
            'type' => 'article',
            'published_at' => '2026-06-07T09:23:00+00:00',
            'wp_id' => 10597,
        ]);

        $this->assertSame(
            'https://maybalotuixachgiare.com/vai-oxford-may-balo-thoi-trang.html',
            $url,
        );
    }

    public function test_resolve_from_sync_keeps_pretty_permalink(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $cached = 'https://example.com/my-slug.html';

        $this->assertSame($cached, $builder->resolveFromSyncItem($site, [
            'permalink' => $cached,
            'slug' => 'my-slug',
            'type' => 'article',
        ]));
    }

    public function test_previews_unsynced_article_with_wordpress_permalink_structure(): void
    {
        $site = new Site([
            'domain' => 'example.com',
            'ssl' => true,
        ]);
        $site->setRelation('metas', collect());
        $article = new SeoArticle([
            'type' => 'article',
        ]);
        $article->setRelation('site', $site);
        $article->setRelation('articleMetas', collect());

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')
            ->andReturn([
                'permalink' => [
                    'structure' => '/%postname%.html',
                ],
            ]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertSame(
            'https://example.com/bai-viet-moi.html',
            $builder->preview($article, 'bai-viet-moi'),
        );
    }

    public function test_uses_runtime_product_template_after_remove_product_base_filter(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);
        $article = new SeoArticle(['type' => 'product']);
        $article->setRelation('site', $site);
        $article->setRelation('articleMetas', collect());

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([
            'permalink' => [
                'structure' => '/%postname%/',
                'templates_version' => 1,
                'templates' => [
                    'product' => 'https://example.com/%slug%/',
                ],
                'woocommerce' => [
                    'product_base' => 'product',
                ],
            ],
        ]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertSame(
            'https://example.com/cap-hoc-sinh-kim-nguu/',
            $builder->preview($article, 'cap-hoc-sinh-kim-nguu'),
        );

        $this->assertSame(
            'https://example.com/cap-hoc-sinh-kim-nguu/',
            $builder->resolve(
                $article,
                'https://example.com/product/cap-hoc-sinh-kim-nguu/',
                'cap-hoc-sinh-kim-nguu',
            ),
        );
    }

    public function test_empty_product_category_base_means_root_url(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);
        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([
            'permalink' => [
                'structure' => '/%postname%/',
                'templates_version' => 1,
                'templates' => [],
                'woocommerce' => [
                    'category_base' => '',
                ],
            ],
        ]);

        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertSame('https://example.com/balo', $builder->resolveFromSyncItem($site, [
            'slug' => 'balo',
            'type' => 'product_category',
            'wp_post_type' => 'product_cat',
        ]));
    }

    public function test_linked_article_keeps_real_wordpress_permalink(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);
        $article = new SeoArticle([
            'type' => 'product',
            'wp_post_id' => 123,
        ]);
        $article->setRelation('site', $site);
        $article->setRelation('articleMetas', collect());

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldNotReceive('getStoredSiteInfo');
        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertSame(
            'https://example.com/url-wordpress-that/',
            $builder->resolve(
                $article,
                'https://example.com/url-wordpress-that/',
                'slug-local',
            ),
        );
    }

    public function test_linked_article_keeps_plain_wordpress_permalink_without_simulating_it(): void
    {
        $site = new Site(['domain' => 'example.com', 'ssl' => true]);
        $article = new SeoArticle([
            'type' => 'product',
            'wp_post_id' => 123,
        ]);
        $article->setRelation('site', $site);
        $article->setRelation('articleMetas', collect());

        $siteInfo = Mockery::mock(\Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService::class);
        $siteInfo->shouldNotReceive('getStoredSiteInfo');
        $builder = new WordPressPermalinkBuilder($siteInfo);

        $this->assertSame(
            'https://example.com/?p=123',
            $builder->resolve($article, 'https://example.com/?p=123', 'product-slug'),
        );
    }
}
