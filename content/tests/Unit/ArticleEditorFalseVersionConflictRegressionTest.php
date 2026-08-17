<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSessionController;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * False document_version / content_hash conflict after bootstrap + autosave.
 * Mix of runtime guard asserts + source contracts (no DB / no Laravel app).
 */
final class ArticleEditorFalseVersionConflictRegressionTest extends TestCase
{
    public function test_matching_document_version_does_not_let_legacy_content_hash_veto(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = $this->articleStub([
            'body' => 'server-body',
            'document_version' => 7,
        ]);

        self::assertNull($guard->assertCompatible($article, [
            'expected_document_version' => 7,
            'expected_content_hash' => $guard->contentHash('stale-client-tiptap-export'),
        ]));
    }

    public function test_mismatched_document_version_still_conflicts(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = $this->articleStub([
            'body' => 'server-body',
            'document_version' => 8,
        ]);

        $fail = $guard->assertCompatible($article, [
            'expected_document_version' => 7,
            'expected_content_hash' => $guard->contentHash('server-body'),
        ]);
        self::assertNotNull($fail);
        self::assertSame('conflict_document_version', $fail->error['code'] ?? null);
    }

    public function test_legacy_content_hash_still_enforced_without_document_version(): void
    {
        $guard = new ArticleContentConflictGuard;
        $article = $this->articleStub([
            'body' => 'server-body',
            'document_version' => 3,
        ]);

        $fail = $guard->assertCompatible($article, [
            'expected_content_hash' => $guard->contentHash('other'),
        ]);
        self::assertNotNull($fail);
        self::assertSame('conflict_content_hash', $fail->error['code'] ?? null);
    }

    public function test_noop_ack_marks_reconciled_when_client_version_behind_same_hash(): void
    {
        $noop = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'tryDocumentNoopAck'));
        self::assertStringContainsString("'reconciled' => \$reconciled", $noop);
        self::assertStringContainsString('expectedVersion > $currentVersion', $noop);
        self::assertStringContainsString('$reconciled = true', $noop);
        self::assertStringNotContainsString('writeArticleRow', $noop);
        self::assertStringNotContainsString('->save(', $noop);
        self::assertStringNotContainsString('->update(', $noop);
    }

    public function test_save_document_passes_version_into_content_hash_assert(): void
    {
        $save = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'saveDocument'));
        self::assertStringContainsString(
            'assertContentHash($freshArticle, $expectedContentHash, $expectedDocumentVersion)',
            $save,
        );
        $noopPos = strpos($save, 'tryDocumentNoopAck');
        $assertPos = strpos($save, 'assertExpected');
        $persistPos = strpos($save, '$persist(');
        self::assertNotFalse($noopPos);
        self::assertNotFalse($assertPos);
        self::assertNotFalse($persistPos);
        self::assertTrue($noopPos < $assertPos);
        self::assertTrue($assertPos < $persistPos);
    }

    public function test_controller_stops_on_noop_before_bundle_apply(): void
    {
        $doc = $this->methodSource(new ReflectionMethod(ArticleEditorSessionController::class, 'document'));
        self::assertStringContainsString("\$payload['noop']", $doc);
        $noopPos = strpos($doc, "\$payload['noop']");
        $applyPos = strpos($doc, 'bundleApply->apply');
        self::assertNotFalse($noopPos);
        self::assertNotFalse($applyPos);
        self::assertTrue($noopPos < $applyPos);
    }

    public function test_observer_skips_double_bump_when_version_already_advanced(): void
    {
        $bump = $this->methodSource(new ReflectionMethod(ArticleDocumentVersionService::class, 'bumpIfBodyChanging'));
        self::assertStringContainsString('isDirty(\'document_version\')', $bump);
        self::assertStringContainsString('bump_skipped_already_advanced', $bump);
        self::assertStringContainsString('bump_source', $bump);
    }

    public function test_finish_save_prefers_server_content_hash_ack(): void
    {
        $api = $this->js('utils/articleEditorApi.js');
        self::assertStringContainsString('export function applyEditorDocumentAck', $api);
        self::assertStringContainsString('export function logArticleEditorVersionDebug', $api);
        self::assertStringContainsString('result?.content_hash', $api);
        self::assertStringContainsString('applyEditorDocumentAck(saveResult.data)', $api);
        self::assertStringContainsString('serverContentHash || hashContent(savedHtml)', $api);
        // Must not set tokens from client HTML alone when server ACK present.
        self::assertDoesNotMatchRegularExpression(
            '/finishArticleSaveFromApi[\s\S]{0,800}const savedContentHash = hashContent\(savedHtml\)/',
            $api,
        );
    }

    public function test_session_client_acks_hash_before_return(): void
    {
        $client = $this->js('utils/editorSessionClient.js');
        self::assertStringContainsString('expected_content_hash: ackHash', $client);
        self::assertStringContainsString('__SEO_EDITOR_DOCUMENT_VERSION__', $client);
        self::assertStringContainsString('expected_document_version: this.documentVersion', $client);
    }

    public function test_explicit_save_waits_inflight_then_rebuilds_payload(): void
    {
        $queue = $this->js('utils/articleEditorSaveQueue.js');
        self::assertStringContainsString('pendingRoundPromise = activeSavePromise', $queue);
        self::assertStringContainsString('return runSingleFlightSave()', $queue);
        self::assertStringContainsString('beginExplicitEditorSave', $queue);
        $editor = $this->js('article-editor.jsx');
        self::assertStringContainsString('saveArticleViaApiSingleFlight(articleId, buildPayload', $editor);
        self::assertStringContainsString("priority: 'explicit'", $editor);
        // Active session path must prefer session document endpoint.
        $api = $this->js('utils/articleEditorApi.js');
        self::assertStringContainsString('sessionClient.saveDocument', $api);
        $legacyPos = strpos($api, '/api/seo/articles/${articleId}/save');
        $sessionPos = strpos($api, 'sessionClient.saveDocument');
        self::assertNotFalse($sessionPos);
        self::assertNotFalse($legacyPos);
        self::assertTrue($sessionPos < $legacyPos);
    }

    public function test_slug_fix_does_not_poison_content_hash_with_tiptap_export(): void
    {
        $editor = $this->js('hooks/useArticleEditorImageSlugRename.js');
        self::assertStringContainsString('Do not replace ACK content_hash with TipTap export hash', $editor);
        self::assertStringContainsString('Keep server content_hash from slug-fix ACK', $editor);
        self::assertStringNotContainsString(
            "expected_content_hash: hashContent(getExportHtml())",
            $editor,
        );
    }

    public function test_bootstrap_html_fallback_gate_is_read_only(): void
    {
        $writer = $this->methodSource(new ReflectionMethod(ArticleEditorDocumentWriter::class, 'resolveForBootstrap'));
        self::assertStringContainsString('isUsableBootstrapDocument', $writer);
        self::assertStringNotContainsString('->save(', $writer);
        self::assertStringNotContainsString('->update(', $writer);
        self::assertStringNotContainsString('writeArticleRow', $writer);
        self::assertStringNotContainsString('writeCanonicalEditorDocument', $writer);
    }

    public function test_local_draft_restore_does_not_overwrite_document_version(): void
    {
        $bootstrap = $this->js('hooks/useArticleEditorBootstrap.js');
        $restorePos = strpos($bootstrap, "decision === 'restore_local'");
        self::assertNotFalse($restorePos);
        $slice = substr($bootstrap, $restorePos, 1800);
        self::assertStringNotContainsString('__SEO_EDITOR_DOCUMENT_VERSION__ =', $slice);
        self::assertStringNotContainsString('setDocumentVersion', $slice);
        self::assertStringContainsString('setBlocks(restoredBlocks)', $slice);
    }

    public function test_acquire_syncs_window_document_version(): void
    {
        $boot = $this->js('article-editor.jsx');
        self::assertStringContainsString(
            'window.__SEO_EDITOR_DOCUMENT_VERSION__ = Math.max(1, Number(client.documentVersion)',
            $boot,
        );
        self::assertStringContainsString('client.acquire(documentVersion)', $boot);
    }

    public function test_heartbeat_does_not_mutate_document_version_authority(): void
    {
        $heartbeat = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'heartbeat'));
        self::assertStringContainsString('touchHeartbeat', $heartbeat);
        self::assertStringContainsString('document_version', $heartbeat);
        self::assertStringNotContainsString('bumpIfBodyChanging', $heartbeat);
        self::assertStringNotContainsString('->update(', $heartbeat);
        self::assertStringNotContainsString('writeArticleRow', $heartbeat);

        $client = $this->js('utils/editorSessionClient.js');
        // Client may sync version FROM heartbeat response (foreign writer) â€” must not PUT a version bump.
        self::assertStringContainsString('/heartbeat', $client);
        self::assertStringContainsString('body: JSON.stringify({})', $client);
    }

    public function test_autosave_uses_apply_editor_document_ack(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        self::assertStringContainsString('applyEditorDocumentAck(result)', $queue);
        self::assertStringContainsString("payload.save_mode = 'autosave'", $queue);
    }

    /**
     * Pure PHPUnit stub â€” no connection, no datetime cast (updated_at avoided).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function articleStub(array $attributes): SeoArticle
    {
        $article = new SeoArticle;
        $article->setRawAttributes($attributes, true);

        return $article;
    }

    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;

        return (string) file_get_contents($path);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
