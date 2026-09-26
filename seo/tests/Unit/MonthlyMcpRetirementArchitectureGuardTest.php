<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\Seo\SeoServiceProvider;
use Omnichannel\Addons\Seo\Services\DomainSeoMcpService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards against reintroducing retired Monthly MCP subsystem in canonical consumers.
 */
final class MonthlyMcpRetirementArchitectureGuardTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'SeoMcpPeriod',
        'SeoMcpReport',
        'SeoMcpSourceSnapshot',
        'MonthlyMcpSnapshotService',
        'MonthlyMcpReportService',
        'McpAiContextBuilder',
        'McpMarkdownRenderer',
        'McpPeriodService',
        'DomainMonthlyIntelligenceService',
        'domain.monthly_intelligence',
        'Services\\MonthlyMcp',
        'McpSourceKey',
    ];

    public function test_monthly_mcp_namespace_and_mcp_intelligence_page_are_absent(): void
    {
        $monthlyDir = dirname(__DIR__, 2).'/src/Services/MonthlyMcp';
        self::assertDirectoryDoesNotExist($monthlyDir);
        self::assertFalse(class_exists('Omnichannel\\Addons\\Seo\\Filament\\Pages\\McpIntelligence'));
    }

    public function test_canonical_consumers_do_not_reference_retired_monthly_mcp(): void
    {
        $classes = [
            SeoPerformanceHub::class,
            TopicalMapAuditService::class,
            TopicalMapAuditContextBuilder::class,
            ContentPlanningIntelligenceService::class,
            DomainSeoMcpService::class,
            ContentProjectAgentGateway::class,
            ContentProjectMcpToolCatalog::class,
            SeoServiceProvider::class,
        ];

        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), $class);
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            foreach (self::FORBIDDEN_MARKERS as $marker) {
                self::assertStringNotContainsString($marker, $src, $class.' must not reference '.$marker);
            }
        }
    }

    public function test_performance_hub_uses_gsc_context_gateway(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(SeoPerformanceHub::class))->getFileName());
        self::assertStringContainsString('GscContextGateway', $src);
        self::assertStringNotContainsString('MonthlyMcpSnapshotService', $src);
        self::assertStringNotContainsString('rebuildSnapshot', $src);
    }
}
