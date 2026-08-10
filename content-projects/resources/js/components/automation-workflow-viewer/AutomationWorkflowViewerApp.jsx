import React, { useMemo, useState } from 'react';
import ReadOnlyFlowCanvas from './ReadOnlyFlowCanvas';
import WorkflowInspector from './WorkflowInspector';

export default function AutomationWorkflowViewerApp({
    workflow,
    executionsUrl = '',
    operationsUrl = '',
    onOpenComponents,
}) {
    const [direction, setDirection] = useState('TB');
    const [selectedId, setSelectedId] = useState(null);
    const [inspectorCollapsed, setInspectorCollapsed] = useState(false);

    const nodes = workflow?.nodes ?? [];
    const edges = workflow?.edges ?? [];
    const selectedNode = useMemo(
        () => nodes.find((n) => n.id === selectedId) ?? null,
        [nodes, selectedId],
    );

    if (!workflow?.id) {
        return (
            <div className="flex h-full min-h-[360px] items-center justify-center rounded-xl border border-dashed border-slate-300 text-sm text-slate-500 dark:border-slate-700">
                Select a workflow to view the map.
            </div>
        );
    }

    return (
        <div className="awv-shell flex h-full min-h-[520px] flex-col overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
            <header className="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-base font-semibold text-slate-900 dark:text-slate-100">
                        {workflow.name}
                    </h2>
                    <p className="mt-0.5 truncate text-xs text-slate-500">
                        {workflow.category_label || workflow.category}
                        {' · '}
                        {workflow.step_count} nodes
                        {' · '}
                        {workflow.mapping_label || workflow.mapping_status}
                        {' · '}
                        Last run: {workflow.status_label || workflow.status}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <button
                        type="button"
                        className={`awv-tool-btn ${direction === 'TB' ? 'awv-tool-btn-active' : ''}`}
                        onClick={() => setDirection('TB')}
                    >
                        Top → Bottom
                    </button>
                    <button
                        type="button"
                        className={`awv-tool-btn ${direction === 'LR' ? 'awv-tool-btn-active' : ''}`}
                        onClick={() => setDirection('LR')}
                    >
                        Left → Right
                    </button>
                    {executionsUrl && (
                        <a href={executionsUrl} className="awv-tool-btn no-underline">Open executions</a>
                    )}
                    {typeof onOpenComponents === 'function' && (
                        <button type="button" className="awv-tool-btn" onClick={onOpenComponents}>
                            Registered components
                        </button>
                    )}
                </div>
            </header>

            {workflow.description && (
                <p className="border-b border-slate-100 px-4 py-2 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300">
                    {workflow.description}
                </p>
            )}

            <div className="flex min-h-0 flex-1">
                <ReadOnlyFlowCanvas
                    nodes={nodes}
                    edges={edges}
                    direction={direction}
                    selectedId={selectedId}
                    onSelect={setSelectedId}
                />
                <WorkflowInspector
                    node={selectedNode}
                    collapsed={inspectorCollapsed}
                    onToggle={() => setInspectorCollapsed((v) => !v)}
                />
            </div>
        </div>
    );
}
