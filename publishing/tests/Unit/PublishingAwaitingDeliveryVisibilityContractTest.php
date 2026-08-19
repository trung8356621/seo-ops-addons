<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Publishing\Console\RepairUnprojectedPublishingCommand;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Invisible dispatch-claimed rows must stay visible as awaiting_delivery.
 */
final class PublishingAwaitingDeliveryVisibilityContractTest extends TestCase
{
    private function src(string $class): string
    {
        $body = file_get_contents((string) (new ReflectionClass($class))->getFileName());
        self::assertIsString($body);

        return $body;
    }

    public function test_every_queue_row_maps_to_exactly_one_presenter_state(): void
    {
        $fixtures = [
            ['publish_queue_status' => 'none', 'publishing_queued_at' => '2026-08-01', 'scheduled_publish_at' => null],
            ['publish_queue_status' => 'waiting', 'scheduled_publish_at' => '2026-08-05 10:00:00', 'scheduled_raw' => '2026-08-05 10:00:00'],
            ['publish_queue_status' => 'queued_for_delivery', 'delivery_dispatched_at' => '2026-08-05 02:20:00', 'publisher_started_at' => null],
            ['publish_queue_status' => 'processing', 'publisher_started_at' => null, 'delivery_dispatched_at' => '2026-08-05 02:20:00'],
            [
                'publish_queue_status' => 'processing',
                'publisher_started_at' => '2026-08-05 02:25:00',
                'publish_lease_expires_at' => '2026-08-05 02:30:00',
            ],
            ['publish_queue_status' => 'retrying', 'next_publish_retry_at' => '2026-08-05 12:00:00'],
            ['publish_queue_status' => 'published', 'publish_published_at' => '2026-08-05 01:00:00'],
            ['publish_queue_status' => 'failed'],
            ['publish_queue_status' => 'weird_legacy', 'publishing_queued_at' => '2026-08-01'],
        ];

        $states = [];
        foreach ($fixtures as $row) {
            $state = PublishingQueueStateClassifier::classify($row)['state'];
            $states[] = $state;
            self::assertNotSame('', $state);
        }

        $summary = PublishingQueueStateClassifier::countSummary($fixtures);
        self::assertTrue($summary['invariant_ok']);
        self::assertSame(count($fixtures), $summary['projected_sum']);
        self::assertSame(count($fixtures), $summary['total']);
        self::assertContains(PublishingQueueStateClassifier::AWAITING_DELIVERY, $states);
        self::assertContains(PublishingQueueStateClassifier::NEEDS_ATTENTION, $states);
    }

    public function test_processing_without_publisher_started_is_awaiting_delivery(): void
    {
        $row = [
            'publish_queue_status' => 'processing',
            'publisher_started_at' => null,
            'delivery_dispatched_at' => '2026-08-05 02:22:50',
        ];
        $c = PublishingQueueStateClassifier::classify($row);
        self::assertSame(PublishingQueueStateClassifier::AWAITING_DELIVERY, $c['state']);
        self::assertSame('Đang chờ bộ xuất bản', $c['label']);
        self::assertNotSame(PublishingQueueStateClassifier::PUBLISHING, $c['state']);
    }

    public function test_processing_with_publisher_started_is_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'processing',
            'publisher_started_at' => '2026-08-05 02:25:00',
            'publish_lease_expires_at' => now()->addMinutes(3)->toIso8601String(),
        ];
        self::assertSame(
            PublishingQueueStateClassifier::PUBLISHING,
            PublishingQueueStateClassifier::classify($row)['state'],
        );
    }

    public function test_awaiting_delivery_included_in_counters_and_filters(): void
    {
        $rows = [
            ['publish_queue_status' => 'queued_for_delivery', 'publisher_started_at' => null],
            ['publish_queue_status' => 'queued_for_delivery', 'publisher_started_at' => null],
            ['publish_queue_status' => 'published', 'publish_published_at' => now()->toIso8601String()],
        ];
        $summary = PublishingQueueStateClassifier::countSummary($rows);
        self::assertSame(2, $summary['awaiting_delivery']);
        self::assertSame(2, $summary['awaiting_worker']); // alias
        self::assertTrue(PublishingQueueStateClassifier::matchesFilter($rows[0], 'awaiting_delivery'));
        self::assertTrue(PublishingQueueStateClassifier::matchesFilter($rows[0], 'awaiting_worker'));
    }

    public function test_fourteen_style_fixture_remains_visible_after_claim(): void
    {
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $rows[] = [
                'id' => 438 + $i,
                'publish_queue_status' => $i < 2 ? 'queued_for_delivery' : 'processing',
                'publisher_started_at' => null,
                'delivery_dispatched_at' => '2026-08-05 02:20:00',
                'publishing_queued_at' => '2026-08-01',
            ];
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'publish_queue_status' => 'none',
                'publishing_queued_at' => '2026-08-01',
                'scheduled_publish_at' => null,
            ];
        }
        for ($i = 0; $i < 11; $i++) {
            $rows[] = [
                'publish_queue_status' => 'published',
                'publish_published_at' => '2026-08-04',
            ];
        }

        $summary = PublishingQueueStateClassifier::countSummary($rows);
        self::assertSame(30, $summary['total']);
        self::assertSame(14, $summary['awaiting_delivery']);
        self::assertSame(5, $summary['unscheduled']);
        self::assertSame(11, $summary['published']);
        self::assertSame(0, $summary['publishing']);
        self::assertTrue($summary['invariant_ok']);
        self::assertSame(30, $summary['projected_sum']);
    }

    public function test_awaiting_actions_block_duplicate_dispatch(): void
    {
        $flags = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => PublishingQueueStateClassifier::AWAITING_DELIVERY,
            'publish_queue_status' => 'queued_for_delivery',
            'publisher_started_at' => null,
        ]);
        self::assertFalse($flags['publish_now']);
        self::assertFalse($flags['retry_now']);
        self::assertTrue($flags['cancel_pending_delivery']);
    }

    public function test_recover_one_handles_queued_for_delivery(): void
    {
        $src = $this->src(PublishingStuckRecoveryService::class);
        self::assertStringContainsString('QueuedForDelivery', $src);
        self::assertStringContainsString('recoverStalledDelivery', $src);
        self::assertStringContainsString('DELIVERY_WORKER_STALLED', $src);
        self::assertStringContainsString('attempt_preserved', $src);
        self::assertStringContainsString('PublishingOverdueInlineDeliveryService', $src);
        self::assertStringContainsString('resolveStalledDeliveryRetryAt', $src);
    }

    public function test_overdue_inline_delivery_service_exists(): void
    {
        $src = $this->src(\Omnichannel\Addons\Publishing\Services\Publishing\PublishingOverdueInlineDeliveryService::class);
        self::assertStringContainsString('shouldAttemptInline', $src);
        self::assertStringContainsString('overdue_inline_delivery_started', $src);
        self::assertStringContainsString('confirmContentProjectPublishDelivery', $src);
    }

    public function test_repair_unprojected_command_registered(): void
    {
        $cmd = $this->src(RepairUnprojectedPublishingCommand::class);
        self::assertStringContainsString('seo:publishing:repair-unprojected', $cmd);
        self::assertStringContainsString('only-unprojected', $cmd);
        self::assertStringContainsString('PublishingUnprojectedRepairService', $cmd);

        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );
        self::assertStringContainsString('RepairUnprojectedPublishingCommand::class', $provider);
    }

    public function test_hub_kpi_includes_awaiting_delivery_card(): void
    {
        $blade = LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php');
        $src = (string) file_get_contents($blade);
        self::assertStringContainsString("'key' => 'awaiting_delivery'", $src);
        self::assertStringContainsString('Chờ xử lý', $src);
        self::assertStringContainsString('invariant_ok', $src);

        $filter = LegacyAddonPath::resolve('resources/views/components/content-project-filter-toolbar.blade.php');
        $filterSrc = (string) file_get_contents($filter);
        self::assertStringContainsString('awaiting_delivery', $filterSrc);
        self::assertStringContainsString('retry_wait', $filterSrc);
        self::assertStringContainsString('needs_attention', $filterSrc);
    }
}
