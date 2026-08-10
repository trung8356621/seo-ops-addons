import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ConfigPanel from './ConfigPanel';
import {
    BRANCH_LABELS,
    NODE_HEIGHT,
    NODE_META,
    NODE_WIDTH,
    allowedBranches,
    cloneGraphState,
    createNodeKey,
    defaultNode,
    graphToPayload,
} from './constants';

const MIN_ZOOM = 0.4;
const MAX_ZOOM = 1.6;

function portPosition(node, branch, branchIndex, branchCount) {
    const x = node.ui_position?.x ?? 0;
    const y = node.ui_position?.y ?? 0;
    const spacing = NODE_WIDTH / (branchCount + 1);

    return {
        x: x + spacing * (branchIndex + 1),
        y: y + NODE_HEIGHT,
    };
}

function inputPortPosition(node) {
    return {
        x: (node.ui_position?.x ?? 0) + NODE_WIDTH / 2,
        y: node.ui_position?.y ?? 0,
    };
}

function edgePath(from, to) {
    const dy = Math.max(40, Math.abs(to.y - from.y) * 0.5);
    return `M ${from.x} ${from.y} C ${from.x} ${from.y + dy}, ${to.x} ${to.y - dy}, ${to.x} ${to.y}`;
}

function useHistory(initial) {
    const [present, setPresent] = useState(initial);
    const pastRef = useRef([]);
    const futureRef = useRef([]);

    const commit = useCallback((next) => {
        pastRef.current.push(cloneGraphState(present.nodes, present.edges));
        if (pastRef.current.length > 50) {
            pastRef.current.shift();
        }
        futureRef.current = [];
        setPresent(next);
    }, [present]);

    const replace = useCallback((next, { resetHistory = false } = {}) => {
        if (resetHistory) {
            pastRef.current = [];
            futureRef.current = [];
        }
        setPresent(next);
    }, []);

    const undo = useCallback(() => {
        const prev = pastRef.current.pop();
        if (!prev) {
            return;
        }
        futureRef.current.push(cloneGraphState(present.nodes, present.edges));
        setPresent(prev);
    }, [present]);

    const redo = useCallback(() => {
        const next = futureRef.current.pop();
        if (!next) {
            return;
        }
        pastRef.current.push(cloneGraphState(present.nodes, present.edges));
        setPresent(next);
    }, [present]);

    return {
        present,
        commit,
        replace,
        undo,
        redo,
        canUndo: pastRef.current.length > 0,
        canRedo: futureRef.current.length > 0,
    };
}

export default function AutomationWorkflowBuilderApp({
    initialGraph,
    rule,
    registry,
    permissions,
    draftRevision,
    backUrl,
    backLabel,
}) {
    const initial = useMemo(() => ({
        nodes: initialGraph?.nodes?.length ? initialGraph.nodes : [defaultNode('trigger', registry, rule)],
        edges: initialGraph?.edges ?? [],
    }), [initialGraph, registry, rule]);

    const {
        present,
        commit,
        replace,
        undo,
        redo,
        canUndo,
        canRedo,
    } = useHistory(initial);

    const [selectedKey, setSelectedKey] = useState(null);
    const [dirty, setDirty] = useState(false);
    const [draftRev, setDraftRev] = useState(draftRevision ?? 0);
    const [connectFrom, setConnectFrom] = useState(null);
    const [pan, setPan] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [toast, setToast] = useState(null);
    const [validation, setValidation] = useState(null);
    const [busy, setBusy] = useState(null);

    const dragRef = useRef(null);
    const canvasRef = useRef(null);
    const savedRevRef = useRef(draftRevision ?? 0);

    const selectedNode = present.nodes.find((n) => n.node_key === selectedKey) ?? null;

    const markDirty = useCallback(() => setDirty(true), []);

    const updateGraph = useCallback((updater) => {
        const nextNodes = typeof updater.nodes === 'function' ? updater.nodes(present.nodes) : updater.nodes ?? present.nodes;
        const nextEdges = typeof updater.edges === 'function' ? updater.edges(present.edges) : updater.edges ?? present.edges;
        commit({ nodes: nextNodes, edges: nextEdges });
        markDirty();
    }, [commit, markDirty, present.edges, present.nodes]);

    useEffect(() => {
        const onBeforeUnload = (e) => {
            if (!dirty) {
                return;
            }
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    useEffect(() => {
        const showToast = (type, message) => {
            setToast({ type, message });
            window.setTimeout(() => setToast(null), 4000);
        };

        const handlers = {
            'automation-workflow-saved': (e) => {
                setBusy(null);
                setDirty(false);
                savedRevRef.current = e.detail?.draft_revision ?? draftRev;
                setDraftRev(savedRevRef.current);
                showToast('success', e.detail?.message ?? 'Draft saved.');
            },
            'automation-workflow-save-failed': (e) => {
                setBusy(null);
                showToast('error', e.detail?.message ?? 'Save failed.');
            },
            'automation-workflow-validated': (e) => {
                setBusy(null);
                setValidation(e.detail ?? null);
                showToast(e.detail?.valid ? 'success' : 'error', e.detail?.valid ? 'Graph valid.' : 'Validation failed.');
            },
            'automation-workflow-published': (e) => {
                setBusy(null);
                showToast('success', e.detail?.message ?? 'Published.');
            },
            'automation-workflow-publish-failed': (e) => {
                setBusy(null);
                showToast('error', e.detail?.message ?? 'Publish failed.');
            },
            'automation-workflow-tested': (e) => {
                setBusy(null);
                showToast('success', e.detail?.message ?? 'Dry run finished.');
            },
            'automation-workflow-test-failed': (e) => {
                setBusy(null);
                showToast('error', e.detail?.message ?? 'Dry run failed.');
            },
            'automation-workflow-imported': (e) => {
                setBusy(null);
                const graph = e.detail?.graph;
                if (graph?.nodes) {
                    replace({ nodes: graph.nodes, edges: graph.edges ?? [] }, { resetHistory: true });
                    setDirty(true);
                    showToast('success', 'Graph imported.');
                }
            },
            'automation-workflow-exported': (e) => {
                setBusy(null);
                if (e.detail?.json) {
                    navigator.clipboard?.writeText(e.detail.json);
                    showToast('success', 'JSON copied to clipboard.');
                }
            },
            'automation-workflow-palette-loaded': (e) => {
                setBusy(null);
                if (e.detail?.registry) {
                    window.__AUTOMATION_REGISTRY__ = e.detail.registry;
                }
            },
        };

        Object.entries(handlers).forEach(([event, handler]) => {
            window.addEventListener(event, handler);
        });

        return () => {
            Object.entries(handlers).forEach(([event, handler]) => {
                window.removeEventListener(event, handler);
            });
        };
    }, [draftRev, replace]);

    const dispatchWire = (eventName, detail) => {
        window.dispatchEvent(new CustomEvent(eventName, { detail }));
    };

    const payload = () => ({
        ...graphToPayload(present.nodes, present.edges),
        draft_revision: draftRev,
        layout: { pan, zoom },
    });

    const handleSaveDraft = () => {
        if (!permissions?.edit) {
            return;
        }
        setBusy('save');
        dispatchWire('automation-workflow-save-draft', payload());
    };

    const handleValidate = () => {
        setBusy('validate');
        dispatchWire('automation-workflow-validate', payload());
    };

    const handlePublish = () => {
        if (!permissions?.publish) {
            return;
        }
        if (!window.confirm('Publish this graph version?')) {
            return;
        }
        setBusy('publish');
        dispatchWire('automation-workflow-publish', { draft_revision: draftRev });
    };

    const handleTest = () => {
        if (!permissions?.execute_test) {
            return;
        }
        const eventId = window.prompt('Business event ID for dry run:');
        if (!eventId) {
            return;
        }
        setBusy('test');
        dispatchWire('automation-workflow-test', { event_id: parseInt(eventId, 10), ...payload() });
    };

    const handleExport = () => {
        setBusy('export');
        dispatchWire('automation-workflow-export', payload());
    };

    const handleImport = () => {
        const raw = window.prompt('Paste graph JSON (nodes + edges):');
        if (!raw) {
            return;
        }
        try {
            const parsed = JSON.parse(raw);
            dispatchWire('automation-workflow-import', { graph: parsed, draft_revision: draftRev });
        } catch {
            setToast({ type: 'error', message: 'Invalid JSON.' });
        }
    };

    const handleBack = () => {
        if (dirty && !window.confirm('Unsaved changes. Leave anyway?')) {
            return;
        }
        if (backUrl) {
            window.location.href = backUrl;
        }
    };

    const addNode = (type) => {
        if (!permissions?.edit) {
            return;
        }
        if (type === 'trigger' && present.nodes.some((n) => n.node_type === 'trigger')) {
            setToast({ type: 'error', message: 'Only one trigger allowed.' });
            return;
        }
        const node = defaultNode(type, registry, rule);
        updateGraph({
            nodes: (nodes) => [...nodes, node],
        });
        setSelectedKey(node.node_key);
    };

    const deleteNode = (key) => {
        updateGraph({
            nodes: (nodes) => nodes.filter((n) => n.node_key !== key),
            edges: (edges) => edges.filter((e) => e.from_node_key !== key && e.to_node_key !== key),
        });
        if (selectedKey === key) {
            setSelectedKey(null);
        }
    };

    const duplicateNode = (key) => {
        const source = present.nodes.find((n) => n.node_key === key);
        if (!source || source.node_type === 'trigger') {
            return;
        }
        const copy = {
            ...JSON.parse(JSON.stringify(source)),
            node_key: createNodeKey(source.node_type),
            ui_position: {
                x: (source.ui_position?.x ?? 0) + 40,
                y: (source.ui_position?.y ?? 0) + 40,
            },
            name: `${source.name ?? source.node_type} copy`,
        };
        updateGraph({ nodes: (nodes) => [...nodes, copy] });
        setSelectedKey(copy.node_key);
    };

    const updateNode = (node) => {
        updateGraph({
            nodes: (nodes) => nodes.map((n) => (n.node_key === node.node_key ? node : n)),
        });
    };

    const startConnect = (nodeKey, branch) => {
        if (!permissions?.edit) {
            return;
        }
        setConnectFrom({ nodeKey, branch });
    };

    const finishConnect = (targetKey) => {
        if (!connectFrom || connectFrom.nodeKey === targetKey) {
            setConnectFrom(null);
            return;
        }
        const source = present.nodes.find((n) => n.node_key === connectFrom.nodeKey);
        if (!source) {
            setConnectFrom(null);
            return;
        }
        const branch = connectFrom.branch;
        const filtered = present.edges.filter(
            (e) => !(e.from_node_key === connectFrom.nodeKey && e.branch === branch)
                && e.to_node_key !== targetKey,
        );
        updateGraph({
            edges: [
                ...filtered,
                {
                    from_node_key: connectFrom.nodeKey,
                    to_node_key: targetKey,
                    branch,
                    priority: 100,
                },
            ],
        });
        setConnectFrom(null);
    };

    const onNodeMouseDown = (e, node) => {
        if (!permissions?.edit) {
            return;
        }
        e.stopPropagation();
        setSelectedKey(node.node_key);
        const startX = e.clientX;
        const startY = e.clientY;
        const origin = { ...(node.ui_position ?? { x: 0, y: 0 }) };

        dragRef.current = {
            nodeKey: node.node_key,
            startX,
            startY,
            origin,
        };

        const onMove = (ev) => {
            if (!dragRef.current) {
                return;
            }
            const dx = (ev.clientX - dragRef.current.startX) / zoom;
            const dy = (ev.clientY - dragRef.current.startY) / zoom;
            replace({
                nodes: present.nodes.map((n) => (
                    n.node_key === dragRef.current.nodeKey
                        ? { ...n, ui_position: { x: dragRef.current.origin.x + dx, y: dragRef.current.origin.y + dy } }
                        : n
                )),
                edges: present.edges,
            }, {});
            markDirty();
        };

        const onUp = () => {
            if (dragRef.current) {
                commit({
                    nodes: present.nodes,
                    edges: present.edges,
                });
            }
            dragRef.current = null;
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    const edgesSvg = present.edges.map((edge) => {
        const fromNode = present.nodes.find((n) => n.node_key === edge.from_node_key);
        const toNode = present.nodes.find((n) => n.node_key === edge.to_node_key);
        if (!fromNode || !toNode) {
            return null;
        }
        const branches = allowedBranches(fromNode.node_type);
        const branchIndex = Math.max(0, branches.indexOf(edge.branch ?? 'always'));
        const from = portPosition(fromNode, edge.branch, branchIndex, branches.length);
        const to = inputPortPosition(toNode);
        const midX = (from.x + to.x) / 2;
        const midY = (from.y + to.y) / 2;

        return (
            <g key={`${edge.from_node_key}-${edge.branch}-${edge.to_node_key}`}>
                <path
                    d={edgePath(from, to)}
                    fill="none"
                    stroke={NODE_META[fromNode.node_type]?.color ?? '#94a3b8'}
                    strokeWidth={2}
                    markerEnd="url(#awb-arrow)"
                />
                <text x={midX} y={midY - 6} textAnchor="middle" className="awb-edge-label fill-slate-600">
                    {BRANCH_LABELS[edge.branch] ?? edge.branch}
                </text>
            </g>
        );
    });

    return (
        <div className="flex h-full w-full flex-col bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <header className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-2 dark:border-slate-700">
                <button type="button" className="rounded-md px-2 py-1 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800" onClick={handleBack}>
                    ← {backLabel ?? 'Back'}
                </button>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-bold">{rule?.name ?? 'Automation workflow'}</p>
                    <p className="truncate text-xs text-slate-500">{rule?.code} · draft rev {draftRev}{dirty ? ' · unsaved' : ''}</p>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <button type="button" disabled={!canUndo} className="rounded px-2 py-1 text-xs font-semibold disabled:opacity-40" onClick={undo}>Undo</button>
                    <button type="button" disabled={!canRedo} className="rounded px-2 py-1 text-xs font-semibold disabled:opacity-40" onClick={redo}>Redo</button>
                    <button type="button" className="rounded px-2 py-1 text-xs font-semibold" onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z - 0.1))}>-</button>
                    <span className="text-xs tabular-nums">{Math.round(zoom * 100)}%</span>
                    <button type="button" className="rounded px-2 py-1 text-xs font-semibold" onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z + 0.1))}>+</button>
                    <button type="button" disabled={busy !== null} className="rounded-md border px-3 py-1.5 text-xs font-semibold" onClick={handleValidate}>Validate</button>
                    {permissions?.edit && (
                        <button type="button" disabled={busy !== null} className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white" onClick={handleSaveDraft}>
                            {busy === 'save' ? 'Saving…' : 'Save draft'}
                        </button>
                    )}
                    {permissions?.publish && (
                        <button type="button" disabled={busy !== null} className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" onClick={handlePublish}>Publish</button>
                    )}
                    {permissions?.execute_test && (
                        <button type="button" disabled={busy !== null} className="rounded-md border px-3 py-1.5 text-xs font-semibold" onClick={handleTest}>Test dry-run</button>
                    )}
                    <button type="button" className="rounded-md border px-3 py-1.5 text-xs font-semibold" onClick={handleExport}>Export</button>
                    {permissions?.edit && (
                        <button type="button" className="rounded-md border px-3 py-1.5 text-xs font-semibold" onClick={handleImport}>Import</button>
                    )}
                </div>
            </header>

            <div className="flex min-h-0 flex-1">
                <aside className="w-56 shrink-0 overflow-y-auto border-r border-slate-200 p-3 dark:border-slate-700">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Nodes</p>
                    <div className="space-y-1">
                        {Object.entries(NODE_META).map(([type, meta]) => (
                            <button
                                key={type}
                                type="button"
                                disabled={!permissions?.edit}
                                className="flex w-full items-center gap-2 rounded-md border border-slate-200 px-2 py-2 text-left text-xs font-semibold hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-900"
                                onClick={() => addNode(type)}
                            >
                                <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: meta.color }} />
                                {meta.label}
                            </button>
                        ))}
                    </div>

                    <p className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</p>
                    <ul className="space-y-1 text-xs text-slate-600 dark:text-slate-400">
                        {(registry?.actions ?? []).slice(0, 8).map((a) => (
                            <li key={a.code} className="truncate" title={a.description}>{a.code}</li>
                        ))}
                    </ul>

                    <p className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">Events</p>
                    <ul className="space-y-1 text-xs text-slate-600 dark:text-slate-400">
                        {(registry?.events ?? []).slice(0, 8).map((ev) => (
                            <li key={ev.name} className="truncate" title={ev.description}>{ev.name}</li>
                        ))}
                    </ul>
                </aside>

                <div
                    ref={canvasRef}
                    className="awb-canvas relative min-w-0 flex-1 overflow-hidden"
                    onMouseDown={() => setSelectedKey(null)}
                >
                    <div
                        className="absolute inset-0 origin-top-left"
                        style={{ transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})` }}
                    >
                        <svg className="pointer-events-none absolute inset-0 h-[4000px] w-[4000px]">
                            <defs>
                                <marker id="awb-arrow" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
                                    <path d="M0,0 L8,4 L0,8 Z" fill="#64748b" />
                                </marker>
                            </defs>
                            {edgesSvg}
                        </svg>

                        {present.nodes.map((node) => {
                            const meta = NODE_META[node.node_type] ?? {};
                            const branches = allowedBranches(node.node_type);
                            const x = node.ui_position?.x ?? 0;
                            const y = node.ui_position?.y ?? 0;

                            return (
                                <div
                                    key={node.node_key}
                                    className={`awb-node absolute rounded-lg border-2 bg-white shadow-md dark:bg-slate-900 ${selectedKey === node.node_key ? 'ring-2 ring-indigo-500' : ''}`}
                                    style={{
                                        left: x,
                                        top: y,
                                        width: NODE_WIDTH,
                                        minHeight: NODE_HEIGHT,
                                        borderColor: meta.color ?? '#cbd5e1',
                                    }}
                                    onMouseDown={(e) => onNodeMouseDown(e, node)}
                                >
                                    <div
                                        className="awb-port absolute -top-1.5 left-1/2 -translate-x-1/2 bg-slate-400"
                                        onMouseUp={(e) => { e.stopPropagation(); finishConnect(node.node_key); }}
                                    />
                                    <div className="px-3 py-2">
                                        <p className="text-[10px] font-bold uppercase tracking-wide" style={{ color: meta.color }}>{meta.label}</p>
                                        <p className="truncate text-sm font-semibold">{node.name ?? node.node_key}</p>
                                        {node.node_type === 'action' && (
                                            <p className="truncate text-xs text-slate-500">{node.action_code}</p>
                                        )}
                                    </div>
                                    <div className="relative flex justify-around px-2 pb-2">
                                        {branches.map((branch, index) => (
                                            <button
                                                key={branch}
                                                type="button"
                                                className="awb-port"
                                                style={{ backgroundColor: meta.color }}
                                                title={BRANCH_LABELS[branch]}
                                                onMouseDown={(e) => e.stopPropagation()}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    if (connectFrom) {
                                                        finishConnect(node.node_key);
                                                    } else {
                                                        startConnect(node.node_key, branch);
                                                    }
                                                }}
                                            />
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {connectFrom && (
                        <div className="pointer-events-none absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-lg">
                            Select target node for branch [{connectFrom.branch}]
                        </div>
                    )}
                </div>

                <aside className="w-80 shrink-0">
                    <ConfigPanel
                        node={selectedNode}
                        registry={registry}
                        permissions={permissions}
                        onUpdate={updateNode}
                        onDelete={deleteNode}
                        onDuplicate={duplicateNode}
                    />
                </aside>
            </div>

            {validation && (
                <div className="border-t border-slate-200 px-4 py-2 text-xs dark:border-slate-700">
                    {validation.valid ? (
                        <span className="font-semibold text-emerald-600">Valid</span>
                    ) : (
                        <ul className="list-disc pl-4 text-rose-600">
                            {(validation.errors ?? []).map((err) => <li key={err}>{err}</li>)}
                        </ul>
                    )}
                    {(validation.warnings ?? []).length > 0 && (
                        <ul className="mt-1 list-disc pl-4 text-amber-600">
                            {validation.warnings.map((w) => <li key={w}>{w}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {toast && (
                <div className={`fixed right-4 top-4 z-[300] rounded-lg px-4 py-3 text-sm font-semibold shadow-xl ${toast.type === 'success' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white'}`}>
                    {toast.message}
                </div>
            )}
        </div>
    );
}
