<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\WordPress\Extension\WordPressPublisher;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSyncController;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectWorkspaceSaveService;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: Content Project editor Save = Laravel-only; close only after proven persist;
 * Published â‰  latest local; Publish Now updates existing WP post.
 */
final class ContentProjectEditorLocalSaveTest extends TestCase
{
    public function test_manual_sync_fail_closed_for_content_project_articles(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );

        self::assertStringContainsString('belongsToContentProject', $source);
        self::assertStringContainsString('content_project_manual_sync_forbidden', $source);
        self::assertStringContainsString('PostPublishWordPressSyncEligibility', $source);
        self::assertStringContainsString('syncPublishedFromEditorBundle', $source);
        self::assertStringNotContainsString('workspaceSave->saveFromEditorBundle', $source);
    }

    public function test_workspace_save_returns_canonical_project_local_save_result(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceSaveService::class))->getFileName(),
        );

        self::assertStringContainsString("SAVE_MODE = 'project_local_save'", $source);
        self::assertStringContainsString("DB::connection('omi_seo_ai')->transaction", $source);
        self::assertStringContainsString('KhÃ´ng bá»c content.update trong TX dÃ i', $source);
        self::assertStringContainsString('persist_hash_mismatch', $source);
        self::assertStringContainsString('content_hash', $source);
        self::assertStringContainsString('project_task_id', $source);
        self::assertStringContainsString("'queued' => false", $source);
        self::assertStringContainsString("'wp_api_called' => false", $source);
        self::assertStringContainsString('markLocalEditPending', $source);
        self::assertStringContainsString('rememberLocalContentHash', $source);
        self::assertStringContainsString("'close_editor' => false", $source);
        self::assertStringContainsString("'close_editor' => true", $source);
        self::assertStringNotContainsString('ManualWordPressSyncJob', $source);
        self::assertStringNotContainsString('enqueueManual', $source);
        self::assertStringNotContainsString('gateway->postJson', $source);
    }

    public function test_sync_wp_controller_marks_blocked_without_queue(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ArticleEditorSyncController::class, 'syncWp'),
        );

        self::assertStringContainsString("\$dispatchStatus === 'blocked'", $source);
        self::assertStringContainsString("\$result['queued'] = false", $source);
        self::assertStringContainsString("\$result['close_editor'] = false", $source);
        self::assertStringNotContainsString('enqueueManual', $source);
    }

    public function test_content_project_editor_hides_sync_wp_and_uses_save_close(): void
    {
        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );
        self::assertStringContainsString('articleIsInContentProject', $actions);
        self::assertStringContainsString('page_action_save_close_label', $actions);
        self::assertStringContainsString('data-seo-page-action="save-close"', $actions);
        self::assertStringContainsString('data-seo-content-project-url', $actions);
        self::assertStringContainsString("action: 'save-close'", $actions);
        self::assertStringNotContainsString('project_local_save', $actions);
        // Unpublished CP still save-close; Published CP may also show post_publish sync.
        self::assertStringContainsString('postPublishWpSyncEligible', $actions);
        self::assertStringContainsString('data-seo-sync-mode="wordpress_sync"', $actions);

        $editorEntry = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );
        self::assertStringContainsString("action === 'save-close'", $editorEntry);
        self::assertStringContainsString('closeEditorAfterProjectLocalSave', $editorEntry);
        self::assertStringContainsString('saveArticleViaApiSingleFlight', $editorEntry);
        self::assertStringContainsString("normalizedAction === 'save-close'", $editorEntry);
    }

    public function test_has_unpublished_changes_ignores_stale_wp_post_id_alone(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleWordPressSyncFlagService::class))->getFileName(),
        );

        self::assertStringContainsString('META_LOCAL_CONTENT_HASH', $source);
        self::assertStringContainsString('META_PUBLISHED_CONTENT_HASH', $source);
        self::assertStringContainsString('function hasUnpublishedChanges', $source);
        self::assertStringContainsString('publishedContentHash', $source);
        self::assertStringContainsString('wp_post_id alone is insufficient', $source);

        $method = $this->methodSource(
            new ReflectionMethod(ArticleWordPressSyncFlagService::class, 'hasUnpublishedChanges'),
        );
        self::assertStringContainsString('hasLocalEditPending', $method);
        self::assertStringContainsString('hash_equals', $method);
    }

    public function test_published_item_with_dirty_local_never_shows_publish_now_in_cp(): void
    {
        // Publish Now moved to Publishing Queue module â€” CP presenter always keeps
        // it false (dirty-published items are still owned by PQ, not CP ops UI).
        $clean = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'published',
            'has_unpublished_changes' => false,
            'article_edit_url' => '/seo/articles/1/edit',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'is_improve' => false,
            'is_scheduled' => false,
        ]);
        self::assertFalse($clean['publish_now']);

        $dirty = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'published',
            'has_unpublished_changes' => true,
            'article_edit_url' => '/seo/articles/1/edit',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'is_improve' => false,
            'is_scheduled' => false,
        ]);
        self::assertFalse($dirty['publish_now']);
    }

    public function test_publish_now_allows_published_to_waiting_for_wp_update(): void
    {
        $guard = new ContentProjectPublishTransitionGuard();
        $guard->assertCanTransition(
            ContentProjectPublishQueueStatus::Published,
            ContentProjectPublishQueueStatus::Waiting,
        );

        $enqueue = $this->methodSource(
            new ReflectionMethod(ContentProjectPublishingQueueService::class, 'enqueueExplicitPublish'),
        );
        self::assertStringNotContainsString(
            'ContentProjectPublishQueueStatus::Published'."\n".'                || $from === ContentProjectPublishQueueStatus::Processing',
            $enqueue,
        );
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Processing', $enqueue);
        self::assertStringContainsString('update existing WP post', $enqueue);
    }

    public function test_wordpress_publisher_requests_delivery_for_existing_wp_post(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressPublisher::class))->getFileName(),
        );

        self::assertStringContainsString('Publish update delivery requested', $source);
        self::assertStringContainsString('deliveryRequested: true', $source);
        self::assertStringContainsString('Updated existing WordPress post', $source);
        self::assertStringNotContainsString('Already published (wp_post_id present)', $source);
    }

    public function test_publish_handler_defers_hash_clear_until_wp_success_on_delivery(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ProcessScheduledProjectItemPublishHandler::class, 'processPublish'),
        );

        self::assertStringContainsString('deliveryRequested', $source);
        self::assertStringContainsString('do NOT clear has_unpublished_changes', $source);

        $deliveryBlock = $this->extractBetween(
            $source,
            'if ($publishResult->deliveryRequested) {',
            'return ContentProjectActionResult::ok(',
        );
        self::assertStringNotContainsString('rememberPublishedContentHash', $deliveryBlock);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    private function extractBetween(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertNotFalse($from, 'start marker missing: '.$start);
        $to = strpos($haystack, $end, $from);
        self::assertNotFalse($to, 'end marker missing: '.$end);

        return substr($haystack, $from, $to - $from);
    }
}
