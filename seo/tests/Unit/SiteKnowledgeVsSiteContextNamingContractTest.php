<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SiteMcp\SiteMcpContextAssembler;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextAssembler;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Site Knowledge Profile ≠ Site Intelligence Context.
 */
final class SiteKnowledgeVsSiteContextNamingContractTest extends TestCase
{
    public function test_knowledge_profile_assembler_documents_distinction(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteMcpContextAssembler::class))->getFileName(),
        );
        self::assertStringContainsString('Site Knowledge', $src);
        self::assertStringContainsString('SiteContextGateway', $src);
        self::assertStringContainsString('NOT Site Intelligence', $src);
    }

    public function test_site_intelligence_gateway_documents_distinction(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteContextGateway::class))->getFileName(),
        );
        self::assertStringContainsString('Site Knowledge Profile', $src);
        self::assertStringContainsString('search-foundation SiteMcp', $src);
        self::assertStringContainsString('site.mcp.v1', $src);
        // Gateway must not assemble prompt tone/CTA knowledge profile.
        self::assertStringNotContainsString('cta_instructions', $src);
        self::assertStringNotContainsString('SiteMcpDraft', $src);
    }

    public function test_intelligence_assembler_does_not_import_knowledge_profile(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SiteContextAssembler::class))->getFileName(),
        );
        self::assertStringNotContainsString('SearchFoundation\\Services\\SiteMcp', $src);
        self::assertStringNotContainsString('seo_domain_prompt_context', $src);
        self::assertStringNotContainsString('site_mcp_draft', $src);
    }
}
