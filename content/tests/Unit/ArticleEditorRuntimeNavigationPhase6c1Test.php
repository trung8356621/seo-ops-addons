<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeNavigationPhase6c1Test extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    private function bladeEditArticle(): string
    {
        return LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
    }

    public function test_react_navigation_renders_from_runtime_registry(): void
    {
        $nav = (string) file_get_contents($this->js('editor/host/EditorSidebarNavigation.jsx'));
        self::assertFileExists($this->js('editor/host/EditorSidebarNavigation.jsx'));
        self::assertStringContainsString('getSidebarEntries', $nav);
        self::assertStringContainsString('subscribeEditorNavigation', $nav);
        self::assertStringContainsString('subscribeRuntimeWidgetHealth', $nav);
        self::assertStringContainsString('openPanel', $nav);
        self::assertStringContainsString('SHELL_BOUNDARY_NAV_ITEMS', $nav);
        self::assertStringContainsString('navChip !== false', $nav);
    }

    public function test_blade_has_mount_roots_not_chip_list(): void
    {
        $blade = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringContainsString('article-editor-sidebar-navigation-root', $blade);
        self::assertStringContainsString('article-editor-sidebar-panel-root', $blade);
        self::assertStringNotContainsString('x-for="chip in chips"', $blade);
        self::assertStringNotContainsString('selectChip(chip.id)', $blade);
        self::assertStringNotContainsString('chipStatus(chip.id)', $blade);
        self::assertStringNotContainsString('data-assistant-widget-id=', $blade);
        self::assertStringNotContainsString('data-assistant-tab-label=', $blade);
    }

    public function test_active_panel_owned_only_by_runtime_navigation(): void
    {
        $runtimeNav = (string) file_get_contents($this->js('editor/runtime/editorRuntimeNavigation.js'));
        self::assertStringContainsString('export function openPanel', $runtimeNav);
        self::assertStringContainsString('export function getActivePanel', $runtimeNav);
        self::assertStringContainsString('let activePanelId', $runtimeNav);

        $alpine = (string) file_get_contents($this->js('utils/seoAssistantNavigator.js'));
        self::assertStringNotContainsString('activePanel:', $alpine);
        self::assertStringNotContainsString('chips:', $alpine);
        self::assertStringNotContainsString('widgetHealth:', $alpine);
        self::assertStringNotContainsString('badges:', $alpine);
        self::assertStringNotContainsString('selectChip(', $alpine);
        self::assertStringNotContainsString('switchPanel(', $alpine);
        self::assertStringContainsString('runtimeActivePanel', $alpine);
        self::assertStringContainsString('subscribeEditorNavigation', $alpine);
        self::assertStringContainsString('Read-only mirror', $alpine);
    }

    public function test_health_badges_use_runtime_store_not_alpine_primary_events(): void
    {
        $compose = (string) file_get_contents($this->js('editor/runtime/composeRuntimeWidgetHealth.js'));
        self::assertStringContainsString('setRuntimeWidgetHealth', $compose);
        self::assertStringContainsString('publishEditorShellHealthSummary', $compose);
        self::assertStringNotContainsString('dispatchAssistantWidgetHealth', $compose);
        self::assertStringNotContainsString("seo-assistant-widget-health", $compose);
        self::assertStringNotContainsString("seo-assistant-navigator-badges", $compose);

        $store = (string) file_get_contents($this->js('editor/runtime/editorRuntimeHealthStore.js'));
        self::assertStringContainsString('subscribeRuntimeWidgetHealth', $store);
        self::assertStringContainsString('seo-editor-shell-health-summary', $store);

        $alpine = (string) file_get_contents($this->js('utils/seoAssistantNavigator.js'));
        self::assertStringNotContainsString('seo-assistant-widget-health', $alpine);
        self::assertStringNotContainsString('seo-assistant-navigator-badges', $alpine);
    }

    public function test_shell_open_panel_goes_through_compatibility_bridge_only(): void
    {
        $bridge = (string) file_get_contents($this->js('editor/runtime/editorShellCompatibilityBridge.js'));
        self::assertStringContainsString('installEditorShellCompatibilityBridge', $bridge);
        self::assertStringContainsString('openPanel', $bridge);
        self::assertStringContainsString('does NOT own active panel', $bridge);
        self::assertStringContainsString('SHELL_COMPAT_DEPRECATED_EVENTS', $bridge);
        self::assertStringNotContainsString('activePanelId', $bridge);
        self::assertStringNotContainsString('let active', $bridge);

        $alpine = (string) file_get_contents($this->js('utils/seoAssistantNavigator.js'));
        self::assertStringNotContainsString('seo-assistant-switch-panel', $alpine);
    }

    public function test_publishing_not_in_runtime_sidebar_registry(): void
    {
        $shell = (string) file_get_contents($this->js('editor/runtime/editorShellNavItems.js'));
        self::assertStringContainsString("id: 'publishing'", $shell);
        self::assertStringContainsString('shell: true', $shell);

        foreach (['seo', 'media', 'featured', 'gallery', 'links', 'faq', 'cta-contact', 'article-meta'] as $mod) {
            $source = (string) file_get_contents($this->js('editor/modules/'.$mod.'/index.js'));
            self::assertStringNotContainsString("panelId: 'publishing'", $source, $mod);
        }

        $modulesIndex = (string) file_get_contents($this->js('editor/modules/index.js'));
        self::assertStringNotContainsString('publishing', $modulesIndex);
    }

    public function test_host_mounts_navigation_without_tiptap_remount_coupling(): void
    {
        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('EditorSidebarNavigation', $host);
        self::assertStringContainsString('article-editor-sidebar-navigation-root', $host);
        self::assertStringContainsString('installRuntimeHealthBadgeBridge', $host);
        self::assertStringContainsString('EditorSidebarPortalHost', $host);
        // TipTap extensions still from cached runtime â€” navigation must not recreate extensions array.
        self::assertStringContainsString('getDocumentExtensions()', $host);
        self::assertStringNotContainsString('x-for="chip in chips"', $host);
    }

    public function test_faq_and_gallery_hidden_from_nav_chips_for_parity(): void
    {
        $faq = (string) file_get_contents($this->js('editor/modules/faq/index.js'));
        self::assertStringContainsString('navChip: false', $faq);
        $gallery = (string) file_get_contents($this->js('editor/modules/gallery/index.js'));
        self::assertStringContainsString('navChip: false', $gallery);
    }

    public function test_sidebar_entry_labels_preserve_parity_order_keys(): void
    {
        $seo = (string) file_get_contents($this->js('editor/modules/seo/index.js'));
        self::assertStringContainsString("label: 'SEO'", $seo);
        self::assertStringContainsString('order: 100', $seo);
        $images = (string) file_get_contents($this->js('editor/modules/media/index.js'));
        self::assertStringContainsString("label: 'Images'", $images);
        $cta = (string) file_get_contents($this->js('editor/modules/cta-contact/index.js'));
        self::assertStringContainsString("panelId: 'cta'", $cta);
        self::assertStringContainsString('order: 145', $cta);
    }

    public function test_docs_describe_react_navigation_ownership(): void
    {
        $runtimeDoc = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_RUNTIME.md';
        $shellDoc = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_SHELL_BOUNDARY.md';
        self::assertFileExists($runtimeDoc);
        self::assertFileExists($shellDoc);
        $runtimeBody = (string) file_get_contents($runtimeDoc);
        self::assertStringContainsString('6C.1', $runtimeBody);
        self::assertStringContainsString('React runtime owns editor navigation', $runtimeBody);
        $shellBody = (string) file_get_contents($shellDoc);
        self::assertStringContainsString('Publishing', $shellBody);
        self::assertStringContainsString('compatibility bridge', $shellBody);
        self::assertStringContainsString('editorShellCompatibilityBridge', $shellBody);
    }
}
