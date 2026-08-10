<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use Omnichannel\Addons\Content\Http\Controllers\SeoArticleRevisionController;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 1.1 enforcement: no Livewire direct body bypass, TipTap/session wiring contracts.
 */
final class ArticleEditorSessionLockEnforcementTest extends TestCase
{
    public function test_edit_article_persist_requires_session_and_uses_persist_service(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(EditArticle::class))->getFileName(),
        );

        self::assertStringContainsString('persistBodyViaSessionAwarePath', $source);
        self::assertStringContainsString('assertOwningActiveSessionForWrite', $source);
        self::assertStringContainsString('ArticleEditorPersistService', $source);
        self::assertStringContainsString('public ?string $editorSessionId', $source);
        self::assertStringContainsString('public ?int $expectedDocumentVersion', $source);

        $silent = $this->methodSource(new ReflectionMethod(EditArticle::class, 'persistArticleLocalSilent'));
        self::assertStringContainsString('persistBodyViaSessionAwarePath', $silent);
        self::assertStringNotContainsString("'body' => \$html", $silent);

        $path = $this->methodSource(new ReflectionMethod(EditArticle::class, 'persistBodyViaSessionAwarePath'));
        self::assertStringContainsString('writeArticleRow', $path);
        self::assertStringNotContainsString("\$this->record->update([", $path);
    }

    public function test_edit_article_has_no_direct_body_update_in_persist_methods(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(EditArticle::class))->getFileName(),
        );

        // Bootstrap hydrate may still write empty body from WP cache, but only when no active session.
        self::assertStringContainsString('findActiveSession', $source);

        $persistLocal = $this->methodSource(new ReflectionMethod(EditArticle::class, 'persistArticleLocal'));
        self::assertStringNotContainsString("'body' => \$html", $persistLocal);
    }

    public function test_session_service_exposes_external_and_owning_write_guards(): void
    {
        $class = new ReflectionClass(ArticleEditorSessionService::class);
        self::assertTrue($class->hasMethod('assertOwningActiveSessionForWrite'));
        self::assertTrue($class->hasMethod('assertNoActiveEditorSession'));
        self::assertTrue($class->hasMethod('assertBodyRewriteAllowed'));
    }

    public function test_revision_restore_blocked_when_editor_locked(): void
    {
        $source = $this->methodSource(new ReflectionMethod(SeoArticleRevisionController::class, 'restore'));
        self::assertStringContainsString('assertNoActiveEditorSession', $source);
        self::assertStringContainsString('revision_restore', $source);
    }

    public function test_media_rewrite_allows_owning_editor_session(): void
    {
        $source = $this->methodSource(new ReflectionMethod(SeoMediaUrlReplacementService::class, 'rewriteArticleReferences'));
        self::assertStringContainsString('assertBodyRewriteAllowed', $source);
        self::assertStringContainsString('media_url_rewrite', $source);
        self::assertStringContainsString('editor_session_id', $source);

        $sessions = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorSessionService::class))->getFileName(),
        );
        self::assertStringContainsString('assertOwningActiveSessionForMediaMutation', $sessions);
        self::assertStringContainsString('Owning active session â†’ allow', $sessions);
    }

    public function test_ai_apply_blocked_when_editor_locked(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplyService::class))->getFileName(),
        );
        self::assertStringContainsString('assertNoActiveEditorSession', $source);
        self::assertStringContainsString('ai_apply_', $source);
    }

    public function test_faq_body_persist_requires_session_when_locked(): void
    {
        $source = $this->methodSource(new ReflectionMethod(
            \Omnichannel\Addons\Content\Services\ArticleContentFaqService::class,
            'persistArticleBodyHtml',
        ));
        self::assertStringContainsString('assertOwningActiveSessionForWrite', $source);
        self::assertStringContainsString('assertNoActiveEditorSession', $source);
        self::assertStringContainsString('faq_body_apply', $source);
    }

    public function test_revision_service_also_guards_restore(): void
    {
        $source = $this->methodSource(new ReflectionMethod(
            \Omnichannel\Addons\Content\Services\SeoArticleRevisionService::class,
            'restoreRevisionToArticle',
        ));
        self::assertStringContainsString('assertNoActiveEditorSession', $source);
    }

    public function test_frontend_tiptap_editable_and_session_state_schema(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        self::assertStringContainsString('editable: Boolean(editable)', $editor);
        self::assertStringContainsString('editor.setEditable(nextEditable)', $editor);
        self::assertStringContainsString('assertWritableEditorSession', $editor);
        self::assertStringContainsString('canMutateEditor()', $editor);

        $state = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorSessionState.js',
        );
        self::assertStringContainsString("ARTICLE_EDITOR_SESSION_STATE_EVENT = 'article-editor-session-state-changed'", $state);
        self::assertStringContainsString('emitArticleEditorSessionState', $state);
        self::assertStringContainsString('ACQUIRING', $state);
        self::assertStringContainsString('TAKEN_OVER', $state);
        self::assertStringContainsString('NETWORK_DEGRADED', $state);
        self::assertStringContainsString("case 'conflict':", $state);
        self::assertStringContainsString('content_project_archived', $state);

        $entry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('emitArticleEditorSessionState', $entry);
        self::assertStringContainsString('editorSessionId', $entry);
        self::assertStringContainsString('expectedDocumentVersion', $entry);
        self::assertStringContainsString('content_project_archived', $entry);
        self::assertStringContainsString('editorMountedRef', $entry);

        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );
        self::assertStringContainsString('article-editor-session-state-changed', $actions);
        self::assertStringContainsString('canMutateDocument()', $actions);
        self::assertStringContainsString('x-bind:disabled="!canMutateDocument()"', $actions);
    }

    public function test_no_remount_on_retry_acquire_contract(): void
    {
        $entry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('editorMountedRef', $entry);
        self::assertStringContainsString('if (!editorMountedRef.current)', $entry);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode('', array_slice($lines, $start, $end - $start));
    }
}
