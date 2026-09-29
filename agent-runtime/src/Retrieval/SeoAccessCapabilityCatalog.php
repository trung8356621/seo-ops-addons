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

    /**
     * @return array<string, mixed>
     */
    public static function modelVisible(): array
    {
        return [
            'resources' => self::resources(),
            'semantics' => 'Business modules only; the runtime maps modules to read resources.',
            'keywords_topic_detail' => 'topic:{id} via parameters.topic_ref',
            'gsc_period' => 'YYYY-MM via parameters.period',
            'missing' => self::missing(),
            'write' => [
                'content_project.draft.intake' => 'not_connected',
            ],
        ];
    }
}
