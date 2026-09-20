<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class WordPressInternalLinkTargetPolicyTest extends TestCase
{
    public function test_unsynced_article_is_not_eligible(): void
    {
        $article = new SeoArticle(['id' => 1, 'slug' => 'local-draft', 'site_id' => 2]);
        $article->setRelation('wordpressLink', null);
        $article->setRelation('articleMetas', collect());

        $policy = new WordPressInternalLinkTargetPolicy;

        self::assertFalse($policy->isEligibleLinkTarget($article));
        self::assertNull($policy->resolveAuthoritativePermalink($article));
    }

    public function test_prefers_observed_permalink_when_present(): void
    {
        $article = new SeoArticle(['id' => 10, 'slug' => 'old-slug', 'site_id' => 2]);
        $link = new WordpressArticleLink([
            'wp_post_id' => 99,
            'observed_permalink' => 'https://example.com/new-observed-url',
        ]);
        $article->setRelation('wordpressLink', $link);
        $article->setRelation('articleMetas', collect([
            (object) ['meta_key' => 'wp_permalink', 'meta_value' => 'https://example.com/meta-url'],
        ]));

        $policy = new WordPressInternalLinkTargetPolicy;

        self::assertTrue($policy->isEligibleLinkTarget($article));
        self::assertSame(
            'https://example.com/new-observed-url',
            $policy->resolveAuthoritativePermalink($article),
        );
    }

    public function test_falls_back_to_wp_permalink_meta_when_observed_missing(): void
    {
        $article = new SeoArticle(['id' => 11, 'slug' => 'slug-only', 'site_id' => 2]);
        $link = new WordpressArticleLink([
            'wp_post_id' => 100,
            'observed_permalink' => null,
        ]);
        $article->setRelation('wordpressLink', $link);
        $article->setRelation('articleMetas', collect([
            (object) ['meta_key' => 'wp_permalink', 'meta_value' => 'https://example.com/from-meta'],
        ]));

        $policy = new WordPressInternalLinkTargetPolicy;

        self::assertSame(
            'https://example.com/from-meta',
            $policy->resolveAuthoritativePermalink($article),
        );
    }

    public function test_synced_without_wordpress_permalink_returns_null_even_with_local_slug(): void
    {
        $article = new SeoArticle(['id' => 12, 'slug' => 'has-slug', 'site_id' => 2]);
        $link = new WordpressArticleLink([
            'wp_post_id' => 101,
            'observed_permalink' => '',
        ]);
        $article->setRelation('wordpressLink', $link);
        $article->setRelation('articleMetas', collect());

        $policy = new WordPressInternalLinkTargetPolicy;

        self::assertFalse($policy->isEligibleLinkTarget($article));
        self::assertNull($policy->resolveAuthoritativePermalink($article));
    }

    public function test_site_index_cache_key_is_versioned(): void
    {
        self::assertSame(
            'article_link_suggest.site_index.v4.7.vi',
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(7, 'vi'),
        );
        self::assertSame(
            'article_link_suggest.site_index.v4.7._',
            WordPressInternalLinkTargetPolicy::siteIndexCacheKey(7),
        );
        self::assertStringNotContainsString(
            '.v1.',
            WordPressInternalLinkTargetPolicy::SITE_INDEX_CACHE_PREFIX,
        );
        self::assertStringNotContainsString(
            '.v3.',
            WordPressInternalLinkTargetPolicy::SITE_INDEX_CACHE_PREFIX,
        );
    }

    public function test_wp_permalink_ignored_when_wp_post_id_missing(): void
    {
        $article = new SeoArticle(['id' => 13, 'slug' => 'draft', 'site_id' => 2]);
        $article->setRelation('wordpressLink', null);
        $article->setRelation('articleMetas', new Collection([
            (object) ['meta_key' => 'wp_permalink', 'meta_value' => 'https://example.com/should-not-use'],
        ]));

        $policy = new WordPressInternalLinkTargetPolicy;

        self::assertNull($policy->resolveAuthoritativePermalink($article));
    }
}
