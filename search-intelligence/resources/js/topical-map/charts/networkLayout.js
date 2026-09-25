/**
 * Deterministic fixed-coordinate layout for Network graph (layout: 'none').
 * Presentation only — never mutate backend DTO / overview payload.
 */
import {
    clampMcp,
    NETWORK_TOPIC_SYMBOL_MIN,
    NETWORK_SITE_SYMBOL_SIZE,
    normalizeTopicSymbolScale,
    networkDnaSymbolSize,
} from './theme.js';

/** Gap between adjacent Topic DNA clouds (px in layout space). */
const TOPIC_CLOUD_GAP = 96;

/** Minimum Topic ring radius from Site center. */
const INNER_RING_BASE = 320;

/** Extra radial padding between successive Topic rings. */
const RING_GAP = 88;

/** Radial step added per outer ring index. */
const RING_INDEX_STEP = 84;

/** Padding beyond Site symbol before first Topic ring. */
const SITE_INNER_PAD = 48;

/**
 * DNA organic cluster layout — single source of truth for overview + focus.
 *
 * NOT a ring / semicircle. DNA fill an annular neighborhood around the Topic
 * via a golden-angle (Vogel) disc with mild ellipse — precomputed, layout:'none'.
 *
 * Distances (Topic center → DNA center):
 *   clusterInner = topicRadius + airGap + childRadius
 *   clusterOuter = clusterInner + sqrt(n) * density  (+ focus boost)
 */
/** @typedef {'overview'|'focus'} NetworkDnaLayoutMode */

/** Hard minimum air between Topic outline and DNA outline (px). */
export const DNA_AIR_GAP_MIN = Object.freeze({ overview: 56, focus: 72 });
/** Soft air-gap floor before parent-scale boost. */
export const DNA_AIR_GAP_BASE = Object.freeze({ overview: 48, focus: 64 });
/** Extra air-gap * normalizeTopicSymbolScale (0..1). */
export const DNA_AIR_GAP_SCALE = Object.freeze({ overview: 40, focus: 56 });
/** Radial growth of the cluster disc with DNA count. */
export const DNA_CLUSTER_DENSITY = Object.freeze({ overview: 22, focus: 30 });
/** Minimum center-to-center between DNA siblings (soft target). */
export const DNA_MIN_SEPARATION = Object.freeze({ overview: 18, focus: 24 });

/** Golden angle — Vogel disc packing (not equal-radius orbit). */
export const DNA_GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

/** @deprecated Alias — prefer DNA_AIR_GAP_BASE via resolveDnaLayoutMetrics. */
export const DNA_ORBIT_BASE = DNA_AIR_GAP_BASE.overview;
/** @deprecated Alias — prefer DNA_CLUSTER_DENSITY. */
export const DNA_ORBIT_SPACING = DNA_CLUSTER_DENSITY.overview;
/** @deprecated Alias — prefer DNA_AIR_GAP_SCALE. */
export const DNA_ORBIT_SCALE_BOOST = DNA_AIR_GAP_SCALE.overview;
/** @deprecated Kept for contract greps; cluster does not multiply topicRadius. */
export const DNA_ORBIT_RADIUS_SCALE = 1;
/**
 * @deprecated Ring/arc layout retired — organic cluster only.
 * Kept so older greps fail closed if someone reintroduces arc span math.
 */
export const DNA_SEMI_ARC_SPAN = 0;
export const DNA_SEMI_ARC_RADIUS_SCALE = 1;
/** Screen-up in ECharts graph coords (y increases downward). */
export const DNA_ARC_UP = -Math.PI / 2;

/**
 * @param {unknown} mode
 * @returns {NetworkDnaLayoutMode}
 */
export function normalizeDnaLayoutMode(mode) {
    return mode === 'focus' ? 'focus' : 'overview';
}

/**
 * Stable float in [0,1) from a numeric seed (presentation only).
 *
 * @param {number} seed
 * @returns {number}
 */
function unitHash(seed) {
    const x = Math.sin(Number(seed) * 12.9898 + 78.233) * 43758.5453;
    return x - Math.floor(x);
}

/**
 * Canonical DNA cluster metrics for one Topic neighborhood.
 *
 * @param {number} topicSymbolSize — Topic diameter (px)
 * @param {number} dnaCount
 * @param {NetworkDnaLayoutMode|string} [mode='overview']
 * @returns {{
 *   mode: NetworkDnaLayoutMode,
 *   topicSize: number,
 *   topicRadius: number,
 *   childSize: number,
 *   childRadius: number,
 *   airGap: number,
 *   clusterInner: number,
 *   clusterOuter: number,
 *   orbit: number,
 * }}
 */
export function resolveDnaLayoutMetrics(
    topicSymbolSize = NETWORK_TOPIC_SYMBOL_MIN,
    dnaCount = 0,
    mode = 'overview',
) {
    const layoutMode = normalizeDnaLayoutMode(mode);
    const size = Number(topicSymbolSize);
    const topicSize = Number.isFinite(size) && size > 0 ? size : NETWORK_TOPIC_SYMBOL_MIN;
    const topicRadius = topicSize / 2;
    const n = Math.max(0, Number(dnaCount) || 0);
    const scale = normalizeTopicSymbolScale(topicSize);
    const childSize = networkDnaSymbolSize(topicSize, { focused: layoutMode === 'focus' });
    const childRadius = childSize / 2;

    const airGap = Math.max(
        DNA_AIR_GAP_MIN[layoutMode],
        DNA_AIR_GAP_BASE[layoutMode] + scale * DNA_AIR_GAP_SCALE[layoutMode],
    );
    const clusterInner = topicRadius + airGap + childRadius;
    const radialGrow = Math.sqrt(n) * DNA_CLUSTER_DENSITY[layoutMode];
    // Soft floor so dense clusters stay clickable (area ∝ n * separation²).
    const minByArea = n > 1
        ? clusterInner + Math.sqrt(n / Math.PI) * DNA_MIN_SEPARATION[layoutMode]
        : clusterInner;
    const clusterOuter = Math.max(clusterInner + radialGrow, minByArea);

    return {
        mode: layoutMode,
        topicSize,
        topicRadius,
        childSize,
        childRadius,
        airGap,
        clusterInner,
        clusterOuter,
        // Back-compat alias used by clearance / older callers.
        orbit: clusterOuter,
    };
}

/**
 * Stable Topic order for Network layout + defensive DNA cap priority.
 * MCP desc → name → id.
 *
 * @param {object} a
 * @param {object} b
 * @returns {number}
 */
export function compareTopicsForNetworkLayout(a, b) {
    const byMcp = clampMcp(b?.mcp) - clampMcp(a?.mcp);
    if (byMcp !== 0) {
        return byMcp;
    }
    const byName = String(a?.name || '').localeCompare(String(b?.name || ''), undefined, {
        sensitivity: 'base',
    });
    if (byName !== 0) {
        return byName;
    }
    return Number(a?.id ?? 0) - Number(b?.id ?? 0);
}

/**
 * Resolve Topic visual diameter from a graph node (symbolSizeHint preferred).
 *
 * @param {object|null|undefined} topicNode
 * @returns {number}
 */
export function resolveTopicSymbolSize(topicNode) {
    const hint = Number(topicNode?.symbolSizeHint);
    if (Number.isFinite(hint) && hint > 0) {
        return hint;
    }
    return NETWORK_TOPIC_SYMBOL_MIN;
}

/**
 * Outer cluster radius (Topic center → farthest DNA center).
 * Thin wrapper over {@see resolveDnaLayoutMetrics}.
 *
 * @param {number} dnaCount
 * @param {number} [topicSymbolSize]
 * @param {NetworkDnaLayoutMode|string} [mode='overview']
 * @returns {number}
 */
export function dnaSatelliteRadius(
    dnaCount,
    topicSymbolSize = NETWORK_TOPIC_SYMBOL_MIN,
    mode = 'overview',
) {
    return resolveDnaLayoutMetrics(topicSymbolSize, dnaCount, mode).clusterOuter;
}

/**
 * Map a layout angle (radians; 0=+x/right, −π/2=up) to an ECharts outside label side.
 * Never returns 'inside' — Topic labels stay outside the symbol.
 *
 * @param {number} angle
 * @returns {'top'|'bottom'|'left'|'right'}
 */
export function labelPositionFromAngle(angle) {
    let a = Number(angle);
    if (!Number.isFinite(a)) {
        return 'bottom';
    }
    while (a > Math.PI) {
        a -= 2 * Math.PI;
    }
    while (a < -Math.PI) {
        a += 2 * Math.PI;
    }
    const c = Math.cos(a);
    const s = Math.sin(a);
    if (Math.abs(s) >= Math.abs(c)) {
        return s > 0 ? 'bottom' : 'top';
    }
    return c > 0 ? 'right' : 'left';
}

/**
 * Split N Topics into ring capacities (inner rings fill first = higher MCP).
 *
 * @param {number} topicCount
 * @returns {number[]} capacities per ring (sum === topicCount)
 */
export function planTopicRingCapacities(topicCount) {
    const n = Math.max(0, Math.floor(Number(topicCount) || 0));
    if (n <= 0) {
        return [];
    }
    const capacities = [];
    let remaining = n;
    // Inner ring modest; outer rings grow so circumference can absorb DNA clouds.
    let capacity = Math.min(14, Math.max(8, Math.ceil(Math.sqrt(n) * 1.8)));
    while (remaining > 0) {
        const take = Math.min(capacity, remaining);
        capacities.push(take);
        remaining -= take;
        capacity = Math.max(take + 4, Math.ceil(capacity * 1.45));
    }
    return capacities;
}

/**
 * Clearance half-width for a Topic + its DNA cloud (for ring spacing).
 * Uses overview metrics — focus layout is local and does not affect rings.
 *
 * @param {number} dnaCount
 * @param {number} [topicSymbolSize]
 * @returns {number}
 */
export function topicCloudClearance(dnaCount, topicSymbolSize = NETWORK_TOPIC_SYMBOL_MIN) {
    const metrics = resolveDnaLayoutMetrics(topicSymbolSize, dnaCount, 'overview');
    return metrics.clusterOuter + metrics.childRadius + TOPIC_CLOUD_GAP / 2;
}

/**
 * Compute ring radii so Topic centers leave room for DNA satellites.
 *
 * @param {number[]} capacities
 * @param {number[]} clearancesPerTopic — length === sum(capacities), MCP-sorted order
 * @returns {number[]} radius per ring
 */
export function computeRingRadii(capacities, clearancesPerTopic) {
    const radii = [];
    let prevOuter = NETWORK_SITE_SYMBOL_SIZE / 2 + SITE_INNER_PAD;
    let offset = 0;

    for (let r = 0; r < capacities.length; r += 1) {
        const count = capacities[r];
        const slice = clearancesPerTopic.slice(offset, offset + count);
        const maxClear = slice.reduce((m, c) => Math.max(m, c), 0);
        const minByChord = count > 0
            ? (count * Math.max(...slice, 1) * 2) / (2 * Math.PI)
            : INNER_RING_BASE;
        const radius = Math.max(
            INNER_RING_BASE + r * RING_INDEX_STEP,
            prevOuter + maxClear + RING_GAP,
            minByChord,
        );
        radii.push(radius);
        prevOuter = radius + maxClear;
        offset += count;
    }

    return radii;
}

/**
 * Place DNA as an organic Vogel/golden-angle cluster around a Topic.
 * Precomputed only — no realtime force. Radii vary (annular disc), not a ring.
 * Stamps symbolSizeHint + labelPosition per DNA.
 *
 * @param {object[]} dnaNodes
 * @param {{ x: number, y: number }} parent
 * @param {number} topicSymbolSize
 * @param {{
 *   mode?: NetworkDnaLayoutMode|string,
 *   seed?: number,
 *   biasAngle?: number,
 * }} [opts]
 * @returns {ReturnType<typeof resolveDnaLayoutMetrics>}
 */
export function placeDnaInOrganicCluster(dnaNodes, parent, topicSymbolSize, opts = {}) {
    const list = Array.isArray(dnaNodes) ? dnaNodes : [];
    const tx = Number(parent?.x) || 0;
    const ty = Number(parent?.y) || 0;
    const metrics = resolveDnaLayoutMetrics(topicSymbolSize, list.length, opts.mode);
    const { clusterInner, clusterOuter, childSize, topicSize } = metrics;
    const count = list.length;
    if (count === 0) {
        return metrics;
    }

    const seedBase = Number.isFinite(Number(opts.seed))
        ? Number(opts.seed)
        : (tx * 0.13 + ty * 0.17);
    const angle0 = Number.isFinite(Number(opts.biasAngle))
        ? Number(opts.biasAngle) + unitHash(seedBase) * 0.35
        : unitHash(seedBase) * Math.PI * 2;
    // Mild ellipse breaks the "perfect circle of dots" look.
    const stretchX = 1.12;
    const stretchY = 0.9;

    for (let i = 0; i < count; i += 1) {
        const t = count === 1 ? 0.55 : Math.sqrt((i + 0.5) / count);
        const radius = clusterInner + (clusterOuter - clusterInner) * t;
        const theta = angle0 + i * DNA_GOLDEN_ANGLE;
        // Mild anisotropic direction (not equal-radius ring); keep exact radius.
        let ux = Math.cos(theta) * stretchX;
        let uy = Math.sin(theta) * stretchY;
        const norm = Math.hypot(ux, uy) || 1;
        ux /= norm;
        uy /= norm;
        const dx = ux * radius;
        const dy = uy * radius;
        list[i].x = tx + dx;
        list[i].y = ty + dy;
        list[i].symbolSizeHint = childSize;
        list[i].parentSymbolSize = topicSize;
        // Label outside, away from parent center.
        list[i].labelPosition = labelPositionFromAngle(Math.atan2(dy, dx));
    }

    return metrics;
}

/**
 * @deprecated Use {@see placeDnaInOrganicCluster}. Arc/ring layout removed.
 */
export function placeDnaOnSemiArc(dnaNodes, parent, topicSymbolSize, arcCenter, opts = {}) {
    return placeDnaInOrganicCluster(dnaNodes, parent, topicSymbolSize, {
        ...opts,
        biasAngle: arcCenter,
    });
}

/**
 * Assign fixed x/y for Site, Topics, and DNA satellites (full Network).
 * Mutates node objects in place (presentation graph nodes only).
 *
 * @param {object[]} nodes
 * @param {{ topicDnaCounts?: Map<number, number> }} [opts]
 */
export function assignNetworkFixedCoordinates(nodes, opts = {}) {
    const list = Array.isArray(nodes) ? nodes : [];
    const dnaCountByTopic = opts.topicDnaCounts instanceof Map
        ? opts.topicDnaCounts
        : new Map();

    const site = list.find((n) => n.category === 'site');
    if (site) {
        site.x = 0;
        site.y = 0;
    }

    const topics = list.filter((n) => n.category === 'topic');
    const capacities = planTopicRingCapacities(topics.length);
    const clearances = topics.map((n) => {
        const tid = Number(String(n.id).replace(/^topic:/, ''));
        const count = dnaCountByTopic.has(tid)
            ? Number(dnaCountByTopic.get(tid))
            : Number(n.dna_count ?? 0);
        return topicCloudClearance(count, resolveTopicSymbolSize(n));
    });
    const radii = computeRingRadii(capacities, clearances);

    let topicIndex = 0;
    for (let r = 0; r < capacities.length; r += 1) {
        const count = capacities[r];
        const radius = radii[r] ?? INNER_RING_BASE;
        const startAngle = -Math.PI / 2 + r * 0.17;
        for (let i = 0; i < count; i += 1) {
            const node = topics[topicIndex];
            if (!node) {
                break;
            }
            const angle = startAngle + (2 * Math.PI * i) / count;
            node.x = Math.cos(angle) * radius;
            node.y = Math.sin(angle) * radius;
            node._layoutAngle = angle;
            node._layoutRing = r;
            topicIndex += 1;
        }
    }

    /** @type {Map<number, {x: number, y: number, angle: number, ring: number, symbolSize: number}>} */
    const topicPos = new Map();
    for (const node of topics) {
        const tid = Number(String(node.id).replace(/^topic:/, ''));
        if (Number.isFinite(tid) && tid > 0) {
            topicPos.set(tid, {
                x: Number(node.x) || 0,
                y: Number(node.y) || 0,
                angle: Number(node._layoutAngle) || 0,
                ring: Number(node._layoutRing) || 0,
                symbolSize: resolveTopicSymbolSize(node),
            });
        }
        delete node._layoutAngle;
        delete node._layoutRing;
    }

    /** @type {Map<number, object[]>} */
    const dnaByTopic = new Map();
    for (const node of list) {
        if (node.category !== 'dna') {
            continue;
        }
        const tid = Number(node.topic_id);
        if (!Number.isFinite(tid) || tid <= 0) {
            node.x = 0;
            node.y = 0;
            continue;
        }
        if (!dnaByTopic.has(tid)) {
            dnaByTopic.set(tid, []);
        }
        dnaByTopic.get(tid).push(node);
    }

    for (const node of topics) {
        const tid = Number(String(node.id).replace(/^topic:/, ''));
        const parent = Number.isFinite(tid) ? topicPos.get(tid) : null;
        // Prefer Topic label opposite the radial outward direction (toward Site).
        const outward = parent?.angle ?? DNA_ARC_UP;
        node.labelPosition = labelPositionFromAngle(outward + Math.PI);
    }

    for (const [tid, dnaNodes] of dnaByTopic) {
        const parent = topicPos.get(tid);
        const outward = parent?.angle ?? DNA_ARC_UP;
        placeDnaInOrganicCluster(
            dnaNodes,
            { x: parent?.x ?? 0, y: parent?.y ?? 0 },
            parent?.symbolSize ?? NETWORK_TOPIC_SYMBOL_MIN,
            {
                mode: 'overview',
                seed: tid,
                biasAngle: outward,
            },
        );
    }
}

/**
 * Focused-mode local layout (separate from overview ring layout):
 *   Topic centered · DNA organic cluster · label BELOW · Site (Back) further below.
 * Uses focus metrics: larger children, larger air-gap, easier click targets.
 * Mutates presentation nodes only. layout: 'none' — no force.
 *
 * @param {object[]} nodes
 */
export function assignFocusedNetworkCoordinates(nodes) {
    const list = Array.isArray(nodes) ? nodes : [];
    const site = list.find((n) => n.category === 'site');
    const topic = list.find((n) => n.category === 'topic');
    const dnaNodes = list.filter((n) => n.category === 'dna');

    const topicSize = resolveTopicSymbolSize(topic);
    const tid = topic
        ? Number(String(topic.id).replace(/^topic:/, ''))
        : 0;

    if (topic) {
        topic.x = 0;
        topic.y = 0;
        topic.labelPosition = 'bottom';
    }

    const metrics = placeDnaInOrganicCluster(
        dnaNodes,
        { x: 0, y: 0 },
        topicSize,
        {
            mode: 'focus',
            seed: tid,
            biasAngle: DNA_ARC_UP,
        },
    );

    if (site) {
        // Clear of DNA cluster extent + Topic label (name + MCP%).
        site.x = 0;
        site.y = Math.max(
            metrics.clusterOuter + NETWORK_SITE_SYMBOL_SIZE / 2 + 48,
            topicSize / 2 + 120,
        );
        site.labelPosition = 'bottom';
    }

    return metrics;
}
