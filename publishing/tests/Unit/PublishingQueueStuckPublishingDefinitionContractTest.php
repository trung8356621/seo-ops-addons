<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class PublishingQueueStuckPublishingDefinitionContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_past_due_processing_is_stuck_before_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:43:00'));

        $row = [
            'publish_queue_status' => 'processing',
            'scheduled_publish_at' => Carbon::parse('2026-08-03 12:22:00'),
            'last_publish_attempt_at' => Carbon::parse('2026-08-03 12:22:00')->toIso8601String(),
        ];

        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches($row));
        self::assertTrue(PublishingQueueStuckPublishingDefinition::isPastDueStuck($row));
    }

    public function test_fresh_future_schedule_processing_not_stuck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:10:00'));

        $row = [
            'publish_queue_status' => 'processing',
            'scheduled_publish_at' => Carbon::parse('2026-08-03 12:22:00'),
            'last_publish_attempt_at' => Carbon::parse('2026-08-03 12:10:00')->toIso8601String(),
        ];

        self::assertFalse(PublishingQueueStuckPublishingDefinition::matches($row));
    }

    public function test_missing_attempt_is_stuck(): void
    {
        $row = [
            'publish_queue_status' => 'processing',
            'scheduled_publish_at' => Carbon::parse('2026-08-03 12:22:00'),
        ];

        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches($row));
    }
}
