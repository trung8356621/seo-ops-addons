<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * HTML inspector pretty-print + TipTap preserveWhitespace:full must not invent
 * empty &lt;p&gt; inside table cells on Apply HTML.
 */
final class ArticleHtmlInspectorApplyTableContractTest extends TestCase
{
    public function test_prepare_html_for_tiptap_apply_exists_and_strips_cell_whitespace(): void
    {
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/inlineLinkNormalizer.js',
        );

        self::assertStringContainsString('export function prepareHtmlForTipTapApply', $js);
        self::assertStringContainsString('STRUCTURAL_WHITESPACE_PARENTS', $js);
        self::assertStringContainsString("querySelectorAll('td, th')", $js);
        self::assertStringContainsString('isEmptyParagraphElement', $js);
    }

    public function test_active_block_editor_prepares_html_before_set_content(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ActiveBlockEditor.jsx',
        );

        self::assertStringContainsString('prepareHtmlForTipTapApply', $jsx);
        self::assertMatchesRegularExpression(
            '/prepareHtmlForTipTapApply[\s\S]{0,200}setContent/',
            $jsx,
        );
    }

    public function test_html_inspector_stat_i18n_uses_brace_count_placeholder(): void
    {
        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );

        self::assertStringContainsString("html_inspector_stat_anchors: 'Anchors: {count}'", $i18n);
        self::assertStringNotContainsString("html_inspector_stat_anchors: 'Anchors: :count'", $i18n);
    }
}
