<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Heartbeat;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Independent lightweight WP heartbeat poll (not tied to Site Sync).
 */
final class WordPressHeartbeatPollService
{
    public const META_KEY = 'seo_wp_heartbeat';

    public function __construct(
        private readonly WordPressSiteSyncClient $client,
    ) {}

    /**
     * @return array{ok: bool, status: string, message: string, heartbeat?: array<string, mixed>}
     */
    public function poll(Site $site): array
    {
        $result = $this->client->fetchHeartbeat($site);
        if (! ($result['success'] ?? false) || ! isset($result['heartbeat']) || ! is_array($result['heartbeat'])) {
            $payload = [
                'status' => 'offline',
                'success' => false,
                'message' => (string) ($result['message'] ?? 'heartbeat unavailable'),
                'observed_at' => now()->toIso8601String(),
            ];
            SiteSyncSiteMeta::putJson($site, self::META_KEY, $payload);

            return [
                'ok' => false,
                'status' => 'offline',
                'message' => $payload['message'],
                'heartbeat' => $payload,
            ];
        }

        $payload = $result['heartbeat'];
        $payload['status'] = (string) ($payload['status'] ?? 'ok');
        $payload['observed_at'] = now()->toIso8601String();
        SiteSyncSiteMeta::putJson($site, self::META_KEY, $payload);

        return [
            'ok' => true,
            'status' => $payload['status'],
            'message' => 'ok',
            'heartbeat' => $payload,
        ];
    }
}
