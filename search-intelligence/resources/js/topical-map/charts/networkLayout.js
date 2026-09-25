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
 * Annulus / safe-zone around each Topic (NOT ring / semicircle / force):
 *   clusterInner = topicRadius + LABEL_CLEARANCE + MIN_DNA_GAP + childRadius
 *   clusterOuter = clusterInner + (BASE_DEPTH + sqrt(n) * SPACING) * spread
 *
 * DNA angles: golden-angle + deterministic hash jitter.
 * DNA radii: mixed packing + hash fill of the annulus.
 * Precomputed only — layout:'none'.
 */
/** @typedef {'overview'|'focus'} NetworkDnaLayoutMode */

/** Label clearance beyond Topic outline (px). */
export const DNA_LABEL_CLEARANCE = Object.freeze({ overview: 24, focus: 28 });
/** Minimum gap Topic outline → DNA outline (px). */
export const DNA_MIN_GAP = Object.freeze({ overview: 32, focus: 40 });
/** Hard minimum air between Topic outline and DNA outline (px). */
export const DNA_AIR_GAP_MIN = Object.freeze({
    overview: DNA_LABEL_CLEARANCE.overview + DNA_MIN_GAP.overview,
    focus: DNA_LABEL_CLEARANCE.focus + DNA_MIN_GAP.focus,
});
/** Soft air-gap floor before parent-scale boost (alias of AIR_GAP_MIN). */
export const DNA_AIR_GAP_BASE = Object.freeze({
    overview: DNA_AIR_GAP_MIN.overview,
    focus: DNA_AIR_GAP_MIN.focus,
});
/** Extra air-gap * normalizeTopicSymbolScale (0..1) — large Topics get more room. */
export const DNA_AIR_GAP_SCALE = Object.freeze({ overview: 16, focus: 20 });
/** Base radial depth of the DNA neighborhood (before sqrt(n) growth). */
export const DNA_BASE_CLUSTER_DEPTH = Object.freeze({ overview: 28, focus: 36 });
/** Radial growth of the cluster disc with DNA count. */
export const DNA_CLUSTER_DENSITY = Object.freeze({ overview: 16, focus: 20 });
/** Minimum center-to-center between DNA siblings (soft target). */
export const DNA_MIN_SEPARATION = Object.freeze({ overview: 16, focus: 22 });
/**
 * Spread multiplier on cluster depth.
 * overview 1.0 · focus 1.6 → roomier inspection neighborhood.
 */
export const DNA_CLUSTER_SPREAD = Object.freeze({ overview: 1.0, focus: 1.6 });

/** Golden angle — low-discrepancy angular packing (not equal-radius orbit). */
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
 * Deterministic — never Math.random().
 *
 * @param {number} seed
 * @returns {number}
 */
function unitHash(seed) {
    const x = Math.sin(Number(seed) * 12.9898 + 78.233) * 43758.5453;
    return x - Math.floor(x);
}

/**
 * Stable numeric seed from Topic id + DNA id/index.
 *
 * @param {number} topicSeed
 * @param {object} dnaNode
 * @param {number} index
 * @returns {number}
 */
function dnaPlacementSeed(topicSeed, dnaNode, index) {
    const rawId = String(dnaNode?.id ?? '');
    let idPart = 0;
    for (let c = 0; c < rawId.length; c += 1) {
        idPart = (idPart * 31 + rawId.charCodeAt(c)) | 0;
    }
    return Number(topicSeed) * 1009 + idPart * 17 + index * 131;
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
 *   spread: number,
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
    const spread = DNA_CLUSTER_SPREAD[layoutMode];

    // innerRadius = topicRadius + LABEL_CLEARANCE + MIN_DNA_GAP (+ parent scale) + childRadius
    const airGap = Math.max(
        DNA_AIR_GAP_MIN[layoutMode],
        DNA_LABEL_CLEARANCE[layoutMode]
            + DNA_MIN_GAP[layoutMode]
            + scale * DNA_AIR_GAP_SCALE[layoutMode],
    );
    const clusterInner = topicRadius + airGap + childRadius;
    const depth = DNA_BASE_CLUSTER_DEPTH[layoutMode]
        + Math.sqrt(n) * DNA_CLUSTER_DENSITY[layoutMode];
    // Soft floor so dense clusters stay clickable (area ∝ n * separation²).
    const minByArea = n > 1
        ? Math.sqrt(n / Math.PI) * DNA_MIN_SEPARATION[layoutMode]
        : 0;
    const clusterOuter = clusterInner + Math.max(depth, minByArea) * spread;

    return {
        mode: layoutMode,
        topicSize,
        topicRadius,
        childSize,
        childRadius,
        airGap,
        clusterInner,
        clusterOuter,
        spread,
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
    // Ellipse stretch can push DNA ~1.36× past clusterOuter along the long axis.
    return metrics.clusterOuter * 1.36 + metrics.childRadius + TOPIC_CLOUD_GAP / 2;
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
 * Place DNA as an organic annulus cluster around a Topic.
 * Precomputed only — no realtime force. Radii vary inside [inner, outer];
 * angles use golden-angle + hash jitter (no ring / radial spokes).
 * Stamps symbolSizeHint + left/right labelPosition per DNA.
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
        ? Number(opts.biasAngle) + unitHash(seedBase) * 0.55
        : unitHash(seedBase) * Math.PI * 2;
    // Per-Topic ellipse stretch — breaks circular silhouette without force.
    const stretchX = 1.08 + unitHash(seedBase + 3) * 0.28;
    const stretchY = 0.78 + unitHash(seedBase + 7) * 0.22;

    for (let i = 0; i < count; i += 1) {
        const seed = dnaPlacementSeed(seedBase, list[i], i);
        const hR = unitHash(seed);
        const hA = unitHash(seed + 101);
        // Mix low-discrepancy packing with hash so radii are not a thin outer ring.
        const packingT = count === 1 ? 0.42 : Math.sqrt((i + 0.35) / count);
        const t = Math.max(0, Math.min(1, packingT * 0.38 + hR * 0.62));
        const radius = clusterInner + (clusterOuter - clusterInner) * t;
        // Golden angle + hash jitter — avoids spokes and equal spacing look.
        const theta = angle0 + i * DNA_GOLDEN_ANGLE + (hA - 0.5) * 0.7;
        // Apply anisotropic stretch WITHOUT renormalizing onto a circle.
        let dx = Math.cos(theta) * radius * stretchX;
        let dy = Math.sin(theta) * radius * stretchY;
        // Keep DNA inside an elliptical safe annulus (inner hard, outer soft).
        const dist = Math.hypot(dx, dy);
        const maxOuter = clusterOuter * Math.max(stretchX, stretchY);
        if (dist > 0 && dist < clusterInner) {
            const s = clusterInner / dist;
            dx *= s;
            dy *= s;
        } else if (dist > maxOuter) {
            const s = maxOuter / dist;
            dx *= s;
            dy *= s;
        }
        list[i].x = tx + dx;
        list[i].y = ty + dy;
        list[i].symbolSizeHint = childSize;
        list[i].parentSymbolSize = topicSize;
        // External left/right by side of Topic — readable, never rotated.
        list[i].labelPosition = dx < 0 ? 'left' : 'right';
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
        // Topic labels always outside, preferred below the node.
        node.labelPosition = 'bottom';
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
        // Clear of DNA cluster extent (incl. ellipse stretch) + Topic label (name + MCP%).
        const dnaExtent = metrics.clusterOuter * 1.36 + metrics.childRadius;
        site.x = 0;
        site.y = Math.max(
            dnaExtent + NETWORK_SITE_SYMBOL_SIZE / 2 + 48,
            topicSize / 2 + 120,
        );
        site.labelPosition = 'bottom';
    }

    return metrics;
}
