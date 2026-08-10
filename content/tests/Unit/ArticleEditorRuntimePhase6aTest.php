<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6A â€” contract tests for internal editor runtime (built-in modules only).
 */
final class ArticleEditorRuntimePhase6aTest extends TestCase
{
    private function addonJs(string $relative): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
    }

    public function test_runtime_core_files_exist(): void
    {
        foreach ([
            'editor/runtime/createEditorRuntime.js',
            'editor/runtime/editorRuntimeContext.js',
            'editor/runtime/editorRuntimeRegistry.js',
            'editor/runtime/editorRuntimeModule.js',
            'editor/runtime/editorRuntimeValidation.js',
            'editor/runtime/editorRuntimeSelectors.js',
            'editor/runtime/editorRuntimeSlots.js',
            'editor/runtime/editorRuntimeErrors.js',
            'editor/runtime/EditorRuntimeSlot.jsx',
            'editor/runtime/EditorModuleErrorBoundary.jsx',
            'editor/runtime/index.js',
            'editor/modules/index.js',
            'editor/modules/core/index.js',
            'editor/modules/seo/index.js',
            'editor/modules/media/index.js',
            'editor/modules/featured/index.js',
            'editor/modules/gallery/index.js',
            'editor/modules/links/index.js',
            'editor/modules/faq/index.js',
            'editor/modules/cta-contact/index.js',
            'editor/modules/article-meta/index.js',
        ] as $path) {
            self::assertFileExists($this->addonJs($path), $path);
        }
    }

    public function test_validation_rejects_duplicate_and_circular(): void
    {
        $source = (string) file_get_contents($this->addonJs('editor/runtime/editorRuntimeValidation.js'));
        self::assertStringContainsString('DUPLICATE_MODULE', $source);
        self::assertStringContainsString('MISSING_DEPENDENCY', $source);
        self::assertStringContainsString('CIRCULAR_DEPENDENCY', $source);
        self::assertStringContainsString('validateRuntimeModules', $source);
    }

    public function test_runtime_caches_document_extensions(): void
    {
        $source = (string) file_get_contents($this->addonJs('editor/runtime/createEditorRuntime.js'));
        self::assertStringContainsString('cachedDocumentExtensions', $source);
        self::assertStringContainsString('Do NOT invalidate TipTap extension cache', $source);
        self::assertStringContainsString('extensionNames', $source);
    }

    public function test_builtin_modules_have_stable_ids_and_orders(): void
    {
        $index = (string) file_get_contents($this->addonJs('editor/modules/index.js'));
        self::assertStringContainsString('article-editor.core', (string) file_get_contents($this->addonJs('editor/modules/core/index.js')));
        self::assertStringContainsString('article-editor.seo', (string) file_get_contents($this->addonJs('editor/modules/seo/index.js')));
        self::assertStringContainsString('article-editor.media', (string) file_get_contents($this->addonJs('editor/modules/media/index.js')));
        self::assertStringContainsString('article-editor.faq', (string) file_get_contents($this->addonJs('editor/modules/faq/index.js')));
        self::assertStringContainsString('article-editor.cta-contact', (string) file_get_contents($this->addonJs('editor/modules/cta-contact/index.js')));
        self::assertStringContainsString('BUILTIN_ARTICLE_EDITOR_MODULES', $index);
        self::assertStringNotContainsString('article-editor.publishing', $index);
    }

    public function test_core_module_reuses_tiptap_extensions_array(): void
    {
        $core = (string) file_get_contents($this->addonJs('editor/modules/core/index.js'));
        self::assertStringContainsString('articleEditorExtensions', $core);
        self::assertStringContainsString('schemaVersion: 1', $core);
        $ext = (string) file_get_contents($this->addonJs('utils/editorExtensions.js'));
        self::assertStringContainsString('export const articleEditorExtensions', $ext);
    }

    public function test_seo_article_editor_uses_runtime_extensions(): void
    {
        $source = (string) file_get_contents($this->addonJs('components/SeoArticleEditor.jsx'));
        self::assertStringContainsString('getDefaultArticleEditorRuntime', $source);
        self::assertStringContainsString('getDocumentExtensions()', $source);
        self::assertStringContainsString('Phase 6A â€” sync internal runtime context', $source);
        self::assertStringNotContainsString("import { articleEditorExtensions } from '../utils/editorExtensions'", $source);
    }

    public function test_command_layer_still_canonical(): void
    {
        $source = (string) file_get_contents($this->addonJs('utils/editorCommands/index.js'));
        self::assertStringContainsString('executeEditorCommand', $source);
        $runtimeCreate = (string) file_get_contents($this->addonJs('editor/runtime/createEditorRuntime.js'));
        self::assertStringNotContainsString('editor.chain()', $runtimeCreate);
    }

    public function test_error_boundary_and_slot_exist(): void
    {
        $boundary = (string) file_get_contents($this->addonJs('editor/runtime/EditorModuleErrorBoundary.jsx'));
        self::assertStringContainsString('componentDidCatch', $boundary);
        self::assertStringContainsString('Retry', $boundary);
        $slot = (string) file_get_contents($this->addonJs('editor/runtime/EditorRuntimeSlot.jsx'));
        self::assertStringContainsString('EditorModuleErrorBoundary', $slot);
        self::assertStringContainsString('data-editor-runtime-slot', $slot);
    }

    public function test_architecture_docs_exist(): void
    {
        $doc = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_RUNTIME.md';
        self::assertFileExists($doc);
        $body = (string) file_get_contents($doc);
        // Keep assertions aligned with current ARTICLE_EDITOR_RUNTIME.md wording (6A+6B).
        self::assertTrue(
            str_contains($body, 'internal foundation')
            || str_contains($body, 'internal UI cutover foundation')
            || str_contains($body, 'Internal registry'),
            'Runtime docs must describe internal runtime foundation.',
        );
        self::assertStringContainsString('Not a public SDK', $body);
        self::assertTrue(
            str_contains($body, 'Built-in modules')
            || str_contains($body, 'built-in modules'),
            'Runtime docs must mention built-in modules.',
        );
        self::assertStringContainsString('Phase 6B', $body);
    }

    public function test_sidebar_helper_lists_runtime_panels(): void
    {
        $source = (string) file_get_contents($this->addonJs('utils/articleEditorModules.js'));
        self::assertStringContainsString('listRuntimeSidebarPanelIds', $source);
        self::assertStringContainsString('EDITOR_HOSTED_MODULES', $source);
        self::assertStringContainsString('publishing', $source);
        // Must not statically import runtime singleton (TDZ cycle with modules registry).
        self::assertStringNotContainsString(
            "from '../editor/runtime/defaultArticleEditorRuntime'",
            $source,
        );
    }
}
