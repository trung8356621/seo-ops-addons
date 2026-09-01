<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FocusKeywordCoverageDomainCardContractTest extends TestCase
{
    public function test_site_health_card_wires_focus_keywords_section(): void
    {
        $presenter = dirname(__DIR__, 2)
            .'/src/Services/ContentProject/Operations/SiteHealthCardPresenter.php';
        $blade = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-health-card.blade.php';
        $domainOverview = dirname(__DIR__, 3)
            .'/seo/src/Services/DomainOverviewService.php';

        $presenterSrc = (string) file_get_contents($presenter);
        $bladeSrc = (string) file_get_contents($blade);
        $overviewSrc = (string) file_get_contents($domainOverview);

        self::assertStringContainsString('focusKeywordCoverageSection', $presenterSrc);
        self::assertStringContainsString("'focus_keywords'", $presenterSrc);
        self::assertStringContainsString('FocusKeywordCoverageService', $presenterSrc);
        self::assertStringContainsString("'focus_keywords'", $bladeSrc);
        self::assertStringContainsString('Focus Keyword Coverage (detail)', $bladeSrc);
        self::assertStringContainsString('buildArticlesFilterUrlForMissingFocusKeyword', $overviewSrc);
        self::assertStringContainsString("focus_keyword_status", $overviewSrc);
    }
}
