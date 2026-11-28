<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Routing\CatalogToolRouteAuthority;

final class StructuredRetrievalPlanner
{
    public function __construct(
        private readonly CatalogToolRouteAuthority $authority = new CatalogToolRouteAuthority(),
        private readonly int $maxCapabilities = 5,
    ) {}

    public function plan(QueryUnderstanding $understanding): RetrievalPlanResult
    {
        $mapping = [
            'site_knowledge' => ['site.knowledge'],
            'articles' => ['articles.inventory'],
            'article_quality' => ['seo_audit.worst_articles'],
            'keyword_inventory' => ['keywords.inventory'],
            'keyword_landscape' => ['keywords.landscape'],
            'topic_coverage' => ['keywords.relationship'],
            'internal_links' => ['links.internal'],
            'external_links' => ['links.external'],
            'content_projects' => ['content_projects.read'],
            'technical_audit' => [],
        ];

        $capabilities = [];
        $missing = [];
        $requirements = match ($understanding->parameters['entity_type'] ?? null) {
            'keyword' => ['keyword_inventory'],
            'topic' => ['topic_coverage'],
            default => $understanding->dataRequirements,
        };
        foreach ($requirements as $requirement) {
            $candidates = $mapping[$requirement] ?? [];
            $accepted = false;
            foreach ($candidates as $capability) {
                if (! AgentCapabilityCatalog::known($capability) || ! $this->authority->accepts($capability)) {
                    continue;
                }
                if (AgentCapabilityCatalog::requiresConfirmation($capability)) {
                    continue;
                }
                $capabilities[] = $capability;
                $accepted = true;
            }
            if (! $accepted) {
                $missing[] = $requirement;
            }
        }

        $capabilities = array_slice(array_values(array_unique($capabilities)), 0, max(1, $this->maxCapabilities));
        $modules = AgentCapabilityCatalog::modulesFor($capabilities);
        $parameters = $understanding->parameters;
        $parameters['limit_max'] = min((int) ($parameters['limit_max'] ?? ($understanding->depth === 'summary' ? 10 : 25)), 250);
        if (in_array('article_quality', $understanding->dataRequirements, true)) {
            $parameters['task'] = 'low_score';
        }
        if (isset($parameters['topic_ref'])) {
            $modules = array_values(array_diff($modules, ['keywords']));
        } elseif (in_array('topics', $modules, true) && in_array('keywords', $modules, true)) {
            $modules = array_values(array_filter($modules, static fn (string $module): bool => $module !== 'topics'));
        }

        return new RetrievalPlanResult(
            $capabilities,
            array_values(array_filter($modules, static fn (string $module): bool => $module !== 'gsc')),
            $parameters,
            array_values(array_unique($missing)),
            $understanding->depth,
        );
    }
}
