<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\ArticleWpContentCacheService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Contract: articles.body is editor SoT; WP cache is HTTP optimization only.
 */
final class ArticleWpContentLifecycleContractTest extends TestCase
{
    public function test_cache_service_ttl_is_seven_days(): void
    {
        self::assertSame(7, ArticleWpContentCacheService::TTL_DAYS);
    }

    public function test_content_service_exposes_hydrate_and_keeps_cache_as_optimization(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/WordPressArticleContentService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('hydrateEditorBodyFromWordPress', $src);
        self::assertStringContainsString('resolveEditorHtmlDetailed', $src);
        self::assertStringContainsString('resolveEditorHtmlLocalOnly', $src);
        self::assertStringContainsString('ArticleWpContentCacheService', $src);
        self::assertStringContainsString("'source' => 'wp_cache'", $src);
        self::assertStringContainsString("'source' => 'wp_fetch'", $src);
        self::assertStringContainsString("'source' => 'wp_fetch_failed'", $src);
        self::assertStringContainsString('writeLegacyHtmlAndInvalidateDocument', $src);
        self::assertStringContainsString('pending_wp_fetch', $src);
        self::assertStringNotContainsString("meta_key' => 'wp_post_content'", $src);
    }

    public function test_edit_article_auto_hydrate_persists_body(): void
    {
        $path = dirname(__DIR__, 3)
            .'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('hydrateEditorBodyFromWordPress', $src);
        self::assertStringContainsString('loadWpEditorHtmlFromWordPress', $src);
        self::assertStringContainsString("'persisted'", $src);
        self::assertStringNotContainsString('Intentionally do NOT persist into articles.body', $src);
    }

    public function test_persist_always_writes_body_html(): void
    {
        $path = dirname(__DIR__, 3).'/content/src/Services/ArticleEditorPersistService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString("'body' => \$html", $src);
        self::assertStringNotContainsString('keepBodyNull', $src);
        self::assertStringContainsString('forget($article)', $src);
    }

    public function test_force_overwrite_pull_persists_prepared_body(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/SyncDomainContentService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString("'body' => \$htmlForBody !== '' ? \$htmlForBody : null", $src);
        self::assertStringContainsString('wp_force_overwrite_pull', $src);
        self::assertStringNotContainsString("// Body null is valid for WP-synced articles until editor opens", $src);
    }

    public function test_wp_sync_success_clears_body_and_cache(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/WordPressArticleSyncService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('ArticleWpContentCacheService', $src);
        self::assertStringContainsString("update(['body' => null])", $src);
        self::assertStringContainsString('no_local_unsynced_body', $src);
        self::assertStringContainsString('hadLocalUnsyncedBody', $src);
    }

    public function test_skip_editor_sync_when_no_local_body(): void
    {
        $path = dirname(__DIR__, 2).'/src/Services/WordPressArticleSyncService.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("'reason' => 'no_local_unsynced_body'", $src);
        self::assertSame(WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT, 'wp_editor_sync_fingerprint');
    }

    public function test_destructive_readers_skip_wp_backed_empty_body(): void
    {
        $linkMap = (string) file_get_contents(
            dirname(__DIR__, 3).'/content/src/Services/ArticleLinkContextMapService.php',
        );
        $toc = (string) file_get_contents(
            dirname(__DIR__, 3).'/content/src/Services/ArticleTocExtractionService.php',
        );

        self::assertStringContainsString('WP is canonical', $linkMap);
        self::assertStringContainsString('keep existing TOC', $toc);
    }

    public function test_migration_and_purge_command_exist(): void
    {
        $migration = dirname(__DIR__, 2)
            .'/database/migrations/2026_08_31_140000_create_article_wp_content_cache_table.php';
        $command = dirname(__DIR__, 2).'/src/Console/PurgeExpiredArticleWpContentCacheCommand.php';

        self::assertFileExists($migration);
        self::assertFileExists($command);
        $migrationSrc = (string) file_get_contents($migration);
        self::assertStringContainsString('article_wp_content_cache', $migrationSrc);
        self::assertStringNotContainsString('wp_post_content', $migrationSrc);
    }

    public function test_preflight_modal_sticky_shell(): void
    {
        $modal = dirname(__DIR__, 3)
            .'/seo-content-ai-compat/resources/views/filament/resources/domain-resource/pages/partials/site-sync-preflight-modal.blade.php';
        $src = (string) file_get_contents($modal);
        self::assertStringContainsString('site-sync-preflight__shell', $src);
        self::assertStringContainsString('site-sync-preflight__body', $src);
        self::assertStringContainsString('site-sync-preflight__header', $src);
        self::assertStringContainsString('site-sync-preflight__footer', $src);
        self::assertStringContainsString('max-height: calc(100dvh - 24px)', $src);
        self::assertStringContainsString('overflow-y: auto', $src);
        self::assertStringContainsString('overscroll-behavior: contain', $src);
        self::assertStringContainsString('flex-shrink: 0', $src);
        self::assertStringContainsString('min-height: 0', $src);
        self::assertStringNotContainsString('max-h-[85vh]', $src);
    }
}
