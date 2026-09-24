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

export const MCP_SYMBOL_MIN = 12;
export const MCP_SYMBOL_MAX = 34;

export function clampMcp(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.max(0, Math.min(100, n));
}

/** Normalize MCP 0–100 → symbol diameter (px). */
export function mcpToSymbolSize(mcp) {
    const t = clampMcp(mcp) / 100;
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
