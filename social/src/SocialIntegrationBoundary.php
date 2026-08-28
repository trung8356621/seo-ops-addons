<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social;

/**
 * Social peer boundary — server identity/share phase 1.
 * Electron / automation remain separate products (not wired here).
 */
final class SocialIntegrationBoundary
{
    public const CAPABILITY = 'social.publish';

    public const PROFILE_CAPABILITY = 'social.profile';

    /**
     * @return list<string>
     */
    public static function plannedSurfaces(): array
    {
        return [
            'profile.crud',
            'share.manual',
            'channel.connection',
            'post.draft',
            'post.schedule',
            'post.publish',
            'engagement.ingest',
        ];
    }
}
