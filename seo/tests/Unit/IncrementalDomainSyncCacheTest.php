<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\IncrementalDomainSyncCache;
use PHPUnit\Framework\TestCase;

final class IncrementalDomainSyncCacheTest extends TestCase
{
    public function test_progress_from_state_when_running(): void
    {
        $progress = IncrementalDomainSyncCache::progressFromState([
            'status' => IncrementalDomainSyncCache::STATUS_RUNNING,
            'refs' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            'offset' => 1,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->assertSame(1, $progress['done']);
        $this->assertSame(3, $progress['total']);
        $this->assertTrue($progress['running']);
        $this->assertSame(IncrementalDomainSyncCache::STATUS_RUNNING, $progress['status']);
    }

    public function test_progress_falls_back_to_refs_count_without_manifest_total(): void
    {
        $progress = IncrementalDomainSyncCache::progressFromState([
            'status' => IncrementalDomainSyncCache::STATUS_RUNNING,
            'refs' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            'offset' => 1,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->assertSame(1, $progress['done']);
        $this->assertSame(3, $progress['total']);
    }

    public function test_progress_from_state_when_completed(): void
    {
        $progress = IncrementalDomainSyncCache::progressFromState([
            'status' => IncrementalDomainSyncCache::STATUS_COMPLETED,
            'refs' => [
                ['id' => 1],
                ['id' => 2],
            ],
            'offset' => 2,
            'message' => 'Done',
        ]);

        $this->assertSame(2, $progress['done']);
        $this->assertSame(2, $progress['total']);
        $this->assertFalse($progress['running']);
        $this->assertSame('Done', $progress['message']);
    }

    public function test_is_resumable_when_stale_running_with_partial_offset(): void
    {
        $state = [
            'status' => IncrementalDomainSyncCache::STATUS_RUNNING,
            'refs' => array_fill(0, 10, ['id' => 1]),
            'offset' => 4,
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'updated_at' => now()->subMinutes(10)->toIso8601String(),
        ];

        $this->assertTrue(IncrementalDomainSyncCache::isResumable($state));
        $this->assertFalse(IncrementalDomainSyncCache::isActivelyRunning($state));
    }

    public function test_is_actively_running_when_recently_updated(): void
    {
        $state = [
            'status' => IncrementalDomainSyncCache::STATUS_RUNNING,
            'refs' => array_fill(0, 10, ['id' => 1]),
            'offset' => 4,
            'updated_at' => now()->toIso8601String(),
        ];

        $this->assertTrue(IncrementalDomainSyncCache::isActivelyRunning($state));
        $this->assertFalse(IncrementalDomainSyncCache::isResumable($state));
    }

    public function test_mark_resuming_sets_running_status(): void
    {
        $state = [
            'status' => IncrementalDomainSyncCache::STATUS_FAILED,
            'refs' => [['id' => 1], ['id' => 2]],
            'offset' => 1,
            'message' => 'Error',
        ];

        $resumed = IncrementalDomainSyncCache::markResuming($state);

        $this->assertSame(IncrementalDomainSyncCache::STATUS_RUNNING, $resumed['status']);
        $this->assertNull($resumed['message']);
        $this->assertArrayHasKey('updated_at', $resumed);
    }
}
