<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Catalog;

use InvalidArgumentException;

final class AgentCapabilityCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'site.knowledge' => self::capability('Site Knowledge', 'Site identity, business context, and current site-level facts.', true, 'direct', false, 'SeoAccessSiteKnowledgeComposer', ['site']),
            'keywords.inventory' => self::capability('Keyword Inventory', 'Actual managed keywords in the site-scoped Keywords inventory.', true, 'direct', false, 'SeoAccessKeywordsComposer::inventory', ['keyword_inventory']),
            'keywords.landscape' => self::capability('Keyword Landscape', 'Keyword landscape and coverage for planning and prioritization.', true, 'direct', false, 'SeoAccessBusinessModulesComposer::keywords', ['keywords']),
            'keywords.relationship' => self::capability('Keyword Relationship', 'Topic and keyword relationships used for semantic planning.', true, 'direct', false, 'SeoAccessBusinessModulesComposer::keywordsTopic', ['topics', 'keywords']),
            'articles.inventory' => self::capability('Articles Inventory', 'Collection-level article inventory and SEO signals; never a plain detail lookup for one article.', true, 'direct', false, 'SeoAccessBusinessModulesComposer::articles', ['articles']),
            'links.internal' => self::capability('Internal Links', 'Internal link inventory and relationships.', true, 'direct', false, 'SeoAccessBusinessModulesComposer::links(internal)', ['internal_links']),
            'links.external' => self::capability('External Links', 'External and managed cross-site link inventory.', true, 'direct', false, 'SeoAccessBusinessModulesComposer::links(external)', ['external_links']),
            'site.network' => self::capability('Site Network', 'Directional relationships across accessible managed sites.', true, 'direct', false, 'SiteNetworkReadModel', [], 'not_connected'),
            'industry.core' => self::capability('Industry Core', 'Full industry and business context for deep planning.', true, 'direct', false, 'IndustryContextProfile', [], 'not_connected'),
            'gsc.performance' => self::capability('GSC Performance', 'Google Search Console performance for the requested period.', true, 'tool', false, 'RetrievalExecutor -> SeoAccessGscComposer::compose', ['gsc']),
            'content_projects.read' => self::capability('Content Projects Read', 'Planning, project state, and draft workflow information.', true, 'tool', false, 'ContentProjectAgentReadService', ['content_projects']),
            'seo_audit.worst_articles' => self::capability('SEO Audit – Worst Articles', 'Find a group of poor SEO article candidates for optimization planning.', true, 'tool', false, 'SeoAuditAgentReadService::listArticles', ['articles']),
            'seo_audit.improve' => self::capability('SEO Audit – Improve Article', 'Start the supported SEO Audit or Draft improvement workflow.', true, 'tool', false, 'not_connected', ['articles'], 'not_connected'),
            'seo_audit.publish' => self::capability('SEO Audit – Publish Article', 'Start the supported SEO Audit or Draft publishing workflow.', true, 'tool', false, 'not_connected', ['articles'], 'not_connected'),
            'industry.core_small' => self::capability('Industry Core Small', 'Compact companion industry context.', false, 'companion', false, 'IndustryContextProfile', []),
            'industry.discovery' => self::capability('Industry Discovery', '', false, 'internal', false, 'IndustryContextGenerationService::discovery', []),
            'industry.breakout' => self::capability('Industry Breakout', '', false, 'internal', false, 'IndustryContextGenerationService::breakout', []),
            'seo.router' => self::capability('SEO Router', '', false, 'internal', false, 'Agent routing stage', []),
            'industry.match' => self::capability('Industry Match', '', false, 'internal', false, 'IndustryMatchRuntime', []),
        ];
    }

    /** @return list<array{key: string, description: string}> */
    public static function modelVisible(): array
    {
        $visible = [];
        foreach (self::all() as $key => $metadata) {
            if ($metadata['jev_selectable'] === true && $metadata['status'] === 'available') {
                $visible[] = ['key' => $key, 'description' => $metadata['description']];
            }
        }

        return $visible;
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $capability = self::all()[$key] ?? null;
        if (! is_array($capability)) {
            throw new InvalidArgumentException("Unknown Agent capability [{$key}].");
        }

        return $capability;
    }

    public static function isSelectable(string $key): bool
    {
        return (self::all()[$key]['jev_selectable'] ?? false) === true;
    }

    public static function known(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function isAvailable(string $key): bool
    {
        return (self::all()[$key]['status'] ?? null) === 'available';
    }

    public static function requiresConfirmation(string $key): bool
    {
        return (self::get($key)['requires_confirmation'] ?? false) === true;
    }

    public static function executionMode(string $key): string
    {
        return (string) (self::get($key)['execution_mode'] ?? '');
    }

    /** @param list<string> $capabilities @return list<string> */
    public static function toolCapabilities(array $capabilities): array
    {
        return array_values(array_filter(
            $capabilities,
            static fn (string $key): bool => self::executionMode($key) === 'tool' && self::isAvailable($key),
        ));
    }

    /** @param list<string> $capabilities @return list<string> */
    public static function modulesFor(array $capabilities): array
    {
        $modules = [];
        foreach ($capabilities as $key) {
            foreach ((array) (self::get($key)['modules'] ?? []) as $module) {
                $modules[] = (string) $module;
            }
        }

        return array_values(array_unique($modules));
    }

    public static function fromLegacyModule(string $module): ?string
    {
        return [
            'site' => 'site.knowledge',
            'articles' => 'articles.inventory',
            'internal_links' => 'links.internal',
            'external_links' => 'links.external',
            'keywords' => 'keywords.landscape',
            'topics' => 'keywords.relationship',
            'content_projects' => 'content_projects.read',
            'gsc' => 'gsc.performance',
        ][$module] ?? null;
    }

    /** @param list<string> $modules @return array<string, mixed> */
    private static function capability(string $label, string $description, bool $selectable, string $mode, bool $confirmation, string $executor, array $modules, string $status = 'available'): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'jev_selectable' => $selectable,
            'execution_mode' => $mode,
            'requires_confirmation' => $confirmation,
            'status' => $status,
            'executor' => $executor,
            'modules' => $modules,
        ];
    }
}
