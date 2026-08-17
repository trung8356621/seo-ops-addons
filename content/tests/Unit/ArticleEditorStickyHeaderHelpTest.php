<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Sticky editor header + Global Help modal contracts.
 */
final class ArticleEditorStickyHeaderHelpTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function addonPath(string $relative): string
    {
        return $this->resolveLegacyOrMovedAddonPath($relative);
    }

    public function test_edit_article_adds_article_editor_page_body_class(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('Filament/Resources/ArticleResource/Pages/EditArticle.php'),
        );

        self::assertStringContainsString('function getExtraBodyAttributes()', $source);
        self::assertStringContainsString("'class' => 'article-editor-page'", $source);
    }

    public function test_article_edit_page_css_hides_fixed_topbar_help_on_editor(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/css/article-edit-page.css'),
        );

        self::assertStringContainsString('body.article-editor-page .fi-topbar', $source);
        self::assertStringContainsString('global-help-topbar-host', $source);
        self::assertStringContainsString('seo-article-editor-sticky-header', $source);
        self::assertStringContainsString('.seo-editor-page-actions .seo-editor-preview-split', $source);
        self::assertStringContainsString('overflow: visible !important', $source);
    }

    public function test_edit_article_blade_has_sticky_header_and_no_shortcuts_rail_include(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );

        self::assertStringContainsString('data-seo-sticky-editor-header', $source);
        self::assertStringContainsString('article-editor-page', $source);
        self::assertStringContainsString('data-seo-sticky-save-status', $source);
        self::assertStringContainsString('data-article-editor-runtime-marker="sticky-help-v1"', $source);
        self::assertStringContainsString('article-editor-ui-revision', $source);
        self::assertStringContainsString('global-help-topbar-host', $source);
        self::assertStringContainsString('display: none !important', $source);
        self::assertStringNotContainsString("article-editor-shortcuts-rail')", $source);
    }

    public function test_page_actions_mount_help_next_to_more_button(): void
    {
        $source = (string) file_get_contents(
            $this->addonPath('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );

        self::assertStringContainsString("detail: { action: 'save' }", $source);
        self::assertStringContainsString("detail: { action: 'sync' }", $source);
        self::assertStringContainsString('data-seo-page-action="review"', $source);
        self::assertStringContainsString('submitReviewAction(', $source);
        self::assertStringContainsString('data-seo-page-action="help"', $source);
        self::assertStringContainsString('data-help-trigger', $source);
        self::assertStringContainsString('data-seo-page-actions-more', $source);
        self::assertStringNotContainsString('wire:click="toggleArticleReview"', $source);
        self::assertStringNotContainsString('article-editor:help-open', $source);
        self::assertStringNotContainsString('seo-article-editor-help-btn', $source);
    }

    public function test_global_help_trigger_and_modal_selectors(): void
    {
        $trigger = (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-trigger.blade.php'),
        );
        $modal = (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-modal.blade.php'),
        );
        $provider = (string) file_get_contents(
            $this->addonPath('Providers/SeoPanelProvider.php'),
        );

        self::assertStringContainsString('id="global-help-trigger"', $trigger);
        self::assertStringContainsString('data-help-trigger', $trigger);
        self::assertStringContainsString('global-help-trigger', $trigger);
        self::assertStringContainsString('id="global-help-modal"', $modal);
        self::assertStringContainsString('data-help-modal', $modal);
        self::assertStringContainsString('help-navigation', $modal);
        self::assertStringContainsString('USER_MENU_BEFORE', $provider);
        self::assertStringContainsString('global-help-trigger', $provider);
        self::assertStringContainsString('global-help-modal', $provider);
        self::assertStringContainsString('SeoHelpRegistry', (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-assets.blade.php'),
        ));
        self::assertStringNotContainsString('@vite', (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-assets.blade.php'),
        ));
    }

    public function test_help_registry_is_two_level_and_context_aware(): void
    {
        $phpRegistry = (string) file_get_contents(
            $this->addonPath('Support/SeoHelpRegistry.php'),
        );
        $assets = (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-assets.blade.php'),
        );
        $entry = (string) file_get_contents(
            $this->addonPath('resources/js/article-editor.jsx'),
        );

        self::assertStringContainsString('article-editor', $phpRegistry);
        self::assertStringContainsString('clientPayload', $phpRegistry);
        self::assertStringContainsString("Alpine.store('help'", $assets);
        self::assertStringContainsString('Escape', (string) file_get_contents(
            $this->addonPath('resources/views/filament/hooks/global-help-modal.blade.php'),
        ));
        self::assertStringNotContainsString('ArticleEditorHelpModal', $entry);
    }

    public function test_shortcuts_mount_helpers_removed_but_shortcut_logic_file_remains(): void
    {
        $headerActions = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorHeaderActions.js'),
        );
        $editor = (string) file_get_contents(
            $this->addonPath('resources/js/components/SeoArticleEditor.jsx'),
        );
        $shortcuts = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorShortcuts.js'),
        );

        self::assertStringNotContainsString('mountShortcutsBelowOutline', $headerActions);
        self::assertStringNotContainsString('observeShortcutsBelowOutline', $headerActions);
        self::assertStringNotContainsString('data-seo-outline-shortcuts-host', $editor);
        self::assertStringContainsString('articleShortcutActionFromEvent', $shortcuts);
        self::assertStringContainsString("return event.shiftKey ? 'sync' : 'save'", $shortcuts);
    }

    public function test_save_status_dispatched_to_sticky_header(): void
    {
        $editor = (string) file_get_contents(
            $this->addonPath('resources/js/hooks/useArticleEditorExternalEventsBridge.js'),
        );
        $bridge = (string) file_get_contents(
            $this->addonPath('resources/js/utils/articleEditorStickyHeader.js'),
        );

        self::assertStringContainsString('article-editor:save-status', $editor);
        self::assertStringContainsString('ARTICLE_EDITOR_SAVE_STATUS_EVENT', $bridge);
        self::assertStringContainsString('data-seo-sticky-save-status', $bridge);
        self::assertStringContainsString("from '../help/helpEvents'", $bridge);
    }
}
