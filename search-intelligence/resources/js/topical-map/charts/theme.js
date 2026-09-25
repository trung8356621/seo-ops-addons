/**
 * Deterministic topic palette + MCP → symbol size helpers for Tree view.
 */

/** Distinct hues readable on light backgrounds. */
export const LIGHT_NODE_PALETTE = [
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

/** Same deterministic hue families, lifted for soft-dark surfaces. */
export const DARK_NODE_PALETTE = [
    '#60a5fa', // blue
    '#f472b6', // pink
    '#34d399', // emerald
    '#fbbf24', // amber
    '#a78bfa', // violet
    '#22d3ee', // cyan
    '#f87171', // red
    '#818cf8', // indigo
    '#facc15', // yellow
    '#2dd4bf', // teal
    '#e879f9', // fuchsia
    '#fb923c', // orange
    '#38bdf8', // sky
    '#a3e635', // lime
    '#c084fc', // purple
    '#fb7185', // rose
];

/** Backward-compatible light palette export. */
export const TOPIC_PALETTE = LIGHT_NODE_PALETTE;

export function normalizeChartTheme(theme) {
    return theme === 'dark' ? 'dark' : 'light';
}

/** Central presentation tokens for ECharts canvas options. */
export function getChartTheme(theme = 'light') {
    if (normalizeChartTheme(theme) === 'dark') {
        return {
            name: 'dark',
            isDark: true,
            background: '#111827',
            text: '#e5e7eb',
            textStrong: '#f1f5f9',
            textSoft: '#cbd5e1',
            textMuted: '#94a3b8',
            labelMask: 'rgba(17,24,39,0.9)',
            tooltipBackground: 'rgba(23,32,51,0.98)',
            tooltipBorder: 'rgba(148,163,184,0.22)',
            tooltipShadow: 'rgba(2,6,23,0.42)',
            nodeBorder: '#1e293b',
            siteColor: '#64748b',
            siteBorder: '#94a3b8',
            bucketColor: '#64748b',
            bucketBorder: '#94a3b8',
            edge: 'rgba(148,163,184,0.22)',
            edgeMuted: 'rgba(148,163,184,0.18)',
            treemapBorder: '#1e293b',
            treemapEmphasisBorder: '#e5e7eb',
        };
    }

    return {
        name: 'light',
        isDark: false,
        background: '#ffffff',
        text: '#0f172a',
        textStrong: '#1e293b',
        textSoft: '#334155',
        textMuted: '#64748b',
        labelMask: 'rgba(255,255,255,0.88)',
        tooltipBackground: 'rgba(255,255,255,0.96)',
        tooltipBorder: 'rgba(15,23,42,0.1)',
        tooltipShadow: 'rgba(15,23,42,0.16)',
        nodeBorder: '#ffffff',
        siteColor: '#94a3b8',
        siteBorder: '#64748b',
        bucketColor: '#94a3b8',
        bucketBorder: '#e2e8f0',
        edge: 'rgba(148,163,184,0.55)',
        edgeMuted: 'rgba(148,163,184,0.45)',
        treemapBorder: '#ffffff',
        treemapEmphasisBorder: '#0f172a',
    };
}

/** Fixed keyword leaf size — never scales with MCP. */
export const KEYWORD_SYMBOL_SIZE = 8;

/**
 * Fixed Network DNA size (fallback and canonical value).
 * Prefer {@see networkDnaSymbolSize} at call sites.
 */
export const NETWORK_DNA_SYMBOL_SIZE = 10;

/** One fixed DNA diameter in overview and focus. */
export const NETWORK_DNA_SYMBOL_MIN = NETWORK_DNA_SYMBOL_SIZE;
export const NETWORK_DNA_SYMBOL_MAX = NETWORK_DNA_SYMBOL_SIZE;
/** Compatibility aliases: focus deliberately keeps the same DNA size. */
export const NETWORK_DNA_SYMBOL_FOCUS_MIN = NETWORK_DNA_SYMBOL_SIZE;
export const NETWORK_DNA_SYMBOL_FOCUS_MAX = NETWORK_DNA_SYMBOL_SIZE;
/** Retired parent scaling; kept as an explicit zero-value compatibility token. */
export const NETWORK_DNA_PARENT_SIZE_RATIO = 0;

/** Fixed Network Site node — structural, not MCP-scaled. */
export const NETWORK_SITE_SYMBOL_SIZE = 24;

export const MCP_SYMBOL_MIN = 12;
export const MCP_SYMBOL_MAX = 30;

/** One fixed Topic diameter; MCP remains label/data only. */
export const NETWORK_TOPIC_SYMBOL_SIZE = 14;
export const NETWORK_TOPIC_SYMBOL_MIN = NETWORK_TOPIC_SYMBOL_SIZE;
export const NETWORK_TOPIC_SYMBOL_MAX = NETWORK_TOPIC_SYMBOL_SIZE;
export const NETWORK_MCP_SIZE_EXPONENT = 0;

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
            return { topic: 10, site: 10, dna: 11, distance: 5 };
        case 'medium':
            return { topic: 13, site: 13, dna: 13, distance: 6 };
        case 'large':
            return { topic: 16, site: 15, dna: 14, distance: 7 };
        case 'normal':
        default:
            return { topic: 11, site: 12, dna: 12, distance: 5 };
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
 * Fixed Network Topic diameter. MCP remains visible in the label and tooltip,
 * but never changes geometry.
 *
 * @param {unknown} mcp
 * @param {unknown} maxMcp
 * @returns {number}
 */
export function networkTopicSymbolSize(mcp, maxMcp) {
    void mcp;
    void maxMcp;
    return NETWORK_TOPIC_SYMBOL_SIZE;
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
 * Fixed DNA satellite diameter in overview and focus.
 *
 * @param {unknown} topicSymbolSize
 * @param {{ focused?: boolean }} [opts]
 * @returns {number}
 */
export function networkDnaSymbolSize(topicSymbolSize, opts = {}) {
    void topicSymbolSize;
    void opts;
    return NETWORK_DNA_SYMBOL_SIZE;
}

/** Stable color from topic id (reload-safe). */
export function topicColorById(topicId, theme = 'light') {
    const id = Math.abs(Number(topicId) || 0);
    const palette = normalizeChartTheme(theme) === 'dark'
        ? DARK_NODE_PALETTE
        : LIGHT_NODE_PALETTE;
    return palette[id % palette.length];
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
export function tagColorById(tagId, theme = 'light') {
    return topicColorById(tagId, theme);
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
