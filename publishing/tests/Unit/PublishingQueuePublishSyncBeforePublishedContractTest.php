<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Publishing Queue "Xuất bản" must sync latest article (+ product reviews) before Published.
 * Single / bulk / scheduled / retry converge on PublishDueItemService → same WP sync hook.
 */
final class PublishingQueuePublishSyncBeforePublishedContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $file = (string) $m->getFileName();
        $start = (int) $m->getStartLine();
        $end = (int) $m->getEndLine();
        $lines = file($file);
        self::assertNotFalse($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function test_single_bulk_scheduled_retry_converge_on_publish_due_item_service(): void
    {
        $hub = $this->readAddon('Filament/Pages/PublishingQueueHub.php');
        self::assertStringContainsString('PublishProjectItemsNowCommand', $hub);
        self::assertStringContainsString('RetryProjectItemPublishingCommand', $hub);
        self::assertStringContainsString('bulkPublishNow', $hub);
        self::assertStringNotContainsString('Bulk Sync WordPress', $hub);
        self::assertStringNotContainsString('bulkSyncWordPress', $hub);

        $due = (string) file_get_contents(
            (string) (new ReflectionClass(PublishDueItemService::class))->getFileName(),
        );
        self::assertStringContainsString('ProcessScheduledProjectItemPublishCommand', $due);
        self::assertStringContainsString('TRIGGER_PUBLISH_NOW', $due);
        self::assertStringContainsString('TRIGGER_RETRY_NOW', $due);
        self::assertStringContainsString('TRIGGER_SCHEDULER', $due);
    }

    public function test_rewrite_emits_update_existing_and_does_not_mark_published_before_delivery(): void
    {
        $handler = $this->readAddon(
            'Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php',
        );

        self::assertStringContainsString(
            "'publish_mode' => \$strategy->isImmediateUpdate() ? 'update_existing' : 'publish'",
            $handler,
        );
        self::assertStringContainsString('deliveryRequested', $handler);
        self::assertStringContainsString('Do NOT markPublished here', $handler);
        self::assertStringContainsString('already_published_force_resync', $handler);
        self::assertStringContainsString('[PUBLISH_TRACE]', $handler);
    }

    public function test_pipeline_update_existing_forces_update_published_article_only(): void
    {
        $pipeline = $this->readAddon('Services/WordPress/SyncArticleToWordPressPipeline.php');
        self::assertStringContainsString(
            "'update_existing' => \$this->articleSync->updatePublishedArticleOnly",
            $pipeline,
        );
        self::assertStringNotContainsString(
            "'update_existing' => \$this->articleSync->syncForArticle",
            $pipeline,
        );

        $updateOnly = $this->methodBody(
            \Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService::class,
            'updatePublishedArticleOnly',
        );
        self::assertStringContainsString("'force_editor_sync' => true", $updateOnly);
        self::assertStringContainsString("'create_post_called' => false", $updateOnly);
    }

    public function test_hook_runs_shared_review_sequence_before_confirm_published(): void
    {
        $action = $this->readAddon('Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php');

        self::assertStringContainsString('ArticleWordPressBusinessSequence', $action);
        self::assertStringContainsString('runCreate', $action);
        self::assertStringContainsString('runSync', $action);
        self::assertStringContainsString('confirmContentProjectPublishDelivery', $action);
        self::assertStringContainsString('PRODUCT_REVIEW_POST_SYNC_FAILED', $action);
        self::assertStringContainsString('wp_sync_skipped_forced_update', $action);
        self::assertStringContainsString('[PUBLISH_TRACE]', $action);

        $confirmPos = strpos($action, 'confirmContentProjectPublishDelivery');
        $createPos = strpos($action, '->runCreate(');
        $failPos = strpos($action, 'PRODUCT_REVIEW_POST_SYNC_FAILED');
        self::assertNotFalse($confirmPos);
        self::assertNotFalse($createPos);
        self::assertNotFalse($failPos);
        self::assertLessThan($confirmPos, $createPos, 'reviews must run before confirm/Published');
        self::assertLessThan($confirmPos, $failPos, 'product review failure must block confirm');
    }

    public function test_queue_actor_never_short_circuits_on_existing_wp_post_id(): void
    {
        $publisher = $this->methodBody(
            \Omnichannel\Addons\WordPress\Extension\WordPressPublisher::class,
            'publish',
        );
        self::assertStringContainsString('deliveryRequested: true', $publisher);
        self::assertStringContainsString('alreadyPublished: false', $publisher);
        self::assertStringContainsString('Do not short-circuit on stale wp_post_id', $publisher);
    }

    public function test_bulk_publish_ux_is_xuat_ban_only(): void
    {
        $hub = $this->readAddon('Filament/Pages/PublishingQueueHub.php');
        self::assertStringContainsString('function bulkPublishNow', $hub);
        self::assertStringContainsString('PublishProjectItemsNowCommand', $hub);
        self::assertStringNotContainsString('BulkSyncWordPress', $hub);
        self::assertStringNotContainsString('bulkSyncWordPress', $hub);
        self::assertStringNotContainsString('Bulk Sync WordPress', $hub);
    }

    public function test_dispatch_publish_request_maps_publish_mode(): void
    {
        $seeder = $this->readAddon('Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php');
        self::assertStringContainsString("'publish_mode' => '{{ payload.publish_mode }}'", $seeder);
        self::assertStringContainsString('dispatch-publish-request', $seeder);
        self::assertStringContainsString('wordpress.article.sync', $seeder);
    }
}
