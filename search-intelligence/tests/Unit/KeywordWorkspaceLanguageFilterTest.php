<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class KeywordWorkspaceLanguageFilterTest extends TestCase
{
    public function test_navigation_trait_exposes_language_filter_state(): void
    {
        $trait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordWorkspaceLanguageFilter.php');

        $this->assertStringContainsString('keywordLanguageFilter', $trait);
        $this->assertStringContainsString('resolveKeywordLanguageFilterVariants', $trait);
        $this->assertStringContainsString('SitePrimaryLanguageService', $trait);
        $this->assertStringContainsString('KeywordWorkspaceLanguageScope', $trait);
    }

    public function test_workspace_nav_renders_language_select_with_local_storage(): void
    {
        $nav = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-workspace-nav.blade.php',
        ));

        $this->assertStringContainsString('keyword-workspace-tabs-bar__filter', $nav);
        $this->assertStringContainsString('wire:model.live="keywordLanguageFilter"', $nav);
        $this->assertStringContainsString('keywordWorkspace.language.', $nav);
        $this->assertStringContainsString('<x-select', $nav);
    }

    public function test_language_scope_supports_legacy_variants(): void
    {
        $scope = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Support/KeywordWorkspace/KeywordWorkspaceLanguageScope.php');

        $this->assertStringContainsString('ContentLanguageLegacyRepair', $scope);
        $this->assertStringContainsString('applyToKeywordQuery', $scope);
        $this->assertStringContainsString('applyToSeoLinkMapQuery', $scope);
    }
}
