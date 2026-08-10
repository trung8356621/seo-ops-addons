<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Observability;

use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncHeartbeat;

final class SiteSyncHeartbeatService
{
    public function touch(string $channel, array $meta = []): void
    {
        SeoSiteSyncHeartbeat::query()->updateOrCreate(
            ['channel' => $channel],
            [
                'last_seen_at' => now(),
                'meta' => $meta,
            ],
        );
    }
}
