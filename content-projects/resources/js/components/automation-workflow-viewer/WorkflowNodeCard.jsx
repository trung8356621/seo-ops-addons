import React, { useMemo } from 'react';
import { NODE_TYPE_META, STATUS_META, VIEWER_NODE_WIDTH } from '../automation-workflow/canvasUtils';

export default function WorkflowNodeCard({
    node,
    selected,
    onSelect,
}) {
    const typeMeta = NODE_TYPE_META[node.type] ?? NODE_TYPE_META.action;
    const statusKey = node.status || 'never_executed';
    const statusMeta = STATUS_META[statusKey] ?? STATUS_META.never_executed;
    const mode = node.mode || 'sync';
    const dashed = node.optional || mode === 'optional';

    return (
        <button
            type="button"
            className={[
                'awv-node absolute rounded-xl border bg-white text-left shadow-sm transition dark:bg-slate-900',
                selected ? 'awv-node-selected ring-2 ring-indigo-500' : '',
                dashed ? 'border-dashed' : 'border-solid',
                statusMeta.className,
            ].join(' ')}
            style={{
                left: node.position?.x ?? 0,
                top: node.position?.y ?? 0,
                width: VIEWER_NODE_WIDTH,
                borderColor: typeMeta.accent,
            }}
            onClick={(e) => {
                e.stopPropagation();
                onSelect(node.id);
            }}
            title={node.technical_id || node.label}
        >
            <div className="flex items-start gap-2 px-3 py-2.5">
                <span className="mt-0.5 text-base leading-none" aria-hidden>{typeMeta.icon}</span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                        {node.label}
                    </p>
                    <p className="mt-0.5 truncate text-[11px] text-slate-500">
                        {typeMeta.label}
                        {' · '}
                        {mode}
                    </p>
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        <span className={`awv-badge ${statusMeta.className}`}>{statusMeta.label}</span>
                        {node.optional && <span className="awv-badge awv-badge-optional">Optional</span>}
                        {mode === 'manual' && <span className="awv-badge awv-badge-manual">Manual</span>}
                        {mode === 'queued' && <span className="awv-badge awv-badge-queued">Queued</span>}
                    </div>
                </div>
            </div>
        </button>
    );
}
