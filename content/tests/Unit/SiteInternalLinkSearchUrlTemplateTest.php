<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\SiteInternalLinkSearchUrlTemplate;
use PHPUnit\Framework\TestCase;

final class SiteInternalLinkSearchUrlTemplateTest extends TestCase
{
    public function test_keyword_placeholder_is_url_encoded_when_expanded(): void
    {
        $template = (new SiteInternalLinkSearchUrlTemplate)->normalize(
            'https://example.test/?s={keyword}&post_type=product',
        );
        self::assertNotNull($template);

        $expanded = str_replace(
            SiteInternalLinkSearchUrlTemplate::PLACEHOLDER,
            rawurlencode('balo đi học'),
            $template,
        );

        self::assertStringContainsString(rawurlencode('balo đi học'), $expanded);
        self::assertStringNotContainsString('{keyword}', $expanded);
    }

    public function test_normalize_rejects_template_without_placeholder_or_invalid_url(): void
    {
        $service = new SiteInternalLinkSearchUrlTemplate;

        self::assertNull($service->normalize(''));
        self::assertNull($service->normalize('https://example.test/?s=kw'));
        self::assertNull($service->normalize('/?s={keyword}'));
        self::assertNotNull($service->normalize('https://example.test/?s={keyword}'));
    }

    public function test_meta_key_is_stable(): void
    {
        self::assertSame('internal_link_search_url_template', SiteInternalLinkSearchUrlTemplate::META_KEY);
    }
}