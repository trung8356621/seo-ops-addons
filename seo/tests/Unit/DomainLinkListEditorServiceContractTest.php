<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\DomainLinkListEditorService;
use Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver;
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
        self::assertStringNotContainsString("promptContext->getForSite", $source);
        self::assertStringContainsString('textContainsPhrase', $source);
        self::assertStringContainsString('KeywordPhraseMatcher', $source);
    }

    public function test_resolver_sources_product_cat_only_from_product_category_articles(): void
    {
        $ref = new ReflectionClass(EffectiveDomainLinkResolver::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString("whereIn('type', ['product_category', 'product_cat'])", $source);
        self::assertStringNotContainsString("where('type', 'category')", $source);
        self::assertStringNotContainsString('post_tag', $source);
        self::assertStringNotContainsString('product_tag', $source);
        self::assertStringContainsString('company_short_identity', $source);
        self::assertStringContainsString('looksLikeHostnameOrUrl', $source);
    }
}
