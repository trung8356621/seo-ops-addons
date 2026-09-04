<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryListService;
use Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ArticleExecutionHistoryService;
use Omnichannel\Addons\Content\Services\ArticleExecutionHistory\ExecutionHistoryNodeVisibility;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\ViewArticlePrompts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class ArticleExecutionHistoryPresentationTest extends TestCase
{
    public function test_ai_calls_list_filters_only_real_prompt_results(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleAiHistoryListService::class))->getFileName(),
        );
        self::assertStringContainsString('function listAiCalls', $source);
        self::assertStringContainsString("(\$prompt['result_id'] ?? 0) > 0", $source);
    }

    public function test_execution_history_service_exists(): void
    {
        self::assertTrue(class_exists(ArticleExecutionHistoryService::class));
        self::assertTrue(method_exists(ArticleExecutionHistoryService::class, 'build'));
    }

    public function test_view_article_prompts_exposes_workflow_and_ai_call_tabs(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ViewArticlePrompts::class))->getFileName());
        self::assertStringContainsString('getExecutionRuns', $src);
        self::assertStringContainsString('getAiCallGroups', $src);
        self::assertStringContainsString('getMaxContentWidth', $src);
        self::assertStringContainsString('MaxWidth::Full', $src);
        self::assertStringContainsString('setActiveTab', $src);
        self::assertStringContainsString('executionHistory', $src);
        self::assertStringContainsString('listAiCalls', $src);
    }

    public function test_blade_has_workflow_canvas_assets(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'),
        );
        self::assertStringContainsString('tab_workflow', $blade);
        self::assertStringContainsString('tab_ai_calls', $blade);
        self::assertStringContainsString('article-execution-history-root', $blade);
        self::assertStringContainsString('__SEO_PROMPTS__', $blade);
        self::assertStringContainsString('getAiCallGroups', $blade);
    }

    public function test_execution_history_js_uses_shared_task_canvas(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-execution-history.jsx',
        );
        self::assertStringContainsString('TaskWorkflowCanvas', $jsx);
        self::assertStringContainsString('seo-execution-history-workflow-panel', $jsx);
        self::assertStringContainsString('artifact_ref', $jsx);
        self::assertStringContainsString('normalizeFlowData', $jsx);
        self::assertStringContainsString('execution_by_node_id', $jsx);
        self::assertStringNotContainsString('nodes.map((node, index)', $jsx);
    }

    public function test_run_service_persists_execution_trace_additively(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('execution_trace', $src);
        self::assertStringContainsString('buildWorkflowOutputSnapshot', $src);
        self::assertStringContainsString('WorkflowExecutionTrace::fromSteps', $src);
    }

    public function test_text_routing_blade_has_no_pencil_for_text_group(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-ai-center.blade.php',
        );
        self::assertStringNotContainsString('manage_model_order', $blade);
        self::assertStringContainsString('text_routing_follows_models', $blade);
        self::assertStringContainsString("@if (\$group !== 'text')", $blade);
    }

    public function test_resolve_node_title_prefers_workflow_title_not_raw_id(): void
    {
        self::assertSame(
            'Dàn ý bài viết',
            ArticleExecutionHistoryService::resolveNodeTitle([
                'id' => 'node_1779076194905',
                'title' => 'Dàn ý bài viết',
            ]),
        );
        self::assertSame(
            'Extract keywords',
            ArticleExecutionHistoryService::resolveNodeTitle([
                'id' => 'node_extract',
                'data' => ['label' => 'Extract keywords'],
            ]),
        );
    }

    public function test_execution_overlay_marks_completed_nodes_from_trace(): void
    {
        $overlay = $this->buildExecutionOverlay(
            workflowNodes: [
                ['id' => 'outline', 'type' => 'prompt', 'title' => 'Outline Prompt'],
                ['id' => 'extract', 'type' => 'filter', 'title' => 'Extract keywords'],
                ['id' => 'save_vocab', 'type' => 'action', 'title' => 'Save vocabulary research'],
                ['id' => 'writing', 'type' => 'prompt', 'title' => 'Writing'],
                ['id' => 'save_article', 'type' => 'action', 'title' => 'Save article'],
            ],
            trace: [
                ['node_id' => 'outline', 'status' => 'completed', 'type' => 'prompt'],
                ['node_id' => 'extract', 'status' => 'completed', 'type' => 'filter'],
                ['node_id' => 'save_vocab', 'status' => 'completed', 'type' => 'action'],
                ['node_id' => 'writing', 'status' => 'completed', 'type' => 'prompt'],
                ['node_id' => 'save_article', 'status' => 'completed', 'type' => 'action'],
            ],
            hasFullTrace: true,
        );

        self::assertCount(5, $overlay);
        foreach (['outline', 'extract', 'save_vocab', 'writing', 'save_article'] as $nodeId) {
            self::assertSame('completed', $overlay[$nodeId]['status'] ?? null);
            self::assertSame('Completed', $overlay[$nodeId]['status_label'] ?? null);
        }
    }

    public function test_execution_overlay_marks_skipped_branch_without_changing_graph(): void
    {
        $overlay = $this->buildExecutionOverlay(
            workflowNodes: [
                ['id' => 'outline', 'type' => 'prompt', 'title' => 'Outline Prompt'],
                ['id' => 'extract', 'type' => 'filter', 'title' => 'Extract keywords'],
                ['id' => 'save_vocab', 'type' => 'action', 'title' => 'Save vocabulary research'],
                ['id' => 'writing', 'type' => 'prompt', 'title' => 'Writing'],
                ['id' => 'save_article', 'type' => 'action', 'title' => 'Save article'],
            ],
            trace: [
                ['node_id' => 'outline', 'status' => 'completed', 'type' => 'prompt'],
                ['node_id' => 'extract', 'status' => 'skipped', 'skip_reason' => 'not_reachable', 'type' => 'filter'],
                ['node_id' => 'save_vocab', 'status' => 'skipped', 'skip_reason' => 'not_reachable', 'type' => 'action'],
                ['node_id' => 'writing', 'status' => 'completed', 'type' => 'prompt'],
                ['node_id' => 'save_article', 'status' => 'completed', 'type' => 'action'],
            ],
            hasFullTrace: true,
        );

        self::assertSame('skipped', $overlay['extract']['status']);
        self::assertSame('Not reachable from rerun start node', $overlay['extract']['skip_reason_label']);
        self::assertSame('skipped', $overlay['save_vocab']['status']);
        self::assertSame('completed', $overlay['writing']['status']);
    }

    public function test_legacy_run_without_trace_uses_unknown_legacy_status(): void
    {
        $overlay = $this->buildExecutionOverlay(
            workflowNodes: [
                ['id' => 'outline', 'type' => 'prompt', 'title' => 'Dàn ý bài viết'],
            ],
            trace: [],
            hasFullTrace: false,
        );

        self::assertSame('unknown', $overlay['outline']['status']);
        self::assertSame('Unknown / Legacy', $overlay['outline']['status_label']);
    }

    public function test_disconnected_node_without_trace_row_is_not_reached_when_trace_exists(): void
    {
        $overlay = $this->buildExecutionOverlay(
            workflowNodes: [
                ['id' => 'outline', 'type' => 'prompt', 'title' => 'Outline Prompt'],
                ['id' => 'orphan_branch', 'type' => 'action', 'title' => 'Unused action'],
            ],
            trace: [
                ['node_id' => 'outline', 'status' => 'completed', 'type' => 'prompt'],
            ],
            hasFullTrace: true,
        );

        self::assertSame('not_reached', $overlay['orphan_branch']['status']);
        self::assertSame('Not reached', $overlay['orphan_branch']['status_label']);
    }

    public function test_payload_exposes_workflow_graph_and_execution_overlay(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleExecutionHistoryService::class))->getFileName());
        self::assertStringContainsString('execution_by_node_id', $src);
        self::assertStringContainsString('buildExecutionByNodeId', $src);
        self::assertStringContainsString("'nodes' => is_array(\$flow['nodes']", $src);
        self::assertStringContainsString("'edges' => is_array(\$flow['edges']", $src);
    }

    public function test_task_workflow_canvas_component_exists(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/resources/js/components/TaskWorkflowCanvas.jsx';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('readOnly', $src);
        self::assertStringContainsString('executionByNodeId', $src);
        self::assertStringContainsString('getPromptOutputPorts', $src);
    }

    public function test_workflow_workspace_css_is_full_width(): void
    {
        $css = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/css/project-run-step.css',
        );
        self::assertStringContainsString('seo-run-history-page--workflow-tool', $css);
        self::assertStringContainsString('seo-execution-history-workspace', $css);
        self::assertStringContainsString('max-width: none', $css);
    }

    public function test_article_flow_builder_exports_normalize_flow_data(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/components/ArticleFlowBuilder.jsx',
        );
        self::assertStringContainsString('export function normalizeFlowData', $src);
    }

    public function test_node_visibility_classifies_context_routing_and_execution(): void
    {
        self::assertSame('context', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'article']));
        self::assertSame('context', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'user_input']));
        self::assertSame('routing', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'article_filter']));
        self::assertSame('execution', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'prompt']));
        self::assertSame('execution', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'filter']));
        self::assertSame('execution', ExecutionHistoryNodeVisibility::classifyNode(['type' => 'action']));
    }

    public function test_processor_filter_is_not_collapsible(): void
    {
        $desc = ExecutionHistoryNodeVisibility::describeNode(
            ['type' => 'filter', 'title' => 'Extract keywords'],
            ['status' => 'completed'],
        );
        self::assertSame('execution', $desc['semantic']);
        self::assertFalse($desc['collapsible']);
    }

    public function test_completed_article_input_is_collapsible(): void
    {
        $desc = ExecutionHistoryNodeVisibility::describeNode(
            ['type' => 'article', 'title' => 'Bài viết'],
            ['status' => 'completed'],
        );
        self::assertSame('context', $desc['semantic']);
        self::assertTrue($desc['collapsible']);
    }

    public function test_failed_routing_node_is_not_collapsible(): void
    {
        $desc = ExecutionHistoryNodeVisibility::describeNode(
            ['type' => 'article_filter', 'title' => 'Lọc bài viết'],
            ['status' => 'failed', 'message' => 'Filter error'],
        );
        self::assertSame('routing', $desc['semantic']);
        self::assertFalse($desc['collapsible']);
    }

    public function test_unusual_skip_reason_blocks_collapse(): void
    {
        $desc = ExecutionHistoryNodeVisibility::describeNode(
            ['type' => 'article_filter', 'title' => 'Lọc bài viết'],
            ['status' => 'skipped', 'skip_reason' => 'condition_not_met'],
        );
        self::assertFalse($desc['collapsible']);
    }

    public function test_payload_includes_node_visibility_and_context_summary(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ArticleExecutionHistoryService::class))->getFileName());
        self::assertStringContainsString('node_visibility', $src);
        self::assertStringContainsString('context_summary', $src);
        self::assertStringContainsString('ExecutionHistoryNodeVisibility::classifyWorkflowNodes', $src);
        self::assertStringContainsString('buildContextSummary', $src);
    }

    public function test_execution_history_js_has_simplified_projection_and_toggle(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-execution-history.jsx',
        );
        $projection = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/executionHistoryGraphProjection.js',
        );
        self::assertStringContainsString('projectExecutionHistoryGraph', $jsx);
        self::assertStringContainsString('showFullWorkflow', $jsx);
        self::assertStringNotContainsString('ContextSummaryCard', $jsx);
        self::assertStringContainsString('virtualEdges', $jsx);
        self::assertStringContainsString('node_visibility', $jsx);
        self::assertStringContainsString('context_summary', $jsx);
        self::assertStringContainsString('ARTICLE_CONTEXT_NODE_ID', $projection);
        self::assertStringContainsString('__execution_article_context__', $projection);
        self::assertStringContainsString('buildVirtualArticleContextNode', $projection);
        self::assertStringContainsString('execution_article_context', $projection);
        self::assertStringContainsString('virtual: true', $projection);
    }

    public function test_simplified_projection_single_branch_from_article_context(): void
    {
        $projection = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/executionHistoryGraphProjection.js',
        );
        self::assertStringContainsString('findRootVisibleTargets', $projection);
        self::assertStringContainsString('sourceNode: ARTICLE_CONTEXT_NODE_ID', $projection);
    }

    public function test_task_canvas_renders_presentation_article_context_node(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/components/TaskWorkflowCanvas.jsx',
        );
        self::assertStringContainsString('virtualEdges', $src);
        self::assertStringContainsString('isPresentationContextNode', $src);
        self::assertStringContainsString('CONTEXT', $src);
        self::assertStringContainsString('strokeDasharray', $src);
        self::assertStringNotContainsString('presentationAnchor', $src);
    }

    public function test_classify_workflow_nodes_maps_all_node_ids(): void
    {
        $nodes = [
            ['id' => 'article', 'type' => 'article'],
            ['id' => 'filter', 'type' => 'article_filter'],
            ['id' => 'outline', 'type' => 'prompt'],
            ['id' => 'extract', 'type' => 'filter'],
        ];
        $execution = [
            'article' => ['status' => 'completed'],
            'filter' => ['status' => 'completed'],
            'outline' => ['status' => 'completed'],
            'extract' => ['status' => 'completed'],
        ];

        $map = ExecutionHistoryNodeVisibility::classifyWorkflowNodes($nodes, $execution);
        self::assertTrue($map['article']['collapsible']);
        self::assertTrue($map['filter']['collapsible']);
        self::assertFalse($map['outline']['collapsible']);
        self::assertFalse($map['extract']['collapsible']);
    }

    public function test_execution_history_js_shows_ai_call_count_in_prompt_node_title(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-execution-history.jsx',
        );
        $helper = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/executionHistoryNodeTitle.js',
        );
        self::assertStringContainsString('formatPromptNodeTitleWithAiCallCount', $jsx);
        self::assertStringContainsString('enrichPromptNodesWithAiCallCounts', $jsx);
        self::assertStringContainsString('(${count})', $helper);
        self::assertStringContainsString('ai_calls', $helper);
    }

    public function test_execution_history_service_loads_site_domain_only(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleExecutionHistoryService::class))->getFileName(),
        );
        self::assertStringContainsString("loadMissing(['site:id,domain'])", $source);
        self::assertStringNotContainsString("'url'", $source);
    }

    public function test_execution_history_maps_split_outline_subtasks_per_result(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleExecutionHistoryService::class))->getFileName(),
        );
        self::assertStringContainsString("\$snapshot['outline_subtask']", $source);
        self::assertStringContainsString("'outline' => 0, 'vocabulary' => 1", $source);
        self::assertStringContainsString('nodeInspectorMessage', $source);
        self::assertStringContainsString('outline_result_id', $source);
        self::assertStringContainsString('vocabulary_result_id', $source);
    }

    public function test_execution_history_js_hides_aggregate_message_when_child_ai_calls_exist(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-execution-history.jsx',
        );
        self::assertStringContainsString('!(isPrompt && aiCalls.length > 0)', $jsx);
        self::assertStringContainsString('call.message', $jsx);
        self::assertStringContainsString('call.status_label', $jsx);
    }

    /**
     * @param  list<array<string, mixed>>  $workflowNodes
     * @param  list<array<string, mixed>>  $trace
     * @return array<string, array<string, mixed>>
     */
    private function buildExecutionOverlay(array $workflowNodes, array $trace, bool $hasFullTrace): array
    {
        $service = new ArticleExecutionHistoryService(
            new \Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService,
            new \Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver,
        );
        $method = new ReflectionMethod(ArticleExecutionHistoryService::class, 'buildExecutionByNodeId');
        $method->setAccessible(true);

        /** @var array<string, array<string, mixed>> $result */
        $result = $method->invoke(
            $service,
            ['nodes' => $workflowNodes, 'edges' => []],
            $trace,
            collect(),
            collect(),
            $hasFullTrace,
        );

        return $result;
    }
}
