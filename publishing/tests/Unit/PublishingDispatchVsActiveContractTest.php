<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueAwaitingWorkerDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueuePublishingDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Dispatch vs active publisher lease — regression contracts.
 */
final class PublishingDispatchVsActiveContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_scanner_dispatch_does_not_create_publisher_lease(): void
    {
        $svc = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');
        self::assertStringContainsString('function claimForDispatch', $svc);
        self::assertStringContainsString('QueuedForDelivery', $svc);
        self::assertStringContainsString('Intentionally do NOT increment publish_attempt_count here', $svc);
        self::assertStringContainsString('publishing.dispatched', $svc);
        // claimForDispatch merges clearer (clears lease) rather than setting leaseExpiresAt.
        $dispatchPos = strpos($svc, 'function claimForDispatch');
        $beginPos = strpos($svc, 'function beginPublisherAttempt');
        self::assertNotFalse($dispatchPos);
        self::assertNotFalse($beginPos);
        $dispatchBlock = substr($svc, $dispatchPos, $beginPos - $dispatchPos);
        self::assertStringNotContainsString('leaseExpiresAt', $dispatchBlock);
    }

    public function test_dispatched_unreserved_is_queued_for_delivery_not_processing(): void
    {
        $row = [
            'publish_queue_status' => 'queued_for_delivery',
            'delivery_dispatched_at' => '2026-08-05 02:20:00',
            'publisher_started_at' => null,
            'publish_lease_expires_at' => null,
        ];
        self::assertTrue(PublishingQueueAwaitingWorkerDefinition::matches($row));
        self::assertFalse(PublishingQueuePublishingDefinition::matches($row));
        self::assertSame('awaiting_delivery', PublishingQueueStateClassifier::classify($row)['state']);

        $predicate = new PublishingActiveProcessing;
        self::assertFalse($predicate->isActivelyPublishing($row, CarbonImmutable::parse('2026-08-05 02:26:00', 'UTC')));
        self::assertTrue($predicate->isQueuedAwaitingWorker($row));
    }

    public function test_worker_start_creates_publisher_lease_and_increments_attempt(): void
    {
        $svc = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');
        self::assertStringContainsString('function beginPublisherAttempt', $svc);
        self::assertStringContainsString('publishing.publisher_started', $svc);
        $beginPos = strpos($svc, 'function beginPublisherAttempt');
        $superPos = strpos($svc, 'function supersedeDeliveryAttempt');
        self::assertNotFalse($beginPos);
        self::assertNotFalse($superPos);
        $block = substr($svc, $beginPos, $superPos - $beginPos);
        self::assertStringContainsString('leaseExpiresAt', $block);
        self::assertStringContainsString("'publish_attempt_count'", $block);
        self::assertStringContainsString('publisher_started_at', $block);
    }

    public function test_queue_backlog_does_not_consume_publish_retries(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 02:26:48', 'UTC');
        // Item 438-style: processing + lease but no publisher_started_at after migration columns.
        $item438 = [
            'publish_queue_status' => 'processing',
            'publish_attempt_count' => 3,
            'publish_lease_expires_at' => '2026-08-05 02:27:51',
            'publisher_started_at' => null,
            'delivery_dispatched_at' => '2026-08-05 02:22:50',
        ];
        self::assertFalse($predicate->isActivelyPublishing($item438, $now));
        self::assertTrue($predicate->isQueuedAwaitingWorker($item438, $now));
        self::assertSame('queued_awaiting_worker', $predicate->classifyStaleReason($item438, $now));
    }

    public function test_awaiting_worker_timeout_is_delivery_worker_stalled(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 02:40:00', 'UTC');
        $stalled = [
            'publish_queue_status' => 'queued_for_delivery',
            'delivery_dispatched_at' => '2026-08-05 02:20:00',
            'publisher_started_at' => null,
        ];
        self::assertTrue($predicate->isDeliveryWorkerStalled($stalled, $now));
        self::assertSame('queued_worker_stalled', $predicate->classifyStaleReason($stalled, $now));
        self::assertSame(10, PublishingRetryPolicy::AWAITING_WORKER_MINUTES);

        $code = $this->readAddon('Enums/OperationalNotificationEventCode.php');
        self::assertStringContainsString('PublishingDeliveryWorkerStalled', $code);
        self::assertStringContainsString('publishing.delivery_worker_stalled', $code);
    }

    public function test_active_real_publisher_lease_remains_protected(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 02:26:00', 'UTC');
        $active = [
            'publish_queue_status' => 'processing',
            'publisher_started_at' => '2026-08-05 02:25:00',
            'publish_lease_expires_at' => '2026-08-05 02:30:00',
            'publish_attempt_count' => 1,
        ];
        self::assertTrue($predicate->isActivelyPublishing($active, $now));
        self::assertSame('active_real_publisher', $predicate->classifyStaleReason($active, $now));
        self::assertTrue(PublishingQueuePublishingDefinition::matches($active));
    }

    public function test_ui_publishing_means_publisher_worker_started(): void
    {
        self::assertFalse(PublishingQueuePublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => null,
        ]));
        self::assertTrue(PublishingQueuePublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => '2026-08-05 02:25:00',
        ]));
    }

    public function test_wp_sync_verifies_attempt_token_before_publish(): void
    {
        $src = $this->readAddon('Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php');
        self::assertStringContainsString('beginPublisherAttempt', $src);
        self::assertStringContainsString('superseded', $src);
        self::assertStringContainsString('publish_attempt_token', $src);
    }

    public function test_schedule_report_and_recover_summary_contracts(): void
    {
        $svc = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');
        self::assertStringContainsString('function scheduleWithReport', $svc);
        self::assertStringContainsString('skipped_active', $svc);
        self::assertStringContainsString('cancelled_pending', $svc);
        self::assertStringContainsString('function supersedeDeliveryAttempt', $svc);

        $recover = $this->readAddon('Services/ContentProject/Publishing/PublishingStuckRecoveryService.php');
        self::assertStringContainsString('skipped_ids', $recover);
        self::assertStringContainsString('nearest_lease_expires_at', $recover);
        self::assertStringContainsString('force', $recover);

        $handler = $this->readAddon('Services/ContentProject/Application/Handlers/RecoverStuckPublishingHandler.php');
        self::assertStringContainsString('Không có bài nào cần khôi phục', $handler);
        self::assertStringContainsString('Đã khôi phục', $handler);

        $schedule = $this->readAddon('Services/ContentProject/Application/Handlers/ScheduleProjectItemsHandler.php');
        self::assertStringContainsString('scheduleWithReport', $schedule);
        self::assertStringContainsString('Đã đổi lịch', $schedule);
        self::assertStringContainsString('skipped_active', $schedule);
    }

    public function test_migration_adds_delivery_markers(): void
    {
        $mig = $this->readAddon('database/migrations/2026_08_05_100000_add_delivery_dispatch_publisher_markers_to_seo_project_tasks.php');
        self::assertStringContainsString('delivery_dispatched_at', $mig);
        self::assertStringContainsString('publisher_started_at', $mig);
        self::assertStringContainsString('publish_attempt_token', $mig);
        self::assertStringContainsString('dispatch_count', $mig);
    }

    public function test_process_scheduled_stays_queued_for_delivery_after_emit(): void
    {
        $src = $this->readAddon('Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php');
        self::assertStringContainsString('claimForDispatch', $src);
        self::assertStringContainsString('awaiting worker', $src);
        self::assertStringContainsString('publish_attempt_token', $src);
        self::assertStringContainsString('queued_for_delivery', $src);
    }
}
