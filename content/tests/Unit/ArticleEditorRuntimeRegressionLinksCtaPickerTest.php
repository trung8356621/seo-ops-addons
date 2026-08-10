<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Contract: Links/CTA recursion + CTA icons + SharedMediaPicker parity.
 */
final class ArticleEditorRuntimeRegressionLinksCtaPickerTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_focus_reason_bridge_does_not_recurse(): void
    {
        $nav = $this->readAddon('resources/js/editor/runtime/editorRuntimeNavigation.js');
        $bridge = $this->readAddon('resources/js/editor/runtime/editorShellCompatibilityBridge.js');

        self::assertStringContainsString('fromRuntime: true', $nav);
        self::assertStringContainsString("event?.detail?.fromRuntime === true", $bridge);
        self::assertStringContainsString('never call focusReason again', $bridge);
        self::assertStringContainsString("source === 'shell_focus_reason'", $bridge);
    }

    public function test_host_actions_accept_plain_detail_not_event(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('insertSuggestedLinkAction', $editor);
        self::assertStringContainsString('insertCtaLinkAction', $editor);
        self::assertStringContainsString('removeInternalLinkAction', $editor);
        self::assertStringContainsString('scrollToLinkAction', $editor);
        self::assertStringContainsString('module actions accept plain detail', $editor);
        self::assertStringContainsString(
            'editorHostActionsRef.current.insertSuggestedLink = insertSuggestedLinkAction',
            $editor,
        );
        self::assertStringContainsString(
            'editorHostActionsRef.current.insertCtaLink = insertCtaLinkAction',
            $editor,
        );
        self::assertStringNotContainsString(
            'editorHostActionsRef.current.insertSuggestedLink = onInsertSuggestedLink',
            $editor,
        );
    }

    public function test_cta_uses_compact_type_icons_not_long_text_button(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');

        self::assertStringContainsString('ctaTypeIcon', $cta);
        self::assertStringContainsString('<TypeIcon size={14}', $cta);
        self::assertStringContainsString('cta_widget_insert_sentence_tooltip', $cta);
        self::assertStringNotContainsString('wp-article-links-insert-btn--text', $cta);
        self::assertStringNotContainsString("{t('cta_widget_insert_cta_short')}", $cta);
    }

    public function test_insert_image_registered_once_by_media_module(): void
    {
        $media = $this->readAddon('resources/js/editor/modules/media/index.js');
        $ai = $this->readAddon('resources/js/editor/modules/ai/index.js');
        $core = $this->readAddon('resources/js/editor/modules/core/index.js');

        self::assertSame(1, substr_count($media, "name: 'insert_image'"));
        self::assertStringNotContainsString("id: 'insert_image'", $ai);
        self::assertStringNotContainsString("name: 'insert_image'", $core);
    }

    public function test_shared_media_picker_has_article_wp_local_custom_refresh_locale(): void
    {
        $picker = $this->readAddon('resources/js/editor/host/SharedMediaPicker.jsx');
        $i18n = $this->readAddon('resources/js/utils/i18n.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString("data-media-picker-tab=\"article\"", $picker);
        self::assertStringContainsString("data-media-picker-tab=\"original\"", $picker);
        self::assertStringContainsString("data-media-picker-tab=\"local\"", $picker);
        self::assertStringContainsString('seo-request-editor-images-catalog', $picker);
        self::assertStringContainsString('loadCustomPickerTabs', $picker);
        self::assertStringContainsString('addCustomPickerTab', $picker);
        self::assertStringContainsString('data-media-picker-refresh="1"', $picker);
        self::assertStringContainsString('skipCache', $picker);
        self::assertStringContainsString("t('media_picker_content_title')", $picker);
        self::assertStringContainsString('articleDomain=', $editor);

        self::assertStringContainsString('media_picker_content_title:', $i18n);
        self::assertStringContainsString('Choose image/video from library', $i18n);
        self::assertStringContainsString('Chọn ảnh/video từ thư viện', $i18n);
        self::assertStringContainsString('media_picker_tab_article:', $i18n);
        self::assertStringContainsString('Trong bài', $i18n);
    }

    public function test_links_sidebar_calls_host_action_once_path(): void
    {
        $links = $this->readAddon('resources/js/components/ArticleLinksSidebar.jsx');
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');

        self::assertStringContainsString('actions.insertSuggestedLink(detail)', $links);
        self::assertStringContainsString('actions.insertCtaLink(detail)', $cta);
        self::assertStringContainsString('executeEditorCommand', $this->readAddon('resources/js/components/SeoArticleEditor.jsx'));
        self::assertStringContainsString("executeEditorCommand('insert_link'", $this->readAddon('resources/js/components/SeoArticleEditor.jsx'));
        self::assertStringContainsString("executeEditorCommand(commandName", $this->readAddon('resources/js/components/SeoArticleEditor.jsx'));
    }
}
