<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SocialSeoNavContractTest extends TestCase
{
    public function test_seo_module_nav_no_longer_includes_social_profiles_page(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoPerformanceHub::class))->getFileName()
        );
        self::assertStringNotContainsString('SocialProfilesPage', $source);
        self::assertStringNotContainsString('isSocialNav', $source);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3).'/social/src/Filament/Pages/SocialProfilesPage.php'
        );
    }

    public function test_seo_module_route_helpers_exclude_retired_social_page(): void
    {
        self::assertFalse(SeoPanelRoutes::isSocialNav('filament.seo.pages.social'));
        self::assertFalse(SeoPanelRoutes::isSeoModule('filament.seo.pages.social'));
        self::assertTrue(SeoPanelRoutes::isSeoModule('filament.seo.pages.performance-hub'));
        self::assertFalse(method_exists(SeoPanelRoutes::class, 'isMcpIntelligenceNav'));
    }

    public function test_gsc_social_stays_out_of_seo_audit_and_planner(): void
    {
        $builder = (string) file_get_contents(
            dirname(__DIR__, 3).'/search-intelligence/src/Services/GscIntelligence/GscSocialTop10Builder.php'
        );
        self::assertStringNotContainsString('SeoAudit', $builder);
        self::assertStringNotContainsString('IdeaCandidate', $builder);
        self::assertStringNotContainsString('VocabularySuggest', $builder);
        self::assertStringNotContainsString('DraftProject', $builder);
        self::assertStringNotContainsString('SocialProfile', $builder);
    }
}
