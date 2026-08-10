<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class SerpIntelligenceUiTest extends TestCase
{
    public function test_keyword_workspace_blade_has_serp_intelligence_tab_and_subtabs(): void
    {
        $bladePath = LegacyAddonPath::resolve('resources/views/filament/pages/keyword-intelligence/view-keyword-workspace.blade.php');
        $partialPath = LegacyAddonPath::resolve('resources/views/filament/pages/keyword-intelligence/partials/serp-intelligence-tab.blade.php');

        self::assertFileExists($bladePath);

        $blade = (string) file_get_contents($bladePath);
        self::assertStringContainsString('serp_intelligence', $blade);
        self::assertStringContainsString('tab_serp_intelligence', $blade);

        $enLang = LegacyAddonPath::resolve('lang/en/filament.php');
        self::assertFileExists($enLang);
        $enSource = (string) file_get_contents($enLang);
        self::assertStringContainsString("'tab_serp_intelligence' => 'SERP Intelligence'", $enSource);

        if (is_file($partialPath)) {
            $partial = (string) file_get_contents($partialPath);
            foreach (['Overview', 'Queries', 'Snapshots', 'Cluster Evidence', 'Content Gaps', 'Competitors', 'Operations'] as $label) {
                self::assertStringContainsString($label, $partial, "Missing sub-tab label: {$label}");
            }
        }
    }

    public function test_view_keyword_workspace_allows_serp_tab(): void
    {
        $phpPath = ProjectRoot::addonsPath().'/search-intelligence/src/Filament/Pages/KeywordIntelligence/ViewKeywordWorkspace.php';
        self::assertFileExists($phpPath);

        $source = (string) file_get_contents($phpPath);
        self::assertStringContainsString("'serp_intelligence'", $source);
        self::assertStringContainsString('previewSerpImport', $source);
    }
}
