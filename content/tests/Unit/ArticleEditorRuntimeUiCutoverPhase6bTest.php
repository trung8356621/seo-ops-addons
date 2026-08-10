<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeUiCutoverPhase6bTest extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    public function test_runtime_navigation_and_shell_bridge_exist(): void
    {
        self::assertFileExists($this->js('editor/runtime/editorRuntimeNavigation.js'));
        self::assertFileExists($this->js('editor/runtime/editorShellCompatibilityBridge.js'));
        self::assertFileExists($this->js('editor/runtime/composeRuntimeWidgetHealth.js'));
        $nav = (string) file_get_contents($this->js('editor/runtime/editorRuntimeNavigation.js'));
        self::assertStringContainsString('export function openPanel', $nav);
        self::assertStringContainsString('export function subscribeEditorNavigation', $nav);
        $bridge = (string) file_get_contents($this->js('editor/runtime/editorShellCompatibilityBridge.js'));
        self::assertStringContainsString('installEditorShellCompatibilityBridge', $bridge);
        self::assertStringContainsString('SHELL_COMPAT_DEPRECATED_EVENTS', $bridge);
    }

    public function test_editor_hosted_panels_use_runtime_components(): void
    {
        foreach ([
            'editor/modules/seo/SeoSidebarPanel.jsx',
            'editor/modules/media/ImagesSidebarPanel.jsx',
            'editor/modules/article-meta/ReviewsSidebarPanel.jsx',
            'editor/host/EditorSidebarPortalHost.jsx',
            'editor/host/EditorHostApiContext.jsx',
        ] as $path) {
            self::assertFileExists($this->js($path), $path);
        }

        $seo = (string) file_get_contents($this->js('editor/modules/seo/index.js'));
        self::assertStringContainsString('SeoSidebarPanel', $seo);
        self::assertStringContainsString("portalRootKey: 'seo'", $seo);
    }

    public function test_seo_article_editor_does_not_hardcode_panel_modules(): void
    {
        $source = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('EditorSidebarPortalHost', $source);
        // Phase-1 perf: typing path uses partial health (content widgets only).
        self::assertStringContainsString('publishPartialRuntimeWidgetHealth', $source);
        self::assertStringContainsString('installEditorShellCompatibilityBridge', $source);
        self::assertStringContainsString('subscribeEditorNavigation', $source);
        self::assertStringNotContainsString("lazy(() => import('../modules/SeoModule'))", $source);
        self::assertStringNotContainsString("lazy(() => import('../modules/ImagesModule'))", $source);
        self::assertStringNotContainsString('buildSeoWidgetHealth({', $source);
        self::assertStringNotContainsString('MODULE_EVENT_SWITCH', $source);
    }

    public function test_toolbar_renders_from_runtime_registry(): void
    {
        $toolbar = (string) file_get_contents($this->js('components/BlockFormatToolbar.jsx'));
        self::assertStringContainsString('RuntimeToolbarCommandButtons', $toolbar);
        self::assertStringContainsString('executeEditorCommand', $toolbar);
        self::assertStringNotContainsString("run('toggle_bold')", $toolbar);
        self::assertStringNotContainsString("run('undo')", $toolbar);

        $runtimeToolbar = (string) file_get_contents($this->js('editor/runtime/RuntimeToolbarCommandButtons.jsx'));
        self::assertStringContainsString('getToolbarItems', $runtimeToolbar);
        self::assertStringContainsString('executeEditorCommand', $runtimeToolbar);
        self::assertStringNotContainsString('editor.chain(', $runtimeToolbar);
    }

    public function test_module_host_removed_navigation_owned_by_runtime(): void
    {
        self::assertFileDoesNotExist($this->js('components/ArticleEditorModuleHost.jsx'));
        $nav = (string) file_get_contents($this->js('editor/runtime/editorRuntimeNavigation.js'));
        self::assertStringContainsString('subscribeEditorNavigation', $nav);
        self::assertStringContainsString('export function openPanel', $nav);
    }

    public function test_orphan_image_toolbar_bubble_removed(): void
    {
        self::assertFileDoesNotExist($this->js('components/ImageToolbarBubble.jsx'));
    }

    public function test_public_sdk_readiness_adr_exists(): void
    {
        $adr = ProjectRoot::path().'/docs/architecture/decisions/ARTICLE_EDITOR_RUNTIME_PUBLIC_SDK_READINESS.md';
        self::assertFileExists($adr);
        $body = (string) file_get_contents($adr);
        self::assertStringContainsString('Not ready', $body);
        self::assertStringContainsString('internal stability', $body);
    }

    public function test_health_compose_uses_registry_builders(): void
    {
        $compose = (string) file_get_contents($this->js('editor/runtime/composeRuntimeWidgetHealth.js'));
        self::assertStringContainsString('getHealthProviders', $compose);
        self::assertStringContainsString('buildSeoWidgetHealth', $compose);
        self::assertStringContainsString('setRuntimeWidgetHealth', $compose);
    }
}
