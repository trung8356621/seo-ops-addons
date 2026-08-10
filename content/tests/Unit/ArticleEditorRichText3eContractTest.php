<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Seo\Support\AssistantWidgetHealthRules;
use Omnichannel\Addons\Seo\Support\SeoReasonPresentation;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Source contracts for CTA / media health / link unlink / quote / image counts (3E).
 */
final class ArticleEditorRichText3eContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_cta_insert_uses_article_cta_paragraph_semantics(): void
    {
        $selection = $this->readAddon('resources/js/utils/editorSelectionUtils.js');
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');

        self::assertStringContainsString('insertContactCtaAtBookmark', $selection);
        self::assertStringContainsString("class: 'article-cta'", $selection);
        self::assertStringContainsString('article-cta__value', $selection);
        self::assertStringContainsString('is_cta_sentence', $cta);
    }

    public function test_link_mark_is_not_inclusive_and_unlink_keeps_text(): void
    {
        $extensions = $this->readAddon('resources/js/utils/editorExtensions.js');
        $commands = $this->readAddon('resources/js/utils/editorLinkCommands.js');
        $toolbar = $this->readAddon('resources/js/components/BlockFormatToolbar.jsx');

        self::assertStringContainsString('inclusive: false', $extensions);
        self::assertStringContainsString('removeLinkKeepText', $commands);
        self::assertStringContainsString('removeMark', $commands);
        self::assertStringNotContainsString('deleteSelection', $commands);
        self::assertStringNotContainsString('deleteRange', $commands);
        self::assertStringContainsString('removeLinkKeepText', $toolbar);
        self::assertStringNotContainsString('.unsetLink().run()', $toolbar);
    }

    public function test_quote_css_disables_decorative_quote_characters(): void
    {
        $css = $this->readAddon('resources/css/article-editor.css');

        self::assertStringContainsString('quotes: none', $css);
        self::assertStringContainsString('blockquote p::before', $css);
        self::assertStringContainsString('content: none !important', $css);
    }

    public function test_images_health_issue_badge_does_not_use_missing_as_denominator(): void
    {
        $health = $this->readAddon('resources/js/utils/assistantWidgetHealth.js');

        self::assertStringContainsString('Never "6/11"', $health);
        self::assertStringContainsString('recommended_count', $health);
        self::assertStringContainsString('fixableIssues + (missingRecommended > 0 ? 1 : 0)', $health);
    }

    public function test_featured_health_prefers_snapshot_over_stale_missing(): void
    {
        $health = $this->readAddon('resources/js/utils/assistantWidgetHealth.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('Presence wins', $health);
        self::assertStringContainsString('featuredHealthSnapshot', $editor);
        self::assertStringContainsString('article-editor-media-snapshot-changed', $editor);
        self::assertStringContainsString('setMediaHealthTick', $editor);
    }

    public function test_image_ratio_recommendation_stable_for_fixed_word_count(): void
    {
        $words = implode(' ', array_fill(0, 1150, 'word'));
        $html5 = '<p>'.$words.'</p>'
            .str_repeat('<img src="https://cdn.example/a.jpg" alt="a" />', 5);
        $html6 = $html5.'<img src="https://cdn.example/b.jpg" alt="b" />';

        $a = SeoReasonPresentation::imageRatioMetrics($html5);
        $b = SeoReasonPresentation::imageRatioMetrics($html6);

        self::assertSame(5, $a['current_image_count']);
        self::assertSame(6, $b['current_image_count']);
        self::assertSame($a['recommended_image_count'], $b['recommended_image_count']);
        // target_words_per_image = 200 → ceil(1150/200) = 6
        self::assertSame(6, $a['recommended_image_count']);
        self::assertSame(1, $a['missing_image_count']);
        self::assertSame(0, $b['missing_image_count']);
    }

    public function test_links_health_still_enforces_minimum(): void
    {
        $health = AssistantWidgetHealthRules::buildLinksHealth([
            'internal' => [['href' => 'https://example.com/1']],
            'external' => [],
        ]);

        self::assertSame('error', $health['status']);
    }
}
