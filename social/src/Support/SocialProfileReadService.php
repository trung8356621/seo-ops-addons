<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Support;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Social\Enums\SocialPlatform;
use Omnichannel\Addons\Social\Models\SocialProfile;
use Throwable;

/**
 * Read Social Profile rows scoped by site — no Electron / automation.
 *
 * @phpstan-type ProfileDto array{
 *   id: int,
 *   site_id: int,
 *   platform: string,
 *   platform_label: string,
 *   compact_label: string,
 *   display_name: string,
 *   profile_url: string,
 *   is_active: bool
 * }
 */
final class SocialProfileReadService
{
    /**
     * @return list<ProfileDto>
     */
    public function activeForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return [];
        }

        try {
            return SocialProfile::query()
                ->forSite($siteId)
                ->active()
                ->orderBy('id')
                ->get()
                ->map(fn (SocialProfile $profile): array => $this->toDto($profile))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<ProfileDto>
     */
    public function allForSite(int $siteId): array
    {
        if ($siteId <= 0 || ! $this->tableReady()) {
            return [];
        }

        try {
            return SocialProfile::query()
                ->forSite($siteId)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->get()
                ->map(fn (SocialProfile $profile): array => $this->toDto($profile))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('seo_social_profiles');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return ProfileDto
     */
    private function toDto(SocialProfile $profile): array
    {
        $platform = $profile->platform instanceof SocialPlatform
            ? $profile->platform
            : SocialPlatform::tryFrom((string) $profile->platform) ?? SocialPlatform::Other;

        return [
            'id' => (int) $profile->id,
            'site_id' => (int) $profile->site_id,
            'platform' => $platform->value,
            'platform_label' => $platform->label(),
            'compact_label' => $platform->compactLabel(),
            'display_name' => (string) $profile->display_name,
            'profile_url' => (string) $profile->profile_url,
            'is_active' => (bool) $profile->is_active,
        ];
    }
}
