<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

final class ArticleEditorHeadingStyleDropdownOverflowTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function read(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_paragraph_style_dropdown_portals_menu_outside_toolbar_overflow(): void
    {
        $dropdown = $this->read('resources/js/components/ParagraphStyleDropdown.jsx');
        self::assertStringContainsString('createPortal', $dropdown);
        self::assertStringContainsString('seo-fmt-dropdown-menu--portal', $dropdown);
        self::assertStringContainsString("position: 'fixed'", $dropdown);
        self::assertStringContainsString('document.body', $dropdown);
    }

    public function test_format_toolbar_row_does_not_clip_dropdown_with_overflow_x_auto(): void
    {
        $css = $this->read('resources/css/article-editor.css');
        self::assertStringContainsString('.seo-toolbar-row--format', $css);
        self::assertStringContainsString('seo-fmt-dropdown-menu--portal', $css);
        // Default format row must stay overflow:visible (not overflow-x:auto).
        self::assertMatchesRegularExpression(
            '/\.seo-toolbar-row--format\s*\{[^}]*overflow:\s*visible/s',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.seo-toolbar-row--format\s*\{[^}]*overflow-x:\s*auto/s',
            $css,
        );
    }
}
