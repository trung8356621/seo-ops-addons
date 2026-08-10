<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use RuntimeException;
use Throwable;

/**
 * Business lock cho pipeline build Topical Map — tránh 2 lần build() chạy song song trên
 * cùng workspace, và chặn build khi workspace đang có 1 keyword analysis chạy dở (dữ liệu
 * cluster/keyword có thể đổi giữa chừng). Dùng chung hạ tầng lock của Content Project.
 */
final class KeywordTopicalMapBuildLock
{
    private const DEFAULT_TTL_SECONDS = 900;

    /** @var list<string> */
    private const ACTIVE_OPERATION_STATUSES = ['processing', 'running'];

    public function __construct(
        private readonly ContentProjectBusinessLock $businessLock,
    ) {}

    public function lockKey(string $workspaceRef): string
    {
        return 'keyword-topical-map-build:'.trim($workspaceRef);
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

    public function isHeld(string $workspaceRef): bool
    {
        $token = $this->acquire($workspaceRef, 0);
        if ($token === null) {
            return true;
        }
        $this->release($workspaceRef, $token);

        return false;
    }

    public function requestCancel(string $workspaceRef): void
    {
        $this->putCancelFlag($workspaceRef, true);
    }

    public function clearCancel(string $workspaceRef): void
    {
        $this->putCancelFlag($workspaceRef, false);
    }

    public function cancelRequested(string $workspaceRef): bool
    {
        if (! function_exists('cache')) {
            return false;
        }
        try {
            return (bool) cache()->get($this->cancelKey($workspaceRef), false);
        } catch (Throwable) {
            return false;
        }
    }

    private function cancelKey(string $workspaceRef): string
    {
        return 'ki:topical-map-build-cancel:'.trim($workspaceRef);
    }

    private function putCancelFlag(string $workspaceRef, bool $value): void
    {
        if (! function_exists('cache')) {
            return;
        }
        try {
            if ($value) {
                cache()->put($this->cancelKey($workspaceRef), true, $this->ttlConfig());
            } else {
                cache()->forget($this->cancelKey($workspaceRef));
            }
        } catch (Throwable) {
        }
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

    /**
     * Chặn build khi workspace đang analyzing hoặc có operation đang processing/running —
     * dữ liệu cluster/keyword chưa ổn định để build map.
     */
    public function assertAnalysisNotRunning(SeoKeywordWorkspace $workspace): void
    {
        if ($workspace->status === KeywordWorkspaceStatus::Analyzing) {
            throw new RuntimeException('topical_map.keyword_analysis_running: Workspace is currently analyzing keywords.');
        }

        $hasActiveOperation = SeoKeywordAnalysisOperation::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', self::ACTIVE_OPERATION_STATUSES)
            ->exists();

        if ($hasActiveOperation) {
            throw new RuntimeException('topical_map.keyword_analysis_running: A keyword analysis operation is still in progress.');
        }
    }

    private function ttlConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_TTL_SECONDS;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.topical_map.lock_ttl_seconds', self::DEFAULT_TTL_SECONDS);
        } catch (Throwable) {
            return self::DEFAULT_TTL_SECONDS;
        }
    }
}
