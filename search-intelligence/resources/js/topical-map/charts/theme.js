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

export const MCP_SYMBOL_MIN = 12;
export const MCP_SYMBOL_MAX = 30;

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
 */
export function mcpToSymbolSize(mcp) {
    const t = Math.sqrt(clampMcp(mcp) / 100);
    return Math.round(MCP_SYMBOL_MIN + t * (MCP_SYMBOL_MAX - MCP_SYMBOL_MIN));
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
 * Primary tree label — Topic name + MCP (+ DNA). No article count.
 */
export function topicTreeLabel(topic) {
    const name = String(topic?.name || '').trim() || 'Topic';
    const mcp = clampMcp(topic?.mcp);
    const dna = Number(topic?.dna_count ?? 0);
    if (Number.isFinite(dna) && dna > 0) {
        return `${name}\n${mcp.toFixed(0)}% · DNA ${dna}`;
    }
    return `${name}\n${mcp.toFixed(0)}%`;
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

/**
 * Inflate Tree series bottom so sibling Topics get readable vertical gaps.
 * Content may extend past viewport; roam/pan + Fit handle navigation.
 *
 * @param {number} topicCount
 * @returns {string} CSS-like percent for series.bottom (may be negative)
 */
export function treeSeriesBottomExtent(topicCount) {
    const n = Math.max(0, Number(topicCount) || 0);
    // ~10 Topics fit comfortably in default viewport height with 2-line labels.
    const comfortable = 10;
    const extra = Math.max(0, n - comfortable);
    // Each extra Topic adds layout height without packing siblings.
    const bottomPct = -(extra * 8);
    return `${Math.max(-400, bottomPct)}%`;
}
