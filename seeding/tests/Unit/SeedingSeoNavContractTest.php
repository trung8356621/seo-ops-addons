<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingSeoNavContractTest extends TestCase
{
    public function test_seo_module_nav_shortcut_points_at_canonical_seeding_url(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoPerformanceHub::class))->getFileName()
        );
        self::assertStringContainsString("url('/seeding')", $source);
        self::assertStringContainsString('class_exists($seedingPage)', $source);
        self::assertStringNotContainsString('SeedingTopicsPage::getUrl()', $source);
        self::assertStringNotContainsString('isSeedingTopicsNav()', $source);
    }

    public function test_seo_panel_does_not_peer_discover_seeding_pages(): void
    {
        $path = dirname(__DIR__, 3).'/seo-content-ai-compat/Providers/SeoPanelProvider.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString("'seeding' => 'Seeding'", $source);
        self::assertStringContainsString('Seeding owns its own Filament panel', $source);
    }

    public function test_seo_module_route_helpers_treat_seeding_as_external_shortcut(): void
    {
        self::assertTrue(SeoPanelRoutes::isSeedingTopicsNav('filament.seeding.pages.workspace'));
        self::assertTrue(SeoPanelRoutes::isSeedingTopicsNav('filament.seo.pages.seeding-topics'));
        self::assertFalse(SeoPanelRoutes::isSeoModule('filament.seo.pages.seeding-topics'));
        self::assertFalse(SeoPanelRoutes::isSeoModule('filament.seeding.pages.workspace'));
        self::assertFalse(SeoPanelRoutes::isSocialNav('filament.seo.pages.seeding-topics'));
    }
}
