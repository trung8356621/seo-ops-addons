<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Enums\ArticleEditorSessionStatus;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSessionController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSyncController;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleEditorSession;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 1 editor session lock + document_version contracts (source + pure symbols).
 */
final class ArticleEditorSessionLockContractTest extends TestCase
{
    public function test_error_codes_cover_protocol(): void
    {
        self::assertSame('article_editor_locked', ArticleEditorSessionErrorCode::LOCKED);
        self::assertSame('article_editor_session_not_found', ArticleEditorSessionErrorCode::SESSION_NOT_FOUND);
        self::assertSame('article_editor_session_expired', ArticleEditorSessionErrorCode::SESSION_EXPIRED);
        self::assertSame('article_editor_session_revoked', ArticleEditorSessionErrorCode::SESSION_REVOKED);
        self::assertSame('article_editor_session_taken_over', ArticleEditorSessionErrorCode::SESSION_TAKEN_OVER);
        self::assertSame('article_editor_lock_not_owned', ArticleEditorSessionErrorCode::LOCK_NOT_OWNED);
        self::assertSame('article_document_version_conflict', ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT);
        self::assertSame('article_content_hash_conflict', ArticleEditorSessionErrorCode::CONTENT_HASH_CONFLICT);
        self::assertSame('article_not_editable', ArticleEditorSessionErrorCode::NOT_EDITABLE);
        self::assertSame('content_project_archived', ArticleEditorSessionErrorCode::CONTENT_PROJECT_ARCHIVED);
        self::assertSame('takeover_forbidden', ArticleEditorSessionErrorCode::TAKEOVER_FORBIDDEN);
    }

    public function test_session_status_enum_values(): void
    {
        self::assertSame('active', ArticleEditorSessionStatus::Active->value);
        self::assertSame('released', ArticleEditorSessionStatus::Released->value);
        self::assertSame('expired', ArticleEditorSessionStatus::Expired->value);
        self::assertSame('revoked', ArticleEditorSessionStatus::Revoked->value);
        self::assertSame('taken_over', ArticleEditorSessionStatus::TakenOver->value);
        self::assertTrue(ArticleEditorSessionStatus::Released->isTerminal());
        self::assertFalse(ArticleEditorSessionStatus::Active->isTerminal());
    }

    public function test_migrations_define_document_version_and_sessions_table(): void
    {
        $migrationRoot = ProjectRoot::addonsPath().'/content/database/migrations';
        $versionMigration = (string) file_get_contents($migrationRoot.'/2026_08_02_140000_add_document_version_to_articles_table.php');
        $sessionMigration = (string) file_get_contents($migrationRoot.'/2026_08_02_140100_create_article_editor_sessions_table.php');

        self::assertStringContainsString("document_version", $versionMigration);
        self::assertStringContainsString("unsignedBigInteger('document_version')->default(1)", $versionMigration);
        self::assertStringContainsString("article_editor_sessions", $sessionMigration);
        self::assertStringContainsString("->uuid('id')->primary()", $sessionMigration);
        self::assertStringContainsString('client_instance_id', $sessionMigration);
        self::assertStringContainsString('heartbeat_at', $sessionMigration);
        self::assertStringContainsString('expires_at', $sessionMigration);
        self::assertStringContainsString('takeover_by_user_id', $sessionMigration);
    }

    public function test_session_service_exposes_protocol_methods(): void
    {
        $class = new ReflectionClass(ArticleEditorSessionService::class);
        foreach ([
            'acquire',
            'heartbeat',
            'saveDocument',
            'close',
            'release',
            'takeover',
            'revokeActiveSessionsForArticles',
            'findActiveSession',
            'userCanTakeover',
            'assertArticleEditable',
        ] as $method) {
            self::assertTrue($class->hasMethod($method), $method);
        }

        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString('ActionSupport::withArticleLock', $source);
        self::assertStringContainsString('ArticleEditorSessionStatus::TakenOver', $source);
        self::assertStringContainsString('content_project_archived', $source);
        self::assertStringContainsString('lockTtlSeconds', $source);
    }

    public function test_atomic_close_releases_only_after_persist_success(): void
    {
        $method = $this->methodSource(new ReflectionMethod(ArticleEditorSessionService::class, 'close'));
        $persistPos = strpos($method, '$persist(');
        $releasePos = strpos($method, 'ArticleEditorSessionStatus::Released');
        self::assertNotFalse($persistPos);
        self::assertNotFalse($releasePos);
        self::assertTrue($persistPos < $releasePos, 'release must follow persist');
        self::assertStringContainsString('if (! ($result[\'success\'] ?? false))', $method);
    }

    public function test_document_version_service_bumps_on_body_change(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleDocumentVersionService::class))->getFileName(),
        );
        self::assertStringContainsString('bumpIfBodyChanging', $source);
        self::assertStringContainsString("isDirty('body')", $source);
        self::assertStringContainsString('assertExpected', $source);
        self::assertStringContainsString('DOCUMENT_VERSION_CONFLICT', $source);
    }

    public function test_seo_article_observer_bumps_document_version(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SeoArticle::class))->getFileName());
        self::assertStringContainsString('document_version', $source);
        self::assertStringContainsString('bumpIfBodyChanging', $source);
        self::assertStringContainsString('ensureDefaultOnCreate', $source);
        self::assertStringContainsString("protected static function booted()", $source);
    }

    public function test_conflict_guard_checks_document_version_first(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Support/ArticleContentConflictGuard.php',
        );
        self::assertStringContainsString('expected_document_version', $source);
        self::assertStringContainsString('conflict_document_version', $source);
        self::assertTrue(
            strpos($source, 'expected_document_version') < strpos($source, 'expected_updated_at'),
        );
    }

    public function test_legacy_save_blocks_active_session_bypass(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorSyncController::class))->getFileName(),
        );
        self::assertStringContainsString('rejectLegacySaveWhenSessionActive', $source);
        self::assertStringContainsString('X-Editor-Session-Id', $source);
        self::assertStringContainsString('ArticleEditorSessionErrorCode::LOCKED', $source);
        self::assertStringContainsString('423', $source);
    }

    public function test_session_controller_routes_and_methods_exist(): void
    {
        $class = new ReflectionClass(ArticleEditorSessionController::class);
        foreach (['store', 'heartbeat', 'document', 'close', 'destroy', 'takeover'] as $method) {
            self::assertTrue($class->hasMethod($method), $method);
        }

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'));
        self::assertStringContainsString("editor-sessions", $provider);
        self::assertStringContainsString('ArticleEditorSessionController::class', $provider);
        self::assertStringContainsString('seo.articles.editor-sessions.store', $provider);
        self::assertStringContainsString('seo.articles.editor-sessions.close', $provider);
        self::assertStringContainsString('seo.articles.editor-sessions.takeover', $provider);
    }

    public function test_archive_revokes_editor_sessions(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArchiveContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('ArticleEditorSessionService', $source);
        self::assertStringContainsString('revokeActiveSessionsForArticles', $source);
        self::assertStringContainsString('content_project_archived', $source);
    }

    public function test_frontend_session_client_and_user_scoped_draft(): void
    {
        $client = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorSessionClient.js',
        );
        self::assertStringContainsString('class EditorSessionClient', $client);
        self::assertStringContainsString('getOrCreateClientInstanceId', $client);
        self::assertStringContainsString('sessionStorage', $client);
        self::assertStringContainsString('/editor-sessions', $client);
        self::assertStringContainsString('expected_document_version', $client);

        $storage = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorStorage.js',
        );
        self::assertStringContainsString('ARTICLE_EDITOR_DRAFT_VERSION = 3', $storage);
        self::assertStringContainsString('resolveDraftUserId', $storage);
        self::assertStringContainsString('base_document_version', $storage);
        self::assertStringContainsString('${user}:${articleId}', $storage);

        $entry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString('ArticleEditorWithSession', $entry);
        self::assertStringContainsString('EditorSessionClient', $entry);
        self::assertStringContainsString('closeArticleViaSessionApi', $entry);
        self::assertStringContainsString('ExclusiveLockScreen', $entry);
        self::assertStringNotContainsString('EditorSessionLockBanner', $entry);
        self::assertStringNotContainsString("t('editor_locked_takeover')", $entry);
        self::assertStringNotContainsString('editor_locked_takeover:', $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        ));

        self::assertStringContainsString('editor_locked_retry', $i18n);
        self::assertStringContainsString('Bài viết đang được chỉnh sửa', $i18n);

        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('closeArticleViaSessionApi', $api);
        self::assertStringContainsString('saveDocument', $api);
        self::assertStringContainsString('__seoEditorSessionClient', $api);
    }

    public function test_model_table_and_active_lock_helper(): void
    {
        $model = new ReflectionClass(SeoArticleEditorSession::class);
        self::assertTrue($model->hasMethod('isActiveLock'));
        $source = (string) file_get_contents((string) $model->getFileName());
        self::assertStringContainsString("article_editor_sessions", $source);
        self::assertStringContainsString('HasUuids', $source);
    }

    public function test_config_lock_ttl_defaults(): void
    {
        $config = include LegacyAddonPath::resolve('config/article_editor.php');
        self::assertIsArray($config);
        self::assertSame(240, $config['lock_ttl_seconds']);
        self::assertSame(60, $config['lease_renew_lead_seconds']);
        self::assertArrayNotHasKey('heartbeat_seconds', $config);
        self::assertSame(4000, $config['server_autosave_debounce_ms']);
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
