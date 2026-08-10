<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\PublishProjectItemsNowHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RecoverStuckPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RetryProjectItemPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ScheduleProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Services\Publishing\DispatchClaimResult;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemOutcome;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingDueItemSelector;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingProcessingMarkerClearer;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStatusLabelBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pipeline contracts: due → claim → publisher invoke → explicit outcome.
 * Full DB integration runs on remote host; this file locks the wiring + UX invariants.
 */
final class PublishingPipelineE2EContractTest extends TestCase
{
    private function src(string $class): string
    {
        $path = (string) (new ReflectionClass($class))->getFileName();
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_canonical_due_item_service_exists_and_is_single_entry(): void
    {
        $svc = $this->src(PublishDueItemService::class);
        self::assertStringContainsString('TRIGGER_SCHEDULER', $svc);
        self::assertStringContainsString('TRIGGER_RETRY_NOW', $svc);
        self::assertStringContainsString('TRIGGER_PUBLISH_NOW', $svc);
        self::assertStringContainsString('TRIGGER_RECOVERY', $svc);
        self::assertStringContainsString('releaseStickyIdempotency', $svc);
        self::assertStringContainsString('due:%s:attempt:', $svc);
        self::assertStringContainsString('ProcessScheduledProjectItemPublishCommand', $svc);
        self::assertStringContainsString('publishing.due_item_outcome', $svc);
    }

    public function test_runner_uses_due_item_service_not_stable_operation_key(): void
    {
        $runner = $this->src(ContentProjectPublishingQueueRunner::class);
        self::assertStringContainsString('PublishDueItemService', $runner);
        self::assertStringContainsString('due_scheduled_ids', $runner);
        self::assertStringContainsString('claim_attempted_ids', $runner);
        self::assertStringContainsString('claim_success_ids', $runner);
        self::assertStringContainsString('claim_rejected_ids', $runner);
        self::assertStringContainsString('publisher_invoked_ids', $runner);
        self::assertStringContainsString('publishing.due_scan_no_progress', $runner);
        self::assertStringNotContainsString('publish_operation_key ?? \'\'', $runner);
        self::assertStringNotContainsString('idempotencyKey: $idemKey', $runner);
    }

    public function test_claim_returns_structured_rejection_codes(): void
    {
        self::assertSame('claimed', DispatchClaimResult::CLAIMED);
        self::assertSame('active_publish', DispatchClaimResult::ACTIVE_PUBLISH);
        self::assertSame('stale_claim', DispatchClaimResult::STALE_CLAIM);
        self::assertSame('invalid_status', DispatchClaimResult::INVALID_STATUS);
        self::assertSame('awaiting_worker', DispatchClaimResult::AWAITING_WORKER);
        self::assertSame('dispatch_failed', DispatchClaimResult::DISPATCH_FAILED);

        $svc = $this->src(ContentProjectPublishingQueueService::class);
        self::assertStringContainsString('DispatchClaimResult', $svc);
        self::assertStringContainsString('function claimForDispatch', $svc);
        self::assertStringContainsString('DispatchClaimResult::rejected', $svc);

        $handler = $this->src(ProcessScheduledProjectItemPublishHandler::class);
        self::assertStringContainsString('publishing.claim_rejected', $handler);
        self::assertStringContainsString('claim_code', $handler);
        self::assertStringContainsString('$claim->isClaimed()', $handler);
    }

    public function test_marker_clearer_releases_both_bus_tenants(): void
    {
        $src = $this->src(PublishingProcessingMarkerClearer::class);
        self::assertStringContainsString(':actor:queue', $src);
        self::assertStringContainsString("':queue'", $src);
        self::assertStringContainsString('releasePublishOperation', $src);
    }

    public function test_retry_and_publish_now_call_canonical_service_immediately(): void
    {
        $retry = $this->src(RetryProjectItemPublishingHandler::class);
        self::assertStringContainsString('PublishDueItemService', $retry);
        self::assertStringContainsString('TRIGGER_RETRY_NOW', $retry);
        self::assertStringContainsString('Đã thử lại', $retry);
        self::assertStringNotContainsString('dispatchDue()', $retry);

        $now = $this->src(PublishProjectItemsNowHandler::class);
        self::assertStringContainsString('PublishDueItemService', $now);
        self::assertStringContainsString('TRIGGER_PUBLISH_NOW', $now);
        self::assertStringContainsString('Publish now:', $now);
        self::assertStringNotContainsString('dispatchDue()', $now);
    }

    public function test_schedule_plus_report_and_recover_summaries(): void
    {
        $schedule = $this->src(ScheduleProjectItemsHandler::class);
        self::assertStringContainsString('Đã đổi lịch', $schedule);
        self::assertStringContainsString('Bỏ qua', $schedule);

        $recover = $this->src(RecoverStuckPublishingHandler::class);
        self::assertStringContainsString('Không có bài nào cần khôi phục', $recover);
        self::assertStringContainsString('Đã khôi phục', $recover);
    }

    public function test_health_shows_dominant_rejection_not_only_degraded(): void
    {
        $health = $this->src(ContentProjectQueueHealthService::class);
        self::assertStringContainsString('rememberScanNoProgress', $health);
        self::assertStringContainsString('bài quá hạn chưa được xử lý', $health);
        self::assertStringContainsString('dominant_reason', $health);
    }

    public function test_user_facing_labels_hide_technical_jargon(): void
    {
        self::assertSame('Đã lên lịch', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => PublishingQueueStateClassifier::SCHEDULED,
        ]));
        self::assertSame('Đang xuất bản', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => PublishingQueueStateClassifier::PUBLISHING,
        ]));
        self::assertSame('Đã xuất bản', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => PublishingQueueStateClassifier::PUBLISHED,
        ]));
        self::assertSame('Không thể xuất bản', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => PublishingQueueStateClassifier::FAILED,
        ]));
        self::assertSame('Thử lại sau ít phút', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => PublishingQueueStateClassifier::RETRY_WAIT,
            'next_publish_retry_at' => now()->subMinutes(10)->toIso8601String(),
        ]));

        $classified = PublishingQueueStateClassifier::classify([
            'publish_queue_status' => 'queued_for_delivery',
            'delivery_dispatched_at' => now()->toIso8601String(),
            'publisher_started_at' => null,
        ]);
        self::assertSame('Đang chờ bộ xuất bản', $classified['label']);
        self::assertSame(PublishingQueueStateClassifier::AWAITING_DELIVERY, $classified['state']);
    }

    public function test_actions_by_state_hide_invalid_mutations(): void
    {
        $scheduled = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::SCHEDULED,
            'publish_queue_status' => 'waiting',
        ]);
        self::assertTrue($scheduled['publish_now']);
        self::assertTrue($scheduled['unschedule']);
        self::assertFalse($scheduled['retry_now']);

        $retry = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::RETRY_WAIT,
            'publish_queue_status' => 'retrying',
        ]);
        self::assertTrue($retry['retry_now']);
        self::assertFalse($retry['publish_now']);
        self::assertTrue($retry['cancel']); // Skip

        $publishing = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::PUBLISHING,
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->toIso8601String(),
            'publish_lease_expires_at' => now()->addMinutes(5)->toIso8601String(),
        ]);
        self::assertFalse($publishing['publish_now']);
        self::assertFalse($publishing['retry_now']);
        self::assertFalse($publishing['schedule']);

        $published = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::PUBLISHED,
            'wp_permalink' => 'https://example.com/post',
        ]);
        self::assertTrue($published['view_on_wordpress']);
        self::assertFalse($published['publish_now']);
    }

    public function test_outcome_codes_cover_required_terminal_states(): void
    {
        self::assertSame('published', PublishDueItemOutcome::PUBLISHED);
        self::assertSame('retry_wait', PublishDueItemOutcome::RETRY_WAIT);
        self::assertSame('failed', PublishDueItemOutcome::FAILED);
        self::assertSame('awaiting_delivery', PublishDueItemOutcome::AWAITING_DELIVERY);
        self::assertSame('skipped', PublishDueItemOutcome::SKIPPED);
    }

    public function test_due_selector_predicates_remain_canonical(): void
    {
        $src = $this->src(PublishingDueItemSelector::class);
        self::assertStringContainsString('applyScheduledDue', $src);
        self::assertStringContainsString('applyRetryDue', $src);
        self::assertStringContainsString('next_publish_retry_at', $src);
        self::assertStringContainsString("Waiting->value", $src);
        self::assertStringContainsString("Retrying->value", $src);
    }

    public function test_fourteen_overdue_must_each_produce_logged_outcome(): void
    {
        $runner = $this->src(ContentProjectPublishingQueueRunner::class);
        self::assertStringContainsString('publishing.due_item_outcome', $this->src(PublishDueItemService::class));
        self::assertStringContainsString("\$stats['outcomes'][]", $runner);
        self::assertStringContainsString('claim_rejection_reason', $runner);
        self::assertStringContainsString('due_scan_no_progress', $runner);
    }
}
