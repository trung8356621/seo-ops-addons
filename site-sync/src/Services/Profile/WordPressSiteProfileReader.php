<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Profile;

use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use Omnichannel\Addons\SiteSync\Services\Profile\Contracts\WordPressSiteProfileSource;
use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * Lightweight WordPress profile reader — not full Site Sync orchestration.
 */
final class WordPressSiteProfileReader implements WordPressSiteProfileSource
{
    private const PROFILE_ENDPOINT = '/omi-seo-ai/v1/sync/v2/profile';

    public function __construct(
        private readonly WordPressSiteSyncClient $client,
    ) {}

    /**
     * @return array{success: bool, message: string, site_name?: string, short_description?: string}
     */
    public function read(Site $site): array
    {
        $result = $this->client->fetchProfile($site);
        if (! ($result['success'] ?? false)) {
            RuntimeLogger::warning('wordpress.profile_reader_failed', [
                'site_id' => (int) $site->id,
                'endpoint' => self::PROFILE_ENDPOINT,
                'message' => (string) ($result['message'] ?? ''),
            ]);

            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'profile fetch failed'),
            ];
        }

        $profile = is_array($result['profile'] ?? null) ? $result['profile'] : [];

        $siteName = trim((string) ($profile['site_name'] ?? ''));
        if ($siteName === '') {
            $schemaOrg = is_array($profile['schema_org'] ?? null) ? $profile['schema_org'] : [];
            $siteName = trim((string) ($schemaOrg['name'] ?? ''));
        }

        return [
            'success' => true,
            'message' => 'ok',
            'site_name' => $siteName,
            'short_description' => trim((string) ($profile['short_description'] ?? '')),
        ];
    }
}
