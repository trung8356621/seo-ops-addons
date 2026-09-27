<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Projects;

use App\Core\Sites\SiteAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class EloquentSiteDirectory implements SiteDirectory
{
    public function listActiveSites(?int $userId = null): array
    {
        try {
            if (! app()->bound('db') || ! Schema::hasTable('sites')) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        if ($userId !== null) {
            if ($userId <= 0) {
                return [];
            }
            if (app()->bound(SiteAccess::class)) {
                $user = User::query()->find($userId);
                $query = app(SiteAccess::class)->accessibleSitesQuery($user);
            } else {
                $query = Site::query();
                if (Schema::hasColumn('sites', 'user_id')) {
                    $user = User::query()->find($userId);
                    $ownerId = $user instanceof User ? ($user->accountOwnerId() ?? (int) $user->id) : $userId;
                    $query->where('user_id', $ownerId);
                }
            }
        } else {
            $query = Site::query();
        }

        if (Schema::hasColumn('sites', 'status')) {
            $query->where('status', 'active');
        }

        $out = [];
        foreach ($query->orderBy('domain')->limit(500)->get(['id', 'domain']) as $site) {
            if (! $site instanceof Site) {
                continue;
            }
            $id = (int) $site->getKey();
            $domain = strtolower(trim((string) $site->domain));
            if ($id <= 0 || $domain === '' || str_contains($domain, '__trashed__')) {
                continue;
            }
            $out[] = ['id' => $id, 'domain' => $domain];
        }

        return $out;
    }

    public function isSiteVisible(int $siteId, ?int $userId = null): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        try {
            if (! app()->bound('db') || ! Schema::hasTable('sites')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        if ($userId !== null) {
            if ($userId <= 0) {
                return false;
            }
            if (app()->bound(SiteAccess::class)) {
                $user = User::query()->find($userId);
                if (! app(SiteAccess::class)->canAccessSite($siteId, $user)) {
                    return false;
                }
            } elseif (Schema::hasColumn('sites', 'user_id')) {
                $user = User::query()->find($userId);
                $ownerId = $user instanceof User ? ($user->accountOwnerId() ?? (int) $user->id) : $userId;
                if (! Site::query()->whereKey($siteId)->where('user_id', $ownerId)->exists()) {
                    return false;
                }
            }
        }

        $query = Site::query()->whereKey($siteId);
        if (Schema::hasColumn('sites', 'status')) {
            $query->where('status', 'active');
        }

        $site = $query->first(['id', 'domain']);
        if (! $site instanceof Site) {
            return false;
        }

        $domain = strtolower(trim((string) $site->domain));

        return $domain !== '' && ! str_contains($domain, '__trashed__');
    }
}
