<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsOptimisticCounterMerge;
use PHPUnit\Framework\TestCase;

final class ContentProjectOpsOptimisticCounterMergeTest extends TestCase
{
    public function test_display_merges_retry_deltas(): void
    {
        $canonical = [
            'pending' => 1,
            'needs_review' => 0,
            'failed' => 24,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $pending = [
            ContentProjectOpsOptimisticCounterMerge::makePending(
                'op-1',
                10,
                'retry',
                ['failed' => -1, 'pending' => 1],
                $canonical,
                1_000_000,
            ),
        ];

        $display = ContentProjectOpsOptimisticCounterMerge::display($canonical, $pending, 1_000_100);

        self::assertSame(23, $display['failed']);
        self::assertSame(2, $display['pending']);
    }

    public function test_stale_canonical_keeps_pending_display(): void
    {
        $baseline = [
            'pending' => 1,
            'failed' => 24,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $pending = [
            ContentProjectOpsOptimisticCounterMerge::makePending(
                'op-1',
                10,
                'retry',
                ['failed' => -1, 'pending' => 1],
                $baseline,
                1_000_000,
            ),
        ];

        // Server still stale after accept.
        $stale = $baseline;
        self::assertFalse(ContentProjectOpsOptimisticCounterMerge::isReconciled($pending[0], $stale));

        $display = ContentProjectOpsOptimisticCounterMerge::display($stale, $pending, 1_000_100);
        self::assertSame(23, $display['failed']);
        self::assertSame(2, $display['pending']);

        $pruned = ContentProjectOpsOptimisticCounterMerge::prunePending($stale, $pending, 1_000_100);
        self::assertCount(1, $pruned);
    }

    public function test_reconciled_canonical_clears_pending_without_double_apply(): void
    {
        $baseline = [
            'pending' => 1,
            'failed' => 24,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $pending = [
            ContentProjectOpsOptimisticCounterMerge::makePending(
                'op-1',
                10,
                'retry',
                ['failed' => -1, 'pending' => 1],
                $baseline,
                1_000_000,
            ),
        ];
        $fresh = [
            'pending' => 2,
            'failed' => 23,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];

        self::assertTrue(ContentProjectOpsOptimisticCounterMerge::isReconciled($pending[0], $fresh));
        $pruned = ContentProjectOpsOptimisticCounterMerge::prunePending($fresh, $pending, 1_000_100);
        self::assertSame([], $pruned);

        $display = ContentProjectOpsOptimisticCounterMerge::display($fresh, $pruned, 1_000_100);
        self::assertSame(23, $display['failed']);
        self::assertSame(2, $display['pending']);
    }

    public function test_expired_pending_dropped_without_rollback_semantics(): void
    {
        $baseline = [
            'pending' => 1,
            'failed' => 24,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $pending = [
            ContentProjectOpsOptimisticCounterMerge::makePending(
                'op-1',
                10,
                'retry',
                ['failed' => -1, 'pending' => 1],
                $baseline,
                1_000_000,
                1000,
            ),
        ];

        $pruned = ContentProjectOpsOptimisticCounterMerge::prunePending($baseline, $pending, 1_002_000);
        self::assertSame([], $pruned);

        $display = ContentProjectOpsOptimisticCounterMerge::display($baseline, $pruned, 1_002_000);
        self::assertSame(24, $display['failed']);
        self::assertSame(1, $display['pending']);
    }

    public function test_operation_id_idempotent_in_prune(): void
    {
        $baseline = [
            'pending' => 1,
            'failed' => 24,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $a = ContentProjectOpsOptimisticCounterMerge::makePending(
            'op-dup',
            10,
            'retry',
            ['failed' => -1, 'pending' => 1],
            $baseline,
            1_000_000,
        );
        $b = ContentProjectOpsOptimisticCounterMerge::makePending(
            'op-dup',
            10,
            'retry',
            ['failed' => -1, 'pending' => 1],
            $baseline,
            1_000_000,
        );

        $pruned = ContentProjectOpsOptimisticCounterMerge::prunePending($baseline, [$a, $b], 1_000_100);
        self::assertCount(1, $pruned);
    }

    public function test_two_retries_sum_deltas(): void
    {
        $baseline = [
            'pending' => 1,
            'failed' => 24,
            'needs_review' => 0,
            'review' => 0,
            'approved' => 0,
            'scheduled' => 0,
            'published' => 0,
            'running' => 0,
        ];
        $pending = [
            ContentProjectOpsOptimisticCounterMerge::makePending('op-a', 1, 'retry', ['failed' => -1, 'pending' => 1], $baseline, 1_000_000),
            ContentProjectOpsOptimisticCounterMerge::makePending('op-b', 2, 'retry', ['failed' => -1, 'pending' => 1], $baseline, 1_000_000),
        ];

        $display = ContentProjectOpsOptimisticCounterMerge::display($baseline, $pending, 1_000_100);
        self::assertSame(22, $display['failed']);
        self::assertSame(3, $display['pending']);
    }
}
