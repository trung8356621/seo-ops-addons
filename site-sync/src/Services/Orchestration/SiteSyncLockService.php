<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncLock;
use App\Models\Site;
use Illuminate\Support\Str;

final class SiteSyncLockService
{
    public function acquire(Site $site, string $lockType = 'sync', int $ttlSeconds = 1800): ?string
    {
        $token = Str::uuid()->toString();
        $existing = SeoSiteSyncLock::query()->where('site_id', (int) $site->id)->first();
        if ($existing !== null) {
            if ($existing->expires_at !== null && $existing->expires_at->isFuture()) {
                return null;
            }
            $existing->delete();
        }

        SeoSiteSyncLock::query()->create([
            'site_id' => (int) $site->id,
            'owner_token' => $token,
            'lock_type' => $lockType,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $token;
    }

    public function release(Site $site, string $ownerToken): bool
    {
        $lock = SeoSiteSyncLock::query()->where('site_id', (int) $site->id)->first();
        if ($lock === null) {
            return true;
        }
        if (! hash_equals((string) $lock->owner_token, $ownerToken)) {
            return false;
        }
        $lock->delete();

        return true;
    }

    public function isLocked(Site $site): bool
    {
        $lock = SeoSiteSyncLock::query()->where('site_id', (int) $site->id)->first();

        return $lock !== null && $lock->expires_at !== null && $lock->expires_at->isFuture();
    }
}
