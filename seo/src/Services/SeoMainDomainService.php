<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Support\Collection;

final class SeoMainDomainService
{
    public const META_KEY = 'seo_is_main';

    public function isMain(Site $site): bool
    {
        $primaryId = $this->primarySiteIdForOwner((int) $site->user_id);

        return $primaryId !== null && $primaryId === (int) $site->getKey();
    }

    public function setAsMain(Site|int $site): void
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);

        $ownerId = (int) $site->user_id;

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            abort_unless($ownerId === SeoAccessControl::accountSiteOwnerId(), 403);
        }

        $this->clearPrimaryForOwner($ownerId);

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => '1'],
        );

        SeoAccessControl::setGlobalSiteId((int) $site->getKey());
    }

    /**
     * Mỗi user (manager) chỉ giữ một miền chính — dọn dữ liệu cũ trùng.
     */
    public function deduplicatePrimarySitesForVisibleOwners(): void
    {
        $query = Site::query()->select('user_id')->distinct();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        foreach ($query->pluck('user_id') as $ownerId) {
            if ($ownerId !== null && (int) $ownerId > 0) {
                $this->ensureSinglePrimaryForOwner((int) $ownerId);
            }
        }
    }

    public function resolveMainSite(): ?Site
    {
        $globalId = SeoAccessControl::globalSiteId();
        if ($globalId !== null && $globalId > 0) {
            $site = Site::query()->find($globalId);
            if ($site instanceof Site) {
                return $site;
            }
        }

        $userId = SeoAccessControl::shouldScopeToAccountOwner()
            ? SeoAccessControl::accountSiteOwnerId()
            : (int) auth()->id();

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $primaryId = $this->primarySiteIdForOwner($userId);
            if ($primaryId !== null) {
                return Site::query()->find($primaryId);
            }

            return Site::query()->where('user_id', $userId)->orderBy('id')->first();
        }

        $primaryId = $this->primarySiteIdForOwner($userId);
        if ($primaryId !== null) {
            return Site::query()->find($primaryId);
        }

        return Site::query()->orderBy('id')->first();
    }

    public function resolveMainSiteId(): ?int
    {
        $site = $this->resolveMainSite();

        return $site !== null ? (int) $site->id : null;
    }

    public function resolveMainSiteLabel(): string
    {
        $site = $this->resolveMainSite();
        if ($site === null) {
            return 'Chưa có miền chính';
        }

        return trim((string) $site->domain) ?: ('Site #'.$site->id);
    }

    public function primarySiteIdForOwner(int $ownerId): ?int
    {
        if ($ownerId <= 0) {
            return null;
        }

        $this->ensureSinglePrimaryForOwner($ownerId);

        $siteIds = Site::query()->where('user_id', $ownerId)->pluck('id');
        if ($siteIds->isEmpty()) {
            return null;
        }

        $primarySiteId = SiteMeta::query()
            ->where('meta_key', self::META_KEY)
            ->where('meta_value', '1')
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->value('site_id');

        return $primarySiteId !== null ? (int) $primarySiteId : null;
    }

    private function clearPrimaryForOwner(int $ownerId): void
    {
        $siteIds = Site::query()->where('user_id', $ownerId)->pluck('id');
        if ($siteIds->isEmpty()) {
            return;
        }

        SiteMeta::query()
            ->whereIn('site_id', $siteIds)
            ->where('meta_key', self::META_KEY)
            ->delete();
    }

    private function ensureSinglePrimaryForOwner(int $ownerId): void
    {
        $siteIds = Site::query()->where('user_id', $ownerId)->pluck('id');
        if ($siteIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, int> $primarySiteIds */
        $primarySiteIds = SiteMeta::query()
            ->where('meta_key', self::META_KEY)
            ->where('meta_value', '1')
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->pluck('site_id')
            ->map(static fn ($id): int => (int) $id);

        if ($primarySiteIds->count() <= 1) {
            return;
        }

        $keepId = $primarySiteIds->first();
        $removeIds = $primarySiteIds->slice(1)->values();

        SiteMeta::query()
            ->where('meta_key', self::META_KEY)
            ->whereIn('site_id', $removeIds)
            ->delete();
    }
}
