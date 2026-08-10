import React, { useState } from 'react';
import { NODE_TYPE_META, STATUS_META } from '../automation-workflow/canvasUtils';

export default function WorkflowInspector({ node, collapsed, onToggle }) {
    const [showTech, setShowTech] = useState(false);

    if (collapsed) {
        return (
            <aside className="flex w-10 shrink-0 flex-col items-center border-l border-slate-200 bg-white py-3 dark:border-slate-700 dark:bg-slate-950">
                <button
                    type="button"
                    className="rounded px-1 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                    onClick={onToggle}
                    title="Open inspector"
                >
                    ◂
                </button>
            </aside>
        );
    }

    if (!node) {
        return (
            <aside className="awv-inspector-open flex shrink-0 flex-col border-l border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
                <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Inspector</p>
                    <button type="button" className="text-xs text-slate-500" onClick={onToggle}>▸</button>
                </div>
                <p className="p-4 text-sm text-slate-500">Select a node to inspect details.</p>
            </aside>
        );
    }

    const typeMeta = NODE_TYPE_META[node.type] ?? NODE_TYPE_META.action;
    const statusMeta = STATUS_META[node.status] ?? STATUS_META.never_executed;

    return (
        <aside className="awv-inspector-open flex shrink-0 flex-col border-l border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
            <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-700">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Inspector</p>
                <button type="button" className="text-xs text-slate-500" onClick={onToggle}>▸</button>
            </div>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                <div>
                    <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">{node.label}</p>
                    <p className="mt-1 font-mono text-[11px] text-slate-500 break-all">{node.technical_id}</p>
                </div>

                <dl className="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <dt className="text-slate-500">Type</dt>
                        <dd className="font-medium">{typeMeta.label}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Mode</dt>
                        <dd className="font-medium">{node.mode}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Status</dt>
                        <dd className="font-medium">{statusMeta.label}</dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Registered</dt>
                        <dd className="font-medium">{node.registered ? 'Yes' : 'No'}</dd>
                    </div>
                </dl>

                {node.handler && (
                    <div>
                        <p className="text-xs font-semibold text-slate-500">Handler / component</p>
                        <p className="mt-1 font-mono text-[11px] break-all text-slate-700 dark:text-slate-300">{node.handler}</p>
                    </div>
                )}

                <div>
                    <button
                        type="button"
                        className="text-xs font-semibold text-indigo-600 hover:underline"
                        onClick={() => setShowTech((v) => !v)}
                    >
                        {showTech ? 'Hide technical details' : 'Show technical details'}
                    </button>
                    {showTech && (
                        <div className="mt-2 space-y-2">
                            <div>
                                <p className="text-xs font-semibold text-slate-500">Evidence</p>
                                <ul className="mt-1 list-disc space-y-1 ps-4 text-[11px] text-slate-600 dark:text-slate-300">
                                    {(node.evidence?.length ? node.evidence : ['—']).map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                            </div>
                            {(node.matched_components?.length ?? 0) > 0 && (
                                <div>
                                    <p className="text-xs font-semibold text-slate-500">Matched components</p>
                                    <ul className="mt-1 space-y-1 font-mono text-[11px] text-slate-600 dark:text-slate-300">
                                        {node.matched_components.map((c) => (
                                            <li key={c.id || c.code}>
                                                {c.id || c.code}
                                                {c.last_status ? ` · ${c.last_status}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </aside>
    );
}
