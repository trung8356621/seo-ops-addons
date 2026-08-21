<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\DomainContext;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DomainContextSiteIdUrlTest extends TestCase
{
    public function test_resolver_reads_site_id_query_param(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(DomainContextResolver::class))->getFileName());

        self::assertStringContainsString("DomainContext::SITE_ID_QUERY_KEY", $source);
        self::assertStringContainsString('$request->query(DomainContext::SITE_ID_QUERY_KEY)', $source);
    }

    public function test_append_site_to_url_uses_site_id_and_drops_domain_param(): void
    {
        $url = (new DomainContextResolver())->appendSiteToUrl(
            '/seo/panel/keywords/clusters/demo?domain=long-domain.example',
            5,
        );

        self::assertStringContainsString('site_id=5', $url);
        self::assertStringNotContainsString('domain=', $url);
    }

    public function test_site_id_query_constant_is_declared(): void
    {
        self::assertSame('site_id', DomainContext::SITE_ID_QUERY_KEY);
    }
}
