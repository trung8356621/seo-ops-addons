<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeedingSeoNavContractTest extends TestCase
{
    public function test_seo_module_nav_includes_seeding_topic_v2_child(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoPerformanceHub::class))->getFileName()
        );
        self::assertStringContainsString('SeedingTopicsPage', $source);
        self::assertStringContainsString('isSeedingTopicsNav', $source);
    }

    public function test_seo_module_route_helpers_include_seeding_topics(): void
    {
        self::assertTrue(SeoPanelRoutes::isSeedingTopicsNav('filament.seo.pages.seeding-topics'));
        self::assertTrue(SeoPanelRoutes::isSeedingTopicsNav('filament.seo.pages.seeding-topic-manage'));
        self::assertTrue(SeoPanelRoutes::isSeoModule('filament.seo.pages.seeding-topics'));
        self::assertFalse(SeoPanelRoutes::isSocialNav('filament.seo.pages.seeding-topics'));
    }
}
