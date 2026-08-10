<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

final class ArticleEditorRuntimeCompletionPhase6c4Test extends TestCase
{
    private function js(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    private function bladeEditArticle(): string
    {
        return LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php');
    }

    public function test_ai_chat_renders_from_runtime_module_not_module_host(): void
    {
        self::assertFileDoesNotExist($this->js('components/ArticleEditorModuleHost.jsx'));

        $mod = (string) file_get_contents($this->js('editor/modules/ai/index.js'));
        self::assertStringContainsString("id: 'article-editor.ai'", $mod);
        self::assertStringContainsString("host: 'editor'", $mod);
        self::assertStringContainsString("portalRootKey: 'aiChat'", $mod);
        self::assertStringContainsString('navChip: false', $mod);
        self::assertFileExists($this->js('editor/modules/ai/AiChatSidebarPanel.jsx'));

        $entry = (string) file_get_contents($this->js('article-editor.jsx'));
        self::assertStringNotContainsString('ArticleEditorModuleHost', $entry);

        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('seo-article-ai-chat-root', $host);
        self::assertStringContainsString('aiChat:', $host);
    }

    public function test_ai_panel_uses_host_actions_not_generate_events(): void
    {
        $panel = (string) file_get_contents($this->js('components/ArticleAiChatPanel.jsx'));
        self::assertStringContainsString('onGenerateImage', $panel);
        self::assertStringContainsString('onGenerateVideo', $panel);
        self::assertStringContainsString('onClose', $panel);
        self::assertStringNotContainsString("seo-article-ai-chat-close", $panel);
        self::assertStringNotContainsString("generate-article-image", $panel);
        self::assertStringNotContainsString('setContent', $panel);
        self::assertStringNotContainsString('editor.chain', $panel);

        $hook = (string) file_get_contents($this->js('editor/host/hooks/useEditorAi.js'));
        self::assertStringContainsString('generateArticleImage', $hook);
        self::assertStringContainsString('canMutateEditor', $hook);
        self::assertStringContainsString('openPanel', $hook);
    }

    public function test_host_contract_version_and_scoped_hooks_exist(): void
    {
        $contract = (string) file_get_contents($this->js('editor/runtime/editorHostContract.js'));
        self::assertStringContainsString('EDITOR_RUNTIME_HOST_CONTRACT_VERSION = 1', $contract);
        self::assertStringContainsString('useEditorAi', $contract);
        self::assertStringContainsString('useEditorNotifications', $contract);

        $index = (string) file_get_contents($this->js('editor/host/hooks/index.js'));
        self::assertStringContainsString('useEditorAi', $index);
        self::assertStringContainsString('useEditorDocument', $index);
        self::assertStringContainsString('useEditorNavigation', $index);
        self::assertStringContainsString('useEditorPermissions', $index);
        self::assertStringContainsString('useEditorShellBoundary', $index);

        $notify = (string) file_get_contents($this->js('editor/host/hooks/useEditorNotifications.js'));
        self::assertStringContainsString('success:', $notify);
        self::assertStringContainsString('error:', $notify);
        self::assertStringContainsString('warning:', $notify);
    }

    public function test_runtime_modules_do_not_import_core_internals(): void
    {
        $base = ProjectRoot::addonsPath().'/content/resources/js/editor/modules';
        $moduleFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower($fileInfo->getExtension());
            if ($ext !== 'js' && $ext !== 'jsx') {
                continue;
            }
            $moduleFiles[] = $fileInfo->getPathname();
        }
        self::assertNotEmpty($moduleFiles);
        foreach ($moduleFiles as $file) {
            $body = (string) file_get_contents($file);
            $norm = str_replace('\\', '/', $file);
            self::assertStringNotContainsString('SeoArticleEditor', $body, $norm);
            self::assertStringNotContainsString('ArticleEditorModuleHost', $body, $norm);
            if (preg_match('#/editor/modules/(featured|gallery|links|faq|ai)/#', $norm) === 1) {
                self::assertStringNotContainsString('window.dispatchEvent', $body, $norm);
                self::assertStringNotContainsString('localStorage', $body, $norm);
            }
        }
    }

    public function test_core_does_not_import_module_panels_directly(): void
    {
        $host = (string) file_get_contents($this->js('components/SeoArticleEditor.jsx'));
        self::assertStringNotContainsString("from '../editor/modules/ai/", $host);
        self::assertStringNotContainsString("from '../editor/modules/featured/", $host);
        self::assertStringNotContainsString('LinkEditBubble', $host);
        self::assertStringContainsString('EditorInspectorBubbleHost', $host);
        self::assertStringContainsString('EditorSidebarPortalHost', $host);
    }

    public function test_shell_bridge_owns_browser_boundary_events(): void
    {
        $bridge = (string) file_get_contents($this->js('editor/runtime/editorShellCompatibilityBridge.js'));
        self::assertStringContainsString('SHELL_COMPAT_DEPRECATED_EVENTS', $bridge);
        self::assertStringContainsString('seo-article-ai-chat-open', $bridge);
        self::assertStringContainsString('openPanel', $bridge);
        self::assertStringContainsString('subscribeEditorNavigation', $bridge);
        self::assertStringNotContainsString('editor.chain', $bridge);
        self::assertStringNotContainsString('setContent', $bridge);
        self::assertStringNotContainsString('applyMediaSnapshot', $bridge);
    }

    public function test_alpine_dead_picker_state_removed(): void
    {
        $blade = (string) file_get_contents($this->bladeEditArticle());
        self::assertStringNotContainsString('pickerImages:', $blade);
        self::assertStringNotContainsString('galleryPickerSelectedKeys:', $blade);
        self::assertStringNotContainsString('pickerLoading:', $blade);
        self::assertStringNotContainsString('class="seo-article-media-modal"', $blade);
        self::assertStringContainsString('__seoOpenSharedMediaPicker', $blade);
        self::assertStringNotContainsString('featuredImageDraft', $blade);
        self::assertStringContainsString('seo-article-ai-chat-root', $blade);
    }

    public function test_editor_hosted_includes_ai_chat_external_empty(): void
    {
        $modules = (string) file_get_contents($this->js('utils/articleEditorModules.js'));
        self::assertStringContainsString("'ai-chat'", $modules);
        self::assertStringContainsString('EXTERNAL_HOSTED_MODULES = Object.freeze([])', $modules);
    }

    public function test_publishing_not_in_runtime_command_registry_as_module_command(): void
    {
        $index = (string) file_get_contents($this->js('editor/modules/index.js'));
        self::assertStringNotContainsString('publishingModule', $index);
        self::assertStringContainsString('aiModule', $index);

        $shell = (string) file_get_contents($this->js('editor/runtime/editorShellNavItems.js'));
        self::assertStringContainsString("panelId: 'publishing'", $shell);
        self::assertStringContainsString('shell: true', $shell);
    }

    public function test_fab_and_block_insert_use_open_panel(): void
    {
        $fab = (string) file_get_contents($this->js('components/ArticleAiFloatingLauncher.jsx'));
        self::assertStringContainsString('openPanel', $fab);
        self::assertStringNotContainsString('seo-article-ai-chat-open', $fab);

        $menu = (string) file_get_contents($this->js('components/BlockInsertMenu.jsx'));
        self::assertStringContainsString("openPanel('ai-chat'", $menu);
    }

    public function test_single_shared_media_picker_component(): void
    {
        $shared = $this->js('editor/host/SharedMediaPicker.jsx');
        $store = $this->js('editor/runtime/editorMediaPickerStore.js');
        self::assertFileExists($shared);
        self::assertFileExists($store);
        // No second React picker under components/.
        self::assertFileDoesNotExist($this->js('components/SharedMediaPicker.jsx'));
        self::assertFileDoesNotExist($this->js('components/ArticleMediaPicker.jsx'));
    }
}
