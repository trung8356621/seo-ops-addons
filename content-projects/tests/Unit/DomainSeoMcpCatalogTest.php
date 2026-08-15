<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DomainSeoMcpCatalogTest extends TestCase
{
    public function test_catalog_and_gateway_include_domain_tools(): void
    {
        $catalog = (string) file_get_contents(
            (new ReflectionClass(ContentProjectMcpToolCatalog::class))->getFileName(),
        );
        $gateway = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );
        foreach ([
            'domain.seo_brief',
            'domain.keyword_overview',
            'domain.keyword_landscape',
            'domain.keyword_gaps',
            'domain.keyword_cluster_detail',
            'domain.keyword_generation_context',
            'domain.internal_link_opportunities',
            'domain.orphan_pages',
            'domain.broken_links',
            'domain.indexability',
            'domain.action_plan',
            'domain.monthly_intelligence',
            'domain.run_analysis',
        ] as $tool) {
            self::assertStringContainsString($tool, $catalog);
            self::assertStringContainsString($tool, $gateway);
        }
        self::assertStringContainsString('Analysis queued', $gateway);
    }
}
