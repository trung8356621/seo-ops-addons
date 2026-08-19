<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class McpIntelligenceUiStateTest extends TestCase
{
    public function test_domain_switch_listener_does_not_sync_global_site_id(): void
    {
        $method = new ReflectionMethod(McpIntelligence::class, 'onDomainContextChanged');
        $source = (string) file_get_contents((string) $method->getFileName());

        self::assertStringContainsString('onDomainContextChanged', $source);
        self::assertStringNotContainsString('syncSiteFromGlobalContext', $source);
        self::assertStringContainsString('$this->linkedArticlesPage = 1', $source);
    }

    public function test_view_state_overlays_live_content_distribution(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionMethod(McpIntelligence::class, 'viewState'))->getFileName());

        self::assertStringContainsString('SiteMcpContentDistributionAggregator', $source);
        self::assertStringContainsString("['content_distribution'] = \$liveDistribution", $source);
    }

    public function test_period_switch_resets_linked_articles_page(): void
    {
        $method = new ReflectionMethod(McpIntelligence::class, 'updatedPeriodKey');
        $source = (string) file_get_contents((string) $method->getFileName());

        self::assertStringContainsString('updatedPeriodKey', $source);
        self::assertStringContainsString('$this->linkedArticlesPage = 1', $source);
    }

    public function test_linked_articles_range_meta_uses_full_total(): void
    {
        $page1 = McpIntelligence::linkedArticlesRangeMeta(57, 1, 10);
        self::assertSame(1, $page1['start']);
        self::assertSame(10, $page1['end']);

        $page2 = McpIntelligence::linkedArticlesRangeMeta(57, 2, 10);
        self::assertSame(11, $page2['start']);
        self::assertSame(20, $page2['end']);

        $page6 = McpIntelligence::linkedArticlesRangeMeta(57, 6, 10);
        self::assertSame(51, $page6['start']);
        self::assertSame(57, $page6['end']);
    }

    public function test_linked_articles_page_window_bounds(): void
    {
        $window = McpIntelligence::linkedArticlesPageWindow(3, 10, 2);
        self::assertSame(1, $window['first']);
        self::assertSame(5, $window['last']);

        $window2 = McpIntelligence::linkedArticlesPageWindow(9, 10, 2);
        self::assertSame(7, $window2['first']);
        self::assertSame(10, $window2['last']);
    }

    public function test_top_clusters_preserves_input_order(): void
    {
        $clusters = [
            ['name' => 'A'],
            ['name' => 'B'],
            ['name' => 'C'],
            ['name' => 'D'],
            ['name' => 'E'],
            ['name' => 'F'],
        ];

        $top = McpIntelligence::topClusters($clusters, 3);
        self::assertSame(['A', 'B', 'C'], array_column($top, 'name'));
    }
}

