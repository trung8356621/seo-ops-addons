/**
 * Shared canvas geometry — reused by Automation Workflow Builder & read-only viewer.
 * No editor state here.
 */

export const VIEWER_NODE_WIDTH = 240;
export const VIEWER_NODE_HEIGHT = 84;

export function edgePath(from, to) {
    const dy = Math.max(40, Math.abs(to.y - from.y) * 0.5);
    return `M ${from.x} ${from.y} C ${from.x} ${from.y + dy}, ${to.x} ${to.y - dy}, ${to.x} ${to.y}`;
}

export function nodeAnchor(node, side, layout) {
    const x = node.position?.x ?? 0;
    const y = node.position?.y ?? 0;
    const w = VIEWER_NODE_WIDTH;
    const h = VIEWER_NODE_HEIGHT;

    if (layout === 'LR') {
        if (side === 'out') {
            return { x: x + w, y: y + h / 2 };
        }
        return { x, y: y + h / 2 };
    }

    if (side === 'out') {
        return { x: x + w / 2, y: y + h };
    }
    return { x: x + w / 2, y };
}

/**
 * Layered auto-layout (no dagre dependency).
 * direction: 'TB' | 'LR'
 */
export function autoLayout(nodes, edges, direction = 'TB') {
    const ids = nodes.map((n) => n.id);
    const idSet = new Set(ids);
    const incoming = Object.fromEntries(ids.map((id) => [id, 0]));
    const outgoing = Object.fromEntries(ids.map((id) => [id, []]));

    edges.forEach((edge) => {
        if (!idSet.has(edge.source) || !idSet.has(edge.target)) {
            return;
        }
        incoming[edge.target] += 1;
        outgoing[edge.source].push(edge.target);
    });

    const roots = ids.filter((id) => incoming[id] === 0);
    const queue = roots.length ? [...roots] : ids.slice(0, 1);
    const depth = Object.fromEntries(ids.map((id) => [id, 0]));
    const visited = new Set();

    while (queue.length) {
        const current = queue.shift();
        if (visited.has(current)) {
            continue;
        }
        visited.add(current);
        (outgoing[current] ?? []).forEach((next) => {
            depth[next] = Math.max(depth[next] ?? 0, (depth[current] ?? 0) + 1);
            if (!visited.has(next)) {
                queue.push(next);
            }
        });
    }

    ids.forEach((id) => {
        if (!visited.has(id)) {
            depth[id] = (Math.max(0, ...Object.values(depth)) || 0) + 1;
        }
    });

    const layers = {};
    ids.forEach((id) => {
        const d = depth[id] ?? 0;
        if (!layers[d]) {
            layers[d] = [];
        }
        layers[d].push(id);
    });

    const gapX = direction === 'LR' ? 280 : 40;
    const gapY = direction === 'LR' ? 28 : 120;
    const startX = 48;
    const startY = 48;
    const positions = {};

    Object.keys(layers)
        .map(Number)
        .sort((a, b) => a - b)
        .forEach((layerIndex) => {
            const row = layers[layerIndex];
            row.forEach((id, colIndex) => {
                if (direction === 'LR') {
                    positions[id] = {
                        x: startX + layerIndex * gapX,
                        y: startY + colIndex * (VIEWER_NODE_HEIGHT + gapY),
                    };
                } else {
                    const rowWidth = row.length * VIEWER_NODE_WIDTH + (row.length - 1) * gapX;
                    const originX = startX + Math.max(0, (720 - rowWidth) / 2);
                    positions[id] = {
                        x: originX + colIndex * (VIEWER_NODE_WIDTH + gapX),
                        y: startY + layerIndex * (VIEWER_NODE_HEIGHT + gapY),
                    };
                }
            });
        });

    return nodes.map((node) => ({
        ...node,
        position: positions[node.id] ?? { x: startX, y: startY },
    }));
}

export const EDGE_STROKE = {
    next: '#94a3b8',
    success: '#10b981',
    failure: '#f43f5e',
    retry: '#f59e0b',
    optional: '#94a3b8',
    manual: '#6366f1',
    queued: '#0ea5e9',
};

export const NODE_TYPE_META = {
    trigger: { label: 'Trigger', icon: '⚡', accent: '#6366f1' },
    condition: { label: 'Condition', icon: '◇', accent: '#f59e0b' },
    command: { label: 'Command', icon: '⌘', accent: '#8b5cf6' },
    event: { label: 'Event', icon: '◎', accent: '#0ea5e9' },
    queue: { label: 'Queue', icon: '⏱', accent: '#0284c7' },
    action: { label: 'Action', icon: '▶', accent: '#059669' },
    result: { label: 'Result', icon: '●', accent: '#64748b' },
};

export const STATUS_META = {
    completed: { label: 'Completed', className: 'awv-status-completed' },
    processing: { label: 'Processing', className: 'awv-status-processing' },
    failed: { label: 'Failed', className: 'awv-status-failed' },
    stale: { label: 'Stale', className: 'awv-status-stale' },
    never_executed: { label: 'Never executed', className: 'awv-status-never' },
    registered: { label: 'Registered', className: 'awv-status-registered' },
};
