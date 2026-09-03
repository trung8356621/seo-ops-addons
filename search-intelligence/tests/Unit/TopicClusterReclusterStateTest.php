<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterTopicClustersJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterReclusterState;
use Tests\TestCase;

final class TopicClusterReclusterStateTest extends TestCase
{
    private const SITE_A = 7;

    private const SITE_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A));
        Cache::forget(ReclusterTopicClustersJob::resultCacheKey(self::SITE_B));
    }

    public function test_queued_and_running_lock_mutations(): void
    {
        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ], 3600);

        self::assertTrue(TopicClusterReclusterState::isActive(self::SITE_A));
        self::assertTrue(TopicClusterReclusterState::isMutationLocked(self::SITE_A));

        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        self::assertTrue(TopicClusterReclusterState::isActive(self::SITE_A));
        self::assertTrue(TopicClusterReclusterState::isMutationLocked(self::SITE_A));
    }

    public function test_completed_and_failed_unlock_mutations(): void
    {
        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'completed',
            'finished_at' => now()->toIso8601String(),
        ], 3600);

        self::assertFalse(TopicClusterReclusterState::isActive(self::SITE_A));
        self::assertFalse(TopicClusterReclusterState::isMutationLocked(self::SITE_A));

        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'failed',
            'error' => 'boom',
            'finished_at' => now()->toIso8601String(),
        ], 3600);

        self::assertFalse(TopicClusterReclusterState::isActive(self::SITE_A));
        self::assertFalse(TopicClusterReclusterState::isMutationLocked(self::SITE_A));
    }

    public function test_lock_is_site_scoped(): void
    {
        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        self::assertTrue(TopicClusterReclusterState::isMutationLocked(self::SITE_A));
        self::assertFalse(TopicClusterReclusterState::isMutationLocked(self::SITE_B));
    }

    public function test_assert_mutation_allowed_rejects_without_throwing(): void
    {
        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        self::assertFalse(TopicClusterReclusterState::assertMutationAllowed(self::SITE_A));
        self::assertTrue(TopicClusterReclusterState::assertMutationAllowed(self::SITE_B));
    }

    public function test_stale_running_marks_failed_and_unlocks(): void
    {
        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'running',
            'started_at' => now()->subSeconds(TopicClusterReclusterState::STALE_RUNNING_SECONDS + 30)->toIso8601String(),
            'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
        ], 3600);

        $state = TopicClusterReclusterState::stateForSite(self::SITE_A);

        self::assertSame('failed', $state['status'] ?? null);
        self::assertFalse(TopicClusterReclusterState::isMutationLocked(self::SITE_A));
    }

    public function test_uses_existing_job_result_cache_key(): void
    {
        self::assertSame(
            'topic_cluster_recluster:'.self::SITE_A,
            ReclusterTopicClustersJob::resultCacheKey(self::SITE_A),
        );

        Cache::put(ReclusterTopicClustersJob::resultCacheKey(self::SITE_A), [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ], 3600);

        $state = TopicClusterReclusterState::stateForSite(self::SITE_A);
        self::assertSame('queued', $state['status'] ?? null);
    }
}
