<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SiteLink\SiteLinkPolicyResolver;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\VerifiedProductCatLinkSource;
use Omnichannel\Addons\Seo\Services\DomainLinkListEditorService;
use Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver;
use Omnichannel\Addons\Seo\Support\EditorAnchorOccurrenceMatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DomainLinkListEditorServiceContractTest extends TestCase
{
    public function test_for_site_uses_effective_resolver_not_prompt_links_only(): void
    {
        $ref = new ReflectionClass(DomainLinkListEditorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('EffectiveDomainLinkResolver', $source);
        self::assertStringContainsString('$this->effectiveLinks->forSite($site)', $source);
        self::assertStringNotContainsString('promptContext->getForSite', $source);
        self::assertStringContainsString('EditorAnchorOccurrenceMatcher', $source);
        self::assertStringContainsString('filterActionable', $source);
    }

    public function test_effective_resolver_delegates_composition_to_site_link_policy(): void
    {
        $resolverSrc = (string) file_get_contents(
            (string) (new ReflectionClass(EffectiveDomainLinkResolver::class))->getFileName(),
        );
        $policySrc = (string) file_get_contents(
            (string) (new ReflectionClass(SiteLinkPolicyResolver::class))->getFileName(),
        );
        $loaderSrc = (string) file_get_contents(
            (string) (new ReflectionClass(VerifiedProductCatLinkSource::class))->getFileName(),
        );

        self::assertStringContainsString('SiteLinkPolicyResolver', $resolverSrc);
        self::assertStringContainsString('forArticleEditor', $resolverSrc);
        self::assertStringContainsString('normalizeVerified', $loaderSrc);
        self::assertStringContainsString('SiteMcpProductCatIdentity', $loaderSrc);
        self::assertStringContainsString('company_short_identity', $policySrc);
        self::assertStringContainsString('looksLikeHostnameOrUrl', $policySrc);
    }

    public function test_editor_anchor_matcher_uses_keyword_phrase_normalization(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(EditorAnchorOccurrenceMatcher::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordPhraseMatcher', $src);
        self::assertStringContainsString('plainTextExcludingAnchors', $src);
        self::assertStringContainsString('longest', strtolower($src));
    }
}
