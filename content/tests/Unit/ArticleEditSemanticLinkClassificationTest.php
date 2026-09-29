<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Behavioral runtime tests for Article Edit semantic classification:
 * 1. Internal: same-site -> internal bucket
 * 2. ManagedCrossSite: -> external semantic bucket, safe, not treated as same-site internal
 * 3. WikiTrust: -> external, low
 * 4. NeedsReview: -> external, review, UI Warning
 * 5. Legacy External: -> external, review, UI Warning
 * 6. Social: not semantic Internal, not Warning (excluded from extracted links)
 * 7. Contact: not semantic Internal, not Warning (excluded from extracted links)
 * 8. Unresolved ManagedCrossSite: remains ManagedCrossSite, no fake target Article/Keyword
 * 9. User-facing labels are NOT hardcoded in SeoArticle domain logic
 * 10. ArticleLinksSidebar still does NOT automatically start suggestions on mount
 */
final class ArticleEditSemanticLinkClassificationTest extends TestCase
{
    private SeoArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->article = new SeoArticle(['id' => 10, 'site_id' => 1]);
    }

    // 1. Internal: same-site -> internal bucket
    public function test_internal_same_site_goes_to_internal_bucket(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::Internal,
            'target_external_url' => 'https://site1.com/about-us',
            'anchor_text' => 'Về chúng tôi',
            'target_site_id' => 1,
            'target_article_id' => 20,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(1, $extracted['internal']);
        self::assertCount(0, $extracted['external']);
        self::assertSame('https://site1.com/about-us', $extracted['internal'][0]['href']);
        self::assertSame('Về chúng tôi', $extracted['internal'][0]['text']);
        self::assertSame('internal', $extracted['internal'][0]['link_type']);
        self::assertArrayNotHasKey('semantic_risk', $extracted['internal'][0]);
    }

    // 2. ManagedCrossSite: -> external semantic bucket, safe, not treated as same-site internal
    public function test_managed_cross_site_goes_to_external_bucket_with_safe_risk(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::ManagedCrossSite,
            'target_external_url' => 'https://site2.com/san-pham',
            'anchor_text' => 'Sản phẩm bên site 2',
            'target_site_id' => 2,
            'target_article_id' => 50,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal'], 'ManagedCrossSite must NOT fall into internal bucket');
        self::assertCount(1, $extracted['external'], 'ManagedCrossSite must go to external bucket');

        $row = $extracted['external'][0];
        self::assertSame('https://site2.com/san-pham', $row['href']);
        self::assertSame('managed_cross_site', $row['link_type']);
        self::assertSame('safe', $row['semantic_risk']);
        self::assertTrue($row['is_semantic_eligible']);
        self::assertSame(2, $row['target_site_id']);
        self::assertSame(50, $row['target_article_id']);
    }

    // 2b. Internal with target on different site automatically routes to ManagedCrossSite
    public function test_internal_link_type_with_cross_site_target_routes_to_managed_cross_site(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::Internal,
            'target_external_url' => 'https://site2.com/other-site-page',
            'anchor_text' => 'Cross-site link',
            'target_site_id' => 2,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal']);
        self::assertCount(1, $extracted['external']);
        self::assertSame('managed_cross_site', $extracted['external'][0]['link_type']);
        self::assertSame('safe', $extracted['external'][0]['semantic_risk']);
    }

    // 3. WikiTrust: -> external, low
    public function test_wiki_trust_goes_to_external_bucket_with_low_risk(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::WikiTrust,
            'target_external_url' => 'https://vi.wikipedia.org/wiki/SEO',
            'anchor_text' => 'Wikipedia',
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal']);
        self::assertCount(1, $extracted['external']);

        $row = $extracted['external'][0];
        self::assertSame('https://vi.wikipedia.org/wiki/SEO', $row['href']);
        self::assertSame('wiki_trust', $row['link_type']);
        self::assertSame('low', $row['semantic_risk']);
        self::assertTrue($row['is_semantic_eligible']);
    }

    // 4. NeedsReview: -> external, review
    public function test_needs_review_goes_to_external_bucket_with_review_risk(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::NeedsReview,
            'target_external_url' => 'https://unknown-blog.com/seo-tips',
            'anchor_text' => 'Blog ngoài',
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal']);
        self::assertCount(1, $extracted['external']);

        $row = $extracted['external'][0];
        self::assertSame('https://unknown-blog.com/seo-tips', $row['href']);
        self::assertSame('needs_review', $row['link_type']);
        self::assertSame('review', $row['semantic_risk']);
        self::assertTrue($row['is_semantic_eligible']);
    }

    // 5. Legacy External: -> external, review
    public function test_legacy_external_goes_to_external_bucket_with_review_risk(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::External,
            'target_external_url' => 'https://some-external-site.com',
            'anchor_text' => 'Liên kết ngoài cũ',
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal']);
        self::assertCount(1, $extracted['external']);

        $row = $extracted['external'][0];
        self::assertSame('https://some-external-site.com', $row['href']);
        self::assertSame('external', $row['link_type']);
        self::assertSame('review', $row['semantic_risk']);
        self::assertTrue($row['is_semantic_eligible']);
    }

    // 6. Social: not semantic Internal, not Warning (excluded from extracted links)
    public function test_social_destination_is_excluded_from_both_buckets(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::Social,
            'target_external_url' => 'https://facebook.com/mypage',
            'anchor_text' => 'Fanpage',
            'is_semantic_eligible' => false,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal'], 'Social must NOT become fake internal link');
        self::assertCount(0, $extracted['external'], 'Social must NOT receive Warning or external semantic badge');
    }

    // 7. Contact: not semantic Internal, not Warning (excluded from extracted links)
    public function test_contact_destination_is_excluded_from_both_buckets(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::Contact,
            'target_external_url' => 'tel:0901234567',
            'anchor_text' => 'Hotline tư vấn',
            'is_semantic_eligible' => false,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(0, $extracted['internal'], 'Contact must NOT become fake internal link');
        self::assertCount(0, $extracted['external'], 'Contact must NOT receive Warning or external semantic badge');
    }

    // 8. Unresolved ManagedCrossSite: remains ManagedCrossSite, no fake target Article/Keyword
    public function test_unresolved_managed_cross_site_remains_managed_with_no_fake_targets(): void
    {
        $map = new SeoLinkMap([
            'link_type' => SeoLinkMapType::ManagedCrossSite,
            'target_external_url' => 'https://site2.com/unresolved-landing',
            'anchor_text' => 'Landing chưa đồng bộ bài viết',
            'target_site_id' => 2,
            'target_article_id' => null,
            'keyword_id' => null,
        ]);

        $this->article->setRelation('linkMaps', collect([$map]));
        $extracted = $this->article->resolveExtractedLinks();

        self::assertCount(1, $extracted['external']);
        $row = $extracted['external'][0];

        // Must remain managed_cross_site — NOT downgraded to generic External
        self::assertSame('managed_cross_site', $row['link_type']);
        self::assertSame('safe', $row['semantic_risk']);
        self::assertSame(2, $row['target_site_id']);
        self::assertArrayNotHasKey('target_article_id', $row);
    }

    // 9. User-facing labels are NOT hardcoded in SeoArticle domain logic
    public function test_user_facing_labels_are_not_hardcoded_in_seo_article_domain_logic(): void
    {
        $map1 = new SeoLinkMap([
            'link_type' => SeoLinkMapType::ManagedCrossSite,
            'target_external_url' => 'https://site2.com/page',
            'anchor_text' => 'Cross-site',
        ]);
        $map2 = new SeoLinkMap([
            'link_type' => SeoLinkMapType::WikiTrust,
            'target_external_url' => 'https://en.wikipedia.org/wiki/SEO',
            'anchor_text' => 'Wiki',
        ]);
        $map3 = new SeoLinkMap([
            'link_type' => SeoLinkMapType::NeedsReview,
            'target_external_url' => 'https://example.com',
            'anchor_text' => 'Unknown',
        ]);

        $this->article->setRelation('linkMaps', collect([$map1, $map2, $map3]));
        $extracted = $this->article->resolveExtractedLinks();

        foreach ($extracted['external'] as $row) {
            // No semantic_label attribute with hardcoded English/Vietnamese strings
            self::assertArrayNotHasKey('semantic_label', $row, 'semantic_label must NOT be emitted by SeoArticle');
        }

        // Verify method source contains no hardcoded UI labels
        $ref = new ReflectionClass(SeoArticle::class);
        $m = $ref->getMethod('linkMapsToExtractedArray');
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));
        $body = implode("\n", array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        self::assertStringNotContainsString("'Trusted / Reference'", $body);
        self::assertStringNotContainsString("'Warning'", $body);
        self::assertStringNotContainsString("'Cảnh báo'", $body);
        self::assertStringNotContainsString("'Safe'", $body);
        self::assertStringNotContainsString("'Low'", $body);
    }

    // 10. ArticleLinksSidebar still does NOT automatically start suggestions on mount
    public function test_article_links_sidebar_still_does_not_auto_start_suggestions_on_mount(): void
    {
        $path = dirname(__DIR__, 2) . '/resources/js/components/ArticleLinksSidebar.jsx';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        // Auto-search removal verified: session-restore does not call loadLinkSuggestions()
        $marker = 'suggestionsAutoStartedRef.current';
        $pos = strpos($source, $marker);
        self::assertNotFalse($pos, 'suggestionsAutoStartedRef must exist');

        $block = substr($source, $pos, 2500);
        self::assertStringNotContainsString('void loadLinkSuggestions()', $block);
        self::assertStringNotContainsString('loadLinkSuggestions()', $block);

        // Explicit Generate Suggestions callback remains intact
        self::assertStringContainsString('onGenerateSuggestions={loadLinkSuggestions}', $source);

        // Sidebar renders semantic badges via presentation helper
        self::assertStringContainsString('renderSemanticBadges(item)', $source);
    }

    // 11. SeoLinkMapType SSOT returns correct risk levels
    public function test_seo_link_map_type_is_the_canonical_risk_ssot(): void
    {
        self::assertSame('safe', SeoLinkMapType::ManagedCrossSite->semanticRisk());
        self::assertSame('low', SeoLinkMapType::WikiTrust->semanticRisk());
        self::assertSame('review', SeoLinkMapType::NeedsReview->semanticRisk());
        self::assertSame('review', SeoLinkMapType::External->semanticRisk());
        self::assertNull(SeoLinkMapType::Internal->semanticRisk());
        self::assertNull(SeoLinkMapType::Social->semanticRisk());
        self::assertNull(SeoLinkMapType::Contact->semanticRisk());
    }
}
