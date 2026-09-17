<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Services\WordPressSiteInfoService;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
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
        $builder = new WordPressPermalinkBuilder(Mockery::mock(WordPressSiteInfoService::class));

        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?p=10597'));
        $this->assertTrue($builder->isPlainPermalinkUrl('https://example.com/?page_id=12'));
        $this->assertFalse($builder->isPlainPermalinkUrl('https://example.com/my-post.html'));
    }

    public function test_post_candidate_uses_site_tin_tuc_structure(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'category_base' => 'category',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://mayhopphat.com/tin-tuc/%slug%.html',
            ],
        ]);

        $article = $this->article('post', 'mayhopphat.com');

        $this->assertSame(
            'https://mayhopphat.com/tin-tuc/tui-dung-my-pham-hanayuki.html',
            $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'),
        );
    }

    public function test_different_site_blog_structure_resolves_differently(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/blog/%postname%/',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://blog.example/blog/%slug%/',
            ],
        ]);

        $article = $this->article('post', 'blog.example');

        $this->assertSame(
            'https://blog.example/blog/same-slug/',
            $builder->candidatePermalink($article, 'same-slug'),
        );
    }

    public function test_product_root_permalink_does_not_use_tin_tuc(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://mayhopphat.com/tin-tuc/%slug%.html',
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => [
                'product_base' => '',
                'category_base' => 'product-category',
            ],
        ]);

        // Legacy articles.type still "article" — raw WP type is product via meta.
        $article = $this->article('product', 'mayhopphat.com', legacyType: 'article');

        $this->assertSame(
            'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
            $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'),
        );
        $this->assertStringNotContainsString('/tin-tuc/', $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'));
    }

    public function test_product_custom_woo_base(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [],
            'woocommerce' => [
                'product_base' => 'san-pham',
                'category_base' => 'danh-muc',
            ],
        ]);

        $article = $this->article('product', 'shop.test');

        $this->assertSame(
            'https://shop.test/san-pham/balo',
            $builder->candidatePermalink($article, 'balo'),
        );
    }

    public function test_page_root_and_hierarchical_parent_path(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'page' => 'https://example.com/%slug%/',
            ],
            'post_types' => [
                'page' => ['rewrite_slug' => '', 'hierarchical' => true, 'with_front' => false],
            ],
        ]);

        $page = $this->article('page', 'example.com');
        $this->assertSame('https://example.com/about/', $builder->candidatePermalink($page, 'about'));

        $child = $this->article('page', 'example.com', extraMeta: [
            'wp_parent_path' => 'about',
        ]);
        // Template wins when present.
        $this->assertSame('https://example.com/team/', $builder->candidatePermalink($child, 'team'));

        $builderNoPageTpl = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [],
            'post_types' => [
                'page' => ['rewrite_slug' => '', 'hierarchical' => true, 'with_front' => false],
            ],
        ]);
        $nested = $this->article('page', 'example.com', extraMeta: [
            'wp_parent_path' => 'about/company',
        ]);
        $this->assertSame(
            'https://example.com/about/company/team',
            $builderNoPageTpl->candidatePermalink($nested, 'team'),
        );
    }

    public function test_category_and_product_cat_custom_bases(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'category_base' => 'chuyen-muc',
            'templates_version' => 1,
            'templates' => [],
            'woocommerce' => [
                'product_base' => 'product',
                'category_base' => 'danh-muc-san-pham',
            ],
        ]);

        $category = $this->article('category', 'example.com');
        $this->assertSame(
            'https://example.com/chuyen-muc/tin-moi',
            $builder->candidatePermalink($category, 'tin-moi'),
        );

        $productCat = $this->article('product_cat', 'example.com');
        $this->assertSame(
            'https://example.com/danh-muc-san-pham/balo',
            $builder->candidatePermalink($productCat, 'balo'),
        );
    }

    public function test_native_cpt_uses_rewrite_slug(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [],
            'post_types' => [
                'recipe' => [
                    'rewrite_slug' => 'cong-thuc',
                    'hierarchical' => false,
                    'with_front' => false,
                ],
            ],
        ]);

        $article = $this->article('recipe', 'example.com');
        $this->assertSame(
            'https://example.com/cong-thuc/pho-bo',
            $builder->candidatePermalink($article, 'pho-bo'),
        );
    }

    public function test_candidate_uses_article_site_not_header_domain(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ]);

        $article = $this->article('product', 'mayhopphat.com');
        $url = $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki');

        $this->assertStringContainsString('mayhopphat.com', $url);
        $this->assertStringNotContainsString('other-domain.com', $url);
    }

    public function test_local_post_to_product_changes_candidate_keeps_wp_permalink(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://mayhopphat.com/tin-tuc/%slug%.html',
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ]);

        $observed = 'https://mayhopphat.com/tin-tuc/tui-dung-my-pham-hanayuki.html';
        $article = $this->article('product', 'mayhopphat.com', extraMeta: [
            'wp_permalink' => $observed,
        ], wpPostId: 12580);

        $this->assertSame(
            'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
            $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'),
        );
        $this->assertSame(
            $observed,
            $builder->resolve($article, $observed, 'tui-dung-my-pham-hanayuki'),
        );
    }

    public function test_local_slug_change_updates_candidate_keeps_wp_permalink(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://example.com/tin-tuc/%slug%.html',
            ],
        ]);

        $observed = 'https://example.com/tin-tuc/old-slug.html';
        $article = $this->article('post', 'example.com', extraMeta: [
            'wp_permalink' => $observed,
        ], wpPostId: 99);

        $this->assertSame(
            'https://example.com/tin-tuc/new-slug.html',
            $builder->candidatePermalink($article, 'new-slug'),
        );
        $this->assertSame($observed, $builder->resolve($article, $observed, 'new-slug'));
    }

    public function test_sync_item_pretty_link_wins_over_candidate(): void
    {
        $site = new Site(['domain' => 'mayhopphat.com', 'ssl' => true]);
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/san-pham/%slug%/',
            ],
            'woocommerce' => ['product_base' => 'san-pham', 'category_base' => ''],
        ]);

        $remote = 'https://mayhopphat.com/tui-dung-my-pham-hanayuki';
        $this->assertSame($remote, $builder->resolveFromSyncItem($site, [
            'permalink' => $remote,
            'slug' => 'tui-dung-my-pham-hanayuki',
            'wp_post_type' => 'product',
            'type' => 'product',
        ]));
    }

    public function test_missing_routing_profile_returns_empty_candidate_not_tin_tuc(): void
    {
        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([]);
        $builder = new WordPressPermalinkBuilder($siteInfo);

        $article = $this->article('product', 'mayhopphat.com', legacyType: 'article');
        $this->assertSame('', $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'));
    }

    public function test_content_project_normalize_post_type_untouched(): void
    {
        $this->assertSame('post', SeoProjectTask::normalizePostType('page'));
        $this->assertSame('post', SeoProjectTask::normalizePostType('recipe'));
    }

    public function test_resolve_from_sync_builds_postname_html_when_plain(): void
    {
        $site = new Site([
            'domain' => 'maybalotuixachgiare.com',
            'ssl' => true,
        ]);
        $site->setRelation('metas', collect());

        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%.html',
            'category_base' => 'category',
            'tag_base' => 'tag',
            'templates_version' => 1,
            'templates' => [],
        ]);

        $url = $builder->resolveFromSyncItem($site, [
            'permalink' => 'https://maybalotuixachgiare.com/?p=10597',
            'slug' => 'vai-oxford-may-balo-thoi-trang',
            'type' => 'article',
            'wp_post_type' => 'post',
            'published_at' => '2026-06-07T09:23:00+00:00',
            'wp_id' => 10597,
        ]);

        $this->assertSame(
            'https://maybalotuixachgiare.com/vai-oxford-may-balo-thoi-trang.html',
            $url,
        );
    }

    public function test_linked_article_keeps_real_wordpress_permalink(): void
    {
        $article = $this->article('product', 'example.com', wpPostId: 123);
        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
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

    public function test_vietnamese_product_does_not_inherit_en_prefix_from_contaminated_template(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                // Contaminated sample from latest English product.
                'product' => 'https://mayhopphat.com/en/%slug%',
                'post' => 'https://mayhopphat.com/tin-tuc/%slug%.html',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => true,
            'default' => 'vi',
            'languages' => [
                ['slug' => 'vi', 'name' => 'Tiếng Việt', 'locale' => 'vi', 'url_prefix' => ''],
                ['slug' => 'en', 'name' => 'English', 'locale' => 'en_US', 'url_prefix' => 'en'],
            ],
        ]);

        $article = $this->article('product', 'mayhopphat.com', language: 'vi', extraMeta: [
            'wp_permalink' => 'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
        ], wpPostId: 12580);

        $candidate = $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki');
        $this->assertSame('https://mayhopphat.com/tui-dung-my-pham-hanayuki', $candidate);
        $this->assertStringNotContainsString('/en/', $candidate);
        $this->assertStringNotContainsString('/tin-tuc/', $candidate);

        // Linked translation must not mutate remote authority.
        $this->assertSame(
            'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
            $builder->resolve($article, 'https://mayhopphat.com/tui-dung-my-pham-hanayuki', 'tui-dung-my-pham-hanayuki'),
        );
    }

    public function test_english_article_uses_explicit_en_prefix_from_site_info(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => true,
            'default' => 'vi',
            'languages' => [
                ['slug' => 'vi', 'name' => 'Tiếng Việt', 'locale' => 'vi', 'url_prefix' => ''],
                ['slug' => 'en', 'name' => 'English', 'locale' => 'en_US', 'url_prefix' => 'en'],
            ],
        ]);

        $article = $this->article('product', 'mayhopphat.com', language: 'en');
        $this->assertSame(
            'https://mayhopphat.com/en/cosmetic-bag',
            $builder->candidatePermalink($article, 'cosmetic-bag'),
        );
    }

    public function test_default_language_without_prefix_stays_root(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => true,
            'default' => 'vi',
            'languages' => [
                ['slug' => 'vi', 'name' => 'Tiếng Việt', 'locale' => 'vi', 'url_prefix' => ''],
                ['slug' => 'en', 'name' => 'English', 'locale' => 'en_US', 'url_prefix' => 'en'],
            ],
        ]);

        $article = $this->article('product', 'mayhopphat.com', language: 'vi');
        $this->assertSame(
            'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
            $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki'),
        );
    }

    public function test_no_multilingual_routing_never_invents_locale_prefix(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => false,
            'default' => 'vi',
            'languages' => [],
        ]);

        $article = $this->article('product', 'mayhopphat.com', language: 'en');
        $this->assertSame(
            'https://mayhopphat.com/cosmetic-bag',
            $builder->candidatePermalink($article, 'cosmetic-bag'),
        );
        $this->assertStringNotContainsString('/en/', $builder->candidatePermalink($article, 'cosmetic-bag'));
    }

    public function test_linked_translation_language_does_not_change_current_candidate(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/%postname%/',
            'templates_version' => 1,
            'templates' => [
                'product' => 'https://mayhopphat.com/en/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => true,
            'default' => 'vi',
            'languages' => [
                ['slug' => 'vi', 'name' => 'Tiếng Việt', 'locale' => 'vi', 'url_prefix' => ''],
                ['slug' => 'en', 'name' => 'English', 'locale' => 'en_US', 'url_prefix' => 'en'],
            ],
        ]);

        // Current article is Vietnamese; presence of an English translation relation is irrelevant.
        $vi = $this->article('product', 'mayhopphat.com', language: 'vi');
        $this->assertSame(
            'https://mayhopphat.com/tui-dung-my-pham-hanayuki',
            $builder->candidatePermalink($vi, 'tui-dung-my-pham-hanayuki'),
        );

        $en = $this->article('product', 'mayhopphat.com', language: 'en');
        $this->assertSame(
            'https://mayhopphat.com/en/tui-dung-my-pham-hanayuki',
            $builder->candidatePermalink($en, 'tui-dung-my-pham-hanayuki'),
        );
    }

    public function test_product_plus_language_does_not_leak_post_tin_tuc_template(): void
    {
        $builder = $this->builderWithPermalink([
            'structure' => '/tin-tuc/%postname%.html',
            'templates_version' => 1,
            'templates' => [
                'post' => 'https://mayhopphat.com/tin-tuc/%slug%.html',
                'product' => 'https://mayhopphat.com/en/%slug%',
            ],
            'woocommerce' => ['product_base' => '', 'category_base' => ''],
        ], [
            'active' => true,
            'default' => 'vi',
            'languages' => [
                ['slug' => 'vi', 'url_prefix' => ''],
                ['slug' => 'en', 'url_prefix' => 'en'],
            ],
        ]);

        $article = $this->article('product', 'mayhopphat.com', language: 'vi', legacyType: 'article');
        $url = $builder->candidatePermalink($article, 'tui-dung-my-pham-hanayuki');
        $this->assertSame('https://mayhopphat.com/tui-dung-my-pham-hanayuki', $url);
        $this->assertStringNotContainsString('/tin-tuc/', $url);
        $this->assertStringNotContainsString('/en/', $url);
    }

    /**
     * @param  array<string, mixed>  $permalink
     * @param  array<string, mixed>  $polylang
     */
    private function builderWithPermalink(array $permalink, array $polylang = []): WordPressPermalinkBuilder
    {
        $siteInfo = Mockery::mock(WordPressSiteInfoService::class);
        $siteInfo->shouldReceive('getStoredSiteInfo')->andReturn([
            'permalink' => $permalink,
            'polylang' => $polylang,
        ]);

        return new WordPressPermalinkBuilder($siteInfo);
    }

    /**
     * @param  array<string, string>  $extraMeta
     */
    private function article(
        string $wpPostType,
        string $domain,
        string $legacyType = 'article',
        array $extraMeta = [],
        int $wpPostId = 0,
        string $language = 'vi',
    ): SeoArticle {
        $contentType = match ($wpPostType) {
            'product', 'product_cat' => 'product',
            'page' => 'page',
            'category' => 'post',
            default => in_array($wpPostType, ['post', 'article'], true) ? 'post' : 'post',
        };
        $isTerm = in_array($wpPostType, ['category', 'product_cat'], true) ? '1' : '0';

        $meta = array_merge([
            'content_type' => $contentType,
            'wp_is_term' => $isTerm,
            'wp_post_type' => $wpPostType,
        ], $extraMeta);

        $site = new Site(['domain' => $domain, 'ssl' => true]);
        $site->id = 7;
        $site->setRelation('metas', collect());

        $article = new SeoArticle([
            'type' => $legacyType,
            'slug' => 'sample',
            'site_id' => 7,
            'language' => $language,
        ]);
        if ($wpPostId > 0) {
            $article->setAttribute('wp_post_id', $wpPostId);
            $article->setRelation('wordpressLink', (object) ['wp_post_id' => $wpPostId]);
        }
        $article->setRelation('site', $site);
        $article->setRelation(
            'articleMetas',
            new Collection(array_map(
                static function (string $key, string $value): ArticleMeta {
                    $row = new ArticleMeta;
                    $row->forceFill([
                        'meta_key' => $key,
                        'meta_value' => $value,
                    ]);

                    return $row;
                },
                array_keys($meta),
                array_values($meta),
            )),
        );

        return $article;
    }
}
