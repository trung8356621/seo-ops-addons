<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;

/**
 * MCP tool catalog — built from canonical (core + enabled extension) write
 * caps + hardcoded read schemas.
 *
 * MCP is a stricter subset of the agent write surface: sync_items,
 * stop/resume_execution, and all serp_intelligence / gsc_intelligence
 * writes stay agent-only (see {@see CanonicalCapabilityRegistry::isMcpWriteExposed()}).
 */
final class ContentProjectMcpToolCatalog
{
    public function __construct(
        private readonly CanonicalCapabilityRegistry $registry,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if ($name === '' || ! $this->registry->isMcpWriteExposed($name)) {
                continue;
            }

            $schema = ContentProjectCapabilityRegistry::buildJsonSchema($cap);

            $toolName = $name === 'content_project.rerun' ? 'content_project.rerun_items' : $name;

            $tools[] = [
                'name' => $toolName,
                'description' => (string) ($cap['presentation_description'] ?? $cap['description'] ?? $toolName),
                'inputSchema' => $schema,
            ];
        }

        foreach ($this->readToolDefinitions() as $readTool) {
            $tools[] = $readTool;
        }

        foreach ($this->planToolDefinitions() as $planTool) {
            $tools[] = $planTool;
        }

        return $tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readToolDefinitions(): array
    {
        return [
            $this->readTool(
                'content_project.list_projects',
                'List Content Projects belonging to the explicitly supplied site context. Read-only. Do not use this capability to create, edit, run, or switch projects.',
                ['site_ref'],
            ),
            $this->readTool(
                'content_project.get_project',
                'Get one Content Project by project_ref within the explicitly supplied site context. Read-only. Do not invent or switch project context.',
                ['site_ref', 'project_ref'],
            ),
            $this->readTool('content_project.list_items', 'List items for an explicitly supplied project_ref. Read-only.', ['site_ref', 'project_ref']),
            $this->readTool('content_project.get_item', 'Get a single item by item_ref. Read-only.', ['item_ref']),
            $this->readTool(
                'content_project.get_status',
                'Get lifecycle status and progress for an explicitly supplied project_ref. Read-only. Do not run generation from this tool.',
                ['site_ref', 'project_ref'],
            ),
            $this->readTool('content_project.get_publishing_queue', 'Get publishing queue for a project. Read-only.', ['site_ref', 'project_ref']),
            $this->readTool('content_project.get_timeline', 'Get business timeline for a project. Read-only.', ['site_ref', 'project_ref']),
            $this->readTool('content_project.get_daily_report', 'Get daily ops report for the current site context. Read-only.', ['site_ref']),
            $this->readTool('content_project.get_site_health', 'Get site health snapshot for the current site context. Read-only.', ['site_ref']),
            $this->readTool('content_project.get_operation', 'Get operation log entry by operation_ref. Read-only.', ['operation_ref']),

            // Keyword Intelligence — additive read surface.
            $this->readTool('keyword_intelligence.list_workspaces', 'List keyword workspaces for site context.', []),
            $this->readTool('keyword_intelligence.get_workspace', 'Get a keyword workspace by workspace_ref.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_keywords', 'List keywords in a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_clusters', 'List keyword clusters in a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.get_topical_map', 'Get latest topical map version for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_topics', 'List topics in a keyword workspace topical map.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.get_topic', 'Get a topic by topic_ref.', ['workspace_ref', 'topic_ref']),
            $this->readTool('keyword_intelligence.list_map_conflicts', 'List topical map conflicts for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_link_suggestions', 'List topical link suggestions for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.list_map_versions', 'List topical map versions for a workspace.', ['workspace_ref']),
            $this->readTool('keyword_intelligence.compare_map_versions', 'Compare two topical map versions.', ['workspace_ref', 'left_map_version_ref', 'right_map_version_ref']),
            $this->readTool('keyword_intelligence.get_conversion', 'Get a keyword→content-project conversion by conversion_ref.', ['conversion_ref']),
            $this->readTool('keyword_intelligence.get_analysis_operation', 'Get keyword analysis operation by operation_ref.', ['operation_ref']),

            // SERP Intelligence — additive read surface.
            $this->readTool('serp_intelligence.list_queries', 'List SERP queries in a workspace.', ['workspace_ref']),
            $this->readTool('serp_intelligence.get_query', 'Get a SERP query by query_ref.', ['workspace_ref', 'query_ref']),
            $this->readTool('serp_intelligence.list_snapshots', 'List SERP snapshots for a workspace/query.', ['workspace_ref']),
            $this->readTool('serp_intelligence.get_snapshot', 'Get a SERP snapshot by snapshot_ref.', ['workspace_ref', 'snapshot_ref']),
            $this->readTool('serp_intelligence.list_results', 'List SERP results for a snapshot.', ['snapshot_ref']),
            $this->readTool('serp_intelligence.list_features', 'List SERP features for a snapshot.', ['snapshot_ref']),
            $this->readTool('serp_intelligence.get_cluster_evidence', 'Get SERP cluster evidence by evidence_ref.', ['workspace_ref', 'evidence_ref']),
            $this->readTool('serp_intelligence.list_content_gaps', 'List SERP content gaps for a workspace.', ['workspace_ref']),
            $this->readTool('serp_intelligence.list_competitors', 'List competitor summary for a snapshot.', ['snapshot_ref']),
            $this->readTool('serp_intelligence.get_operation', 'Get SERP collection operation by operation_ref.', ['operation_ref']),

            // GSC Intelligence — additive read surface.
            $this->readTool('gsc_intelligence.list_properties', 'List GSC properties for site context.', []),
            $this->readTool('gsc_intelligence.get_property', 'Get a GSC property by property_ref.', ['property_ref']),
            $this->readTool('gsc_intelligence.list_sync_runs', 'List GSC sync runs for a property.', ['property_ref']),
            $this->readTool('gsc_intelligence.get_sync_run', 'Get a GSC sync run by sync_run_ref.', ['property_ref', 'sync_run_ref']),
            $this->readTool('gsc_intelligence.list_query_mappings', 'List GSC query mappings for a property.', ['property_ref']),
            $this->readTool('gsc_intelligence.get_query_mapping', 'Get a GSC query mapping by mapping_ref.', ['property_ref', 'mapping_ref']),
            $this->readTool('gsc_intelligence.list_page_mappings', 'List GSC page mappings for a property.', ['property_ref']),
            $this->readTool('gsc_intelligence.get_page_mapping', 'Get a GSC page mapping by mapping_ref.', ['property_ref', 'mapping_ref']),
            $this->readTool('gsc_intelligence.list_aggregates', 'List GSC performance aggregates for a property.', ['property_ref']),
            $this->readTool('gsc_intelligence.get_aggregate', 'Get a GSC performance aggregate by aggregate_ref.', ['property_ref', 'aggregate_ref']),
            $this->readTool('gsc_intelligence.list_opportunities', 'List GSC opportunities for a property.', ['property_ref']),
            $this->readTool('gsc_intelligence.get_opportunity', 'Get a GSC opportunity by opportunity_ref.', ['property_ref', 'opportunity_ref']),
            $this->readTool('gsc_intelligence.get_operation', 'Get GSC sync operation by operation_ref.', ['operation_ref']),

            // SEO Audit — site-level read (same query surface as Articles Optimal).
            $this->readTool('seo_audit.list', 'List articles needing SEO audit work for the current site context. Read-only. Optional post_type filter (empty/all = all types). Does not require project_ref.', ['site_ref']),
            $this->readTool('domain.seo_brief', 'Prepared SEO brief from snapshots. No realtime crawl.', ['site_ref']),
            $this->readTool('domain.keyword_overview', 'Aggregate keyword classification counts. No raw dump.', ['site_ref']),
            $this->readTool('domain.keyword_landscape', 'Compressed keyword topic map: core/weak/saturated.', ['site_ref']),
            $this->readTool('domain.keyword_gaps', 'Cluster-level keyword gaps and recommended actions.', ['site_ref']),
            $this->readTool('domain.keyword_cluster_detail', 'Drill-down one cluster with representative variants only.', ['site_ref']),
            $this->readTool('domain.keyword_generation_context', 'Compact AI generation context with hard budget.', ['site_ref']),
            $this->readTool('domain.keyword_opportunities', 'Prepared keyword opportunities or unavailable/stale.', ['site_ref']),
            $this->readTool('domain.keyword_near_top', 'Keywords near top positions when rank data is reliable.', ['site_ref']),
            $this->readTool('domain.rewrite_candidates', 'Prepared rewrite candidates (no full-site AI).', ['site_ref']),
            $this->readTool('domain.content_opportunities', 'Prepared content opportunities from snapshots.', ['site_ref']),
            $this->readTool('domain.internal_link_opportunities', 'Prepared WP local internal-link opportunities.', ['site_ref']),
            $this->readTool('domain.orphan_pages', 'Prepared orphan page counts from WP local index.', ['site_ref']),
            $this->readTool('domain.broken_links', 'Prepared broken-link counts from Link Health snapshot.', ['site_ref']),
            $this->readTool('domain.indexability', 'Indexability summary from typed SEO snapshots.', ['site_ref']),
            $this->readTool('domain.action_plan', 'Deterministic action plan from open SEO findings.', ['site_ref']),
            $this->readTool('domain.monthly_intelligence', 'Stored monthly MCP report AI context. Does not rebuild source modules.', ['site_ref']),
            $this->readTool('domain.run_analysis', 'Dispatch link_health, link_opportunities, or keyword_refresh. Does not wait.', ['site_ref']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planToolDefinitions(): array
    {
        $defs = [
            ['content_project.plan', 'Create agent plan draft from objective.', ['objective']],
            ['content_project.confirm_plan', 'Confirm plan before execution.', ['plan_ref']],
            ['content_project.start_plan', 'Start confirmed plan execution.', ['plan_ref']],
            ['content_project.pause_plan', 'Pause running plan.', ['plan_ref']],
            ['content_project.resume_plan', 'Resume paused plan.', ['plan_ref']],
            ['content_project.cancel_plan', 'Cancel plan and pending steps.', ['plan_ref']],
            ['content_project.get_agent_plan', 'Get plan by plan_ref.', ['plan_ref']],
            ['content_project.list_agent_plans', 'List recent agent plans.', []],
            ['content_project.retry_plan_step', 'Retry failed plan step.', ['plan_ref', 'step_ref']],
            ['content_project.get_agent_policy', 'Preview automation policy for tenant/site.', []],
            ['content_project.approve_agent_action', 'Approve pending agent action.', ['approval_ref']],
            ['content_project.reject_agent_action', 'Reject pending agent approval.', ['approval_ref']],
            ['content_project.list_pending_approvals', 'List pending approvals.', []],
        ];

        $tools = [];
        foreach ($defs as [$name, $description, $required]) {
            $properties = [
                'objective' => ['type' => 'string'],
                'plan_ref' => ['type' => 'string'],
                'step_ref' => ['type' => 'string'],
                'approval_ref' => ['type' => 'string'],
                'state_fingerprint' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
                'constraints' => ['type' => 'object'],
            ];

            $tools[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ];
        }

        return $tools;
    }

    public function isPlanTool(string $name): bool
    {
        return in_array($name, ContentProjectAgentPlanGateway::PLAN_TOOLS, true);
    }

    /**
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function readTool(string $name, string $description, array $required): array
    {
        $properties = [];
        foreach ([
            'site_ref',
            'project_ref',
            'item_ref',
            'operation_ref',
            'date',
            'workspace_ref',
            'query_ref',
            'snapshot_ref',
            'evidence_ref',
            'gap_ref',
            'cluster_ref',
            'property_ref',
            'sync_run_ref',
            'mapping_ref',
            'aggregate_ref',
            'opportunity_ref',
            'period',
            'selected_items',
        ] as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
            'side_effects' => 'none',
            'confirmation_policy' => 'none',
            'mode' => 'read',
        ];
    }
}
