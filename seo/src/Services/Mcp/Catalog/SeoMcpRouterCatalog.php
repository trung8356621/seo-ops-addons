<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Catalog;

use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpPartDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;
use Omnichannel\Addons\Seo\Services\Mcp\Support\McpSizeHint;

/**
 * Canonical SEO MCP router catalog — maps AI parts to Context slice keys.
 */
final class SeoMcpRouterCatalog
{
    public static function build(ContextRegistry $contextRegistry): McpRouterRegistry
    {
        return new McpRouterRegistry(self::definitions(), $contextRegistry);
    }

    /**
     * @return list<McpRouterDefinition>
     */
    public static function definitions(): array
    {
        return [
            new McpRouterDefinition(
                key: 'site',
                title: 'Site',
                description: 'Site health and synchronization context.',
                whenToUse: 'Use when evaluating site readiness or WordPress sync state before recommendations.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'health',
                        contextKey: ContextSliceKey::SITE_HEALTH,
                        whenToUse: 'When evaluating overall SEO/site health before making recommendations.',
                        sizeHint: McpSizeHint::Small,
                    ),
                    new McpPartDefinition(
                        key: 'sync',
                        contextKey: ContextSliceKey::SITE_SYNC,
                        whenToUse: 'When WordPress/site synchronization state affects what data can be trusted.',
                        sizeHint: McpSizeHint::Small,
                    ),
                ],
            ),
            new McpRouterDefinition(
                key: 'content',
                title: 'Content',
                description: 'Content inventory and distribution across the site.',
                whenToUse: 'Use when understanding what content exists and how it is distributed.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'inventory',
                        contextKey: ContextSliceKey::CONTENT_INVENTORY,
                        whenToUse: 'When needing a high-level inventory of site content assets.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                    new McpPartDefinition(
                        key: 'distribution',
                        contextKey: ContextSliceKey::CONTENT_DISTRIBUTION,
                        whenToUse: 'When analyzing how content is distributed across types, status, or structures.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                ],
            ),
            new McpRouterDefinition(
                key: 'seo',
                title: 'SEO',
                description: 'SEO findings and internal linking context.',
                whenToUse: 'Use when auditing SEO issues or internal link structure.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'findings',
                        contextKey: ContextSliceKey::SEO_FINDINGS,
                        whenToUse: 'When reviewing detected SEO findings/issues that need attention.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                    new McpPartDefinition(
                        key: 'internal_links',
                        contextKey: ContextSliceKey::SEO_INTERNAL_LINKS,
                        whenToUse: 'When reasoning about internal link coverage or orphan/hub patterns.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                ],
            ),
            new McpRouterDefinition(
                key: 'publishing',
                title: 'Publishing',
                description: 'Publishing pipeline status for the site.',
                whenToUse: 'Use when checking whether content is scheduled, published, or blocked in the publishing flow.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'status',
                        contextKey: ContextSliceKey::PUBLISHING_STATUS,
                        whenToUse: 'When needing a compact publishing status snapshot.',
                        sizeHint: McpSizeHint::Small,
                    ),
                ],
            ),
            new McpRouterDefinition(
                key: 'keywords',
                title: 'Keywords',
                description: 'Keyword landscape and relationship intelligence.',
                whenToUse: 'Use when planning keyword coverage, selecting topics, or understanding one keyword in context.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'landscape',
                        contextKey: ContextSliceKey::KEYWORDS_LANDSCAPE,
                        whenToUse: 'When deciding broad topic/keyword coverage or planning new content.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                    new McpPartDefinition(
                        key: 'relationship',
                        contextKey: ContextSliceKey::KEYWORDS_RELATIONSHIP,
                        whenToUse: 'When reasoning about one specific keyword and its topic/articles/relationships.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                ],
            ),
            new McpRouterDefinition(
                key: 'gsc',
                title: 'Google Search Console',
                description: 'Search Console performance, opportunities and cannibalization context.',
                whenToUse: 'Use when actual Search Console evidence should inform an SEO decision.',
                scope: 'site',
                parts: [
                    new McpPartDefinition(
                        key: 'performance',
                        contextKey: ContextSliceKey::GSC_PERFORMANCE,
                        whenToUse: 'When actual Search Console performance is relevant to a decision.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                    new McpPartDefinition(
                        key: 'opportunities',
                        contextKey: ContextSliceKey::GSC_OPPORTUNITIES,
                        whenToUse: 'When looking for measurable SEO opportunities from search performance.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                    new McpPartDefinition(
                        key: 'cannibalization',
                        contextKey: ContextSliceKey::GSC_CANNIBALIZATION,
                        whenToUse: 'When checking whether multiple URLs compete for the same queries.',
                        sizeHint: McpSizeHint::Medium,
                    ),
                ],
            ),
        ];
    }
}
