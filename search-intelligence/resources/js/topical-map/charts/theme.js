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

/** Fixed Network DNA satellite size — never scales with MCP / counts. */
export const NETWORK_DNA_SYMBOL_SIZE = 6;

/** Fixed Network Site node — structural, not MCP-scaled. */
export const NETWORK_SITE_SYMBOL_SIZE = 24;

export const MCP_SYMBOL_MIN = 12;
export const MCP_SYMBOL_MAX = 30;

/** Network Topic size — deliberately exaggerated MCP share differences. */
export const NETWORK_TOPIC_SYMBOL_MIN = 10;
export const NETWORK_TOPIC_SYMBOL_MAX = 78;
export const NETWORK_MCP_SIZE_EXPONENT = 1.35;

/** Defensive full-graph DNA cap (never silent). */
export const NETWORK_MAX_DNA_NODES = 1500;

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
