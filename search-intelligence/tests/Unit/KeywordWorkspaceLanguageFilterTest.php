<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordWorkspaceLanguageFilter;
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

    public function test_language_switch_normalizes_state_and_invalidates_request_cache(): void
    {
        $component = new class
        {
            use InteractsWithKeywordWorkspaceLanguageFilter;

            public int $cacheClears = 0;

            public int $pageResets = 0;

            /** @return array<string, string> */
            public function getKeywordLanguageFilterOptions(): array
            {
                return ['vi' => 'Tiếng Việt', 'en' => 'English'];
            }

            public function resolveKeywordWorkspacePrimaryLanguage(): ?string
            {
                return 'vi';
            }

            protected function clearKeywordWorkspaceTabCountsCache(): void
            {
                $this->cacheClears++;
            }

            public function resetPage(): void
            {
                $this->pageResets++;
            }
        };

        $component->keywordLanguageFilter = 'en_US';
        $component->updatedKeywordLanguageFilter();

        self::assertSame('en', $component->keywordLanguageFilter);
        self::assertSame(['en', 'EN', 'en_us', 'en-us', 'en_gb', 'en-gb', 'en_US', 'en-US', 'EN_US', 'en_GB', 'en-GB', 'EN_GB'], $component->resolveKeywordLanguageFilterVariants());
        self::assertSame(1, $component->cacheClears);
        self::assertSame(1, $component->pageResets);
    }
}
