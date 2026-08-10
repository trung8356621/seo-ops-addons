<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Lock;

/**
 * Per-automation lock. Owner token only — no foreign force release.
 */
final class AgentAutomationLockService
{
    private const TTL_SECONDS = 600;

    public function acquire(int $automationId, string $ownerToken): ?Lock
    {
        $lock = Cache::lock($this->key($automationId), self::TTL_SECONDS, $ownerToken);
        if (! $lock->get()) {
            return null;
        }

        return $lock;
    }

    public function key(int $automationId): string
    {
        return 'automation:'.$automationId;
    }

    public function occurrenceKey(int $automationId, string $scheduledAtUtc): string
    {
        return 'automation:'.$automationId.':'.$scheduledAtUtc;
    }
}
