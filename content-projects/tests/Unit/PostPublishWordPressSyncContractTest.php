<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSyncController;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BulkResyncPublishedArticlesToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncPublishedArticleToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SyncPublishedArticleToWordPressHandler;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressPostReconciler;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressSyncEligibility;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: post-publish editorial WP sync updates existing post only.
 * Initial create remains Publishing Queue owned.
 */
final class PostPublishWordPressSyncContractTest extends TestCase
{
    public function test_command_registered_and_distinct_from_initial_publish(): void
    {
        $registrar = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );
        self::assertStringContainsString('SyncPublishedArticleToWordPressCommand::class', $registrar);
        self::assertStringContainsString('BulkResyncPublishedArticlesToWordPressCommand::class', $registrar);

        $cmd = new SyncPublishedArticleToWordPressCommand(articleId: 9632);
        self::assertSame('publishing.sync_published_article_to_wordpress', $cmd->name());
        self::assertNotSame((new ProcessScheduledProjectItemPublishCommand(438))->name(), $cmd->name());
    }

    public function test_unpublished_content_project_still_blocks_manual_sync(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );
        self::assertStringContainsString('belongsToContentProject', $source);
        self::assertStringContainsString('syncEligibility->evaluate', $source);
        self::assertStringContainsString('MODE_REWRITE_UPDATE_EXISTING', $source);
        self::assertStringContainsString('syncRewriteExistingFromEditorBundle', $source);
        self::assertStringContainsString('syncPublishedFromEditorBundle', $source);
    }

    public function test_local_save_controller_never_calls_wordpress(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(ArticleEditorSyncController::class, 'save'),
        );
        self::assertStringContainsString('article.content.update', $source);
        self::assertStringNotContainsString('enqueueFromEditorBundle', $source);
        self::assertStringNotContainsString('updatePublishedArticleOnly', $source);
        self::assertStringNotContainsString('create_post', $source);
    }

    public function test_update_published_path_never_calls_create_post(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressArticleSyncService::class, 'updatePublishedArticleOnly'),
        );
        self::assertStringContainsString('omit_publication_fields', $source);
        self::assertStringContainsString('force_editor_sync', $source);
        self::assertStringContainsString("'create_post_called' => false", $source);
        self::assertStringNotContainsString('createForArticle', $source);
        self::assertStringNotContainsString('article.create_post', $source);
        self::assertStringNotContainsString('publishForArticle', $source);
    }

    public function test_publication_fields_omitted_from_post_publish_payload(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressArticleSyncService::class, 'buildEditorSyncPayload'),
        );
        self::assertStringContainsString("omit_publication_fields", $source);
        self::assertStringContainsString("unset(\$payload['status'], \$payload['post_date']", $source);
    }

    public function test_linked_wordpress_post_payload_sends_slug_unless_field_conflict(): void
    {
        $source = $this->methodSource(
            new ReflectionMethod(WordPressArticleSyncService::class, 'buildEditorSyncPayload'),
        );
        self::assertStringContainsString("'slug' => (string) (\$article->slug ?? '')", $source);
        self::assertStringContainsString('WordPressFieldConflictService::class', $source);
        self::assertStringContainsString("if (\$conflictField === 'slug')", $source);
        self::assertStringContainsString("unset(\$payload['slug']);", $source);
        self::assertStringNotContainsString('shouldPreserveLinkedPostSlug', $source);
        self::assertStringNotContainsString('allow_slug_update', $source);

        $slugSync = $this->methodSource(
            new ReflectionMethod(WordPressArticleSyncService::class, 'syncSlugForArticle'),
        );
        self::assertStringContainsString("'article.sync_slug'", $slugSync);
        self::assertStringContainsString("['slug' => \$slug]", $slugSync);
    }

    public function test_handler_preserves_published_queue_status_on_success_and_failure(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SyncPublishedArticleToWordPressHandler::class))->getFileName(),
        );
        self::assertStringContainsString('publish_queue_status_unchanged', $source);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Published', $source);
        self::assertStringContainsString('META_POST_PUBLISH_SYNC_ERROR', $source);
        self::assertStringContainsString('touchSynced', $source);
        self::assertStringNotContainsString('ProcessScheduledProjectItemPublish', $source);
        self::assertStringNotContainsString('retry_wait', $source);
        self::assertStringContainsString('ÄÃ£ Ä‘á»“ng bá»™ bÃ i viáº¿t lÃªn WordPress.', $source);
        self::assertStringContainsString('KhÃ´ng thá»ƒ Ä‘á»“ng bá»™ thay Ä‘á»•i lÃªn WordPress. BÃ i Ä‘Ã£ xuáº¥t báº£n váº«n Ä‘Æ°á»£c giá»¯ nguyÃªn.', $source);
    }

    public function test_missing_wp_post_id_reconciles_not_creates(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(PostPublishWordPressPostReconciler::class))->getFileName(),
        );
        self::assertStringContainsString('OUTCOME_NOT_FOUND', $source);
        self::assertStringContainsString('OUTCOME_AMBIGUOUS', $source);
        self::assertStringContainsString('KhÃ´ng tÃ¬m tháº¥y bÃ i WordPress Ä‘Ã£ xuáº¥t báº£n', $source);
        self::assertStringContainsString('TÃ¬m tháº¥y nhiá»u bÃ i WordPress phÃ¹ há»£p', $source);
        self::assertStringNotContainsString('create_post', $source);
        self::assertStringNotContainsString('createForArticle', $source);
    }

    public function test_eligibility_requires_queue_published_and_published_at(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(PostPublishWordPressSyncEligibility::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Published', $source);
        self::assertStringContainsString('publish_published_at', $source);
        self::assertStringContainsString('isActivelyPublishing', $source);
        self::assertStringContainsString("articles.status", $source);
        self::assertStringNotContainsString("'status' === 'published'", $source);
    }

    public function test_active_publisher_blocks_but_expired_lease_helper_exists(): void
    {
        $active = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingActiveProcessing::class))->getFileName(),
        );
        self::assertStringContainsString('publish_lease_expires_at', $active);
        self::assertStringContainsString('LEASE_MINUTES', $active);

        $eligibility = (string) file_get_contents(
            (string) (new ReflectionClass(PostPublishWordPressSyncEligibility::class))->getFileName(),
        );
        self::assertStringContainsString('CODE_PUBLISHER_ACTIVE', $eligibility);
        self::assertStringContainsString('isActivelyPublishing', $eligibility);
    }

    public function test_inline_strong_spacing_survives_wordpress_prepare(): void
    {
        $service = new ArticleEditorHtmlSanitizeService();
        $html = '<p>pháº£i <strong>thÃ´ng minh</strong> trong cÃ¡ch lá»±a chá»n</p>';
        $out = $service->prepareHtmlForWordPressSync($html);
        self::assertStringContainsString('pháº£i <strong>thÃ´ng minh</strong> trong', $out);
        self::assertStringNotContainsString('pháº£i<strong>thÃ´ng minh</strong>trong', $out);
    }

    public function test_article_9632_athleisure_fixture_spacing_contract(): void
    {
        // Regression fixture: article 9632 /athleisure.html â€” glued marks must not ship.
        $service = new ArticleEditorHtmlSanitizeService();
        $laravel = '<p>Athleisure pháº£i <strong>thÃ´ng minh</strong> trong cÃ¡ch lá»±a chá»n cháº¥t liá»‡u.</p>';
        $outgoing = $service->prepareHtmlForWordPressSync($laravel);
        self::assertStringContainsString('pháº£i <strong>thÃ´ng minh</strong> trong', $outgoing);
        self::assertSame(
            1,
            preg_match('/pháº£i\s+<strong>thÃ´ng minh<\/strong>\s+trong/u', $outgoing) ?: 0,
        );
    }

    public function test_bulk_resync_reports_updated_skipped_failed(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/BulkResyncPublishedArticlesToWordPressHandler.php',
        );
        self::assertStringContainsString("'updated' => \$updated", $source);
        self::assertStringContainsString("'skipped' => \$skipped", $source);
        self::assertStringContainsString("'failed' => \$failed", $source);
        self::assertStringContainsString('updated={$updated}, skipped={$skipped}, failed={$failed}', $source);

        $cmd = new BulkResyncPublishedArticlesToWordPressCommand(1, [438]);
        self::assertSame('publishing.bulk_resync_published_articles_to_wordpress', $cmd->name());
    }

    public function test_published_queue_row_offers_resync_not_publish_now(): void
    {
        $actions = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::PUBLISHED,
            'wp_permalink' => 'https://example.com/athleisure.html',
            'article_edit_url' => '/seo/articles/9632/edit',
            'publish_operation_key' => 'op-1',
        ]);
        self::assertTrue($actions['view_on_wordpress']);
        self::assertTrue($actions['resync_wordpress']);
        self::assertTrue($actions['view_sync_history']);
        self::assertFalse($actions['publish_now']);
        self::assertFalse($actions['retry_now']);
        self::assertFalse($actions['schedule']);
    }

    public function test_action_codes_exist(): void
    {
        self::assertSame(
            'publishing.published_article_wp_synced',
            ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNCED,
        );
        self::assertSame(
            'publishing.published_article_wp_sync_failed',
            ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_FAILED,
        );
    }

    public function test_editor_actions_expose_post_publish_sync_mode(): void
    {
        $actions = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php'),
        );
        self::assertStringContainsString('ArticleWordPressSyncEligibility::class', $actions);
        self::assertStringContainsString('contentProjectWpSyncEligible', $actions);
        self::assertStringContainsString('data-seo-sync-mode="{{ $wpSyncEligibility', $actions);
        self::assertStringContainsString('data-seo-page-action="save"', $actions);

        $editPage = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );
        self::assertStringContainsString('ArticleWordPressSyncEligibility::class', $editPage);
        self::assertStringContainsString('syncContentProjectEligible', $editPage);
        self::assertStringContainsString("__seoExecuteHeavyArticleAction('sync'", $editPage);
    }

    public function test_queue_status_enum_published_value_stable(): void
    {
        self::assertSame('published', ContentProjectPublishQueueStatus::Published->value);
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
}
