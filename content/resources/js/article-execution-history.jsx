import React, { useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import TaskWorkflowCanvas from '@content-projects-addon/components/TaskWorkflowCanvas.jsx';
import { normalizeFlowData } from '@content-projects-addon/components/ArticleFlowBuilder.jsx';
import { actionTypeCanvasLabel, filterTypeLabel } from '@content-projects-addon/components/flowNodeHelpers';
import {
  ARTICLE_CONTEXT_NODE_ID,
  projectExecutionHistoryGraph,
} from './executionHistoryGraphProjection.js';
import {
  enrichPromptNodesWithAiCallCounts,
  formatPromptNodeTitleWithAiCallCount,
} from './executionHistoryNodeTitle.js';
import '../../../content-projects/resources/css/task-builder.css';

function resolvePrompts(promptsProp) {
  if (Array.isArray(promptsProp) && promptsProp.length > 0) {
    return promptsProp;
  }
  if (typeof window !== 'undefined' && Array.isArray(window.__SEO_PROMPTS__) && window.__SEO_PROMPTS__.length > 0) {
    return window.__SEO_PROMPTS__;
  }
  return [];
}

function formatRanAt(ranAt) {
  if (!ranAt) return '';
  try {
    const date = new Date(ranAt);
    if (Number.isNaN(date.getTime())) return String(ranAt);
    return date.toLocaleString();
  } catch {
    return String(ranAt);
  }
}

function TechnicalDetails({ node, execution, workflow, run }) {
  const [open, setOpen] = useState(false);
  if (!node) return null;

  return (
    <div className="border-t border-slate-200 pt-3 dark:border-slate-700">
      <button
        type="button"
        className="flex w-full items-center justify-between text-xs font-semibold uppercase tracking-wide text-slate-500"
        onClick={() => setOpen((v) => !v)}
      >
        Technical details
        <span>{open ? '▾' : '▸'}</span>
      </button>
      {open && (
        <dl className="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-400">
          <div><dt className="inline font-medium">Node ID: </dt><dd className="inline font-mono">{node.id}</dd></div>
          {execution?.execution_role && <div><dt className="inline font-medium">Execution role: </dt><dd className="inline font-mono">{execution.execution_role}</dd></div>}
          {execution?.hook_key && <div><dt className="inline font-medium">Hook key: </dt><dd className="inline font-mono">{execution.hook_key}</dd></div>}
          {workflow?.snapshot_hash && <div><dt className="inline font-medium">Workflow snapshot hash: </dt><dd className="inline font-mono">{workflow.snapshot_hash}</dd></div>}
          {execution?.prompt_result_ids?.length > 0 && (
            <div><dt className="inline font-medium">Prompt result IDs: </dt><dd className="inline font-mono">{execution.prompt_result_ids.join(', ')}</dd></div>
          )}
          {run?.run_item_id && <div><dt className="inline font-medium">Run item ID: </dt><dd className="inline font-mono">{run.run_item_id}</dd></div>}
          {execution?.mapping_confidence && <div><dt className="inline font-medium">Mapping confidence: </dt><dd className="inline">{execution.mapping_confidence}</dd></div>}
          {execution?.skip_reason && <div><dt className="inline font-medium">Raw skip code: </dt><dd className="inline font-mono">{execution.skip_reason}</dd></div>}
        </dl>
      )}
    </div>
  );
}

function ContextInspector({ contextSummary, labels }) {
  if (!contextSummary || Object.keys(contextSummary).length === 0) {
    return <p className="text-sm text-slate-500">{labels.noContext ?? 'No article context available.'}</p>;
  }

  const rows = [
    ['articleId', contextSummary.article_id],
    ['title', contextSummary.title],
    ['postType', contextSummary.post_type],
    ['generationMode', contextSummary.generation_mode ?? contextSummary.execution_type],
    ['keyword', contextSummary.keyword],
    ['domain', contextSummary.domain],
  ].filter(([, value]) => value !== null && value !== undefined && value !== '');

  return (
    <div className="space-y-4 text-sm">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          {labels.contextInspectorHeading ?? 'Article context'}
        </p>
        <p className="font-semibold text-slate-900 dark:text-slate-100">
          {contextSummary.title || `#${contextSummary.article_id ?? '—'}`}
        </p>
      </div>
      <dl className="space-y-2 text-xs">
        {rows.map(([key, value]) => (
          <div key={key}>
            <dt className="text-slate-500">{labels[`context_${key}`] ?? key}</dt>
            <dd className="font-medium">{String(value)}</dd>
          </div>
        ))}
        {Array.isArray(contextSummary.routing) && contextSummary.routing.length > 0 && (
          <div>
            <dt className="text-slate-500">{labels.contextRouting ?? 'Routing'}</dt>
            <dd className="space-y-1 font-medium">
              {contextSummary.routing.map((line) => (
                <p key={line}>{line}</p>
              ))}
            </dd>
          </div>
        )}
      </dl>
    </div>
  );
}

function ExecutionInspector({ node, execution, labels, workflow, run, onPreview, contextSummary }) {
  const isContextNode = node?.id === ARTICLE_CONTEXT_NODE_ID || node?.type === 'execution_article_context';

  if (isContextNode) {
    return <ContextInspector contextSummary={contextSummary} labels={labels} />;
  }

  if (!node) {
    return <p className="text-sm text-slate-500">{labels.selectNode ?? 'Select a node to inspect.'}</p>;
  }

  const typeLabel = node.type === 'prompt'
    ? labels.prompt
    : node.type === 'filter'
      ? labels.filter
      : node.type === 'action'
        ? labels.action
        : node.type === 'article_filter'
          ? labels.filter
          : node.type;

  const aiCalls = Array.isArray(execution?.ai_calls) ? execution.ai_calls : [];
  const isPrompt = node.type === 'prompt';
  const isAction = node.type === 'action';
  const isFilter = node.type === 'filter';

  return (
    <div className="space-y-4 text-sm">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          {labels.inspectorHeading ?? 'Execution'}
        </p>
        <p className="font-semibold text-slate-900 dark:text-slate-100">
          {formatPromptNodeTitleWithAiCallCount(node, execution)}
        </p>
      </div>

      <dl className="grid grid-cols-2 gap-2 text-xs">
        <div><dt className="text-slate-500">Type</dt><dd className="font-medium">{typeLabel}</dd></div>
        <div><dt className="text-slate-500">Status</dt><dd className="font-medium">{execution?.status_label ?? '—'}</dd></div>
      </dl>

      {execution?.skip_reason_label && (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800">
          <span className="font-medium">Reason: </span>{execution.skip_reason_label}
          {execution.skip_reason && (
            <span className="mt-1 block font-mono text-[10px] text-slate-400">{execution.skip_reason}</span>
          )}
        </div>
      )}

      {execution?.message && !isAction && (
        <p className="text-xs text-slate-600 dark:text-slate-400">{execution.message}</p>
      )}

      {isPrompt && aiCalls.length > 0 && (
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {(labels.aiCalls ?? 'AI Calls (:count)').replace(':count', String(aiCalls.length))}
          </p>
          <ul className="space-y-2">
            {aiCalls.map((call) => (
              <li key={call.result_id} className="rounded border border-slate-200 p-2 text-xs dark:border-slate-700">
                <p className="font-medium">{call.prompt_name || call.hook_key || `#${call.result_id}`}</p>
                {call.hook_key && <p className="font-mono text-[10px] text-slate-500">{call.hook_key}</p>}
                {call.execution_profile && <p>Profile: {call.execution_profile}</p>}
                {call.model && <p>Model: {call.model}{call.provider ? ` · ${call.provider}` : ''}</p>}
                {call.route_position != null && <p>Route position #{call.route_position}</p>}
                {call.outline_subtask && <p>Subtask: {call.outline_subtask}</p>}
                {call.result_id && onPreview && (
                  <button
                    type="button"
                    className="mt-1 text-indigo-600 hover:underline dark:text-indigo-400"
                    onClick={() => onPreview(call.artifact_ref ?? `pr:${call.result_id}`)}
                  >
                    View prompt / output
                  </button>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {isAction && (
        <dl className="space-y-1 text-xs">
          <div><dt className="text-slate-500">Action</dt><dd className="font-mono">{execution?.action ?? node.data?.actionType ?? actionTypeCanvasLabel(node.data?.actionType)}</dd></div>
          {execution?.message && (
            <div><dt className="text-slate-500">Error</dt><dd className="text-red-700 dark:text-red-400">{execution.message}</dd></div>
          )}
        </dl>
      )}

      {isFilter && (
        <dl className="space-y-1 text-xs">
          <div><dt className="text-slate-500">Processor</dt><dd>{filterTypeLabel(node.data?.filterType ?? execution?.filter_type)}</dd></div>
          {execution?.message && (
            <div><dt className="text-slate-500">Output</dt><dd className="whitespace-pre-wrap">{execution.message}</dd></div>
          )}
        </dl>
      )}

      <TechnicalDetails node={node} execution={execution} workflow={workflow} run={run} />
    </div>
  );
}

function RunWorkflowPanel({ run, labels, prompts, onPreview }) {
  const [selectedId, setSelectedId] = useState(null);
  const [inspectorOpen, setInspectorOpen] = useState(true);
  const [showFullWorkflow, setShowFullWorkflow] = useState(false);

  const fullFlow = useMemo(() => {
    const raw = {
      nodes: run.workflow?.nodes ?? [],
      edges: run.workflow?.edges ?? [],
    };
    return normalizeFlowData(raw);
  }, [run.workflow]);

  const projection = useMemo(() => projectExecutionHistoryGraph({
    nodes: fullFlow.nodes,
    edges: fullFlow.edges,
    nodeVisibility: run.node_visibility ?? {},
    showFullWorkflow,
    contextSummary: run.context_summary ?? {},
    labels,
  }), [fullFlow, run.node_visibility, run.context_summary, showFullWorkflow, labels]);

  const canvasNodes = useMemo(
    () => enrichPromptNodesWithAiCallCounts(projection.nodes, run.execution_by_node_id ?? {}),
    [projection.nodes, run.execution_by_node_id],
  );
  const canvasEdges = projection.edges;
  const virtualEdges = projection.virtualEdges ?? [];

  const selectedNode = canvasNodes.find((n) => n.id === selectedId)
    ?? fullFlow.nodes.find((n) => n.id === selectedId)
    ?? null;
  const isContextSelection = selectedId === ARTICLE_CONTEXT_NODE_ID;
  const execution = selectedId && !isContextSelection
    ? (run.execution_by_node_id?.[selectedId] ?? null)
    : null;
  const isLegacyDefinition = ['legacy_current_task', 'legacy_current_task_hash_mismatch'].includes(run.workflow?.definition_source);

  return (
    <div
      key={run.id}
      className="seo-execution-history-workflow-panel seo-flow-builder flex h-full min-h-0 w-full flex-col overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700"
    >
      <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-900/50">
        <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
          <input
            type="checkbox"
            className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            checked={showFullWorkflow}
            onChange={(event) => {
              setShowFullWorkflow(event.target.checked);
              if (event.target.checked) {
                setSelectedId(null);
              }
            }}
          />
          <span>{labels.showFullWorkflow ?? 'Show full workflow'}</span>
        </label>
        {!showFullWorkflow && (
          <span className="text-[10px] uppercase tracking-wide text-slate-400">
            {labels.simplifiedWorkflow ?? 'Simplified workflow'}
          </span>
        )}
      </div>

      <div className="flex min-h-0 flex-1 overflow-hidden">
        <TaskWorkflowCanvas
          nodes={canvasNodes}
          edges={canvasEdges}
          virtualEdges={virtualEdges}
          prompts={prompts}
          readOnly
          selectedNodeId={selectedId}
          onSelectNode={setSelectedId}
          executionByNodeId={run.execution_by_node_id ?? {}}
          fitViewOnMount
          className="min-h-0 flex-1"
        />
        <aside
          className={[
            'relative shrink-0 overflow-hidden border-l border-slate-200 bg-white transition-[width] duration-200 dark:border-slate-700 dark:bg-slate-950',
            inspectorOpen ? 'w-[340px]' : 'w-10',
          ].join(' ')}
        >
          <button
            type="button"
            className="absolute right-2 top-2 z-10 rounded border border-slate-200 px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900"
            onClick={() => setInspectorOpen((open) => !open)}
            aria-label={inspectorOpen ? 'Collapse inspector' : 'Expand inspector'}
          >
            {inspectorOpen ? '›' : '‹'}
          </button>
          {inspectorOpen && (
            <div className="h-full overflow-y-auto p-4 pt-10">
              {isLegacyDefinition && (
                <p className="mb-3 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                  {labels.legacyDefinition ?? 'Legacy workflow definition'}
                </p>
              )}
              <ExecutionInspector
                node={selectedNode}
                execution={execution}
                labels={labels}
                workflow={run.workflow}
                run={run}
                onPreview={onPreview}
                contextSummary={run.context_summary}
              />
              {Array.isArray(run.legacy_unmapped) && run.legacy_unmapped.length > 0 && (
                <div className="mt-4 rounded-lg border border-dashed border-slate-300 p-3 dark:border-slate-600">
                  <p className="text-xs font-semibold uppercase text-slate-500">{labels.legacyUnmapped}</p>
                  {run.legacy_unmapped.map((row, idx) => (
                    <p key={idx} className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                      {row.title} — {row.status_label}: {row.message}
                    </p>
                  ))}
                </div>
              )}
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}

export function ArticleExecutionHistoryApp({ runs = [], labels = {}, prompts = [], onPreview }) {
  const [activeRunId, setActiveRunId] = useState(runs[0]?.id ?? null);
  const activeRun = runs.find((r) => r.id === activeRunId) ?? runs[0] ?? null;
  const resolvedPrompts = useMemo(() => resolvePrompts(prompts), [prompts]);

  if (!runs.length) {
    return <p className="text-sm text-slate-500">{labels.emptyWorkflow}</p>;
  }

  return (
    <div className="flex h-full min-h-0 flex-col gap-3 w-full">
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {runs.map((run) => (
          <button
            key={run.id}
            type="button"
            className={[
              'rounded-lg border px-3 py-2 text-left text-sm transition',
              activeRun?.id === run.id ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 dark:border-slate-700',
            ].join(' ')}
            onClick={() => setActiveRunId(run.id)}
          >
            <p className="font-semibold">
              Run #{run.run_id} · Attempt #{run.attempt}
              {run.project_name ? ` · ${run.project_name}` : ''}
            </p>
            <p className="text-xs text-slate-500">
              {String(run.status ?? '').toUpperCase()}
              {run.ran_at ? ` · ${formatRanAt(run.ran_at)}` : ''}
            </p>
          </button>
        ))}
      </div>
      {activeRun && (
        <div className="min-h-0 flex-1">
          <RunWorkflowPanel
            run={activeRun}
            labels={labels}
            prompts={resolvedPrompts}
            onPreview={onPreview}
          />
        </div>
      )}
    </div>
  );
}

export function mountArticleExecutionHistory(el) {
  if (!el) return;
  const props = JSON.parse(el.dataset.props || '{}');
  const root = createRoot(el);
  root.render(
    <div className="flex h-full min-h-0 w-full">
      <ArticleExecutionHistoryApp
        runs={props.runs ?? []}
        labels={props.labels ?? {}}
        prompts={props.prompts ?? []}
        onPreview={(ref) => {
          window.dispatchEvent(new CustomEvent('execution-history-preview', { detail: { ref } }));
        }}
      />
    </div>,
  );
}

if (typeof window !== 'undefined') {
  window.mountArticleExecutionHistory = mountArticleExecutionHistory;
}
