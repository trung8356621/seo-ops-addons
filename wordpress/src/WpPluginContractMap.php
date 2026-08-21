<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress;

/**
 * Maps wp-seo-ai REST surface → Laravel wordpress addon contracts.
 * Phase 1: inventory constants only — no route rewrite.
 */
final class WpPluginContractMap
{
    public const PLUGIN_REST_NAMESPACE = 'omi-seo-ai/v1';

    public const LARAVEL_BRIDGE_PREFIX = '/api/seo-wp-bridge';

    /**
     * @return array<string, string> plugin route => owning capability
     */
    public static function routeCapabilityMap(): array
    {
        return [
            'GET /capabilities' => 'wordpress.bridge',
            'GET /site-info' => 'wordpress.bridge',
            'GET /sync' => 'site-sync.v2',
            'GET /sync/manifest' => 'site-sync.v2',
            'GET /sync/items' => 'site-sync.v2',
            'GET /sync/v2/profile' => 'site-sync.v2',
            'GET /sync/v2/delta' => 'site-sync.v2',
            'GET /sync/v2/batches' => 'site-sync.v2',
            'GET /sync/v2/manifest' => 'site-sync.v2',
            'GET /taxonomy-catalog/{taxonomy}' => 'wordpress.bridge',
            'POST /posts' => 'wordpress.publisher',
            'POST /posts/{id}/editor-sync' => 'wordpress.bridge',
            'POST /posts/{id}/media' => 'media.library',
            'POST /attachments/*' => 'media.library',
            'Laravel POST /api/seo-wp-bridge/push-content' => 'wordpress.bridge',
            'Laravel POST /api/seo-wp-bridge/snapshot-callback' => 'site-sync.v2',
            'Laravel POST /api/seo-wp-bridge/delta-event' => 'site-sync.v2',
            'Laravel GET /api/seo-wp-bridge/ping' => 'wordpress.bridge',
            'WP REST POST /plugin-update/check' => 'wordpress.bridge',
            'WP REST POST /plugin-update/install' => 'wordpress.bridge',
        ];
    }
}
