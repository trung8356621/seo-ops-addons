<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social;

/**
 * Social peer boundary — compatible with rpa-social concept.
 * Not a child of SEO. Skeleton only in Phase 1.
 */
final class SocialIntegrationBoundary
{
    public const CAPABILITY = 'social.publish';

    /**
     * @return list<string>
     */
    public static function plannedSurfaces(): array
    {
        return [
            'channel.connection',
            'post.draft',
            'post.schedule',
            'post.publish',
            'engagement.ingest',
        ];
    }
}
