<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicClusterReclusterState;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\UpdateClusterCanonicalService;
use Throwable;

/**
 * Background Fix Keywords for one Topic.
 * Site-scoped lock serializes membership mutation; unique per site+cluster collapses duplicates.
 */
final class ReconcileTopicMembershipJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE_NAME = 'default';

    public const MEMBERSHIP_LOCK_PREFIX = 'topic_cluster_membership_mutation:';

    public int $tries = 10;

    public int $timeout = 300;

    /** Unique lock covers retries for the same site+cluster repair. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $siteId,
        public readonly string $clusterKey,
        public readonly ?int $requestedBy = null,
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'topic-membership-reconcile-'.$this->siteId.'-'.trim($this->clusterKey);
    }

    public function handle(UpdateClusterCanonicalService $canonical): void
    {
        $clusterKey = trim($this->clusterKey);
        if ($this->siteId <= 0 || $clusterKey === '') {
            return;
        }

        if (TopicClusterReclusterState::isMutationLocked($this->siteId)) {
            $this->release(15);

            return;
        }

        $lock = Cache::lock(self::membershipLockKey($this->siteId), 120);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            Log::info('ReconcileTopicMembershipJob started', [
                'site_id' => $this->siteId,
                'cluster_key' => $clusterKey,
                'requested_by' => $this->requestedBy,
            ]);

            $canonical->reconcileMembership($this->siteId, $clusterKey);

            Log::info('ReconcileTopicMembershipJob finished', [
                'site_id' => $this->siteId,
                'cluster_key' => $clusterKey,
            ]);
        } catch (Throwable $e) {
            Log::warning('ReconcileTopicMembershipJob failed', [
                'site_id' => $this->siteId,
                'cluster_key' => $clusterKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function membershipLockKey(int $siteId): string
    {
        return self::MEMBERSHIP_LOCK_PREFIX.$siteId;
    }
}
