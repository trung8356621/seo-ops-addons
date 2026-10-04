<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

/**
 * Resources the executor may request. This is not a generic HTTP allowlist.
 */
final class SeoAccessCapabilityCatalog
{
    /**
     * @return list<string>
     */
    public static function resources(): array
    {
        return ['site', 'articles', 'internal_links', 'external_links', 'keywords', 'topics', 'content_projects', 'gsc'];
    }

    /**
     * @return list<string>
     */
    public static function missing(): array
    {
        return [
            'global_seo_access',
            'cross_site_ranking',
            'gsc_force_sync',
            'batch_site_access',
        ];
    }

}
