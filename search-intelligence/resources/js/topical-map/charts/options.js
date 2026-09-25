import {
    topicColorById,
    tagColorById,
    topicStructureLabel,
    topicTreeLabel,
    clampMcp,
    STRUCTURE_SYMBOL_SIZE,
    tintHex,
    networkTopicSymbolSize,
    networkDnaSymbolSize,
    NETWORK_DNA_SYMBOL_SIZE,
    NETWORK_SITE_SYMBOL_SIZE,
    NETWORK_MAX_DNA_NODES,
    CHART_FONT_FAMILY,
    CHART_FONT_TOOLTIP,
    CHART_FONT_NORMAL,
    getChartTypographyBand,
    getStructureTypography,
    getNetworkTypography,
    getTreemapTypographyRich,
    getStructureTypographyBand,
} from './theme.js';
import {
    compareTopicsForNetworkLayout,
    assignNetworkFixedCoordinates,
    assignFocusedNetworkCoordinates,
} from './networkLayout.js';

/**
 * Escape text for ECharts rich-label fragments (`{style|text}`).
 *
 * @param {unknown} value
 * @returns {string}
 */
function escapeRichText(value) {
    return String(value ?? '').replace(/[{}|]/g, '');
}

/**
 * Partial series patch for Structure zoom-band typography (no data rebuild).
 * Authoritative font sizes live here — Structure nodes must NOT set label.fontSize.
 *
 * @param {'compact'|'normal'|'medium'|'large'|'xlarge'} [band]
 * @returns {object}
 */
export function buildStructureTypographyPatch(band = 'compact') {
    const typo = getStructureTypography(band);
    return {
        // Non-leaves: Site / Tag / untagged bucket
        label: {
            show: true,
            position: 'bottom',
            rotate: 90,
            verticalAlign: 'middle',
            align: 'right',
            fontFamily: CHART_FONT_FAMILY,
            fontSize: typo.tag.fontSize,
            fontWeight: typo.tag.fontWeight,
            color: '#475569',
            distance: typo.tag.distance,
            overflow: 'truncate',
            width: typo.tag.width,
            ellipsis: '…',
            formatter(params) {
                const d = params?.data || {};
                const name = escapeRichText(params?.name || '');
                if (d.nodeType === 'site') {
                    return `{site|${name}}`;
                }
                if (d.nodeType === 'untagged_bucket') {
                    return `{bucket|${name}}`;
                }
                return `{tag|${name}}`;
            },
            rich: {
                site: {
                    fontFamily: CHART_FONT_FAMILY,
                    fontSize: typo.site.fontSize,
                    fontWeight: typo.site.fontWeight,
                    color: '#64748b',
                    lineHeight: typo.site.fontSize + 2,
                },
                tag: {
                    fontFamily: CHART_FONT_FAMILY,
                    fontSize: typo.tag.fontSize,
                    fontWeight: typo.tag.fontWeight,
                    color: '#475569',
                    lineHeight: typo.tag.fontSize + 2,
                },
                bucket: {
                    fontFamily: CHART_FONT_FAMILY,
                    fontSize: typo.tag.fontSize,
                    fontWeight: typo.tag.fontWeight,
                    color: '#64748b',
                    lineHeight: typo.tag.fontSize + 2,
                },
            },
        },
        // Topic leaves — plain series leaves.label (no per-node fontSize).
        leaves: {
            label: {
                show: true,
                position: 'top',
                rotate: 90,
                verticalAlign: 'middle',
                align: 'left',
                fontFamily: CHART_FONT_FAMILY,
                fontSize: typo.topic.fontSize,
                fontWeight: typo.topic.fontWeight,
                color: '#334155',
                distance: typo.topic.distance,
                overflow: 'truncate',
                width: typo.topic.width,
                ellipsis: '…',
                formatter: '{b}',
            },
        },
    };
}

/**
 * Network series label rich styles for a typography band.
 *
 * @param {'compact'|'normal'|'medium'|'large'} [band]
 * @returns {{ rich: object, distance: number }}
 */
export function buildNetworkLabelStyles(band = 'normal') {
    const typo = getNetworkTypography(band);
    return {
        distance: typo.distance,
        rich: {
            site: {
                fontFamily: CHART_FONT_FAMILY,
                fontSize: typo.site,
                fontWeight: 600,
                color: '#64748b',
                lineHeight: typo.site + 2,
            },
            topic: {
                fontFamily: CHART_FONT_FAMILY,
                fontSize: typo.topic,
                fontWeight: 700,
                color: '#1e293b',
                lineHeight: typo.topic + 4,
            },
            mcp: {
                fontFamily: CHART_FONT_FAMILY,
                fontSize: Math.max(9, typo.topic - 1),
                fontWeight: 600,
                color: '#475569',
                lineHeight: typo.topic + 2,
            },
            dna: {
                fontFamily: CHART_FONT_FAMILY,
                fontSize: typo.dna,
                fontWeight: 500,
                color: '#334155',
                lineHeight: typo.dna + 4,
                backgroundColor: 'rgba(255,255,255,0.88)',
                borderRadius: 2,
                padding: [1, 3],
            },
        },
    };
}

/**
 * Network DNA phrase label — always full text.
 *
 * @param {unknown} phrase
 * @param {{ focused?: boolean }} [opts]
 * @returns {string}
 */
export function dnaNetworkLabel(phrase, opts = {}) {
    void opts;
    return String(phrase ?? '').trim() || 'DNA';
}

/**
 * Whether DNA node labels should render for this band / focus state.
 * Always enable DNA labels — ECharts hideOverlap cleans collisions in overview.
 *
 * @param {'compact'|'normal'|'medium'|'large'} band
 * @param {boolean} focused
 * @returns {boolean}
 */
export function shouldShowDnaNetworkLabel(band, focused = false) {
    void band;
    void focused;
    return true;
}

/**
 * Partial series patch for Network zoom-band typography (no node/link rebuild).
 *
 * @param {'compact'|'normal'|'medium'|'large'} [band]
 * @param {{ focused?: boolean }} [opts]
 * @returns {object}
 */
export function buildNetworkTypographyPatch(band = 'normal', opts = {}) {
    const styles = buildNetworkLabelStyles(band);
    const focused = Boolean(opts?.focused);
    const dnaLabels = shouldShowDnaNetworkLabel(band, focused);
    return {
        label: {
            show: true,
            // Default outside-below; per-node labelPosition overrides (outside only).
            position: 'bottom',
            distance: Math.max(styles.distance, 6),
            fontFamily: CHART_FONT_FAMILY,
            // Labels must not steal clicks from nodes.
            silent: true,
            formatter(params) {
                const d = params?.data || {};
                const raw = String(params?.name || '');
                const lines = raw.split('\n').map((line) => escapeRichText(line));
                if (d.nodeType === 'site') {
                    return lines.map((line) => `{site|${line}}`).join('\n');
                }
                if (d.nodeType === 'dna') {
                    if (!dnaLabels && !d._forceDnaLabel) {
                        return '';
                    }
                    return `{dna|${lines[0] || ''}}`;
                }
                // Topic: line1 name, line2 MCP% — outside symbol.
                if (lines.length >= 2) {
                    return `{topic|${lines[0]}}\n{mcp|${lines.slice(1).join(' ')}}`;
                }
                return `{topic|${lines[0] || ''}}`;
            },
            rich: styles.rich,
        },
        // Fixed placement reserves every full label; never suppress one afterward.
        labelLayout: {
            hideOverlap: false,
        },
        // focus:'none' — no adjacency blur / full-graph dim on hover.
        emphasis: {
            focus: 'none',
            scale: false,
            lineStyle: { width: 2, opacity: 0.85 },
            label: {
                show: true,
                fontFamily: CHART_FONT_FAMILY,
                silent: true,
                rich: styles.rich,
            },
        },
    };
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

/**
 * Network Topic label — name + MCP % only (Focus/DNA/Keywords/Tags → tooltip).
 *
 * @param {object} topic
 * @returns {string}
 */
export function topicNetworkLabel(topic) {
    const raw = String(topic?.name || '').trim() || 'Topic';
    return `${raw}\n${formatTreemapMcpPercent(topic?.mcp)}`;
}

function formatTagNames(tags) {
    const list = Array.isArray(tags) ? tags : [];
    const names = list
        .map((t) => String(t?.name || '').trim())
        .filter(Boolean);
    return names.length ? names.join(', ') : '—';
}

/** Network Topic tooltip — canonical fields only. */
function networkTopicTooltipHtml(d) {
    const title = String(d.topicName || d.name || '').split('\n')[0] || '';
    const keywords = d.keyword_count != null ? Number(d.keyword_count) : null;
    const lines = [
        `<strong>${escapeHtml(title)}</strong>`,
        `MCP: ${formatTreemapMcpPercent(d.mcp)}`,
        `DNA: ${d.dna_count ?? 0}`,
        `Focus Articles: ${Number(d.article_count ?? 0)}`,
    ];
    if (keywords != null && Number.isFinite(keywords)) {
        lines.push(`Keywords: ${keywords}`);
    }
    lines.push(`Tags: ${escapeHtml(formatTagNames(d.tags))}`);
    lines.push('<em>Single click: Focus · Double click: Open Topic</em>');
    return lines.join('<br/>');
}

function networkDnaTooltipHtml(d) {
    const phrase = String(d.name || '').trim() || 'DNA';
    const parent = String(d.topicName || '').trim() || 'Topic';
    return `<strong>${escapeHtml(phrase)}</strong><br/>Parent Topic: ${escapeHtml(parent)}`;
}

function networkSiteTooltipHtml(d, focused = false) {
    const name = escapeHtml(d.name || 'Site');
    if (focused) {
        return `<strong>${name}</strong><br/><em>Back to full Network</em>`;
    }
    return `<strong>${name}</strong>`;
}

/** Treemap / shared Topic tooltip (double-click open). */
function topicTooltipHtml(d) {
    const title = String(d.name || '').split('\n')[0] || '';
    const keywords = d.keyword_count != null ? Number(d.keyword_count) : null;
    const lines = [
        `<strong>${escapeHtml(title)}</strong>`,
        `MCP: ${formatTreemapMcpPercent(d.mcp)}`,
        `Focus Articles: ${Number(d.article_count ?? 0)}`,
        `DNA: ${d.dna_count ?? 0}`,
    ];
    if (keywords != null && Number.isFinite(keywords)) {
        lines.push(`Keywords: ${keywords}`);
    }
    lines.push('<em>Double-click to open Topic</em>');
    return lines.join('<br/>');
}

/** Structure view Topic tooltip — includes all Tags; single-click open. */
function structureTopicTooltipHtml(d) {
    const title = String(d.topicName || d.name || '').split('\n')[0] || '';
    const keywords = d.keyword_count != null ? Number(d.keyword_count) : null;
    const lines = [
        `<strong>${escapeHtml(title)}</strong>`,
        `MCP: ${formatTreemapMcpPercent(d.mcp)}`,
        `Focus Articles: ${Number(d.article_count ?? 0)}`,
        `DNA: ${d.dna_count ?? 0}`,
    ];
    if (keywords != null && Number.isFinite(keywords)) {
        lines.push(`Keywords: ${keywords}`);
    }
    lines.push(`Tags: ${escapeHtml(formatTagNames(d.tags))}`);
    lines.push('<em>Click to open Topic</em>');
    return lines.join('<br/>');
}

function structureTagTooltipHtml(d) {
    const name = String(d.tagName || d.name || '').split('\n')[0] || 'Tag';
    const count = Number(d.topic_count ?? d.children?.length ?? 0);
    return `<strong>${escapeHtml(name)}</strong><br/>Topics: ${Number.isFinite(count) ? count : 0}`;
}

/** Presentation-only untagged bucket — never a persisted Tag. */
function structureUntaggedBucketTooltipHtml(d) {
    const hint = String(d.bucketTooltip || '').trim();
    if (hint) {
        return escapeHtml(hint);
    }
    return 'Các Topic chưa được gắn tag';
}

/**
 * Canonical Topic tags — preserve persisted array order.
 *
 * @param {object} topic
 * @returns {Array<{id: number, name: string}>}
 */
export function normalizeTopicTags(topic) {
    const tags = Array.isArray(topic?.tags) ? topic.tags : [];
    return tags
        .map((tag) => ({
            id: Number(tag?.id),
            name: String(tag?.name || '').trim(),
        }))
        .filter((tag) => Number.isFinite(tag.id) && tag.id > 0 && tag.name !== '');
}

/**
 * Deterministic primary Tag for Structure layout (one parent — no Topic clones).
 * Prefer first persisted tag in preferredIds (active Tags filter); else first
 * persisted; else smallest id. Never randomize / score "importance".
 *
 * @param {object} topic
 * @param {number[]} [preferredTagIds]
 * @returns {{id: number, name: string}|null}
 */
export function pickPrimaryTag(topic, preferredTagIds = []) {
    const tags = normalizeTopicTags(topic);
    if (tags.length === 0) {
        return null;
    }
    const preferred = new Set(
        (Array.isArray(preferredTagIds) ? preferredTagIds : [])
            .map(Number)
            .filter((id) => Number.isFinite(id) && id > 0),
    );
    if (preferred.size > 0) {
        const hit = tags.find((tag) => preferred.has(tag.id));
        if (hit) {
            return hit;
        }
    }
    return tags[0];
}

function buildStructureTopicNode(topic) {
    const topicId = Number(topic.id);
    const color = topicColorById(topicId);
    const tags = normalizeTopicTags(topic);
    return {
        name: topicStructureLabel(topic, formatTreemapMcpPercent),
        topicName: String(topic.name || '').trim() || 'Topic',
        topicId,
        nodeType: 'topic',
        value: 1,
        mcp: topic.mcp,
        dna_count: topic.dna_count,
        article_count: topic.article_count,
        keyword_count: topic.keyword_count,
        coverage: topic.coverage,
        tags,
        symbolSize: STRUCTURE_SYMBOL_SIZE,
        itemStyle: {
            color,
            borderColor: '#fff',
            borderWidth: 1,
        },
        lineStyle: {
            color: 'rgba(148, 163, 184, 0.55)',
            width: 1,
            curveness: 0.5,
        },
        // Label styles are series/leaves-only (no per-node text overrides).
    };
}

function buildStructureTagNode(tag, topicNodes) {
    const count = topicNodes.length;
    const label = count > 0 ? `${tag.name} · ${count}` : tag.name;
    const color = tagColorById(tag.id);
    return {
        name: label,
        tagName: tag.name,
        tagId: tag.id,
        nodeType: 'tag',
        topic_count: count,
        value: 1,
        symbolSize: STRUCTURE_SYMBOL_SIZE,
        itemStyle: {
            color,
            borderColor: '#fff',
            borderWidth: 1,
        },
        lineStyle: {
            color: 'rgba(148, 163, 184, 0.55)',
            width: 1,
            curveness: 0.5,
        },
        children: topicNodes,
    };
}

/**
 * Presentation-only Structure bucket for Topics with zero persisted Tags.
 * NOT a real Tag — never persisted, never in filters/sync/AI taxonomy.
 * Always placed at the far right of the Tag layer.
 */
export const STRUCTURE_UNTAGGED_BUCKET_KEY = 'structure:untagged';

/** Muted neutral — reads as temporary bucket, not a business Tag. */
const STRUCTURE_UNTAGGED_BUCKET_COLOR = '#94a3b8';

function buildStructureUntaggedBucketNode(topicNodes, opts = {}) {
    const count = topicNodes.length;
    const baseLabel = String(opts.untaggedBucketLabel || 'Chưa gắn tag').trim() || 'Chưa gắn tag';
    const label = count > 0 ? `${baseLabel} · ${count}` : baseLabel;
    const bucketTooltip = String(
        opts.untaggedBucketTooltip || 'Các Topic chưa được gắn tag',
    ).trim();

    return {
        name: label,
        tagName: baseLabel,
        bucketKey: STRUCTURE_UNTAGGED_BUCKET_KEY,
        nodeType: 'untagged_bucket',
        topic_count: count,
        bucketTooltip,
        value: 1,
        symbolSize: STRUCTURE_SYMBOL_SIZE,
        itemStyle: {
            color: STRUCTURE_UNTAGGED_BUCKET_COLOR,
            borderColor: '#e2e8f0',
            borderWidth: 1,
        },
        lineStyle: {
            color: 'rgba(148, 163, 184, 0.45)',
            width: 1,
            curveness: 0.5,
        },
        children: topicNodes,
    };
}


/**
 * Layout-only floor so 0%/tiny-MCP Topics stay visible in Treemap.
 * Labels / tooltip MUST use real MCP — never this weight.
 */
export const TREEMAP_MIN_VISUAL_MCP_WEIGHT = 0.05;

/** @deprecated use TREEMAP_MIN_VISUAL_MCP_WEIGHT */
export const TREEMAP_MIN_DISPLAY_WEIGHT = TREEMAP_MIN_VISUAL_MCP_WEIGHT;

/**
 * Compact MCP % for Treemap tiles (and shared tooltip).
 * >=10 → integer; >=1 → 1 decimal (strip .0); <1 → keep precision (never fake 0%).
 *
 * @param {unknown} mcp
 * @returns {string} e.g. "23%", "5.9%", "0.4%", "0%"
 */
export function formatTreemapMcpPercent(mcp) {
    const n = clampMcp(mcp);
    if (n <= 0) {
        return '0%';
    }
    if (n >= 10) {
        return `${Math.round(n)}%`;
    }
    if (n >= 1) {
        const one = n.toFixed(1);
        return `${one.endsWith('.0') ? one.slice(0, -2) : one}%`;
    }
    // Keep enough digits so tiny shares do not round to 0%.
    if (n < 0.1) {
        const two = n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
        return `${two}%`;
    }
    return `${n.toFixed(1)}%`;
}

/**
 * Tile AREA = canonical Topic MCP % (share).
 * layoutValue may use MIN_VISUAL floor; displayed MCP stays real.
 *
 * @param {unknown} mcp
 * @returns {number}
 */
export function treemapLayoutValue(mcp) {
    const real = clampMcp(mcp);
    return Math.max(TREEMAP_MIN_VISUAL_MCP_WEIGHT, real);
}

/**
 * Relative-area label tiers (fallback when ECharts omits tile geometry).
 * Presentation only — not business filtering.
 *
 * @param {object[]} topics
 * @returns {Map<number, 'large'|'medium'|'small'|'micro'>}
 */
export function assignTreemapLabelTiers(topics) {
    const list = Array.isArray(topics) ? topics : [];
    const rows = list.map((topic) => ({
        id: Number(topic.id),
        value: treemapLayoutValue(topic.mcp),
    })).filter((row) => Number.isFinite(row.id) && row.id > 0);

    const tiers = new Map();
    if (rows.length === 0) {
        return tiers;
    }

    const sorted = [...rows].sort((a, b) => b.value - a.value);
    const maxV = sorted[0].value || 1;
    const total = sorted.reduce((sum, row) => sum + row.value, 0) || 1;

    for (const row of sorted) {
        const rel = row.value / maxV;
        const share = row.value / total;
        if (share >= 0.04 || rel >= 0.28) {
            tiers.set(row.id, 'large');
        } else if (share >= 0.012 || rel >= 0.1) {
            tiers.set(row.id, 'medium');
        } else if (share >= 0.004 || rel >= 0.04) {
            tiers.set(row.id, 'small');
        } else {
            tiers.set(row.id, 'micro');
        }
    }

    const ensureNamed = Math.min(6, sorted.length);
    for (let i = 0; i < ensureNamed; i += 1) {
        const id = sorted[i].id;
        if (tiers.get(id) === 'micro' || tiers.get(id) === 'small') {
            tiers.set(id, 'medium');
        }
        if (i < Math.min(3, sorted.length)) {
            tiers.set(id, 'large');
        }
    }

    return tiers;
}

/** Ink color with readable contrast on Topic tile fills. */
export function treemapLabelInk(hex, opacity = 1) {
    const raw = String(hex || '#64748b').replace('#', '');
    if (raw.length !== 6) {
        return '#0f172a';
    }
    const r = parseInt(raw.slice(0, 2), 16);
    const g = parseInt(raw.slice(2, 4), 16);
    const b = parseInt(raw.slice(4, 6), 16);
    const a = Math.max(0, Math.min(1, Number(opacity) || 1));
    const mix = (c) => Math.round(c * a + 255 * (1 - a));
    const R = mix(r);
    const G = mix(g);
    const B = mix(b);
    const lum = (0.2126 * R + 0.7152 * G + 0.0722 * B) / 255;
    return lum > 0.55 ? '#0f172a' : '#ffffff';
}

/**
 * Treemap label density — drop name before dropping %.
 * Geometry wins; area-tier is fallback only.
 * Never blank for low MCP/rank — only physical tile size.
 *
 * @param {object} d topic node data
 * @param {object} [params] ECharts formatter params
 * @returns {{ text: string, style: 'L'|'M'|'S', tier: string }}
 */
export function resolveTreemapTopicLabel(d, params) {
    const name = String(d?.name || '').trim() || 'Topic';
    const pct = formatTreemapMcpPercent(d?.mcp);
    let tier = d?.labelTier || 'medium';

    const rect = params?.rect || params?.labelRect || null;
    const w = Number(rect?.width ?? 0);
    const h = Number(rect?.height ?? 0);
    if (w > 0 && h > 0) {
        if (w < 26 || h < 14) {
            tier = 'micro';
        } else if (w < 48 || h < 26) {
            tier = 'small';
        } else if (w >= 88 && h >= 44) {
            tier = 'large';
        } else {
            tier = 'medium';
        }
    }

    if (tier === 'micro') {
        return { text: '', style: 'S', tier };
    }
    if (tier === 'small') {
        // Percent-only — middle readable size (not microscopic).
        return { text: pct, style: 'S', tier };
    }
    if (tier === 'large') {
        return { text: `${name}\n${pct}`, style: 'L', tier };
    }
    return { text: `${name}\n${pct}`, style: 'M', tier };
}

/**
 * @param {object} d
 * @param {object} [params]
 * @returns {string}
 */
export function formatTreemapTopicLabel(d, params) {
    return resolveTreemapTopicLabel(d, params).text;
}

/**
 * Rich-text label for ECharts (per-tile font size).
 *
 * @param {object} d
 * @param {object} [params]
 * @returns {string}
 */
export function formatTreemapTopicLabelRich(d, params) {
    const { text, style } = resolveTreemapTopicLabel(d, params);
    if (!text) {
        return '';
    }
    return text
        .split('\n')
        .map((line) => `{${style}|${line}}`)
        .join('\n');
}

/** @deprecated use topicTreeLabel — kept for greps/tests that import topicLabel */
export function topicLabel(topic) {
    return topicTreeLabel(topic);
}

/**
 * Full-site Network graph from filtered Topic rows (no drill / lazy Keywords).
 * Site → Topic → canonical DNA phrases.
 *
 * @param {number} siteId
 * @param {object[]} topics
 * @param {{ siteDomain?: string, maxDnaNodes?: number }} [opts]
 */
export function buildOverviewNeighborhood(siteId, topics, opts = {}) {
    const sid = Number(siteId) || 0;
    const list = Array.isArray(topics) ? topics : [];
    const siteDomain = String(opts.siteDomain || '').trim() || 'Site';
    const maxDnaNodes = Math.max(
        1,
        Number(opts.maxDnaNodes) > 0 ? Number(opts.maxDnaNodes) : NETWORK_MAX_DNA_NODES,
    );

    let maxMcp = 0;
    for (const topic of list) {
        maxMcp = Math.max(maxMcp, clampMcp(topic?.mcp));
    }

    // High-MCP first: ring priority + defensive DNA cap keeps important satellites.
    const ordered = [...list].sort(compareTopicsForNetworkLayout);

    const nodes = [
        {
            id: `site:${sid}`,
            name: siteDomain,
            category: 'site',
            value: 1,
            x: 0,
            y: 0,
        },
    ];
    const links = [];
    let totalDna = 0;
    let showingDna = 0;
    /** @type {Map<number, number>} */
    const topicDnaCounts = new Map();

    for (const topic of ordered) {
        const tid = Number(topic.id);
        if (!Number.isFinite(tid) || tid <= 0) {
            continue;
        }
        const topicName = String(topic.name || '').trim() || 'Topic';
        const mcp = clampMcp(topic.mcp);
        const dnaRows = Array.isArray(topic.dna) ? topic.dna : [];
        totalDna += dnaRows.length;
        const topicSymbol = networkTopicSymbolSize(mcp, maxMcp);

        nodes.push({
            id: `topic:${tid}`,
            name: topicNetworkLabel(topic),
            topicName,
            category: 'topic',
            value: Math.max(0, mcp),
            mcp,
            dna_count: topic.dna_count != null ? Number(topic.dna_count) : dnaRows.length,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            tags: Array.isArray(topic.tags) ? topic.tags : [],
            symbolSizeHint: topicSymbol,
        });
        links.push({ source: `site:${sid}`, target: `topic:${tid}` });

        let placedForTopic = 0;
        for (let i = 0; i < dnaRows.length; i += 1) {
            if (showingDna >= maxDnaNodes) {
                break;
            }
            const phrase = String(dnaRows[i]?.phrase || '').trim();
            if (!phrase) {
                continue;
            }
            const dnaId = `dna:${tid}:${i}`;
            nodes.push({
                id: dnaId,
                name: dnaNetworkLabel(phrase),
                category: 'dna',
                value: 1,
                topic_id: tid,
                topicName,
                parentSymbolSize: topicSymbol,
                symbolSizeHint: networkDnaSymbolSize(topicSymbol),
            });
            links.push({ source: `topic:${tid}`, target: dnaId });
            showingDna += 1;
            placedForTopic += 1;
        }
        topicDnaCounts.set(tid, placedForTopic);
    }

    assignNetworkFixedCoordinates(nodes, { topicDnaCounts });

    const dnaTruncated = totalDna > showingDna;

    return {
        nodes,
        links,
        truncated: dnaTruncated,
        dna_truncated: dnaTruncated,
        showing_topics: ordered.filter((t) => Number(t.id) > 0).length,
        total_topics: ordered.filter((t) => Number(t.id) > 0).length,
        showing_dna: showingDna,
        total_dna: totalDna,
        max_mcp: maxMcp,
    };
}

/**
 * Structure view (route key `view=tree`): ECharts tree orient BT.
 * Visual: Topics (top leaves) ← Tags (middle) ← Site (bottom root).
 * Tagged: SITE → Tag → Topic.
 * Untagged: SITE → presentation-only "Chưa gắn tag" bucket → Topic
 *   (NOT a persisted Tag / filter / AI taxonomy node; far-right muted bucket).
 * Multi-tag Topics: one deterministic primary Tag. No Keyword children.
 *
 * Pattern: https://echarts.apache.org/examples/en/editor.html?c=tree-orient-bottom-top
 *
 * @param {object} data filtered overview
 * @param {{
 *   preferredTagIds?: number[],
 *   siteDomain?: string,
 *   untaggedBucketLabel?: string,
 *   untaggedBucketTooltip?: string,
 *   typographyBand?: 'compact'|'normal'|'medium'|'large'|'xlarge',
 * }} [opts]
 */
export function buildTreeOption(data, opts = {}) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const preferredTagIds = Array.isArray(opts?.preferredTagIds) ? opts.preferredTagIds : [];
    const siteDomain = String(
        opts?.siteDomain
        || data?.site_domain
        || '',
    ).trim();
    const siteLabel = siteDomain || 'Site';
    const typographyBand = opts?.typographyBand || getStructureTypographyBand(1);
    const typographyPatch = buildStructureTypographyPatch(typographyBand);

    /** @type {Map<number, {id: number, name: string, topics: object[]}>} */
    const tagBuckets = new Map();
    /** @type {object[]} */
    const untaggedTopics = [];

    for (const topic of topics) {
        const topicId = Number(topic?.id);
        if (!Number.isFinite(topicId) || topicId <= 0) {
            continue;
        }
        const primary = pickPrimaryTag(topic, preferredTagIds);
        if (!primary) {
            untaggedTopics.push(topic);
            continue;
        }
        let bucket = tagBuckets.get(primary.id);
        if (!bucket) {
            bucket = { id: primary.id, name: primary.name, topics: [] };
            tagBuckets.set(primary.id, bucket);
        }
        bucket.topics.push(topic);
    }

    const facets = Array.isArray(data?.tag_facets) ? data.tag_facets : [];
    const facetOrder = new Map(
        facets.map((facet, index) => [Number(facet.id), index]),
    );
    const sortedTags = [...tagBuckets.values()].sort((a, b) => {
        const ia = facetOrder.has(a.id) ? facetOrder.get(a.id) : Number.MAX_SAFE_INTEGER;
        const ib = facetOrder.has(b.id) ? facetOrder.get(b.id) : Number.MAX_SAFE_INTEGER;
        if (ia !== ib) {
            return ia - ib;
        }
        if (a.id !== b.id) {
            return a.id - b.id;
        }
        return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' });
    });

    const sortTopics = (list) => [...list].sort((a, b) => {
        const na = String(a?.name || '').toLocaleLowerCase();
        const nb = String(b?.name || '').toLocaleLowerCase();
        if (na !== nb) {
            return na.localeCompare(nb, undefined, { sensitivity: 'base' });
        }
        return Number(a?.id ?? 0) - Number(b?.id ?? 0);
    });

    // Real Tags first; presentation-only untagged bucket always far right.
    const rootChildren = [
        ...sortedTags.map((tag) => buildStructureTagNode(
            tag,
            sortTopics(tag.topics).map((topic) => buildStructureTopicNode(topic)),
        )),
    ];
    if (untaggedTopics.length > 0) {
        rootChildren.push(buildStructureUntaggedBucketNode(
            sortTopics(untaggedTopics).map((topic) => buildStructureTopicNode(topic)),
            opts,
        ));
    }

    return {
        backgroundColor: 'transparent',
        textStyle: {
            fontFamily: CHART_FONT_FAMILY,
        },
        tooltip: {
            trigger: 'item',
            triggerOn: 'mousemove',
            confine: true,
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: 'rgba(15,23,42,0.1)',
            borderWidth: 1,
            textStyle: {
                color: '#0f172a',
                fontSize: CHART_FONT_TOOLTIP,
                fontFamily: CHART_FONT_FAMILY,
            },
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'topic') {
                    return structureTopicTooltipHtml(d);
                }
                if (d.nodeType === 'tag') {
                    return structureTagTooltipHtml(d);
                }
                if (d.nodeType === 'untagged_bucket') {
                    return structureUntaggedBucketTooltipHtml(d);
                }
                if (d.nodeType === 'site') {
                    return `<strong>${escapeHtml(d.name || 'Site')}</strong>`;
                }
                return escapeHtml(params?.name || 'Site');
            },
        },
        series: [
            {
                type: 'tree',
                id: 'topical-map-tree',
                name: 'Structure',
                data: [
                    {
                        name: siteLabel,
                        nodeType: 'site',
                        symbolSize: STRUCTURE_SYMBOL_SIZE,
                        itemStyle: {
                            color: '#94a3b8',
                            borderColor: '#64748b',
                            borderWidth: 1,
                        },
                        // No per-node label — Site uses series.label rich `{site|…}`.
                        children: rootChildren,
                    },
                ],
                // Official BT example margins — top room for rotated leaf labels.
                left: '2%',
                right: '2%',
                top: '20%',
                bottom: '8%',
                symbol: 'circle',
                orient: 'BT',
                expandAndCollapse: false,
                initialTreeDepth: -1,
                zoom: 1,
                ...typographyPatch,
                lineStyle: {
                    color: 'rgba(148, 163, 184, 0.55)',
                    width: 1,
                    curveness: 0.5,
                },
                // Tag hover → highlight descendant Topics.
                emphasis: { focus: 'descendant' },
                animationDuration: 280,
                animationDurationUpdate: 750,
                // Pan/drag only — wheel zoom is owned by ChartCanvas host listener.
                roam: 'move',
                scaleLimit: { min: 0.2, max: 4 },
            },
        ],
    };
}

/**
 * Fixed full-site Topic distribution Treemap (flat overview — no Site drill).
 * Area ∝ canonical MCP % (share). Colors stay per-Topic identity.
 * ECharts implicit root is fine; do NOT expose a visible Site node.
 */
export function buildTreemapOption(data) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const labelTiers = assignTreemapLabelTiers(topics);

    const leaves = topics.map((topic) => {
        const topicId = Number(topic.id);
        const color = topicColorById(topicId);
        const realMcp = clampMcp(topic.mcp);
        // Soft identity tint — not a second quantitative scale.
        const opacity = 0.62 + 0.38 * (realMcp / 100);
        const articleCount = Math.max(0, Number(topic.article_count ?? 0));
        const name = String(topic.name || 'Topic');
        const tier = labelTiers.get(topicId) || 'medium';
        const ink = treemapLabelInk(color, opacity);
        // Small tiles: center the %; larger tiles keep top-left name+%.
        const labelPosition = (tier === 'small' || tier === 'micro')
            ? 'inside'
            : 'insideTopLeft';

        return {
            name,
            // layoutValue ≠ displayed MCP (see TREEMAP_MIN_VISUAL_MCP_WEIGHT).
            value: treemapLayoutValue(realMcp),
            topicId,
            nodeType: 'topic',
            mcp: realMcp,
            dna_count: topic.dna_count,
            article_count: articleCount,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
            labelTier: tier,
            itemStyle: {
                color,
                opacity,
                borderColor: '#fff',
                borderWidth: 1,
            },
            label: {
                show: true,
                position: labelPosition,
                color: ink,
                textShadowColor: ink === '#ffffff' ? 'rgba(15,23,42,0.45)' : 'rgba(255,255,255,0.4)',
                textShadowBlur: 2,
                textShadowOffsetY: 1,
            },
        };
    });

    return {
        backgroundColor: 'transparent',
        textStyle: {
            fontFamily: CHART_FONT_FAMILY,
        },
        tooltip: {
            trigger: 'item',
            confine: true,
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: 'rgba(15,23,42,0.1)',
            borderWidth: 1,
            textStyle: {
                color: '#0f172a',
                fontSize: CHART_FONT_TOOLTIP,
                fontFamily: CHART_FONT_FAMILY,
            },
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'topic') {
                    return topicTooltipHtml(d);
                }
                return escapeHtml(params?.name || '');
            },
        },
        series: [
            {
                type: 'treemap',
                id: 'topical-map-treemap',
                name: 'Topic distribution by MCP share',
                width: '100%',
                height: '100%',
                top: 4,
                left: 4,
                right: 4,
                bottom: 4,
                roam: false,
                nodeClick: false,
                zoomToNodeDuration: 0,
                breadcrumb: {
                    show: false,
                },
                visibleMin: 0,
                squareRatio: 0.75 * (1 + Math.sqrt(5)),
                label: {
                    show: true,
                    position: 'insideTopLeft',
                    distance: 0,
                    padding: [8, 10, 8, 10],
                    fontFamily: CHART_FONT_FAMILY,
                    fontSize: CHART_FONT_NORMAL,
                    fontWeight: 600,
                    lineHeight: 18,
                    overflow: 'truncate',
                    ellipsis: '…',
                    formatter(params) {
                        const d = params?.data || {};
                        if (d.nodeType !== 'topic') {
                            return '';
                        }
                        return formatTreemapTopicLabelRich(d, params);
                    },
                    rich: getTreemapTypographyRich(),
                },
                upperLabel: { show: false },
                itemStyle: {
                    borderColor: '#fff',
                    borderWidth: 2,
                    gapWidth: 2,
                },
                emphasis: {
                    label: { fontWeight: 700 },
                    itemStyle: {
                        borderColor: '#0f172a',
                        borderWidth: 2,
                    },
                },
                levels: [
                    {
                        itemStyle: {
                            borderColor: 'transparent',
                            borderWidth: 0,
                            gapWidth: 2,
                        },
                        label: { show: false },
                        upperLabel: { show: false },
                    },
                    {
                        itemStyle: {
                            borderColor: '#fff',
                            borderWidth: 1,
                            gapWidth: 1,
                        },
                        label: {
                            show: true,
                            fontFamily: CHART_FONT_FAMILY,
                            padding: [8, 10, 8, 10],
                            overflow: 'truncate',
                            ellipsis: '…',
                        },
                        upperLabel: { show: false },
                    },
                ],
                data: leaves,
                animationDuration: 280,
                animationDurationUpdate: 200,
            },
        ],
    };
}

/**
 * Client-side Topic Focus subset from an already-built full Network neighborhood.
 * Does not call APIs / mutate the full neighborhood.
 *
 * Nodes: Site + selected Topic + its DNA.
 * Coordinates: local centered layout (not full-map edge positions).
 *
 * @param {object|null|undefined} fullNeighborhood
 * @param {unknown} topicId
 * @returns {object|null} focused neighborhood, or null if Topic missing
 */
export function buildFocusedNetworkNeighborhood(fullNeighborhood, topicId) {
    const tid = Number(topicId);
    if (!Number.isFinite(tid) || tid <= 0) {
        return null;
    }
    const nodes = Array.isArray(fullNeighborhood?.nodes) ? fullNeighborhood.nodes : [];
    const links = Array.isArray(fullNeighborhood?.links) ? fullNeighborhood.links : [];
    if (nodes.length === 0) {
        return null;
    }

    const siteNode = nodes.find((n) => n.category === 'site');
    const topicNode = nodes.find(
        (n) => n.category === 'topic' && Number(String(n.id).replace(/^topic:/, '')) === tid,
    );
    if (!topicNode) {
        return null;
    }
    const dnaNodes = nodes.filter(
        (n) => n.category === 'dna' && Number(n.topic_id) === tid,
    );

    const focusedNodes = [
        ...(siteNode ? [{ ...siteNode }] : []),
        { ...topicNode },
        ...dnaNodes.map((n) => ({
            ...n,
            name: dnaNetworkLabel(n.name, { focused: true }),
        })),
    ];
    const keep = new Set(focusedNodes.map((n) => String(n.id)));
    const focusedLinks = links
        .filter((l) => keep.has(String(l.source)) && keep.has(String(l.target)))
        .map((l) => ({ ...l }));

    assignFocusedNetworkCoordinates(focusedNodes);

    const topicName = String(topicNode.topicName || topicNode.name || '').split('\n')[0].trim()
        || 'Topic';

    return {
        ...fullNeighborhood,
        nodes: focusedNodes,
        links: focusedLinks,
        truncated: false,
        dna_truncated: false,
        showing_topics: 1,
        total_topics: Number(fullNeighborhood?.total_topics ?? 1),
        showing_dna: dnaNodes.length,
        total_dna: dnaNodes.length,
        focused_topic_id: tid,
        focused_topic_name: topicName,
        max_mcp: clampMcp(topicNode.mcp),
    };
}

/**
 * Network: Site → Topic → DNA with fixed radial coordinates.
 * layout: 'none' — no force physics.
 * Typography via series rich styles — zoom bands patch rich only.
 * Hover uses emphasis.focus:'none' (no full-graph dim).
 *
 * @param {object} neighborhood
 * @param {{
 *   siteDomain?: string,
 *   typographyBand?: 'compact'|'normal'|'medium'|'large',
 *   focused?: boolean,
 *   zoom?: number,
 * }} [ui]
 */
export function buildNetworkOption(neighborhood, ui = {}) {
    const nodes = Array.isArray(neighborhood?.nodes) ? neighborhood.nodes : [];
    const links = Array.isArray(neighborhood?.links) ? neighborhood.links : [];
    const categories = [
        { name: 'site' },
        { name: 'topic' },
        { name: 'DNA' },
    ];
    const categoryIndex = { site: 0, topic: 1, dna: 2, DNA: 2 };
    const siteDomain = String(ui.siteDomain || '').trim();
    const typographyBand = ui.typographyBand || getChartTypographyBand(1);
    const focused = Boolean(
        ui.focused
        || neighborhood?.focused_topic_id
        || (Number(neighborhood?.showing_topics) === 1 && neighborhood?.focused_topic_name),
    );
    const typographyPatch = buildNetworkTypographyPatch(typographyBand, { focused });

    let maxMcp = Number(neighborhood?.max_mcp);
    if (!Number.isFinite(maxMcp) || maxMcp <= 0) {
        maxMcp = 0;
        for (const n of nodes) {
            if (n.category === 'topic') {
                maxMcp = Math.max(maxMcp, clampMcp(n.mcp));
            }
        }
    }

    /** @type {Map<number, number>} parent Topic symbol size for DNA fallback */
    const topicSizeById = new Map();
    for (const n of nodes) {
        if (n.category !== 'topic') {
            continue;
        }
        const tid = Number(String(n.id).replace(/^topic:/, ''));
        if (!Number.isFinite(tid) || tid <= 0) {
            continue;
        }
        const size = Number(n.symbolSizeHint) > 0
            ? Number(n.symbolSizeHint)
            : networkTopicSymbolSize(n.mcp, maxMcp);
        topicSizeById.set(tid, size);
    }

    return {
        backgroundColor: 'transparent',
        animation: false,
        animationDuration: 0,
        animationDurationUpdate: 0,
        textStyle: {
            fontFamily: CHART_FONT_FAMILY,
        },
        legend: [{
            data: categories.map((c) => c.name),
            textStyle: {
                fontFamily: CHART_FONT_FAMILY,
            },
        }],
        tooltip: {
            confine: true,
            trigger: 'item',
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: 'rgba(15,23,42,0.1)',
            borderWidth: 1,
            textStyle: {
                color: '#0f172a',
                fontSize: CHART_FONT_TOOLTIP,
                fontFamily: CHART_FONT_FAMILY,
            },
            formatter(params) {
                if (params.dataType === 'edge') {
                    return '';
                }
                const d = params.data || {};
                if (d.nodeType === 'site') {
                    return networkSiteTooltipHtml({
                        name: siteDomain || d.name || 'Site',
                    }, focused);
                }
                if (d.nodeType === 'topic') {
                    return networkTopicTooltipHtml(d);
                }
                if (d.nodeType === 'dna') {
                    return networkDnaTooltipHtml(d);
                }
                return escapeHtml(params.name || '');
            },
        },
        series: [
            {
                type: 'graph',
                id: 'topical-map-network',
                layout: 'none',
                // Keep Topic/DNA glyphs proportional to text while positions spread on zoom.
                nodeScaleRatio: 0,
                zoom: Number(ui.zoom) > 0 ? Number(ui.zoom) : (focused ? 1 : 4),
                // Pan/drag only — wheel zoom is owned by ChartCanvas host listener.
                roam: 'move',
                scaleLimit: { min: 0.2, max: 12 },
                draggable: false,
                categories,
                animation: false,
                animationDurationUpdate: 0,
                data: nodes.map((n) => {
                    const category = String(n.category || '');
                    const topicId = category === 'topic'
                        ? Number(String(n.id).replace(/^topic:/, ''))
                        : (category === 'dna' ? Number(n.topic_id) : undefined);
                    const topicColor = topicId ? topicColorById(topicId) : '#94a3b8';

                    let symbolSize = NETWORK_DNA_SYMBOL_SIZE;
                    let color = '#94a3b8';
                    let labelShow = false;
                    let cursor = 'default';
                    /** Outside-only label side — never 'inside'. */
                    let labelPosition = 'bottom';

                    if (category === 'site') {
                        symbolSize = NETWORK_SITE_SYMBOL_SIZE;
                        color = '#94a3b8';
                        labelShow = true;
                        cursor = focused ? 'pointer' : 'default';
                        labelPosition = n.labelPosition === 'top'
                            || n.labelPosition === 'left'
                            || n.labelPosition === 'right'
                            || n.labelPosition === 'bottom'
                            ? n.labelPosition
                            : 'bottom';
                    } else if (category === 'topic') {
                        symbolSize = Number(n.symbolSizeHint) > 0
                            ? Number(n.symbolSizeHint)
                            : networkTopicSymbolSize(n.mcp, maxMcp);
                        color = topicColor;
                        labelShow = true;
                        cursor = 'pointer';
                        labelPosition = n.labelPosition === 'top'
                            || n.labelPosition === 'left'
                            || n.labelPosition === 'right'
                            || n.labelPosition === 'bottom'
                            ? n.labelPosition
                            : 'bottom';
                    } else if (category === 'dna') {
                        const parentSize = Number(n.parentSymbolSize) > 0
                            ? Number(n.parentSymbolSize)
                            : (topicSizeById.get(Number(n.topic_id)) || NETWORK_DNA_SYMBOL_SIZE * 4);
                        symbolSize = Number(n.symbolSizeHint) > 0
                            ? Number(n.symbolSizeHint)
                            : networkDnaSymbolSize(parentSize, { focused });
                        // Topic color family, reduced emphasis — not random bright DNA colors.
                        color = tintHex(topicColor, 0.55);
                        labelShow = shouldShowDnaNetworkLabel(typographyBand, focused);
                        cursor = 'default';
                        labelPosition = n.labelPosition === 'top'
                            || n.labelPosition === 'left'
                            || n.labelPosition === 'right'
                            || n.labelPosition === 'bottom'
                            ? n.labelPosition
                            : 'right';
                    }

                    const x = Number(n.x);
                    const y = Number(n.y);

                    return {
                        id: n.id,
                        name: category === 'site' && siteDomain ? siteDomain : n.name,
                        category: categoryIndex[category] ?? 1,
                        value: n.value ?? 1,
                        x: Number.isFinite(x) ? x : 0,
                        y: Number.isFinite(y) ? y : 0,
                        symbolSize,
                        nodeType: category === 'dna' ? 'dna' : category,
                        topicId: category === 'topic' ? topicId : undefined,
                        topicName: n.topicName || (category === 'dna' ? n.topicName : undefined),
                        mcp: n.mcp,
                        dna_count: n.dna_count,
                        article_count: n.article_count,
                        keyword_count: n.keyword_count,
                        tags: n.tags,
                        cursor,
                        // show + outside position — font sizes from series.label.rich (zoom bands).
                        // Never 'inside' (overflow / wrap bugs on scaled Topics).
                        label: {
                            show: labelShow,
                            position: labelPosition,
                        },
                        emphasis: {
                            // Lightweight local feedback only — series focus:'none' avoids graph-wide blur.
                            scale: false,
                            itemStyle: {
                                borderWidth: category === 'topic' || category === 'site' ? 2 : 1.5,
                                borderColor: category === 'site' ? '#475569' : '#fff',
                            },
                            label: {
                                // Hover always reveals DNA text even if overview band hid it.
                                show: true,
                                position: labelPosition,
                            },
                        },
                        itemStyle: {
                            color,
                            borderColor: category === 'site' ? '#64748b' : '#fff',
                            borderWidth: category === 'site' ? 1 : (category === 'topic' ? 1 : 1.5),
                        },
                    };
                }),
                links: links.map((l) => {
                    const isDna = String(l.target || '').startsWith('dna:');
                    return {
                        source: l.source,
                        target: l.target,
                        lineStyle: isDna
                            ? { color: 'source', opacity: 0.24, width: 0.5, curveness: 0 }
                            : { color: 'source', opacity: 0.14, width: 0.65, curveness: 0.02 },
                        emphasis: {
                            lineStyle: {
                                width: isDna ? 0.9 : 1.2,
                                opacity: 0.5,
                            },
                        },
                    };
                }),
                ...typographyPatch,
                lineStyle: {
                    color: 'source',
                    curveness: 0.02,
                    opacity: 0.14,
                    width: 0.65,
                },
            },
        ],
    };
}
