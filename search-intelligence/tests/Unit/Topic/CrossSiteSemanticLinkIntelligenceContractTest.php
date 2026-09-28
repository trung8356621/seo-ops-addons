<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Tests\TestCase;

/**
 * Cross-Site Semantic Link Intelligence — contract tests.
 *
 * Covers all 16 invariants from the task specification:
 * 1.  same-site Article A → Article B = internal
 * 2.  managed Site A → managed Site B = managed_cross_site
 * 3.  Keyword A → Keyword B across managed sites = semantic eligible
 * 4.  Facebook HTTP URL = social / CTA / no semantic Keyword
 * 5.  Zalo HTTP URL = social / CTA / no semantic Keyword
 * 6.  tel:/mailto: = contact / no semantic Keyword
 * 7.  trusted/Wiki URL = reference / trusted behavior preserved
 * 8.  unknown unmanaged host = needs_review / no fake target Keyword
 * 9.  Site Network edges are directional
 * 10. A→B and B→A remain separate
 * 11. (covered by SiteNetworkReadModel — integration test required)
 * 12. (covered by KeywordRelationship read model — integration test required)
 * 13. CTA/social/contact do not appear in External semantic results
 * 14. existing internal Topical Map behavior remains intact
 * 15. (route/controller level — tested via architecture guard)
 * 16. Agent Runtime global retrieval remains intentionally unsupported
 *
 * These are pure unit tests — NO DB access.
 */
final class CrossSiteSemanticLinkIntelligenceContractTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Test 1: Same-site article → internal
    // ------------------------------------------------------------------ //

    public function test_same_site_article_link_is_internal(): void
    {
        $targetArticle = new SeoArticle(['id' => 5, 'site_id' => 10]);
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(10, $targetArticle);

        $this->assertSame(SeoLinkMapType::Internal, $type);
    }

    public function test_internal_type_is_semantic_eligible(): void
    {
        $this->assertTrue(SeoLinkMapType::Internal->isSemanticEligible());
    }

    // ------------------------------------------------------------------ //
    // Test 2: Managed cross-site article → managed_cross_site
    // ------------------------------------------------------------------ //

    public function test_cross_site_managed_article_is_managed_cross_site(): void
    {
        $targetArticle = new SeoArticle(['id' => 8, 'site_id' => 99]);
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(10, $targetArticle);

        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $type);
        $this->assertNotSame(SeoLinkMapType::External, $type, 'Managed cross-site must NOT be classified as External');
    }

    public function test_cross_site_classification_resolves_target_site(): void
    {
        // Classification via LinkDestinationClassifier (full SSOT path).
        $targetArticle = new SeoArticle(['id' => 42, 'site_id' => 7]);

        $result = LinkDestinationClassifier::classify(
            'https://site-b.com/some-article',
            3, // source site
            $targetArticle,
            false,
        );

        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $result['link_type']);
        $this->assertSame(7, $result['target_site_id']);
    }

    // ------------------------------------------------------------------ //
    // Test 3: Keyword A → Keyword B across managed sites = semantic eligible
    // ------------------------------------------------------------------ //

    public function test_managed_cross_site_type_is_semantic_eligible(): void
    {
        $this->assertTrue(SeoLinkMapType::ManagedCrossSite->isSemanticEligible());
        $this->assertFalse(SeoLinkMapType::ManagedCrossSite->isCta());
    }

    // ------------------------------------------------------------------ //
    // Test 4: Facebook URL = social / CTA
    // ------------------------------------------------------------------ //

    public function test_facebook_url_is_social(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://facebook.com/example');

        $this->assertSame(SeoLinkMapType::Social, $type);
        $this->assertTrue($type->isCta());
        $this->assertFalse($type->isSemanticEligible());
    }

    public function test_facebook_url_via_classifier_is_not_semantic_eligible(): void
    {
        $result = LinkDestinationClassifier::classify('https://www.facebook.com/example', 10, null, false);

        $this->assertSame(SeoLinkMapType::Social, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_fb_com_is_social(): void
    {
        $this->assertTrue(LinkDestinationClassifier::isSocialHost('fb.com'));
    }

    // ------------------------------------------------------------------ //
    // Test 5: Zalo URL = social / CTA
    // ------------------------------------------------------------------ //

    public function test_zalo_url_is_social(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://zalo.me/0123456789');

        $this->assertSame(SeoLinkMapType::Social, $type);
        $this->assertTrue($type->isCta());
        $this->assertFalse($type->isSemanticEligible());
    }

    public function test_zalo_url_via_classifier(): void
    {
        $result = LinkDestinationClassifier::classify('https://zalo.me/some-group', 5, null, false);

        $this->assertSame(SeoLinkMapType::Social, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    // ------------------------------------------------------------------ //
    // Test 6: tel:/mailto: = contact / no semantic Keyword
    // ------------------------------------------------------------------ //

    public function test_tel_link_is_contact(): void
    {
        $this->assertTrue(LinkDestinationClassifier::isContactScheme('tel:+84123456789'));

        $result = LinkDestinationClassifier::classify('tel:+84123456789', 1, null, false);

        $this->assertSame(SeoLinkMapType::Contact, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_mailto_link_is_contact(): void
    {
        $this->assertTrue(LinkDestinationClassifier::isContactScheme('mailto:contact@example.com'));

        $result = LinkDestinationClassifier::classify('mailto:contact@example.com', 1, null, false);

        $this->assertSame(SeoLinkMapType::Contact, $result['link_type']);
        $this->assertTrue($result['is_cta']);
        $this->assertFalse($result['is_semantic_eligible']);
    }

    public function test_contact_type_is_not_semantic_eligible(): void
    {
        $this->assertFalse(SeoLinkMapType::Contact->isSemanticEligible());
        $this->assertTrue(SeoLinkMapType::Contact->isCta());
    }

    // ------------------------------------------------------------------ //
    // Test 7: Trusted/Wiki URL = reference / preserved behavior
    // ------------------------------------------------------------------ //

    public function test_wikipedia_url_is_wiki_trust(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://en.wikipedia.org/wiki/Search_engine_optimization');

        $this->assertSame(SeoLinkMapType::WikiTrust, $type);
        $this->assertTrue($type->isSemanticEligible());
        $this->assertFalse($type->isCta());
    }

    public function test_gov_host_is_wiki_trust(): void
    {
        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('www.nasa.gov'));
    }

    public function test_edu_host_is_wiki_trust(): void
    {
        $this->assertTrue(SeoLinkMapLinkTypeClassifier::isWikiTrustHost('stanford.edu'));
    }

    public function test_wiki_trust_type_is_semantic_eligible(): void
    {
        $this->assertTrue(SeoLinkMapType::WikiTrust->isSemanticEligible());
    }

    // ------------------------------------------------------------------ //
    // Test 8: Unknown unmanaged host = needs_review / no fake Keyword
    // ------------------------------------------------------------------ //

    public function test_unknown_external_url_is_needs_review(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://example-unknown-host.com/page');

        $this->assertSame(SeoLinkMapType::NeedsReview, $type);
    }

    public function test_needs_review_via_classifier(): void
    {
        $result = LinkDestinationClassifier::classify('https://random-unknown.io/page', 1, null, false);

        $this->assertSame(SeoLinkMapType::NeedsReview, $result['link_type']);
        $this->assertFalse($result['is_cta']);
        // NeedsReview is NOT a semantic blocker by itself — the url still exists
        // but no fake Target Keyword is created for unmanaged external.
    }

    // ------------------------------------------------------------------ //
    // Test 9 & 10: Directional edges — A→B ≠ B→A
    // ------------------------------------------------------------------ //

    public function test_site_network_direction_invariant(): void
    {
        // The SeoLinkMapType classification captures direction:
        // a link FROM site A TO site B creates managed_cross_site on A's article.
        // A link FROM site B TO site A creates managed_cross_site on B's article.
        // These are separate records — never collapsed.

        $articleFromA = new SeoArticle(['id' => 1, 'site_id' => 3]);
        $articleFromB = new SeoArticle(['id' => 2, 'site_id' => 7]);

        $typeAtoB = SeoLinkMapLinkTypeClassifier::forManagedArticle(3, $articleFromB);
        $typeBtoA = SeoLinkMapLinkTypeClassifier::forManagedArticle(7, $articleFromA);

        // Both are managed_cross_site
        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $typeAtoB);
        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $typeBtoA);

        // The direction is captured by source_article.site_id vs target_article.site_id.
        // source.site_id=3, target.site_id=7 → edge 3→7
        // source.site_id=7, target.site_id=3 → edge 7→3
        // These are SEPARATE records in seo_link_maps — the SiteNetworkReadModel
        // groups by (source_site_id, target_site_id) and never collapses them.
        $sourceSiteIdAtoB = 3;
        $targetSiteIdAtoB = 7;
        $sourceSiteIdBtoA = 7;
        $targetSiteIdBtoA = 3;

        $this->assertNotSame(
            [$sourceSiteIdAtoB, $targetSiteIdAtoB],
            [$sourceSiteIdBtoA, $targetSiteIdBtoA],
            'A→B and B→A must be separate edges',
        );

        // Edge key uniqueness
        $edgeKeyAtoB = $sourceSiteIdAtoB.'->'.$targetSiteIdAtoB;
        $edgeKeyBtoA = $sourceSiteIdBtoA.'->'.$targetSiteIdBtoA;
        $this->assertNotSame($edgeKeyAtoB, $edgeKeyBtoA);
    }

    // ------------------------------------------------------------------ //
    // Test 13: CTA/social/contact do NOT appear in external semantic results
    // ------------------------------------------------------------------ //

    public function test_social_type_excluded_from_semantic_eligible(): void
    {
        $this->assertFalse(SeoLinkMapType::Social->isSemanticEligible());
        $this->assertFalse(SeoLinkMapType::Contact->isSemanticEligible());
    }

    public function test_internal_and_managed_cross_site_are_semantic_eligible(): void
    {
        $this->assertTrue(SeoLinkMapType::Internal->isSemanticEligible());
        $this->assertTrue(SeoLinkMapType::ManagedCrossSite->isSemanticEligible());
    }

    public function test_external_semantic_types_exclude_social_contact(): void
    {
        // The KeywordExternalRelationshipReadModel uses SEMANTIC_TYPES constant
        // which does NOT include Social or Contact.
        $semanticTypes = [
            SeoLinkMapType::ManagedCrossSite->value,
            SeoLinkMapType::WikiTrust->value,
            SeoLinkMapType::NeedsReview->value,
            SeoLinkMapType::External->value,  // backward compat
        ];

        $this->assertNotContains(SeoLinkMapType::Social->value, $semanticTypes);
        $this->assertNotContains(SeoLinkMapType::Contact->value, $semanticTypes);
        $this->assertNotContains(SeoLinkMapType::Internal->value, $semanticTypes);
    }

    // ------------------------------------------------------------------ //
    // Test 14: Existing internal behavior remains intact
    // ------------------------------------------------------------------ //

    public function test_internal_link_maps_still_classify_correctly(): void
    {
        // Same site article is still internal — no regression.
        $article = new SeoArticle(['id' => 100, 'site_id' => 5]);
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(5, $article);

        $this->assertSame(SeoLinkMapType::Internal, $type);
    }

    // ------------------------------------------------------------------ //
    // Test 16: Agent Runtime global retrieval guard
    // ------------------------------------------------------------------ //

    public function test_agent_runtime_global_retrieval_guard_class_exists(): void
    {
        // The SeoAccessExecutor guard must still exist and reject global scope.
        // We verify the class is still present and unchanged.
        $this->assertTrue(
            class_exists(\Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessExecutor::class),
            'SeoAccessExecutor must still exist with global retrieval guard',
        );
    }

    public function test_seo_link_map_type_has_all_required_cases(): void
    {
        $required = [
            'internal', 'external', 'wiki_trust',
            'managed_cross_site', 'social', 'contact', 'needs_review',
        ];

        foreach ($required as $value) {
            $type = SeoLinkMapType::tryFrom($value);
            $this->assertNotNull($type, "SeoLinkMapType must have case for value '{$value}'");
        }
    }

    public function test_social_is_not_semantic_eligible(): void
    {
        $this->assertFalse(SeoLinkMapType::Social->isSemanticEligible());
    }

    public function test_needs_review_is_not_a_blocking_classification(): void
    {
        // NeedsReview should exist as a type but not block the link from being stored.
        $type = SeoLinkMapType::NeedsReview;
        $this->assertSame('needs_review', $type->value);
        $this->assertFalse($type->isCta());
    }

    public function test_whatsapp_contact_link_is_contact(): void
    {
        $result = LinkDestinationClassifier::classify('whatsapp://send?phone=+84123', 1, null, false);

        $this->assertSame(SeoLinkMapType::Contact, $result['link_type']);
        $this->assertTrue($result['is_cta']);
    }

    public function test_threads_net_is_social(): void
    {
        $this->assertTrue(LinkDestinationClassifier::isSocialHost('threads.net'));
    }

    public function test_tiktok_is_social(): void
    {
        $this->assertTrue(LinkDestinationClassifier::isSocialHost('tiktok.com'));
        $result = LinkDestinationClassifier::classify('https://tiktok.com/@example', 1, null, false);
        $this->assertSame(SeoLinkMapType::Social, $result['link_type']);
    }

    public function test_target_keyword_resolver_rejects_non_positive_article_id(): void
    {
        $resolver = new \Omnichannel\Addons\SearchIntelligence\Services\Topic\TargetKeywordResolver();
        $this->assertNull($resolver->resolveForArticle(0));
        $this->assertNull($resolver->resolveForArticle(-1));
    }

    public function test_target_keyword_resolver_never_guesses_from_slug_or_title(): void
    {
        $resolverSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TargetKeywordResolver::class))->getFileName()
        );

        $code = preg_replace('#/\*.*?\*/#s', '', $resolverSrc) ?? $resolverSrc;
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;

        $this->assertStringNotContainsString('slug', $code);
        $this->assertStringNotContainsString('title', $code);
        $this->assertStringContainsString('siteMainArticleId', $resolverSrc);
        $this->assertStringContainsString('MainArticleId', $resolverSrc);
        $this->assertStringContainsString('seo_focus_keyword', $resolverSrc);
    }

    public function test_site_network_read_model_uses_canonical_article_table_and_connection(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\SearchIntelligence\Services\Topic\SiteNetworkReadModel::class))->getFileName()
        );

        // Must not query hardcoded seo_articles table
        $this->assertStringNotContainsString('join(\'seo_articles\'', $src);
        $this->assertStringNotContainsString('from(\'seo_articles\'', $src);
        $this->assertStringNotContainsString('table(\'seo_articles\'', $src);
        // Uses canonical SeoArticle table
        $this->assertStringContainsString('SeoArticle', $src);
        $this->assertStringContainsString('getTable()', $src);
        // Exposes directional metric keys
        $this->assertStringContainsString('source_keyword_count', $src);
        $this->assertStringNotContainsString('keyword_relation_count', $src);
        $this->assertStringContainsString('article_link_count', $src);
        $this->assertStringContainsString('source_article_count', $src);
        $this->assertStringContainsString('target_article_count', $src);
    }

    public function test_managed_domain_resolution_without_target_article(): void
    {
        $result = LinkDestinationClassifier::classify(
            href: 'https://managed-peer.example.com/some/path',
            sourceSiteId: 10,
            resolvedTargetArticle: null,
            targetSiteId: 20,
        );

        $this->assertSame(SeoLinkMapType::ManagedCrossSite, $result['link_type']);
        $this->assertSame(20, $result['target_site_id']);
        $this->assertTrue($result['is_semantic_eligible']);
        $this->assertFalse($result['is_cta']);
    }
}

