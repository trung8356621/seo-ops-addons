<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 â€” Editor Command Layer source contracts.
 */
final class ArticleEditorCommandLayerPhase4Test extends TestCase
{
    private function root(): string
    {
        return ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands';
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_registry_marks_mutations_writable_and_nav_readonly(): void
    {
        $source = $this->read('editorCommandRegistry.js');
        self::assertStringContainsString("mut('insert_contact_cta'", $source);
        self::assertStringContainsString("mut('remove_link_keep_text'", $source);
        self::assertStringContainsString("mut('toggle_bold'", $source);
        self::assertStringContainsString("mut('insert_image'", $source);
        self::assertStringContainsString("mut('replace_article_document'", $source);
        self::assertStringContainsString("nav('outline_jump'", $source);
        self::assertStringContainsString("nav('set_text_selection'", $source);
        self::assertStringContainsString('requiresWritable: true', $source);
        self::assertStringContainsString('mutatesDocument: false', $source);
    }

    public function test_unknown_command_fails_clearly(): void
    {
        $source = $this->read('index.js');
        self::assertStringContainsString('EDITOR_COMMAND_CODES.UNKNOWN', $source);
        self::assertStringContainsString('executeEditorCommand', $source);
    }

    public function test_resolver_priority_and_ambiguous_fail(): void
    {
        $source = $this->read('resolveTargetEditor.js');
        self::assertStringContainsString('payload.editor', $source);
        self::assertStringContainsString('explicitId', $source);
        self::assertStringContainsString('TARGET_AMBIGUOUS', $source);
        self::assertStringContainsString('map.size === 1', $source);
        self::assertStringNotContainsString('values().next()', $source);
        self::assertStringNotContainsString('querySelector', $source);
    }

    public function test_transaction_wrapper_emits_single_document_changed(): void
    {
        $source = $this->read('runEditorTransaction.js');
        self::assertStringContainsString("DOCUMENT_CHANGED_EVENT = 'article-editor-document-changed'", $source);
        self::assertStringContainsString('emitDocumentChanged', $source);
        self::assertStringContainsString('scheduleAutosave', $source);
        self::assertStringContainsString('markSeoStale', $source);
        self::assertStringContainsString('commitActiveBlock', $source);
    }

    public function test_writable_guard_is_central(): void
    {
        $source = $this->read('editorCommandContext.js');
        self::assertStringContainsString('assertWritableCommandContext', $source);
        self::assertStringContainsString('canMutateEditor', $source);
        self::assertStringContainsString('READ_ONLY', $source);
        self::assertStringContainsString('SESSION_NOT_OWNED', $source);
        self::assertStringContainsString('CONTENT_REPLACE_CONFLICT', $source);
    }

    public function test_link_and_insertion_commands_exist(): void
    {
        $links = $this->read('linkCommands.js');
        self::assertStringContainsString('removeLinkKeepText', $links);
        self::assertStringContainsString('exitLinkAtBoundary', $links);
        self::assertStringNotContainsString('DOMParser', $links);

        $insert = $this->read('insertionCommands.js');
        self::assertStringContainsString('insertContactCtaAtBookmark', $insert);
        self::assertStringContainsString('insertContactValueAtBookmark', $insert);
        self::assertStringNotContainsString('DOMParser', $insert);
    }

    public function test_media_commands_do_not_call_upload_api(): void
    {
        $source = $this->read('mediaCommands.js');
        self::assertStringContainsString('insert_image', $source);
        self::assertStringNotContainsString('fetch(', $source);
        self::assertStringNotContainsString('upload', $source);
        self::assertStringNotContainsString('DOMParser', $source);
    }

    public function test_ui_callers_use_command_layer(): void
    {
        $base = ProjectRoot::addonsPath().'/content/resources/js';
        $toolbar = (string) file_get_contents($base.'/components/BlockFormatToolbar.jsx');
        self::assertStringContainsString('executeEditorCommand', $toolbar);
        self::assertStringNotContainsString('editor.chain()', $toolbar);
        self::assertStringNotContainsString('removeLinkKeepText(editor)', $toolbar);

        $bubble = (string) file_get_contents($base.'/components/LinkEditBubble.jsx');
        self::assertStringContainsString("executeEditorCommand('remove_link_keep_text'", $bubble);

        $editor = (string) file_get_contents($base.'/components/SeoArticleEditor.jsx');
        self::assertStringContainsString('bindEditorCommandHost', $editor);

        $contextMenu = (string) file_get_contents($base.'/components/EditorContextMenu.jsx');
        self::assertStringContainsString('executeEditorCommand', $contextMenu);
        self::assertStringContainsString('CONTEXT_MENU_COMMANDS', $contextMenu);
        self::assertStringContainsString('applyContextMenuSelection', $contextMenu);

        $ctxController = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorContextMenuController.js',
        );
        self::assertStringContainsString("name: 'split_selection_to_heading'", $ctxController);

        $ext = (string) file_get_contents($base.'/utils/editorExtensions.js');
        self::assertStringContainsString("executeEditorCommand('remove_link_keep_text'", $ext);
        self::assertStringContainsString('split_selection_to_heading', $ext);
        self::assertStringContainsString('data-outline-visible', $ext);
        self::assertStringContainsString('data-omi-heading-id', $ext);
    }

    public function test_architecture_doc_exists(): void
    {
        $path = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_COMMAND_LAYER.md';
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        self::assertStringContainsString('executeEditorCommand', $body);
        self::assertStringContainsString('article-editor-document-changed', $body);
        self::assertStringContainsString('resolveTargetEditor', $body);
    }
}
