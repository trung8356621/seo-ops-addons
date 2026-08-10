<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;
use Tests\TestCase;

final class SeoLinkMapLinkClassificationTest extends TestCase
{
    public function test_same_site_managed_article_is_internal(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(
            10,
            new SeoArticle(['id' => 5, 'site_id' => 10]),
        );

        $this->assertSame(SeoLinkMapType::Internal, $type);
    }

    public function test_cross_site_managed_article_is_external(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forManagedArticle(
            10,
            new SeoArticle(['id' => 8, 'site_id' => 99]),
        );

        $this->assertSame(SeoLinkMapType::External, $type);
    }

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

    public function test_unknown_external_url_is_external(): void
    {
        $type = SeoLinkMapLinkTypeClassifier::forUnresolvedUrl('https://example.com/page');

        $this->assertSame(SeoLinkMapType::External, $type);
    }
}
