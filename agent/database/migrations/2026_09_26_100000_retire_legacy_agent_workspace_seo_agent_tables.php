<?php

declare(strict_types=1);

/**
 * Retire legacy Agent Workspace persistence plane (`seo_agent_*`).
 *
 * DOES NOT touch Content Project MCP/planner tables:
 * - seo_content_project_agent_sessions
 * - seo_content_project_agent_plans
 * - seo_content_project_agent_plan_steps
 * - seo_content_project_agent_approvals
 *
 * Data preservation is not required — disposable test/legacy data.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /**
     * FK-safe drop order (children / dependents first).
     *
     * @var list<string>
     */
    private const AGENT_WORKSPACE_TABLES = [
        // Packs
        'seo_agent_pack_templates',
        'seo_agent_pack_skills',
        'seo_agent_pack_revisions',
        'seo_agent_packs',
        // Observability / evaluation / governance
        'seo_agent_feedback',
        'seo_agent_reviews',
        'seo_agent_evaluation_results',
        'seo_agent_evaluation_runs',
        'seo_agent_evaluation_cases',
        'seo_agent_evaluation_datasets',
        'seo_agent_metric_aggregates',
        'seo_agent_metric_events',
        'seo_agent_trace_spans',
        'seo_agent_traces',
        // Agent Workspace product automations (NOT Business Hook automation_*)
        'seo_agent_automation_states',
        'seo_agent_automation_approvals',
        'seo_agent_automation_runs',
        'seo_agent_automations',
        // Knowledge / memory
        'seo_agent_memory_proposals',
        'seo_agent_knowledge_chunks',
        'seo_agent_knowledge_items',
        // Planning / execution
        'seo_agent_planning_runs',
        'seo_agent_execution_plans',
        'seo_agent_executions',
        'seo_agent_messages',
        'seo_agent_conversations',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        foreach (self::AGENT_WORKSPACE_TABLES as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }

    public function down(): void
    {
        // Irreversible retirement — recreate via historical Agent Workspace migrations only if needed for local archaeology.
    }
};
