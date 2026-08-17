<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Autosave protection must not become a data-loss event.
 *
 * Case A: blocked autosave keeps editor + local recovery
 * Case B: F5 can restore the local recovery
 * Case C: stale successful save does not clear a newer recovery
 * Case D: stale autosave ACK is ignored
 * Case E: intermediate image mutation is not sent to server
 * Case F: corrupt local recovery does not crash bootstrap
 */
final class ArticleEditorLocalRecoveryGuardTest extends TestCase
{
    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_case_a_blocked_autosave_still_writes_local_recovery(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        $guard = $this->js('utils/articleEditorSaveGuard.js');
        $i18n = $this->js('utils/i18n.js');

        self::assertStringContainsString('persistLocalRecoverySnapshot', $queue);
        self::assertStringContainsString('notifyProtectedBlock', $queue);
        self::assertStringContainsString("setSaveStatus('blocked')", $queue);
        self::assertStringContainsString('SAVE_FAILURE.PROTECTED_BLOCK', $queue);
        self::assertStringContainsString('inspectWritableDocument', $guard);
        self::assertStringContainsString('inline_whitespace_corruption', $guard);
        self::assertStringNotContainsString('Reload without saving', $i18n);
        self::assertStringNotContainsString('Do not Save the broken version', $i18n);
        self::assertStringContainsString('Đã giữ bản tạm', $i18n);
        self::assertStringContainsString('editor_save_blocked_local', $i18n);

        $scheduleAutosave = $this->methodLike($queue, 'const scheduleAutosave = useCallback');
        self::assertStringNotContainsString('assertWritableDocumentNotWhitespaceCorrupted', $scheduleAutosave);
        self::assertStringContainsString('persistLocalRecoverySnapshot', $this->methodLike($queue, 'const persistLocalRecoverySnapshot'));
    }

    public function test_case_b_reload_keeps_dirty_recovery_and_offers_restore(): void
    {
        $bootstrap = $this->js('hooks/useArticleEditorBootstrap.js');
        $storage = $this->js('utils/articleEditorStorage.js');

        self::assertStringContainsString('shouldPromptRestore', $bootstrap);
        self::assertStringContainsString('setDraftChoiceModalOpen(true)', $bootstrap);
        self::assertStringContainsString('sameRevisionContinuation', $bootstrap);
        self::assertStringContainsString('Keep dirty recovery', $bootstrap);
        self::assertStringContainsString('JSON.parse(raw)', $storage);
        self::assertStringContainsString('localStorage.removeItem(storageKey)', $storage);
    }

    public function test_case_c_successful_save_clears_only_matching_recovery(): void
    {
        $api = $this->js('utils/articleEditorApi.js');
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        $guard = $this->js('utils/articleEditorSaveGuard.js');

        self::assertStringContainsString('shouldClearLocalRecoveryAfterSave', $api);
        self::assertStringContainsString('__seoFlushArticleRecoveryDraft', $api);
        self::assertStringContainsString('writeSyncedLocalSnapshot', $queue);
        self::assertStringContainsString('currentMatchesSaved', $queue);
        self::assertStringContainsString('export function shouldClearLocalRecoveryAfterSave', $guard);
        self::assertStringContainsString('contentsMeaningfullyEqual', $guard);
    }

    public function test_case_d_stale_autosave_response_does_not_ack_newer_state(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');

        self::assertStringContainsString('const seq = ++serverAutosaveSeqRef.current', $queue);
        self::assertMatchesRegularExpression(
            '/if \(seq !== serverAutosaveSeqRef\.current\) \{\s*serverAutosaveDirtyRef\.current = true;\s*return;\s*\}/',
            $queue,
        );
    }

    public function test_case_e_mutation_lock_skips_server_serialize(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        $guard = $this->js('utils/articleEditorSaveGuard.js');
        $images = $this->js('hooks/useArticleEditorImageGeneration.js');

        self::assertStringContainsString('isArticleAutosaveLocked()', $queue);
        self::assertStringContainsString('GUARD_REASON.MUTATION_IN_PROGRESS', $queue);
        self::assertStringContainsString('isUnstableHollowExport', $queue);
        self::assertStringContainsString('GUARD_REASON.CONTENT_TRUNCATED', $queue);
        self::assertStringContainsString("setArticleAutosaveLock('image-insert', true)", $images);
        self::assertStringContainsString("setArticleAutosaveLock('image-insert', false)", $images);
        self::assertStringContainsString('export function isUnstableHollowExport', $guard);
    }

    public function test_case_f_corrupt_recovery_is_discarded_without_throw(): void
    {
        $storage = $this->js('utils/articleEditorStorage.js');
        self::assertStringContainsString('JSON.parse(raw)', $storage);
        self::assertStringContainsString('return null', $storage);
        self::assertStringContainsString('localStorage.removeItem(storageKey)', $storage);
        self::assertStringContainsString('fallthrough to legacy migration below', $storage);
    }

    public function test_local_recovery_debounce_is_faster_than_server_autosave(): void
    {
        $guard = $this->js('utils/articleEditorSaveGuard.js');
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        $editor = $this->js('components/SeoArticleEditor.jsx');

        self::assertStringContainsString('LOCAL_RECOVERY_DEBOUNCE_MS = 800', $guard);
        self::assertStringContainsString('resolveLocalRecoveryDebounceMs', $queue);
        self::assertStringContainsString('serverAutosaveDebounceMs', $editor);
        self::assertStringContainsString('4000', $editor);
    }

    public function test_save_status_ui_distinguishes_protected_block(): void
    {
        $bridge = $this->js('hooks/useArticleEditorExternalEventsBridge.js');
        $css = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/css/article-edit-page.css',
        );

        self::assertStringContainsString("saveStatus === 'blocked'", $bridge);
        self::assertStringContainsString("saveStatus === 'conflict'", $bridge);
        self::assertStringContainsString('editor_save_blocked_local', $bridge);
        self::assertStringContainsString('[data-status="blocked"]', $css);
    }

    public function test_instrumentation_logs_guard_reason_without_body_dump(): void
    {
        $guard = $this->js('utils/articleEditorSaveGuard.js');
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');

        self::assertStringContainsString("console.info('[article-editor-save-guard]'", $guard);
        self::assertStringContainsString('guard_reason', $queue);
        self::assertStringContainsString('request_sequence', $queue);
        self::assertStringContainsString('mutation_in_progress', $guard);
        self::assertStringNotContainsString('dump entire article', $guard);
    }

    public function test_whitespace_guard_still_blocks_server_save(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        $bridge = $this->js('hooks/useArticleEditorExternalEventsBridge.js');

        $server = $this->methodLike($queue, 'const scheduleServerAutosave = useCallback');
        self::assertStringContainsString('assertWritableDocumentNotWhitespaceCorrupted', $server);
        self::assertStringContainsString('persistLocalRecoverySnapshot', $server);
        self::assertStringContainsString('INLINE_WHITESPACE_CORRUPTION_CODE', $bridge);
        self::assertStringContainsString('__seoFlushArticleRecoveryDraft', $bridge);
    }

    /**
     * @return string
     */
    private function methodLike(string $source, string $startNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start, $startNeedle);

        return substr($source, $start, 2200);
    }
}
