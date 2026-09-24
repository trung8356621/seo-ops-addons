import {
    mcpToSymbolSize,
    topicColorById,
    tintHex,
    topicTreeLabel,
    clampMcp,
    sortKeywordsByWordCount,
    treeSeriesBottomExtent,
    KEYWORD_SYMBOL_SIZE,
} from './theme';

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function topicTooltipHtml(d) {
    const title = String(d.name || '').split('\n')[0] || '';
    const keywords = d.keyword_count != null ? Number(d.keyword_count) : null;
    const lines = [
        `<strong>${escapeHtml(title)}</strong>`,
        `Focus Articles: ${Number(d.article_count ?? 0)}`,
        `MCP: ${clampMcp(d.mcp).toFixed(1)}%`,
        `DNA: ${d.dna_count ?? 0}`,
    ];
    if (keywords != null && Number.isFinite(keywords)) {
        lines.push(`Keywords: ${keywords}`);
    }
    lines.push('<em>Double-click to open Topic</em>');
    return lines.join('<br/>');
}

/**
 * Layout-only floor so 0-Focus Topics stay visible in Treemap.
 * Tooltip / labels MUST use real `article_count`, never this weight.
 */
export const TREEMAP_MIN_DISPLAY_WEIGHT = 0.35;

/**
 * Tile AREA = canonical Topic article_count (DISTINCT Focus Articles).
 * layoutValue may use MIN_DISPLAY_WEIGHT; displayed counts stay real.
 */
export function treemapLayoutValue(articleCount) {
    const real = Math.max(0, Number(articleCount) || 0);
    return Math.max(TREEMAP_MIN_DISPLAY_WEIGHT, real);
}

/**
 * Relative-area label tiers (presentation only — not business filtering).
 * Used when ECharts label formatter does not expose tile geometry.
 *
 * @param {object[]} topics
 * @returns {Map<number, 'large'|'medium'|'small'>}
 */
export function assignTreemapLabelTiers(topics) {
    const list = Array.isArray(topics) ? topics : [];
    const rows = list.map((topic) => ({
        id: Number(topic.id),
        value: treemapLayoutValue(topic.article_count),
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
        // Prefer share of canvas + relative to largest tile.
        if (share >= 0.04 || rel >= 0.28) {
            tiers.set(row.id, 'large');
        } else if (share >= 0.012 || rel >= 0.1) {
            tiers.set(row.id, 'medium');
        } else {
            tiers.set(row.id, 'small');
        }
    }

    // Guarantee the biggest tiles always get text even on flat distributions.
    const ensureLarge = Math.min(6, sorted.length);
    for (let i = 0; i < ensureLarge; i += 1) {
        const id = sorted[i].id;
        if (tiers.get(id) === 'small') {
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
    // Composite over near-white canvas so opacity is accounted for.
    const mix = (c) => Math.round(c * a + 255 * (1 - a));
    const R = mix(r);
    const G = mix(g);
    const B = mix(b);
    // Relative luminance (sRGB approx).
    const lum = (0.2126 * R + 0.7152 * G + 0.0722 * B) / 255;
    return lum > 0.55 ? '#0f172a' : '#ffffff';
}

/**
 * Progressive Treemap label density.
 * Geometry (when available) wins; otherwise precomputed relative-area tier.
 *
 * @param {object} d topic node data
 * @param {object} [params] ECharts formatter params
 * @returns {string}
 */
export function formatTreemapTopicLabel(d, params) {
    const name = String(d?.name || '').trim() || 'Topic';
    let tier = d?.labelTier || 'medium';

    const rect = params?.rect || params?.labelRect || null;
    const w = Number(rect?.width ?? 0);
    const h = Number(rect?.height ?? 0);
    if (w > 0 && h > 0) {
        if (w < 56 || h < 34) {
            tier = 'small';
        } else if (w < 120 || h < 58) {
            tier = 'medium';
        } else {
            tier = 'large';
        }
    }

    if (tier === 'small') {
        return '';
    }
    if (tier === 'medium') {
        return name;
    }

    const focus = Number(d?.article_count ?? 0);
    const mcp = clampMcp(d?.mcp).toFixed(1);
    return `${name}\n${focus} Focus · MCP ${mcp}%`;
}

/** @deprecated use topicTreeLabel — kept for greps/tests that import topicLabel */
export function topicLabel(topic) {
    return topicTreeLabel(topic);
}

/**
 * Network overview graph from already-filtered Topic rows (no API).
 * Site → Topic nodes only.
 */
export function buildOverviewNeighborhood(siteId, topics) {
    const sid = Number(siteId) || 0;
    const list = Array.isArray(topics) ? topics : [];
    const nodes = [
        {
            id: `site:${sid}`,
            name: 'Site',
            category: 'site',
            value: 1,
        },
    ];
    const links = [];
    for (const topic of list) {
        const tid = Number(topic.id);
        if (!Number.isFinite(tid) || tid <= 0) {
            continue;
        }
        nodes.push({
            id: `topic:${tid}`,
            name: String(topic.name || ''),
            category: 'topic',
            value: Math.max(1, Number(topic.mcp) || 1),
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
        });
        links.push({ source: `site:${sid}`, target: `topic:${tid}` });
    }
    return {
        nodes,
        links,
        truncated: false,
        showing_topics: list.length,
        total_topics: list.length,
    };
}

export function buildTreeOption(data, childrenCache) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const siteId = Number(data?.site_id ?? 0);
    const children = topics.map((topic) => {
        const topicId = Number(topic.id);
        const color = topicColorById(topicId);
        const cached = childrenCache.get(topicId);
        const node = {
            name: topicTreeLabel(topic),
            topicId,
            nodeType: 'topic',
            value: Math.max(1, clampMcp(topic.mcp)),
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
            symbolSize: mcpToSymbolSize(topic.mcp),
            itemStyle: {
                color,
                borderColor: '#fff',
                borderWidth: 1.5,
                shadowBlur: 4,
                shadowColor: 'rgba(15, 23, 42, 0.12)',
            },
            lineStyle: {
                color,
                width: 1.25,
                opacity: 0.4,
                curveness: 0.35,
            },
            label: {
                color: '#0f172a',
                fontWeight: 600,
            },
            collapsed: !cached,
        };
        if (cached?.children?.length) {
            const leafColor = tintHex(color, 0.5);
            const sorted = sortKeywordsByWordCount(cached.children);
            node.children = sorted.map((child) => ({
                name: child.name,
                keywordId: Number(child.id),
                nodeType: 'keyword',
                value: 1,
                article_count: child.article_count ?? 0,
                symbolSize: KEYWORD_SYMBOL_SIZE,
                itemStyle: {
                    color: leafColor,
                    borderColor: color,
                    borderWidth: 1,
                },
                label: {
                    color: '#64748b',
                    fontWeight: 500,
                    fontSize: 10,
                },
            }));
        } else if (topic.has_children) {
            node.children = [];
        }
        return node;
    });

    const seriesBottom = treeSeriesBottomExtent(topics.length);

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            confine: true,
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: 'rgba(15,23,42,0.1)',
            borderWidth: 1,
            textStyle: { color: '#0f172a', fontSize: 12 },
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'keyword') {
                    return `<strong>${escapeHtml(d.name || '')}</strong><br/>Keyword`
                        + (d.article_count ? `<br/>Articles: ${d.article_count}` : '');
                }
                if (d.nodeType === 'topic') {
                    return topicTooltipHtml(d);
                }
                return escapeHtml(params?.name || 'Site');
            },
        },
        series: [
            {
                type: 'tree',
                id: 'topical-map-tree',
                name: 'Topical Map',
                data: [
                    {
                        name: siteId ? `Site` : 'Site',
                        nodeType: 'site',
                        symbolSize: 18,
                        itemStyle: {
                            color: '#94a3b8',
                            borderColor: '#64748b',
                            borderWidth: 1,
                        },
                        label: {
                            color: '#64748b',
                            fontWeight: 600,
                        },
                        children,
                    },
                ],
                top: '4%',
                left: '12%',
                bottom: seriesBottom,
                right: '22%',
                symbol: 'circle',
                orient: 'LR',
                expandAndCollapse: true,
                initialTreeDepth: 1,
                zoom: 1,
                label: {
                    position: 'left',
                    verticalAlign: 'middle',
                    align: 'right',
                    fontSize: 11,
                    lineHeight: 16,
                    distance: 10,
                },
                leaves: {
                    label: {
                        position: 'right',
                        verticalAlign: 'middle',
                        align: 'left',
                        distance: 10,
                    },
                },
                emphasis: { focus: 'descendant' },
                animationDuration: 350,
                animationDurationUpdate: 450,
                roam: true,
                scaleLimit: { min: 0.35, max: 4 },
            },
        ],
    };
}

/**
 * Site-level Topic distribution Treemap.
 * Area ∝ article_count (Focus Articles). MCP only tints; never area.
 */
export function buildTreemapOption(data) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const siteId = Number(data?.site_id ?? 0);
    const labelTiers = assignTreemapLabelTiers(topics);

    const children = topics.map((topic) => {
        const topicId = Number(topic.id);
        const color = topicColorById(topicId);
        const mcp = clampMcp(topic.mcp);
        // Secondary visual only — keep identity hue, soft opacity by MCP.
        const opacity = 0.62 + 0.38 * (mcp / 100);
        const articleCount = Math.max(0, Number(topic.article_count ?? 0));
        const name = String(topic.name || 'Topic');
        const tier = labelTiers.get(topicId) || 'medium';
        const ink = treemapLabelInk(color, opacity);

        return {
            name,
            // layoutValue ≠ displayed article_count (see TREEMAP_MIN_DISPLAY_WEIGHT).
            value: treemapLayoutValue(articleCount),
            topicId,
            nodeType: 'topic',
            mcp,
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
                // Small tiles: hide entirely (tooltip still works).
                show: tier !== 'small',
                color: ink,
                textShadowColor: ink === '#ffffff' ? 'rgba(15,23,42,0.35)' : 'rgba(255,255,255,0.35)',
                textShadowBlur: 2,
                textShadowOffsetY: 1,
            },
        };
    });

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            confine: true,
            backgroundColor: 'rgba(255,255,255,0.96)',
            borderColor: 'rgba(15,23,42,0.1)',
            borderWidth: 1,
            textStyle: { color: '#0f172a', fontSize: 12 },
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'topic') {
                    return topicTooltipHtml(d);
                }
                if (d.nodeType === 'site') {
                    return `<strong>${escapeHtml(d.name || 'Site')}</strong><br/><em>Click breadcrumb / Fit for overview</em>`;
                }
                return escapeHtml(params?.name || '');
            },
        },
        series: [
            {
                type: 'treemap',
                id: 'topical-map-treemap',
                name: 'Topic distribution',
                width: '100%',
                height: '100%',
                top: 28,
                left: 4,
                right: 4,
                bottom: 4,
                roam: false,
                nodeClick: 'zoomToNode',
                breadcrumb: {
                    show: true,
                    height: 22,
                    left: 4,
                    top: 2,
                    itemStyle: {
                        color: '#f1f5f9',
                        borderColor: 'rgba(15,23,42,0.1)',
                        textStyle: { color: '#334155' },
                    },
                },
                leafDepth: 1,
                visibleMin: 0,
                squareRatio: 0.75 * (1 + Math.sqrt(5)),
                label: {
                    show: true,
                    position: 'insideTopLeft',
                    distance: 0,
                    // ~8px inner padding so text does not touch edges.
                    padding: [8, 8, 8, 8],
                    fontSize: 12,
                    fontWeight: 600,
                    lineHeight: 17,
                    overflow: 'truncate',
                    ellipsis: '…',
                    formatter(params) {
                        const d = params?.data || {};
                        if (d.nodeType !== 'topic') {
                            // One-level Topic distribution — no root title strip.
                            return '';
                        }
                        return formatTreemapTopicLabel(d, params);
                    },
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
                            position: 'insideTopLeft',
                            padding: [8, 8, 8, 8],
                            overflow: 'truncate',
                            ellipsis: '…',
                        },
                        upperLabel: { show: false },
                    },
                ],
                data: [
                    {
                        name: siteId ? 'Site' : 'Site',
                        nodeType: 'site',
                        topicId: undefined,
                        label: { show: false },
                        children,
                    },
                ],
                animationDuration: 350,
                animationDurationUpdate: 450,
            },
        ],
    };
}

/**
 * @param {object} neighborhood
 * @param {{
 *   focused?: boolean,
 *   pendingTopicId?: number|null,
 *   siteNavigable?: boolean,
 * }} [ui]
 */
export function buildNetworkOption(neighborhood, ui = {}) {
    const nodes = Array.isArray(neighborhood?.nodes) ? neighborhood.nodes : [];
    const links = Array.isArray(neighborhood?.links) ? neighborhood.links : [];
    const categories = [
        { name: 'site' },
        { name: 'topic' },
        { name: 'keyword' },
    ];
    const categoryIndex = { site: 0, topic: 1, keyword: 2 };
    const pendingId = ui.pendingTopicId != null && Number(ui.pendingTopicId) > 0
        ? Number(ui.pendingTopicId)
        : null;
    const focused = Boolean(ui.focused);
    const siteNavigable = Boolean(ui.siteNavigable);

    return {
        backgroundColor: 'transparent',
        animation: true,
        animationDuration: 350,
        animationDurationUpdate: 420,
        animationEasingUpdate: 'cubicOut',
        legend: [{ data: categories.map((c) => c.name) }],
        tooltip: {
            confine: true,
            formatter(params) {
                if (params.dataType === 'edge') {
                    return 'Topic membership';
                }
                const d = params.data || {};
                if (d.nodeType === 'site') {
                    if (siteNavigable) {
                        return `<strong>${escapeHtml(d.name || 'Site')}</strong><br/><em>Back to all Topics</em>`;
                    }
                    return `<strong>${escapeHtml(d.name || 'Site')}</strong>`;
                }
                if (d.nodeType === 'topic') {
                    const mcpLine = d.mcp != null ? `<br/>MCP: ${clampMcp(d.mcp).toFixed(1)}%` : '';
                    return `<strong>${escapeHtml(d.name || '')}</strong>${mcpLine}`
                        + '<br/><em>Click to topic detail</em>'
                        + '<br/><em>Double-click to open Topic</em>';
                }
                return `<strong>${escapeHtml(d.name || '')}</strong><br/>${escapeHtml(d.category || '')}`;
            },
        },
        series: [
            {
                type: 'graph',
                id: 'topical-map-network',
                layout: 'force',
                roam: true,
                scaleLimit: { min: 0.35, max: 4 },
                draggable: false,
                categories,
                animation: true,
                animationDurationUpdate: 420,
                animationEasingUpdate: 'cubicOut',
                data: nodes.map((n) => {
                    const topicId = n.category === 'topic'
                        ? Number(String(n.id).replace(/^topic:/, ''))
                        : undefined;
                    const color = n.category === 'topic' && topicId
                        ? topicColorById(topicId)
                        : (n.category === 'site' ? '#94a3b8' : '#cbd5e1');

                    let opacity = 1;
                    let borderColor = undefined;
                    let borderWidth = 0;
                    let shadowBlur = 0;
                    let symbolSize = n.category === 'site' ? 28 : (n.category === 'topic' ? 18 : 10);

                    if (pendingId && n.category === 'topic') {
                        if (Number(topicId) === pendingId) {
                            opacity = 1;
                            borderColor = '#0f172a';
                            borderWidth = 3;
                            shadowBlur = 12;
                            symbolSize = 22;
                        } else {
                            opacity = 0.28;
                        }
                    } else if (pendingId && n.category !== 'site') {
                        opacity = 0.28;
                    }

                    if (n.category === 'site' && siteNavigable) {
                        borderColor = '#0f172a';
                        borderWidth = 2;
                        symbolSize = 32;
                    }

                    return {
                        id: n.id,
                        name: n.name,
                        category: categoryIndex[n.category] ?? 1,
                        value: n.value ?? 1,
                        symbolSize,
                        nodeType: n.category,
                        topicId,
                        keywordId: n.category === 'keyword'
                            ? Number(String(n.id).replace(/^keyword:/, ''))
                            : undefined,
                        mcp: n.mcp,
                        dna_count: n.dna_count,
                        article_count: n.article_count,
                        cursor: (n.category === 'site' && siteNavigable) || n.category === 'topic'
                            ? 'pointer'
                            : 'default',
                        itemStyle: {
                            color,
                            opacity,
                            borderColor,
                            borderWidth,
                            shadowBlur,
                            shadowColor: 'rgba(15, 23, 42, 0.35)',
                        },
                    };
                }),
                links: links.map((l) => ({
                    source: l.source,
                    target: l.target,
                    lineStyle: pendingId
                        ? { opacity: 0.2 }
                        : undefined,
                })),
                label: {
                    show: true,
                    position: 'right',
                    formatter: '{b}',
                    fontSize: 10,
                },
                force: {
                    repulsion: focused ? 160 : 120,
                    edgeLength: focused ? [50, 140] : [40, 120],
                    gravity: 0.08,
                },
                lineStyle: { color: 'source', curveness: 0.05, opacity: 0.55 },
                emphasis: { focus: 'adjacency' },
            },
        ],
    };
}
