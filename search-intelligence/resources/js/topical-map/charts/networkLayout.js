/**
 * Deterministic fixed-coordinate layout for Network graph (layout: 'none').
 * Presentation only — never mutate backend DTO / overview payload.
 */
import {
    clampMcp,
    NETWORK_TOPIC_SYMBOL_MIN,
    NETWORK_SITE_SYMBOL_SIZE,
    networkDnaSymbolSize,
} from './theme.js';

/** Gap between adjacent Topic DNA clouds (px in layout space). */
const TOPIC_CLOUD_GAP = 120;

/** Minimum Topic ring radius from Site center. */
const INNER_RING_BASE = 460;

/** Extra radial padding between successive Topic rings. */
const RING_GAP = 180;

/** Radial step added per outer ring index. */
const RING_INDEX_STEP = 150;

/** Padding beyond Site symbol before first Topic ring. */
const SITE_INNER_PAD = 96;

/** Worst-case label metrics across Network zoom typography bands. */
const LABEL_METRICS = Object.freeze({
    topic: Object.freeze({ charWidth: 9.5, lineHeight: 20, distance: 8 }),
    dna: Object.freeze({ charWidth: 8.4, lineHeight: 20, distance: 9 }),
});

/** Padding enforced between every node/label bounding box. */
const LABEL_COLLISION_PAD = 8;

/** Deterministic spiral growth used while seeking the next collision-free slot. */
const DNA_SPIRAL_STEP = Object.freeze({ overview: 32, focus: 42 });

/** Defensive upper bound; fallback remains deterministic if exhausted. */
const DNA_PLACEMENT_ATTEMPTS = 5000;

/** Dense-but-separated global Topic packing; avoids oversized sparse rings. */
const TOPIC_CENTER_SPIRAL_STEP = 120;
const TOPIC_CENTER_PLACEMENT_ATTEMPTS = 8000;

/**
 * DNA label-aware fixed layout — single source of truth for overview + focus.
 *
 * Safe-zone around each Topic (NOT force physics):
 *   clusterInner = topicRadius + LABEL_CLEARANCE + MIN_DNA_GAP + childRadius
 *   clusterOuter = measured extent of every placed node + full label
 *
 * Candidates follow a deterministic golden-angle spiral and are accepted only
 * when both node and label bounding boxes clear all prior content.
 * Precomputed only — layout:'none'.
 */
/** @typedef {'overview'|'focus'} NetworkDnaLayoutMode */

/** Label clearance beyond Topic outline (px). */
export const DNA_LABEL_CLEARANCE = Object.freeze({ overview: 50, focus: 80 });
/** Minimum gap Topic outline → DNA outline (px). */
export const DNA_MIN_GAP = Object.freeze({ overview: 70, focus: 120 });
/** Hard minimum air between Topic outline and DNA outline (px). */
export const DNA_AIR_GAP_MIN = Object.freeze({
    overview: DNA_LABEL_CLEARANCE.overview + DNA_MIN_GAP.overview,
    focus: DNA_LABEL_CLEARANCE.focus + DNA_MIN_GAP.focus,
});
/** Soft air-gap floor (alias of AIR_GAP_MIN). */
export const DNA_AIR_GAP_BASE = Object.freeze({
    overview: DNA_AIR_GAP_MIN.overview,
    focus: DNA_AIR_GAP_MIN.focus,
});
/** Retired Topic-size boost; geometry now uses fixed Topic/DNA sizes. */
export const DNA_AIR_GAP_SCALE = Object.freeze({ overview: 0, focus: 0 });
/** Base radial depth of the DNA neighborhood (before sqrt(n) growth). */
export const DNA_BASE_CLUSTER_DEPTH = Object.freeze({ overview: 48, focus: 64 });
/** Radial growth of the cluster disc with DNA count. */
export const DNA_CLUSTER_DENSITY = Object.freeze({ overview: 24, focus: 32 });
/** Minimum center-to-center between DNA siblings (soft target). */
export const DNA_MIN_SEPARATION = Object.freeze({ overview: 24, focus: 30 });
/**
 * Spread multiplier on cluster depth.
 * overview 1.15 · focus 1.85 → roomier inspection neighborhood.
 */
export const DNA_CLUSTER_SPREAD = Object.freeze({ overview: 1.15, focus: 1.85 });

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
 * Conservative rendered label width for collision-free fixed placement.
 * Uses the largest Network typography band so zooming never reveals overlap.
 *
 * @param {unknown} text
 * @param {'topic'|'dna'} [nodeType='dna']
 * @returns {number}
 */
export function estimateNetworkLabelWidth(text, nodeType = 'dna') {
    const kind = nodeType === 'topic' ? 'topic' : 'dna';
    const lines = String(text ?? '').split('\n');
    const longest = lines.reduce(
        (max, line) => Math.max(max, Array.from(line).length),
        0,
    );
    return Math.max(12, Math.ceil(longest * LABEL_METRICS[kind].charWidth));
}

/**
 * Axis-aligned label bounds in layout coordinates.
 * Mirrors ECharts outside left/right/bottom placement used by Network nodes.
 *
 * @param {object} node
 * @returns {{ left: number, right: number, top: number, bottom: number }}
 */
export function networkLabelBounds(node) {
    const category = node?.category === 'topic' ? 'topic' : 'dna';
    const metrics = LABEL_METRICS[category];
    const x = Number(node?.x) || 0;
    const y = Number(node?.y) || 0;
    const size = Number(node?.symbolSizeHint) > 0
        ? Number(node.symbolSizeHint)
        : (category === 'topic' ? NETWORK_TOPIC_SYMBOL_MIN : networkDnaSymbolSize());
    const radius = size / 2;
    const lines = String(node?.name ?? '').split('\n');
    const width = estimateNetworkLabelWidth(node?.name, category);
    const height = Math.max(1, lines.length) * metrics.lineHeight;
    const position = node?.labelPosition === 'left'
        || node?.labelPosition === 'right'
        || node?.labelPosition === 'top'
        || node?.labelPosition === 'bottom'
        ? node.labelPosition
        : (category === 'topic' ? 'bottom' : 'right');

    if (position === 'left') {
        return {
            left: x - radius - metrics.distance - width,
            right: x - radius - metrics.distance,
            top: y - height / 2,
            bottom: y + height / 2,
        };
    }
    if (position === 'top') {
        return {
            left: x - width / 2,
            right: x + width / 2,
            top: y - radius - metrics.distance - height,
            bottom: y - radius - metrics.distance,
        };
    }
    if (position === 'bottom') {
        return {
            left: x - width / 2,
            right: x + width / 2,
            top: y + radius + metrics.distance,
            bottom: y + radius + metrics.distance + height,
        };
    }
    return {
        left: x + radius + metrics.distance,
        right: x + radius + metrics.distance + width,
        top: y - height / 2,
        bottom: y + height / 2,
    };
}

/** @param {object} node */
function networkNodeBounds(node) {
    const x = Number(node?.x) || 0;
    const y = Number(node?.y) || 0;
    const size = Number(node?.symbolSizeHint) > 0
        ? Number(node.symbolSizeHint)
        : (node?.category === 'topic' ? NETWORK_TOPIC_SYMBOL_MIN : networkDnaSymbolSize());
    const radius = size / 2;
    return {
        left: x - radius,
        right: x + radius,
        top: y - radius,
        bottom: y + radius,
    };
}

/** @param {{left:number,right:number,top:number,bottom:number}} a @param {object} b */
export function networkBoundsOverlap(a, b, padding = LABEL_COLLISION_PAD) {
    return !(
        a.right + padding <= b.left
        || b.right + padding <= a.left
        || a.bottom + padding <= b.top
        || b.bottom + padding <= a.top
    );
}

/** @param {{left:number,right:number,top:number,bottom:number}} box */
function boxRadialExtent(box) {
    return Math.max(
        Math.hypot(box.left, box.top),
        Math.hypot(box.left, box.bottom),
        Math.hypot(box.right, box.top),
        Math.hypot(box.right, box.bottom),
    );
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
    const childSize = networkDnaSymbolSize(topicSize, { focused: layoutMode === 'focus' });
    const childRadius = childSize / 2;
    const spread = DNA_CLUSTER_SPREAD[layoutMode];

    // innerRadius = topicRadius + LABEL_CLEARANCE + MIN_DNA_GAP (+ parent scale) + childRadius
    const airGap = Math.max(
        DNA_AIR_GAP_MIN[layoutMode],
        DNA_LABEL_CLEARANCE[layoutMode]
            + DNA_MIN_GAP[layoutMode],
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
    return metrics.clusterOuter + TOPIC_CLOUD_GAP / 2;
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
        const minByChord = count > 1
            ? Math.max(...slice, 1) / Math.sin(Math.PI / count)
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
 * Pack Topic centers on a deterministic golden-angle spiral using each measured
 * cluster footprint. Unlike max-sized rings, one large Topic does not force a
 * huge empty radius on every sibling.
 *
 * @param {object[]} topics
 * @param {number[]} clearances
 */
export function placeTopicCentersByFootprint(topics, clearances) {
    const list = Array.isArray(topics) ? topics : [];
    const placed = [];

    for (let i = 0; i < list.length; i += 1) {
        const clearance = Math.max(1, Number(clearances?.[i]) || 1);
        let point = null;

        for (let attempt = 0; attempt < TOPIC_CENTER_PLACEMENT_ATTEMPTS; attempt += 1) {
            const slot = i + attempt + 1;
            const radius = NETWORK_SITE_SYMBOL_SIZE / 2
                + SITE_INNER_PAD
                + clearance
                + Math.sqrt(slot) * TOPIC_CENTER_SPIRAL_STEP;
            const angle = -Math.PI / 2 + slot * DNA_GOLDEN_ANGLE;
            const candidate = {
                x: Math.cos(angle) * radius,
                y: Math.sin(angle) * radius,
                angle,
                clearance,
            };
            const overlaps = placed.some((other) => (
                Math.hypot(candidate.x - other.x, candidate.y - other.y)
                < candidate.clearance + other.clearance
            ));
            if (!overlaps) {
                point = candidate;
                break;
            }
        }

        if (!point) {
            const rightEdge = placed.reduce(
                (max, item) => Math.max(max, item.x + item.clearance),
                NETWORK_SITE_SYMBOL_SIZE / 2 + SITE_INNER_PAD,
            );
            point = {
                x: rightEdge + clearance,
                y: 0,
                angle: 0,
                clearance,
            };
        }

        list[i].x = point.x;
        list[i].y = point.y;
        list[i]._layoutAngle = point.angle;
        placed.push(point);
    }
}

/**
 * Place DNA on a deterministic spiral, rejecting every candidate whose node or
 * full label box collides with previously placed content. No realtime force.
 *
 * @param {object[]} dnaNodes
 * @param {{ x: number, y: number }} parent
 * @param {number} topicSymbolSize
 * @param {{
 *   mode?: NetworkDnaLayoutMode|string,
 *   seed?: number,
 *   biasAngle?: number,
 *   topicLabel?: string,
 * }} [opts]
 * @returns {ReturnType<typeof resolveDnaLayoutMetrics>}
 */
export function placeDnaInOrganicCluster(dnaNodes, parent, topicSymbolSize, opts = {}) {
    const list = Array.isArray(dnaNodes) ? dnaNodes : [];
    const tx = Number(parent?.x) || 0;
    const ty = Number(parent?.y) || 0;
    const metrics = resolveDnaLayoutMetrics(topicSymbolSize, list.length, opts.mode);
    const { clusterInner, childSize, topicSize, mode } = metrics;
    const count = list.length;
    const seedBase = Number.isFinite(Number(opts.seed))
        ? Number(opts.seed)
        : (tx * 0.13 + ty * 0.17);
    const angle0 = Number.isFinite(Number(opts.biasAngle))
        ? Number(opts.biasAngle) + (unitHash(seedBase) - 0.5) * 0.35
        : unitHash(seedBase) * Math.PI * 2;

    const topicProbe = {
        category: 'topic',
        name: String(opts.topicLabel || 'Topic'),
        x: tx,
        y: ty,
        symbolSizeHint: topicSize,
        labelPosition: 'bottom',
    };
    const occupied = [networkNodeBounds(topicProbe), networkLabelBounds(topicProbe)];
    let maxExtent = occupied.reduce((max, box) => Math.max(max, boxRadialExtent({
        left: box.left - tx,
        right: box.right - tx,
        top: box.top - ty,
        bottom: box.bottom - ty,
    })), 0);
    const collides = (boxes) => boxes.some(
        (candidate) => occupied.some((placed) => networkBoundsOverlap(candidate, placed)),
    );

    for (let i = 0; i < count; i += 1) {
        const seed = dnaPlacementSeed(seedBase, list[i], i);
        const jitter = (unitHash(seed + 101) - 0.5) * 0.32;
        let placedNode = null;
        let placedBoxes = null;

        for (let attempt = 0; attempt < DNA_PLACEMENT_ATTEMPTS; attempt += 1) {
            const slot = i + attempt + 1;
            const radius = clusterInner + Math.sqrt(slot) * DNA_SPIRAL_STEP[mode];
            const theta = angle0 + slot * DNA_GOLDEN_ANGLE + jitter;
            const dx = Math.cos(theta) * radius;
            const dy = Math.sin(theta) * radius;
            const candidate = {
                ...list[i],
                category: 'dna',
                x: tx + dx,
                y: ty + dy,
                symbolSizeHint: childSize,
                labelPosition: dx < 0 ? 'left' : 'right',
            };
            const boxes = [networkNodeBounds(candidate), networkLabelBounds(candidate)];
            if (!collides(boxes)) {
                placedNode = candidate;
                placedBoxes = boxes;
                break;
            }
        }

        if (!placedNode || !placedBoxes) {
            const side = i % 2 === 0 ? 1 : -1;
            let row = i + 1;
            do {
                placedNode = {
                    ...list[i],
                    category: 'dna',
                    x: tx + side * (clusterInner + DNA_SPIRAL_STEP[mode] * 2),
                    y: ty + row * (LABEL_METRICS.dna.lineHeight + LABEL_COLLISION_PAD * 2),
                    symbolSizeHint: childSize,
                    labelPosition: side < 0 ? 'left' : 'right',
                };
                placedBoxes = [networkNodeBounds(placedNode), networkLabelBounds(placedNode)];
                row += 1;
            } while (collides(placedBoxes));
        }

        list[i].x = placedNode.x;
        list[i].y = placedNode.y;
        list[i].symbolSizeHint = childSize;
        list[i].parentSymbolSize = topicSize;
        list[i].labelPosition = placedNode.labelPosition;
        occupied.push(...placedBoxes);
        for (const box of placedBoxes) {
            maxExtent = Math.max(maxExtent, boxRadialExtent({
                left: box.left - tx,
                right: box.right - tx,
                top: box.top - ty,
                bottom: box.bottom - ty,
            }));
        }
    }

    const clusterOuter = Math.max(metrics.clusterOuter, maxExtent);
    return { ...metrics, clusterOuter, orbit: clusterOuter };
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
    void opts;

    const site = list.find((n) => n.category === 'site');
    if (site) {
        site.x = 0;
        site.y = 0;
    }

    const topics = list.filter((n) => n.category === 'topic');
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

    // First place each cluster around a local origin. Its measured extent includes
    // every full label, so Topic-center spacing can use the real footprint.
    const clearances = topics.map((topic) => {
        const tid = Number(String(topic.id).replace(/^topic:/, ''));
        const metrics = placeDnaInOrganicCluster(
            dnaByTopic.get(tid) || [],
            { x: 0, y: 0 },
            resolveTopicSymbolSize(topic),
            {
                mode: 'overview',
                seed: tid,
                topicLabel: topic.name,
            },
        );
        return metrics.clusterOuter + TOPIC_CLOUD_GAP / 2;
    });

    placeTopicCentersByFootprint(topics, clearances);

    /** @type {Map<number, {x: number, y: number, angle: number, symbolSize: number}>} */
    const topicPos = new Map();
    for (const node of topics) {
        const tid = Number(String(node.id).replace(/^topic:/, ''));
        if (Number.isFinite(tid) && tid > 0) {
            topicPos.set(tid, {
                x: Number(node.x) || 0,
                y: Number(node.y) || 0,
                angle: Number(node._layoutAngle) || 0,
                symbolSize: resolveTopicSymbolSize(node),
            });
        }
        delete node._layoutAngle;
    }

    for (const node of topics) {
        node.labelPosition = 'bottom';
    }

    for (const [tid, dnaNodes] of dnaByTopic) {
        const parent = topicPos.get(tid);
        const px = parent?.x ?? 0;
        const py = parent?.y ?? 0;
        for (const node of dnaNodes) {
            node.x = (Number(node.x) || 0) + px;
            node.y = (Number(node.y) || 0) + py;
        }
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
            topicLabel: topic?.name,
        },
    );

    if (site) {
        // Clear of measured node + full-label footprint.
        const dnaExtent = metrics.clusterOuter;
        site.x = 0;
        site.y = Math.max(
            dnaExtent + NETWORK_SITE_SYMBOL_SIZE / 2 + 112,
            topicSize / 2 + 180,
        );
        site.labelPosition = 'bottom';
    }

    return metrics;
}
