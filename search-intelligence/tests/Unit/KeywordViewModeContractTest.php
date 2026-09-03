<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class KeywordViewModeContractTest extends TestCase
{
    public function test_dictionary_page_has_quick_and_detail_modes(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/list-keywords.blade.php',
        ));
        $script = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-view-mode-script.blade.php',
        ));
        $css = (string) file_get_contents(ProjectRoot::addonsPath().'/seo/resources/css/keyword-workspace.css');
        $js = (string) file_get_contents(ProjectRoot::addonsPath().'/seo/resources/js/keywordDetailPanel.js');

        self::assertStringContainsString('keywordDictionaryViewMode', $blade);
        self::assertStringContainsString('view_mode_quick', $blade);
        self::assertStringContainsString('view_mode_detail', $blade);
        self::assertStringContainsString('seo_ops_keyword_view_mode', $blade);
        self::assertStringContainsString('data-keyword-quick-select-root', $blade);
        self::assertStringContainsString('onResultsMouseUp', $blade);
        self::assertStringContainsString('is-quick-mode', $blade);

        self::assertStringContainsString("if (window.keywordDictionaryViewMode)", $script);
        self::assertStringContainsString("component.set('tableSearch', text)", $script);
        self::assertStringContainsString('MAX_SELECTION_LENGTH = 200', $script);
        self::assertStringContainsString("STORAGE_KEY = 'seo_ops_keyword_view_mode'", $script);
        self::assertStringContainsString('localStorage.setItem(STORAGE_KEY, mode)', $script);

        self::assertStringContainsString('.keyword-detail-layout.is-quick-mode', $css);
        self::assertStringContainsString('.keyword-view-mode-toggle', $css);
        self::assertStringContainsString('display: none !important', $css);

        self::assertStringContainsString('function isQuickViewMode', $js);
        self::assertStringContainsString('if (isQuickViewMode(root))', $js);
    }
}
