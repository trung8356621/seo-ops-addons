<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;

/**
 * Business lock cho pipeline phân tích 1 workspace — tránh 2 lần analyze() chạy song song
 * trên cùng workspace. Dùng chung hạ tầng lock của Content Project (ContentProjectBusinessLock).
 */
final class KeywordWorkspaceAnalysisLock
{
    private const DEFAULT_TTL_SECONDS = 900;

    public function __construct(
        private readonly ContentProjectBusinessLock $businessLock,
    ) {}

    public function lockKey(string $workspaceRef): string
    {
        return 'keyword-workspace-analysis:'.trim($workspaceRef);
    }

    public function acquire(string $workspaceRef, int $waitSeconds = 0): ?string
    {
        return $this->businessLock->acquire($this->lockKey($workspaceRef), $waitSeconds, $this->ttlConfig());
    }

    public function release(string $workspaceRef, string $ownerToken): bool
    {
        return $this->businessLock->release($this->lockKey($workspaceRef), $ownerToken);
    }

    public function refresh(string $workspaceRef, string $ownerToken): bool
    {
        return $this->businessLock->refresh($this->lockKey($workspaceRef), $ownerToken, $this->ttlConfig());
    }

    /**
     * @template T
     *
     * @param  callable(?string $ownerToken): T  $callback
     * @return T
     */
    public function withLock(string $workspaceRef, callable $callback, int $waitSeconds = 0): mixed
    {
        return $this->businessLock->withLock($this->lockKey($workspaceRef), $callback, $waitSeconds, $this->ttlConfig());
    }

    private function ttlConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_TTL_SECONDS;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.analysis.lock_ttl_seconds', self::DEFAULT_TTL_SECONDS);
        } catch (\Throwable) {
            return self::DEFAULT_TTL_SECONDS;
        }
    }
}
