<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\V1;

/**
 * Explicit inventory for Agent Workspace v1 coverage audit.
 * Status in inventory is expected intent; live audit recomputes actual status.
 *
 * @phpstan-type InventoryRow array{
 *   module: string,
 *   feature_key: string,
 *   label: string,
 *   operation_type: string,
 *   capability_key: ?string,
 *   skill_key: ?string,
 *   slash_command: ?string,
 *   confirmation_policy: ?string,
 *   automation_policy: string,
 *   gateway_exposure: string,
 *   mcp_exposure: string,
 *   priority: string,
 *   expected_status: string,
 *   notes: string,
 *   test_reference: ?string
 * }
 */
final class AgentCapabilityInventory
{
    /**
     * @return list<InventoryRow>
     */
    public static function rows(): array
    {
        return array_merge(
            self::contentProjectP0(),
            self::keywordP1(),
            self::serpP1(),
            self::opsP1(),
            self::agentMeta(),
        );
    }

    /**
     * @return list<InventoryRow>
     */
    private static function contentProjectP0(): array
    {
        $p0 = [
            ['content_project.create', 'Create Content Project', 'write', 'content_project.create', 'content_project.create', '/create-project', 'preview', 'write'],
            ['content_project.update', 'Update Content Project', 'write', 'content_project.update', 'content_project.update', '/update-project', 'preview', 'write'],
            ['content_project.add_items', 'Add project items', 'write', 'content_project.add_items', 'content_project.add_items', '/add-project-items', 'preview', 'write'],
            ['content_project.update_item', 'Update project item', 'write', 'content_project.update_item', 'content_project.update_item', '/update-project-item', 'preview', 'write'],
            ['content_project.generate', 'Generate project items', 'write', 'content_project.generate', 'content_project.generate', '/generate-articles', 'preview', 'write'],
            ['content_project.rerun', 'Rerun outline/article/image', 'write', 'content_project.rerun', 'content_project.rerun', '/rerun-content', 'preview', 'write'],
            ['content_project.start_review', 'Start review', 'write', 'content_project.start_review', 'content_project.start_review', '/start-review', 'preview', 'write'],
            ['content_project.approve', 'Approve items', 'write', 'content_project.approve', 'content_project.approve', '/approve-items', 'preview', 'write'],
            ['content_project.schedule', 'Schedule publishing', 'write', 'content_project.schedule', 'content_project.schedule', '/schedule-content', 'preview', 'write'],
            ['content_project.auto_schedule', 'Auto schedule', 'write', 'content_project.auto_schedule', 'content_project.auto_schedule', '/auto-schedule', 'preview', 'write'],
            ['content_project.unschedule', 'Unschedule', 'write', 'content_project.unschedule', 'content_project.unschedule', '/unschedule-content', 'confirm', 'guarded'],
            ['content_project.move_schedule', 'Move schedule', 'write', 'content_project.move_schedule', 'content_project.move_schedule', '/move-schedule', 'confirm', 'guarded'],
            ['content_project.publish_now', 'Publish now (queue)', 'write', 'content_project.publish_now', 'content_project.publish_now', '/publish-now', 'confirm', 'guarded'],
            ['content_project.retry_publish', 'Retry publish', 'write', 'content_project.retry_publish', 'content_project.retry_publish', '/retry-publish', 'confirm', 'guarded'],
            ['content_project.skip_publish', 'Skip publish', 'write', 'content_project.skip_publish', 'content_project.skip_publish', '/skip-publish', 'confirm', 'guarded'],
            ['content_project.cancel_publish', 'Cancel publish', 'write', 'content_project.cancel_publish', 'content_project.cancel_publish', '/cancel-publish', 'confirm', 'guarded'],
            ['content_project.archive', 'Archive project', 'destructive', 'content_project.archive', 'content_project.archive', '/archive-project', 'confirm', 'never'],
            ['content_project.restore', 'Restore project', 'write', 'content_project.restore', 'content_project.restore', '/restore-project', 'confirm', 'guarded'],
            ['content_project.stop_execution', 'Stop execution', 'write', 'content_project.stop_execution', 'content_project.stop_execution', '/stop-execution', 'confirm', 'guarded'],
            ['content_project.resume_execution', 'Resume execution', 'write', 'content_project.resume_execution', 'content_project.resume_execution', '/resume-execution', 'preview', 'guarded'],
            ['content_project.list_projects', 'List projects', 'read', 'content_project.list_projects', 'content_project.list', '/list-projects', 'none', 'read'],
            ['content_project.get_status', 'Project status', 'read', 'content_project.get_status', 'content_project.status', '/project-status', 'none', 'read'],
            ['content_project.list_items', 'List project items', 'read', 'content_project.list_items', 'content_project.list_items', '/list-project-items', 'none', 'read'],
            ['content_project.get_publishing_queue', 'Publishing queue', 'read', 'content_project.get_publishing_queue', 'content_project.publishing_queue', '/publishing-queue', 'none', 'read'],
            ['content_project.get_timeline', 'Project timeline', 'read', 'content_project.get_timeline', 'content_project.timeline', '/project-timeline', 'none', 'read'],
            ['content_project.get_operation', 'Operation lookup', 'read', 'content_project.get_operation', 'operations.operation_status', '/operation-status', 'none', 'read'],
        ];

        $rows = [];
        foreach ($p0 as $r) {
            $rows[] = self::row(
                module: 'content_project',
                featureKey: $r[0],
                label: $r[1],
                operationType: $r[2],
                capability: $r[3],
                skill: $r[4],
                slash: $r[5],
                confirmation: $r[6],
                automation: $r[7],
                gateway: str_starts_with($r[3], 'content_project.get_') || str_starts_with($r[3], 'content_project.list_') ? 'read' : 'write',
                priority: 'P0',
            );
        }

        return $rows;
    }

    /**
     * @return list<InventoryRow>
     */
    private static function keywordP1(): array
    {
        return [
            self::row('keyword_intelligence', 'keyword.import', 'Import keywords', 'write', 'keyword_intelligence.import_keywords', 'keyword.import', '/import-keywords', 'preview', 'write', 'write', 'P1'),
            self::row('keyword_intelligence', 'keyword.analyze', 'Analyze keywords', 'write', 'keyword_intelligence.analyze_workspace', 'keyword.analyze', '/analyze-keywords', 'preview', 'write', 'write', 'P1'),
            self::row('keyword_intelligence', 'keyword.list_workspaces', 'List keyword workspaces', 'read', 'keyword_intelligence.list_workspaces', 'keyword.list_workspaces', '/list-keyword-workspaces', 'none', 'read', 'read', 'P1'),
            self::row('keyword_intelligence', 'keyword.list_clusters', 'List clusters', 'read', 'keyword_intelligence.list_clusters', 'keyword.list_clusters', '/list-keyword-clusters', 'none', 'read', 'read', 'P1'),
            self::row('keyword_intelligence', 'keyword.build_topical_map', 'Build topical map', 'write', 'keyword_intelligence.build_topical_map', 'keyword.build_topical_map', '/build-topical-map', 'preview', 'write', 'write', 'P1'),
        ];
    }

    /**
     * @return list<InventoryRow>
     */
    private static function serpP1(): array
    {
        return [
            self::row('serp_intelligence', 'serp.collect', 'Collect SERP', 'write', 'serp_intelligence.collect', 'serp.collect', '/collect-serp', 'preview', 'write', 'write', 'P1'),
            self::row('serp_intelligence', 'serp.list_content_gaps', 'List content gaps', 'read', 'serp_intelligence.list_content_gaps', 'serp.list_content_gaps', '/list-content-gaps', 'none', 'read', 'read', 'P1'),
        ];
    }

    /**
     * @return list<InventoryRow>
     */
    private static function opsP1(): array
    {
        return [
            self::row('operations', 'ops.site_health', 'Site health', 'read', 'content_project.get_site_health', 'operations.site_health', '/site-health', 'none', 'read', 'read', 'P1'),
            self::row('operations', 'ops.daily_report', 'Daily report', 'read', 'content_project.get_daily_report', 'operations.daily_report', '/daily-report', 'none', 'read', 'read', 'P1'),
        ];
    }

    /**
     * @return list<InventoryRow>
     */
    private static function agentMeta(): array
    {
        return [
            self::row('agent_knowledge', 'knowledge.list', 'List knowledge', 'read', 'agent.knowledge.list', 'knowledge.list', '/knowledge', 'none', 'read', 'local', 'P1', 'complete', 'Local ApplicationService path'),
            self::row('agent_automation', 'automation.list', 'List automations', 'read', 'agent.automation.list', 'automation.list', '/automations', 'none', 'read', 'local', 'P1', 'complete', 'Local ApplicationService path'),
            self::row('agent_observability', 'obs.health', 'Agent health', 'read', 'agent.observability.health', 'observability.health', '/agent-health', 'none', 'read', 'local', 'P1', 'complete', 'Manager diagnostics'),
            self::row('agent_packs', 'packs.list', 'List packs', 'read', 'agent.pack.list', 'packs.list', '/agent-packs', 'none', 'read', 'local', 'P1', 'complete', 'Manager packs'),
            self::row('internal', 'sync_items', 'Sync items (internal)', 'internal', 'content_project.sync_items', null, null, null, 'never', 'internal', 'P0', 'internal-only', 'Gateway write exposure blocked'),
        ];
    }

    /**
     * @return InventoryRow
     */
    private static function row(
        string $module,
        string $featureKey,
        string $label,
        string $operationType,
        ?string $capability,
        ?string $skill,
        ?string $slash,
        ?string $confirmation,
        string $automation,
        string $gateway,
        string $priority,
        string $expectedStatus = 'complete',
        string $notes = '',
        ?string $testReference = null,
    ): array {
        return [
            'module' => $module,
            'feature_key' => $featureKey,
            'label' => $label,
            'operation_type' => $operationType,
            'capability_key' => $capability,
            'skill_key' => $skill,
            'slash_command' => $slash,
            'confirmation_policy' => $confirmation,
            'automation_policy' => $automation,
            'gateway_exposure' => $gateway,
            'mcp_exposure' => $gateway === 'internal' ? 'none' : 'follow_gateway',
            'priority' => $priority,
            'expected_status' => $expectedStatus,
            'notes' => $notes,
            'test_reference' => $testReference,
        ];
    }
}
