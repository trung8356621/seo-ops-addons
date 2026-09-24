/**
 * Site-level Topical Map — Apache ECharts (Tree / Network / Sunburst).
 * Presentation only: overview is injected; children/network via Livewire.
 * Full-viewport canvas: no persistent side panel. Tag filter applied server-side in overview.
 */
import * as echarts from 'echarts/core';
import { TreeChart, GraphChart, SunburstChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    TreeChart,
    GraphChart,
    SunburstChart,
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
    CanvasRenderer,
]);

const ROOT_SELECTOR = '[data-topical-map-root]';
const META_SELECTOR = '[data-topical-map-meta]';

/** @type {import('echarts').ECharts|null} */
let chart = null;
/** @type {HTMLElement|null} */
let rootEl = null;
/** @type {object|null} */
let overview = null;
/** @type {'tree'|'network'|'sunburst'} */
let renderer = 'tree';
/** @type {Map<number, object>} */
const childrenCache = new Map();
/** @type {WeakMap<HTMLElement, boolean>} */
const wired = new WeakMap();
/** @type {ResizeObserver|null} */
let resizeObserver = null;
/** @type {number|null} */
let lastClickTopicId = null;
/** @type {number} */
let lastClickAt = 0;

function parseOverview(el) {
    const raw = el.getAttribute('data-overview') || '';
    if (! raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        console.error('[TopicalMap] overview JSON parse failed', error);
        return null;
    }
}

function findLivewireComponent(el) {
    if (! window.Livewire || typeof window.Livewire.find !== 'function') {
        return null;
    }
    const host = el.closest('[wire\\:id]');
    if (! host) {
        return null;
    }
    const id = host.getAttribute('wire:id');
    return id ? window.Livewire.find(id) : null;
}

function topicLabel(topic) {
    const mcp = Number(topic.mcp ?? 0);
    const dna = Number(topic.dna_count ?? 0);
    const articles = Number(topic.article_count ?? 0);
    return `${topic.name}\nMCP ${mcp.toFixed(0)}% · DNA ${dna} · Art ${articles}`;
}

function buildTreeOption(data) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const siteId = Number(data?.site_id ?? 0);
    const children = topics.map((topic) => {
        const cached = childrenCache.get(Number(topic.id));
        const node = {
            name: topicLabel(topic),
            topicId: Number(topic.id),
            nodeType: 'topic',
            value: Math.max(1, Number(topic.article_count ?? 0) || Number(topic.keyword_count ?? 0) || 1),
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
            collapsed: ! cached,
        };
        if (cached?.children?.length) {
            node.children = cached.children.map((child) => ({
                name: child.name,
                keywordId: Number(child.id),
                nodeType: 'keyword',
                value: 1,
                article_count: child.article_count ?? 0,
            }));
        } else if (topic.has_children) {
            node.children = [];
        }
        return node;
    });

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'keyword') {
                    return `<strong>${escapeHtml(d.name || '')}</strong><br/>Keyword`
                        + (d.article_count ? `<br/>Articles: ${d.article_count}` : '');
                }
                if (d.nodeType === 'topic') {
                    const tagNames = Array.isArray(d.tags)
                        ? d.tags.map((t) => t.name).filter(Boolean).join(', ')
                        : '';
                    return [
                        `<strong>${escapeHtml(String(d.name || '').split('\n')[0] || '')}</strong>`,
                        `MCP: ${Number(d.mcp ?? 0).toFixed(1)}%`,
                        `DNA: ${d.dna_count ?? 0}`,
                        `Articles: ${d.article_count ?? 0}`,
                        `Keywords: ${d.keyword_count ?? 0}`,
                        d.coverage ? `Coverage: ${escapeHtml(String(d.coverage))}` : '',
                        tagNames ? `Tags: ${escapeHtml(tagNames)}` : '',
                        '<em>Double-click to open Topic</em>',
                    ].filter(Boolean).join('<br/>');
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
                        name: `Site #${siteId || ''}`,
                        nodeType: 'site',
                        children,
                    },
                ],
                top: '4%',
                left: '8%',
                bottom: '4%',
                right: '18%',
                symbolSize: 10,
                orient: 'LR',
                expandAndCollapse: true,
                initialTreeDepth: 1,
                label: {
                    position: 'left',
                    verticalAlign: 'middle',
                    align: 'right',
                    fontSize: 11,
                    lineHeight: 14,
                },
                leaves: {
                    label: {
                        position: 'right',
                        verticalAlign: 'middle',
                        align: 'left',
                    },
                },
                emphasis: { focus: 'descendant' },
                animationDuration: 350,
                animationDurationUpdate: 450,
                roam: true,
            },
        ],
    };
}

function buildSunburstOption(data) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const children = topics.map((topic) => {
        const cached = childrenCache.get(Number(topic.id));
        const value = Math.max(1, Number(topic.article_count ?? 0) || Number(topic.keyword_count ?? 0) || 1);
        const node = {
            name: topic.name,
            value,
            topicId: Number(topic.id),
            nodeType: 'topic',
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
        };
        if (cached?.children?.length) {
            node.children = cached.children.map((child) => ({
                name: child.name,
                value: 1,
                keywordId: Number(child.id),
                nodeType: 'keyword',
                article_count: child.article_count ?? 0,
            }));
        }
        return node;
    });

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'topic') {
                    return [
                        `<strong>${escapeHtml(d.name || '')}</strong>`,
                        `Articles: ${d.article_count ?? d.value ?? 0}`,
                        `Keywords: ${d.keyword_count ?? 0}`,
                        `MCP: ${Number(d.mcp ?? 0).toFixed(1)}%`,
                        '<em>Double-click to open Topic</em>',
                    ].join('<br/>');
                }
                return `<strong>${escapeHtml(params?.name || '')}</strong>`;
            },
        },
        series: [
            {
                type: 'sunburst',
                id: 'topical-map-sunburst',
                radius: [0, '92%'],
                sort: undefined,
                emphasis: { focus: 'ancestor' },
                data: children,
                label: { rotate: 'radial', minAngle: 4, fontSize: 10 },
                levels: [
                    {},
                    { r0: '15%', r: '55%', label: { rotate: 'tangential' } },
                    { r0: '55%', r: '92%', label: { position: 'outside', padding: 2 } },
                ],
            },
        ],
    };
}

function buildNetworkOption(neighborhood) {
    const nodes = Array.isArray(neighborhood?.nodes) ? neighborhood.nodes : [];
    const links = Array.isArray(neighborhood?.links) ? neighborhood.links : [];
    const categories = [
        { name: 'site' },
        { name: 'topic' },
        { name: 'keyword' },
    ];
    const categoryIndex = { site: 0, topic: 1, keyword: 2 };

    return {
        backgroundColor: 'transparent',
        legend: [{ data: categories.map((c) => c.name) }],
        tooltip: {
            formatter(params) {
                if (params.dataType === 'edge') {
                    return 'Topic membership';
                }
                const d = params.data || {};
                return `<strong>${escapeHtml(d.name || '')}</strong><br/>${escapeHtml(d.category || '')}`
                    + (d.nodeType === 'topic' ? '<br/><em>Double-click to open Topic</em>' : '');
            },
        },
        series: [
            {
                type: 'graph',
                id: 'topical-map-network',
                layout: 'force',
                roam: true,
                draggable: false,
                categories,
                data: nodes.map((n) => ({
                    id: n.id,
                    name: n.name,
                    category: categoryIndex[n.category] ?? 1,
                    value: n.value ?? 1,
                    symbolSize: n.category === 'site' ? 28 : (n.category === 'topic' ? 18 : 10),
                    nodeType: n.category,
                    topicId: n.category === 'topic' ? Number(String(n.id).replace(/^topic:/, '')) : undefined,
                    keywordId: n.category === 'keyword' ? Number(String(n.id).replace(/^keyword:/, '')) : undefined,
                })),
                links: links.map((l) => ({
                    source: l.source,
                    target: l.target,
                })),
                label: {
                    show: true,
                    position: 'right',
                    formatter: '{b}',
                    fontSize: 10,
                },
                force: {
                    repulsion: 120,
                    edgeLength: [40, 120],
                    gravity: 0.08,
                },
                lineStyle: { color: 'source', curveness: 0.05, opacity: 0.55 },
                emphasis: { focus: 'adjacency' },
            },
        ],
    };
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function updateMeta(text) {
    const meta = document.querySelector(META_SELECTOR);
    if (! meta) {
        return;
    }
    if (! text) {
        meta.hidden = true;
        meta.textContent = '';
        return;
    }
    meta.hidden = false;
    meta.textContent = text;
}

function ensureChart() {
    if (! rootEl) {
        return null;
    }
    if (! chart) {
        chart = echarts.init(rootEl, undefined, { renderer: 'canvas' });
        chart.on('click', onChartClick);
        chart.on('dblclick', onChartDblClick);
        rootEl.addEventListener('wheel', onChartWheel, { passive: false });
    }
    return chart;
}

function onChartWheel(event) {
    // Keep wheel interaction inside the map canvas (layout already overflow:hidden).
    if (! rootEl || ! rootEl.contains(event.target)) {
        return;
    }
    event.preventDefault();
}

async function onChartClick(params) {
    const data = params?.data || {};
    const wire = rootEl ? findLivewireComponent(rootEl) : null;

    if (data.nodeType === 'topic' && data.topicId) {
        const now = Date.now();
        const isDouble = lastClickTopicId === Number(data.topicId) && (now - lastClickAt) < 350;
        lastClickTopicId = Number(data.topicId);
        lastClickAt = now;
        if (isDouble) {
            return;
        }

        if (wire) {
            try {
                await wire.call('focusTopic', Number(data.topicId));
            } catch (error) {
                console.warn('[TopicalMap] focusTopic failed', error);
            }
        }
        if (renderer === 'tree' || renderer === 'sunburst') {
            await ensureTopicChildren(Number(data.topicId), wire);
            renderCurrent();
        }
        if (renderer === 'network' && wire) {
            const neighborhood = await wire.call('loadNetworkNeighborhood', Number(data.topicId));
            applyNetwork(neighborhood);
        }
        return;
    }
}

async function onChartDblClick(params) {
    const data = params?.data || {};
    if (data.nodeType !== 'topic' || ! data.topicId) {
        return;
    }
    const wire = rootEl ? findLivewireComponent(rootEl) : null;
    if (! wire) {
        return;
    }
    try {
        await wire.call('openTopicDetail', Number(data.topicId));
    } catch (error) {
        console.warn('[TopicalMap] openTopicDetail failed', error);
    }
}

async function ensureTopicChildren(topicId, wire) {
    if (childrenCache.has(topicId)) {
        return childrenCache.get(topicId);
    }
    if (! wire) {
        return null;
    }
    try {
        const result = await wire.call('loadTopicChildren', topicId);
        if (! result?.ok) {
            updateMeta(result?.error || 'Failed to load Topic children.');
            return null;
        }
        childrenCache.set(topicId, result);
        if (result.truncated) {
            updateMeta(`Showing ${result.showing} of ${result.total} keywords`);
        }
        return result;
    } catch (error) {
        console.error('[TopicalMap] loadTopicChildren failed', error);
        updateMeta('Failed to load Topic children.');
        return null;
    }
}

function applyNetwork(neighborhood) {
    const instance = ensureChart();
    if (! instance) {
        return;
    }
    instance.clear();
    instance.setOption(buildNetworkOption(neighborhood || { nodes: [], links: [] }), true);
    if (neighborhood?.truncated) {
        updateMeta(`Showing ${neighborhood.showing_topics} of ${neighborhood.total_topics} Topics (membership neighborhood)`);
    } else {
        updateMeta(neighborhood ? 'Network semantics: Topic membership' : '');
    }
}

function renderCurrent() {
    const instance = ensureChart();
    if (! instance || ! overview) {
        return;
    }

    if (renderer === 'sunburst') {
        instance.clear();
        instance.setOption(buildSunburstOption(overview), true);
        updateMeta('');
        return;
    }

    if (renderer === 'network') {
        return;
    }

    instance.clear();
    instance.setOption(buildTreeOption(overview), true);
    updateMeta('');
}

async function switchRenderer(next) {
    const allowed = ['tree', 'network', 'sunburst'];
    renderer = allowed.includes(next) ? next : 'tree';
    if (rootEl) {
        rootEl.setAttribute('data-renderer', renderer);
    }

    if (renderer === 'network') {
        const wire = rootEl ? findLivewireComponent(rootEl) : null;
        if (wire) {
            const neighborhood = await wire.call('loadNetworkNeighborhood', null);
            applyNetwork(neighborhood);
            return;
        }
        applyNetwork({ nodes: [], links: [], truncated: false });
        return;
    }

    renderCurrent();
}

function destroy() {
    if (resizeObserver) {
        try {
            resizeObserver.disconnect();
        } catch {
            // ignore
        }
        resizeObserver = null;
    }
    if (rootEl) {
        rootEl.removeEventListener('wheel', onChartWheel);
    }
    if (chart) {
        try {
            chart.dispose();
        } catch {
            // ignore
        }
        chart = null;
    }
}

function applyOverview(nextOverview) {
    overview = nextOverview;
    childrenCache.clear();
    if (! overview || ! Array.isArray(overview.topics)) {
        return;
    }
    switchRenderer(renderer);
}

function mount() {
    const el = document.querySelector(ROOT_SELECTOR);
    if (! el) {
        destroy();
        rootEl = null;
        return;
    }

    if (wired.get(el) && rootEl === el && chart) {
        return;
    }

    destroy();
    rootEl = el;
    overview = parseOverview(el);
    renderer = el.getAttribute('data-renderer') || 'tree';
    childrenCache.clear();
    wired.set(el, true);

    if (! overview || ! Array.isArray(overview.topics)) {
        return;
    }

    switchRenderer(renderer);

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            onResize();
        });
        const shell = el.closest('.topical-map-chart-shell') || el;
        resizeObserver.observe(shell);
    }
}

function onResize() {
    if (chart) {
        chart.resize();
    }
}

function focusTopicFromAudit(topicId) {
    const id = Number(topicId);
    if (! Number.isFinite(id) || id <= 0 || ! Array.isArray(overview?.topics)) {
        return;
    }
    const topic = overview.topics.find((row) => Number(row.id) === id);
    if (! topic || ! chart) {
        return;
    }
    // Emphasize via dispatchAction when possible; tooltip path remains primary.
    try {
        chart.dispatchAction({ type: 'highlight', seriesIndex: 0, name: topic.name });
    } catch {
        // ignore
    }
}

function boot() {
    mount();
    window.addEventListener('resize', onResize);
    document.addEventListener('livewire:navigated', mount);
    document.addEventListener('topical-map-renderer-changed', (event) => {
        const next = event?.detail?.renderer || event?.detail?.[0]?.renderer;
        if (next) {
            switchRenderer(String(next));
        }
    });
    document.addEventListener('topical-map-focus-topic', (event) => {
        const topicId = event?.detail?.topicId ?? event?.detail?.[0]?.topicId;
        if (topicId) {
            focusTopicFromAudit(topicId);
        }
    });
    document.addEventListener('topical-map-overview-updated', (event) => {
        const next = event?.detail?.overview ?? event?.detail?.[0]?.overview;
        if (next) {
            applyOverview(next);
        }
    });
    if (window.Livewire) {
        window.Livewire.on('topical-map-renderer-changed', (payload) => {
            const next = payload?.renderer ?? payload?.[0]?.renderer;
            if (next) {
                switchRenderer(String(next));
            }
        });
        window.Livewire.on('topical-map-focus-topic', (payload) => {
            const topicId = payload?.topicId ?? payload?.[0]?.topicId;
            if (topicId) {
                focusTopicFromAudit(topicId);
            }
        });
        window.Livewire.on('topical-map-overview-updated', (payload) => {
            const next = payload?.overview ?? payload?.[0]?.overview;
            if (next) {
                applyOverview(next);
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
