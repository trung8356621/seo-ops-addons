/**
 * Deterministic topic palette + MCP → symbol size helpers for Tree view.
 */

/** Distinct hues readable on light backgrounds. */
export const TOPIC_PALETTE = [
    '#2563eb', // blue
    '#db2777', // pink
    '#059669', // emerald
    '#d97706', // amber
    '#7c3aed', // violet
    '#0891b2', // cyan
    '#dc2626', // red
    '#4f46e5', // indigo
    '#ca8a04', // yellow
    '#0d9488', // teal
    '#c026d3', // fuchsia
    '#ea580c', // orange
    '#0284c7', // sky
    '#65a30d', // lime
    '#9333ea', // purple
    '#e11d48', // rose
];

/** Fixed keyword leaf size — never scales with MCP. */
export const KEYWORD_SYMBOL_SIZE = 8;

/**
 * Default / mid Network DNA size (fallback when parent Topic size unknown).
 * Prefer {@see networkDnaSymbolSize} — DNA scales with parent Topic.
 */
export const NETWORK_DNA_SYMBOL_SIZE = 7;

/** DNA satellite diameter — overview (full map). Modest, still clickable. */
export const NETWORK_DNA_SYMBOL_MIN = 5;
export const NETWORK_DNA_SYMBOL_MAX = 9;
/** DNA satellite diameter — focus/isolate (easier hover/click). */
export const NETWORK_DNA_SYMBOL_FOCUS_MIN = 9;
export const NETWORK_DNA_SYMBOL_FOCUS_MAX = 13;
/** DNA diameter ≈ this fraction of parent Topic diameter (then clamped). */
export const NETWORK_DNA_PARENT_SIZE_RATIO = 0.18;

/** Fixed Network Site node — structural, not MCP-scaled. */
export const NETWORK_SITE_SYMBOL_SIZE = 24;

export const MCP_SYMBOL_MIN = 12;
export const MCP_SYMBOL_MAX = 30;

/** Network Topic size — MCP hierarchy kept; max tempered so Topics don't swallow DNA. */
export const NETWORK_TOPIC_SYMBOL_MIN = 10;
export const NETWORK_TOPIC_SYMBOL_MAX = 60;
export const NETWORK_MCP_SIZE_EXPONENT = 1.35;

/** Defensive full-graph DNA cap (never silent). */
export const NETWORK_MAX_DNA_NODES = 1500;

/** Shared chart typography tokens (presentation only). */
export const CHART_FONT_FAMILY =
    "'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif";
export const CHART_FONT_TOOLTIP = 12;
export const CHART_FONT_SMALL = 11;
export const CHART_FONT_NORMAL = 12;
export const CHART_FONT_MEDIUM = 14;
export const CHART_FONT_LARGE = 16;
export const CHART_FONT_MAX_ZOOM = 18;

/**
 * Discrete zoom → typography band (not a linear font multiplier).
 * Used by Network (and shared helpers). Structure uses {@see getStructureTypographyBand}.
 *
 * @param {unknown} zoom
 * @returns {'compact'|'normal'|'medium'|'large'}
 */
export function getChartTypographyBand(zoom) {
    const z = Number(zoom);
    if (!Number.isFinite(z) || z < 0.75) {
        return 'compact';
    }
    if (z < 1.5) {
        return 'normal';
    }
    if (z < 2.5) {
        return 'medium';
    }
    return 'large';
}

/**
 * Structure-only zoom bands — finer steps so 400% reaches ~20px Topic text.
 * Network must keep {@see getChartTypographyBand}.
 *
 * @param {unknown} zoom
 * @returns {'compact'|'normal'|'medium'|'large'|'xlarge'}
 */
export function getStructureTypographyBand(zoom) {
    const z = Number(zoom);
    if (!Number.isFinite(z) || z < 1) {
        return 'compact';
    }
    if (z < 1.75) {
        return 'normal';
    }
    if (z < 2.5) {
        return 'medium';
    }
    if (z < 3.5) {
        return 'large';
    }
    return 'xlarge';
}

/**
 * Structure BT label metrics by zoom band.
 *
 * @param {'compact'|'normal'|'medium'|'large'|'xlarge'} band
 * @returns {{
 *   topic: { fontSize: number, width: number, distance: number, fontWeight: number },
 *   tag: { fontSize: number, width: number, distance: number, fontWeight: number },
 *   site: { fontSize: number, width: number, distance: number, fontWeight: number },
 * }}
 */
export function getStructureTypography(band) {
    switch (band) {
        case 'compact':
            // ~100% / FIT
            return {
                topic: { fontSize: 10, width: 110, distance: 6, fontWeight: 600 },
                tag: { fontSize: 10, width: 96, distance: 6, fontWeight: 600 },
                site: { fontSize: 11, width: 96, distance: 6, fontWeight: 600 },
            };
        case 'normal':
            // ~150%
            return {
                topic: { fontSize: 12, width: 130, distance: 7, fontWeight: 600 },
                tag: { fontSize: 11, width: 115, distance: 7, fontWeight: 600 },
                site: { fontSize: 11, width: 115, distance: 7, fontWeight: 600 },
            };
        case 'medium':
            // ~200%
            return {
                topic: { fontSize: 14, width: 155, distance: 8, fontWeight: 600 },
                tag: { fontSize: 13, width: 140, distance: 8, fontWeight: 600 },
                site: { fontSize: 13, width: 140, distance: 8, fontWeight: 600 },
            };
        case 'large':
            // ~300%
            return {
                topic: { fontSize: 17, width: 190, distance: 10, fontWeight: 600 },
                tag: { fontSize: 15, width: 165, distance: 9, fontWeight: 600 },
                site: { fontSize: 15, width: 165, distance: 9, fontWeight: 600 },
            };
        case 'xlarge':
            // ~400%
            return {
                topic: { fontSize: 20, width: 220, distance: 12, fontWeight: 600 },
                tag: { fontSize: 17, width: 190, distance: 11, fontWeight: 600 },
                site: { fontSize: 16, width: 180, distance: 11, fontWeight: 600 },
            };
        default:
            return {
                topic: { fontSize: 10, width: 110, distance: 6, fontWeight: 600 },
                tag: { fontSize: 10, width: 96, distance: 6, fontWeight: 600 },
                site: { fontSize: 11, width: 96, distance: 6, fontWeight: 600 },
            };
    }
}

/**
 * Network label font sizes by zoom band (DNA hover only).
 *
 * @param {'compact'|'normal'|'medium'|'large'} band
 * @returns {{ topic: number, site: number, dna: number, distance: number }}
 */
export function getNetworkTypography(band) {
    switch (band) {
        case 'compact':
            return { topic: 10, site: 10, dna: 10, distance: 4 };
        case 'medium':
            return { topic: 13, site: 13, dna: 11, distance: 5 };
        case 'large':
            return { topic: 16, site: 15, dna: 11, distance: 6 };
        case 'normal':
        default:
            return { topic: 11, site: 12, dna: 10, distance: 4 };
    }
}

/**
 * Treemap static tier styles (no zoom).
 *
 * @returns {{ L: object, M: object, S: object }}
 */
export function getTreemapTypographyRich() {
    return {
        L: {
            fontFamily: CHART_FONT_FAMILY,
            fontSize: 18,
            lineHeight: 24,
            fontWeight: 700,
        },
        M: {
            fontFamily: CHART_FONT_FAMILY,
            fontSize: 14,
            lineHeight: 19,
            fontWeight: 600,
        },
        S: {
            fontFamily: CHART_FONT_FAMILY,
            fontSize: 13,
            lineHeight: 16,
            fontWeight: 700,
        },
    };
}

export function clampMcp(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.max(0, Math.min(100, n));
}

/**
 * MCP 0–100 → symbol diameter (px), absolute (not relative to filtered set).
 * Mild sqrt curve so low-MCP Topics (typical 0–30%) still separate visually.
 *
 * size = MIN + (MAX - MIN) * sqrt(mcp / 100)
 * @deprecated Network uses networkTopicSymbolSize (relative + exaggerated).
 */
export function mcpToSymbolSize(mcp) {
    const t = Math.sqrt(clampMcp(mcp) / 100);
    return Math.round(MCP_SYMBOL_MIN + t * (MCP_SYMBOL_MAX - MCP_SYMBOL_MIN));
}

/**
 * Network Topic diameter from MCP share relative to the max visible Topic MCP.
 * zero-MCP → MIN (still visible). Displayed MCP stays real.
 *
 * symbolSize = MIN + (MAX - MIN) * pow(clamp(mcp / maxMcp, 0, 1), EXPONENT)
 *
 * @param {unknown} mcp
 * @param {unknown} maxMcp
 * @returns {number}
 */
export function networkTopicSymbolSize(mcp, maxMcp) {
    const real = clampMcp(mcp);
    const peak = clampMcp(maxMcp);
    if (peak <= 0) {
        return NETWORK_TOPIC_SYMBOL_MIN;
    }
    const normalized = Math.max(0, Math.min(1, real / peak));
    const t = normalized ** NETWORK_MCP_SIZE_EXPONENT;
    return Math.round(
        NETWORK_TOPIC_SYMBOL_MIN
        + (NETWORK_TOPIC_SYMBOL_MAX - NETWORK_TOPIC_SYMBOL_MIN) * t,
    );
}

/**
 * Normalize Topic symbol diameter into 0..1 across NETWORK_TOPIC_SYMBOL_MIN/MAX.
 *
 * @param {unknown} topicSymbolSize
 * @returns {number}
 */
export function normalizeTopicSymbolScale(topicSymbolSize) {
    const size = Number(topicSymbolSize);
    const span = NETWORK_TOPIC_SYMBOL_MAX - NETWORK_TOPIC_SYMBOL_MIN;
    if (!Number.isFinite(size) || span <= 0) {
        return 0;
    }
    return Math.max(0, Math.min(1, (size - NETWORK_TOPIC_SYMBOL_MIN) / span));
}

/**
 * DNA satellite diameter — linked to parent Topic symbol size.
 *
 * Overview: readable but subordinate.
 * Focus (`opts.focused`): one step larger for hover/click — same scale family.
 *
 * @param {unknown} topicSymbolSize
 * @param {{ focused?: boolean }} [opts]
 * @returns {number}
 */
export function networkDnaSymbolSize(topicSymbolSize, opts = {}) {
    const focused = Boolean(opts?.focused);
    const min = focused ? NETWORK_DNA_SYMBOL_FOCUS_MIN : NETWORK_DNA_SYMBOL_MIN;
    const max = focused ? NETWORK_DNA_SYMBOL_FOCUS_MAX : NETWORK_DNA_SYMBOL_MAX;
    const parent = Number(topicSymbolSize);
    if (!Number.isFinite(parent) || parent <= 0) {
        return min;
    }
    const scale = normalizeTopicSymbolScale(parent);
    const byBand = min + scale * (max - min);
    const byRatio = parent * NETWORK_DNA_PARENT_SIZE_RATIO * (focused ? 1.15 : 1);
    const raw = Math.max(byBand, byRatio);
    const size = Math.round(Math.max(min, Math.min(max, raw)));
    // Hard ceiling: DNA must stay visually smaller than Topic.
    return Math.min(size, Math.max(min, Math.floor(parent * 0.5)));
}

/** Stable color from topic id (reload-safe). */
export function topicColorById(topicId) {
    const id = Math.abs(Number(topicId) || 0);
    return TOPIC_PALETTE[id % TOPIC_PALETTE.length];
}

/** Lighter tint of a hex color (keyword leaves). */
export function tintHex(hex, amount = 0.45) {
    const raw = String(hex || '#64748b').replace('#', '');
    if (raw.length !== 6) {
        return hex;
    }
    const mix = (channel) => {
        const c = parseInt(raw.slice(channel, channel + 2), 16);
        const next = Math.round(c + (255 - c) * amount);
        return next.toString(16).padStart(2, '0');
    };
    return `#${mix(0)}${mix(2)}${mix(4)}`;
}

/**
 * Compact Structure Topic leaf label (rotated BT leaves).
 * Prefer single-line "Name · 23%" — DNA/articles stay in tooltip.
 *
 * @param {object} topic
 * @param {(mcp: unknown) => string} [formatMcp]
 */
export function topicStructureLabel(topic, formatMcp) {
    const raw = String(topic?.name || '').trim() || 'Topic';
    const name = raw.length > 36 ? `${raw.slice(0, 35)}…` : raw;
    const pct = typeof formatMcp === 'function'
        ? formatMcp(topic?.mcp)
        : `${clampMcp(topic?.mcp).toFixed(0)}%`;
    return `${name} · ${pct}`;
}

/** @deprecated use topicStructureLabel — Structure no longer shows DNA on-node */
export function topicTreeLabel(topic) {
    return topicStructureLabel(topic);
}

/** Fixed Structure node diameter — never scales by MCP / counts. */
export const STRUCTURE_SYMBOL_SIZE = 8;

/** @deprecated Structure no longer MCP-scales symbol size */
export const STRUCTURE_TOPIC_SYMBOL_MIN = STRUCTURE_SYMBOL_SIZE;
/** @deprecated Structure no longer MCP-scales symbol size */
export const STRUCTURE_TOPIC_SYMBOL_MAX = STRUCTURE_SYMBOL_SIZE;

/** @deprecated use STRUCTURE_SYMBOL_SIZE — MCP must not affect Structure node size */
export function structureTopicSymbolSize(_mcp) {
    return STRUCTURE_SYMBOL_SIZE;
}

/** Deterministic Tag color (identification only — not metric meaning). */
export function tagColorById(tagId) {
    return topicColorById(tagId);
}

/** Unicode-safe word count: trim + collapse whitespace + split. */
export function phraseWordCount(phrase) {
    const normalized = String(phrase ?? '').trim().replace(/\s+/gu, ' ');
    if (normalized === '') {
        return 0;
    }
    return normalized.split(' ').length;
}

export function normalizePhraseKey(phrase) {
    return String(phrase ?? '').trim().replace(/\s+/gu, ' ').toLocaleLowerCase();
}

/**
 * Topical Map Tree children only: fewest words first, then phrase, then id.
 *
 * @param {Array<{id?: number, name?: string}>} children
 * @returns {typeof children}
 */
export function sortKeywordsByWordCount(children) {
    const list = Array.isArray(children) ? children : [];
    return [...list].sort((a, b) => {
        const wa = phraseWordCount(a?.name);
        const wb = phraseWordCount(b?.name);
        if (wa !== wb) {
            return wa - wb;
        }
        const na = normalizePhraseKey(a?.name);
        const nb = normalizePhraseKey(b?.name);
        if (na !== nb) {
            return na.localeCompare(nb, undefined, { sensitivity: 'base' });
        }
        return Number(a?.id ?? 0) - Number(b?.id ?? 0);
    });
}
