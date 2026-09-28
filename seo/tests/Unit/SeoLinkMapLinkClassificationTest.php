<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Tests\TestCase;

final class SeoLinkMapLinkClassificationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // SeoLinkMapLinkTypeClassifier::forManagedArticle
    // -------------------------------------------------------------------------

    public function test_same_site_managed_article_is_internal(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(
            10,
            new SeoArticle(['id' => 5, 'site_id' => 10]),
        );

        $this->assertSame(SeoLinkMapType::Internal, $type);
    }

    /**
     * Previously this returned External; after Phase 1 it returns ManagedCrossSite.
     */
    public function test_cross_site_managed_article_is_managed_cross_site(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(
            10,
            new SeoArticle(['id' => 8, 'site_id' => 99]),
        );

        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $type);
    }

    // -------------------------------------------------------------------------
    // SeoLinkMapLinkTypeClassifier::forUnresolvedUrl
    // -------------------------------------------------------------------------

    public function test_wikipedia_url_is_wiki_trust(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://en.wikipedia.org/wiki/Backpack');

        $this->assertSame(SeoLinkMapType::WikiTrust, $type);
    }

    public function test_gov_and_edu_hosts_are_wiki_trust(): void
    {
        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('www.nasa.gov'));
        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('stanford.edu'));
    }

    /**
     * Unknown external URLs now return NeedsReview instead of External.
     */
    public function test_unknown_external_url_is_needs_review(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://example.com/page');

        $this->assertSame(SeoLinkMapType::NeedsReview, $type);
    }

    // -------------------------------------------------------------------------
    // Social / CTA classification
    // -------------------------------------------------------------------------

    public function test_facebook_url_is_social(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'https://www.facebook.com/somepage',
            sourceSiteId: 1,
        );

        $this->assertSame(SeoLinkMapType::Social, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_zalo_url_is_social(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'https://zalo.me/123456',
            sourceSiteId: 1,
        );

        $this->assertSame(SeoLinkMapType::Social, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_tel_link_is_contact(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'tel:+84901234567',
            sourceSiteId: 1,
        );

        $this->assertSame(SeoLinkMapType::Contact, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_mailto_link_is_contact(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'mailto:hello@example.com',
            sourceSiteId: 1,
        );

        $this->assertSame(SeoLinkMapType::Contact, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    // -------------------------------------------------------------------------
    // isSemanticEligible
    // -------------------------------------------------------------------------

    public function test_managed_cross_site_is_semantic_eligible(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'https://other-site.example.com/article',
            sourceSiteId: 1,
            resolvedTargetArticle: new SeoArticle(['id' => 42, 'site_id' => 2]),
        );

        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $result['link_type']);
        $this->assertTrue($result['is_semantic_eligible']);
        $this->assertFalse($result['is_cta']);
    }

    public function test_social_is_not_semantic_eligible(): void
    {
        $this->assertFalse(SeoLinkMapType::Social->isSemanticEligible());
    }

    public function test_contact_is_not_semantic_eligible(): void
    {
        $this->assertFalse(SeoLinkMapType::Contact->isSemanticEligible());
    }
}
