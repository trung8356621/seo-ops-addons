<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;

/**
 * Business locks cho SERP collection / workspace analysis.
 */
class SerpCollectionLockService
{
    private const DEFAULT_TTL_SECONDS = 600;

    public function __construct(
        private readonly ContentProjectBusinessLock $businessLock,
    ) {}

    public function collectionKey(string $serpQueryRef): string
    {
        return 'serp-collection:'.trim($serpQueryRef);
    }

    public function workspaceAnalysisKey(string $workspaceRef): string
    {
        return 'serp-workspace-analysis:'.trim($workspaceRef);
    }

    public function acquireCollection(string $serpQueryRef, int $waitSeconds = 0): ?string
    {
        return $this->businessLock->acquire($this->collectionKey($serpQueryRef), $waitSeconds, $this->ttlConfig());
    }

    public function releaseCollection(string $serpQueryRef, string $ownerToken): bool
    {
        return $this->businessLock->release($this->collectionKey($serpQueryRef), $ownerToken);
    }

    public function acquireWorkspaceAnalysis(string $workspaceRef, int $waitSeconds = 0): ?string
    {
        return $this->businessLock->acquire($this->workspaceAnalysisKey($workspaceRef), $waitSeconds, $this->ttlConfig());
    }

    public function releaseWorkspaceAnalysis(string $workspaceRef, string $ownerToken): bool
    {
        return $this->businessLock->release($this->workspaceAnalysisKey($workspaceRef), $ownerToken);
    }

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withCollectionLock(string $serpQueryRef, callable $callback, int $waitSeconds = 0): mixed
    {
        return $this->businessLock->withLock($this->collectionKey($serpQueryRef), $callback, $waitSeconds, $this->ttlConfig());
    }

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withWorkspaceAnalysisLock(string $workspaceRef, callable $callback, int $waitSeconds = 0): mixed
    {
        return $this->businessLock->withLock($this->workspaceAnalysisKey($workspaceRef), $callback, $waitSeconds, $this->ttlConfig());
    }

    private function ttlConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_TTL_SECONDS;
        }

        try {
            return (int) config('seo-content-ai.serp_intelligence.lock.ttl_seconds', self::DEFAULT_TTL_SECONDS);
        } catch (\Throwable) {
            return self::DEFAULT_TTL_SECONDS;
        }
    }
}
