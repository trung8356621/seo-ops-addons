import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    EDGE_STROKE,
    autoLayout,
    edgePath,
    nodeAnchor,
    VIEWER_NODE_WIDTH,
    VIEWER_NODE_HEIGHT,
} from '../automation-workflow/canvasUtils';
import WorkflowNodeCard from './WorkflowNodeCard';

const MIN_ZOOM = 0.35;
const MAX_ZOOM = 1.8;

export default function ReadOnlyFlowCanvas({
    nodes,
    edges,
    direction,
    selectedId,
    onSelect,
}) {
    const canvasRef = useRef(null);
    const [pan, setPan] = useState({ x: 24, y: 24 });
    const [zoom, setZoom] = useState(1);
    const panDragRef = useRef(null);

    const laidOut = useMemo(
        () => autoLayout(nodes, edges, direction === 'LR' ? 'LR' : 'TB'),
        [nodes, edges, direction],
    );

    const fitView = () => {
        const el = canvasRef.current;
        if (!el || laidOut.length === 0) {
            return;
        }
        const xs = laidOut.map((n) => n.position.x);
        const ys = laidOut.map((n) => n.position.y);
        const minX = Math.min(...xs);
        const minY = Math.min(...ys);
        const maxX = Math.max(...xs) + VIEWER_NODE_WIDTH;
        const maxY = Math.max(...ys) + VIEWER_NODE_HEIGHT;
        const width = Math.max(1, maxX - minX);
        const height = Math.max(1, maxY - minY);
        const pad = 48;
        const scale = Math.min(
            MAX_ZOOM,
            Math.max(MIN_ZOOM, Math.min(
                (el.clientWidth - pad * 2) / width,
                (el.clientHeight - pad * 2) / height,
            )),
        );
        setZoom(scale);
        setPan({
            x: (el.clientWidth - width * scale) / 2 - minX * scale,
            y: (el.clientHeight - height * scale) / 2 - minY * scale,
        });
    };

    useEffect(() => {
        fitView();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [laidOut, direction]);

    const onWheel = (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.08 : 0.08;
        setZoom((z) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, z + delta)));
    };

    const onCanvasMouseDown = (e) => {
        if (e.button !== 0) {
            return;
        }
        onSelect(null);
        panDragRef.current = {
            startX: e.clientX,
            startY: e.clientY,
            origin: { ...pan },
        };
        const onMove = (ev) => {
            if (!panDragRef.current) {
                return;
            }
            setPan({
                x: panDragRef.current.origin.x + (ev.clientX - panDragRef.current.startX),
                y: panDragRef.current.origin.y + (ev.clientY - panDragRef.current.startY),
            });
        };
        const onUp = () => {
            panDragRef.current = null;
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    const edgesSvg = edges.map((edge) => {
        const fromNode = laidOut.find((n) => n.id === edge.source);
        const toNode = laidOut.find((n) => n.id === edge.target);
        if (!fromNode || !toNode) {
            return null;
        }
        const from = nodeAnchor(fromNode, 'out', direction === 'LR' ? 'LR' : 'TB');
        const to = nodeAnchor(toNode, 'in', direction === 'LR' ? 'LR' : 'TB');
        const stroke = EDGE_STROKE[edge.type] ?? EDGE_STROKE.next;
        const dashed = edge.type === 'optional' || edge.type === 'manual';
        const midX = (from.x + to.x) / 2;
        const midY = (from.y + to.y) / 2;

        return (
            <g key={`${edge.source}-${edge.type}-${edge.target}`}>
                <path
                    d={edgePath(from, to)}
                    fill="none"
                    stroke={stroke}
                    strokeWidth={2.25}
                    strokeDasharray={dashed ? '6 4' : undefined}
                    markerEnd={`url(#awv-arrow-${edge.type || 'next'})`}
                />
                {edge.label && (
                    <text x={midX} y={midY - 6} textAnchor="middle" className="awv-edge-label" fill={stroke}>
                        {edge.label}
                    </text>
                )}
            </g>
        );
    });

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col">
            <div className="absolute end-3 top-3 z-10 flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white/95 p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900/95">
                <button type="button" className="awv-tool-btn" onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z - 0.1))}>-</button>
                <span className="px-1 text-[11px] tabular-nums text-slate-600">{Math.round(zoom * 100)}%</span>
                <button type="button" className="awv-tool-btn" onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z + 0.1))}>+</button>
                <button type="button" className="awv-tool-btn" onClick={fitView}>Fit</button>
                <button type="button" className="awv-tool-btn" onClick={() => { setZoom(1); setPan({ x: 24, y: 24 }); }}>Reset</button>
            </div>

            <div
                ref={canvasRef}
                className="awv-canvas relative min-h-[calc(100vh-18rem)] flex-1 cursor-grab overflow-hidden active:cursor-grabbing"
                onWheel={onWheel}
                onMouseDown={onCanvasMouseDown}
            >
                <div
                    className="absolute inset-0 origin-top-left"
                    style={{ transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})` }}
                >
                    <svg className="pointer-events-none absolute inset-0 h-[5000px] w-[5000px]">
                        <defs>
                            {Object.keys(EDGE_STROKE).map((type) => (
                                <marker
                                    key={type}
                                    id={`awv-arrow-${type}`}
                                    markerWidth="8"
                                    markerHeight="8"
                                    refX="6"
                                    refY="4"
                                    orient="auto"
                                >
                                    <path d="M0,0 L8,4 L0,8 Z" fill={EDGE_STROKE[type]} />
                                </marker>
                            ))}
                        </defs>
                        {edgesSvg}
                    </svg>

                    {laidOut.map((node) => (
                        <WorkflowNodeCard
                            key={node.id}
                            node={node}
                            selected={selectedId === node.id}
                            onSelect={onSelect}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
