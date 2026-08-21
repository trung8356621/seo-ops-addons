<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Contract: Article Editor network connectivity warning (no offline queue / storage).
 *
 * Covers required behaviors Aâ€“G as static source contracts (remote-first).
 */
final class ArticleEditorNetworkConnectivityContractTest extends TestCase
{
    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;

        return (string) file_get_contents($path);
    }

    private function blade(string $relative): string
    {
        $path = LegacyAddonPath::resolve('resources/views/').$relative;

        return (string) file_get_contents($path);
    }

    public function test_network_module_classifies_errors_and_avoids_http_as_offline(): void
    {
        $network = $this->js('utils/articleEditorNetwork.js');

        self::assertStringContainsString('export function isArticleEditorNetworkError', $network);
        self::assertStringContainsString('Failed to fetch', $network);
        self::assertStringContainsString('ERR_NETWORK', $network);
        self::assertStringContainsString('AbortError', $network);
        self::assertStringContainsString('err.response', $network);
        self::assertStringContainsString('sessionError', $network);
        // HTTP status present â‡’ not network offline.
        self::assertStringContainsString('status > 0', $network);
    }

    public function test_api_fetch_emits_network_failure_only_on_throw(): void
    {
        $api = $this->js('utils/seoArticleApi.js');

        self::assertStringContainsString('emitArticleEditorNetworkFailure', $api);
        self::assertStringContainsString('emitArticleEditorNetworkHttpOk', $api);
        self::assertStringContainsString('catch (error)', $api);
        self::assertStringContainsString('throw error', $api);
    }

    public function test_monitor_uses_browser_events_single_verify_no_polling(): void
    {
        $network = $this->js('utils/articleEditorNetwork.js');

        self::assertStringContainsString("addEventListener('offline'", $network);
        self::assertStringContainsString("addEventListener('online'", $network);
        self::assertStringContainsString('verifyPromise', $network);
        self::assertStringContainsString('reconnectAutosaveRevision', $network);
        self::assertStringNotContainsString('setInterval', $network);
        self::assertStringNotContainsString('localStorage', $network);
        self::assertStringNotContainsString('sessionStorage', $network);
        self::assertStringNotContainsString('indexedDB', $network);
        self::assertStringNotContainsString('IndexedDB', $network);
    }

    public function test_verify_reuses_edit_lease_renew_or_seo_summary(): void
    {
        $network = $this->js('utils/articleEditorNetwork.js');

        self::assertStringContainsString('/edit-lease/', $network);
        self::assertStringNotContainsString('/heartbeat', $network);
        self::assertStringContainsString('/editor/settings', $network);
        self::assertStringNotContainsString('/editor/seo-summary', $network);
        self::assertStringContainsString('verifyArticleEditorBackendReachable', $network);
    }

    public function test_hook_is_single_owner_and_cleans_up(): void
    {
        $hook = $this->js('hooks/useArticleEditorNetworkConnectivity.js');

        self::assertStringContainsString('createArticleEditorNetworkMonitor', $hook);
        self::assertStringContainsString('monitor.destroy()', $hook);
        self::assertStringContainsString('__seoEditorNetworkMonitor', $hook);
    }

    public function test_editor_blocks_autosave_spam_and_updates_status_labels(): void
    {
        $editor = $this->js('components/SeoArticleEditor.jsx');
        $i18n = $this->js('utils/i18n.js');

        self::assertStringContainsString('useArticleEditorNetworkConnectivity', $editor);
        self::assertStringContainsString('networkUnavailableRef.current', $editor);
        self::assertStringContainsString('isArticleEditorNetworkError', $editor);
        self::assertStringContainsString('markRecoveringClear', $editor);
        self::assertStringContainsString('immediate: true', $editor);
        self::assertStringContainsString("t('editor_network_offline_unsaved')", $editor);
        self::assertStringContainsString("t('editor_network_reconnected_saving')", $editor);

        self::assertStringContainsString('ChÆ°a lÆ°u â€” máº¥t káº¿t ná»‘i', $i18n);
        self::assertStringContainsString('ÄÃ£ káº¿t ná»‘i láº¡i â€” Ä‘ang lÆ°u thay Ä‘á»•iâ€¦', $i18n);
        self::assertStringContainsString('Máº¥t káº¿t ná»‘i máº¡ng â€” cÃ¡c thay Ä‘á»•i hiá»‡n chÆ°a Ä‘Æ°á»£c lÆ°u.', $i18n);
        self::assertStringContainsString('KhÃ´ng thá»ƒ lÆ°u khi Ä‘ang máº¥t káº¿t ná»‘i.', $i18n);
    }

    public function test_banner_and_sync_disable_wired_in_shell(): void
    {
        $edit = $this->blade('filament/resources/article-resource/pages/edit-article.blade.php');
        $actions = $this->blade('filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php');
        $sticky = $this->js('utils/articleEditorStickyHeader.js');
        $shell = $this->js('article-editor.jsx');

        self::assertStringContainsString('data-seo-network-banner', $edit);
        self::assertStringContainsString('Máº¥t káº¿t ná»‘i máº¡ng â€” cÃ¡c thay Ä‘á»•i hiá»‡n chÆ°a Ä‘Æ°á»£c lÆ°u.', $edit);
        self::assertStringContainsString('__SEO_EDITOR_NETWORK_STATUS__?.unavailable', $edit);
        self::assertStringContainsString('KhÃ´ng thá»ƒ lÆ°u khi Ä‘ang máº¥t káº¿t ná»‘i.', $edit);

        self::assertStringContainsString('editorNetworkAvailable', $actions);
        self::assertStringContainsString('canSyncDocument', $actions);
        self::assertStringContainsString('article-editor:network-status', $actions);

        self::assertStringContainsString('ARTICLE_EDITOR_NETWORK_STATUS_EVENT', $sticky);
        self::assertStringContainsString('data-seo-network-banner', $sticky);

        self::assertStringContainsString('__SEO_EDITOR_NETWORK_STATUS__?.unavailable', $shell);
        self::assertStringContainsString('KhÃ´ng thá»ƒ lÆ°u khi Ä‘ang máº¥t káº¿t ná»‘i.', $shell);
    }

    public function test_seo_settings_lazy_load_is_shared_without_summary_score(): void
    {
        $lazy = $this->js('utils/articleEditorSeoLazy.js');
        $boot = $this->js('article-editor.jsx');
        $hook = $this->js('hooks/useArticleEditorSeoAndLinksState.js');

        self::assertStringContainsString('loadArticleEditorSeoSettings', $lazy);
        self::assertStringContainsString('seoArticleApiFetch(settingsUrl)', $lazy);
        self::assertStringNotContainsString('seoSummaryUrl', $lazy);
        self::assertStringNotContainsString('signal', $lazy);

        self::assertStringContainsString('loadArticleEditorSeoSettings', $boot);
        self::assertStringNotContainsString('seo-editor-seo-summary-loaded', $boot);
        self::assertStringNotContainsString('idleController.abort()', $boot);

        self::assertStringContainsString('seo-editor-seo-settings-loaded', $hook);
        self::assertStringNotContainsString('seo-summary', $hook);
    }

    public function test_no_offline_queue_or_new_storage_in_network_files(): void
    {
        $files = [
            $this->js('utils/articleEditorNetwork.js'),
            $this->js('hooks/useArticleEditorNetworkConnectivity.js'),
            $this->js('utils/articleEditorStickyHeader.js'),
        ];

        foreach ($files as $source) {
            self::assertStringNotContainsString('localStorage', $source);
            self::assertStringNotContainsString('sessionStorage', $source);
            self::assertStringNotContainsString('indexedDB', $source);
            self::assertStringNotContainsString('serviceWorker', $source);
            self::assertStringNotContainsString('offline queue', $source);
        }
    }
}
