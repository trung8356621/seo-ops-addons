/**
 * One-keyword Relationship graph — Apache ECharts GraphChart only.
 * Graph payload comes from KeywordRelationshipGraphPresenter (UI layer).
 */
import * as echarts from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([GraphChart, TooltipComponent, LegendComponent, CanvasRenderer]);

const ROOT_SELECTOR = '[data-keyword-relationship-root]';
const SIDE_SELECTOR = '[data-keyword-relationship-side]';

/** @type {import('echarts').ECharts|null} */
let chart = null;
/** @type {HTMLElement|null} */
let rootEl = null;
/** @type {object|null} */
let graph = null;
/** @type {WeakMap<HTMLElement, boolean>} */
const wired = new WeakMap();

function parseGraph(el) {
    const raw = el.getAttribute('data-graph') || '';
    if (! raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        console.error('[KeywordRelationship] graph JSON parse failed', error);
        return null;
    }
}

function renderSide(panel) {
    const side = document.querySelector(SIDE_SELECTOR);
    if (! side) {
        return;
    }
    if (! panel || typeof panel !== 'object') {
        side.innerHTML = '<p class="keyword-relationship-side__placeholder">Select a node.</p>';
        return;
    }
    const rows = Object.entries(panel)
        .filter(([, v]) => v !== null && v !== undefined && typeof v !== 'object')
        .map(([k, v]) => `<div class="keyword-relationship-side__row"><dt>${escapeHtml(k)}</dt><dd>${escapeHtml(String(v))}</dd></div>`)
        .join('');
    side.innerHTML = `<dl class="keyword-relationship-side__dl">${rows || '<p class="keyword-relationship-side__placeholder">No scalar fields.</p>'}</dl>`;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function buildOption(data) {
    const categories = Array.isArray(data?.categories) ? data.categories : [];
    const nodes = (Array.isArray(data?.nodes) ? data.nodes : []).map((n) => ({
        ...n,
        label: { show: true, formatter: '{b}' },
    }));
    const links = (Array.isArray(data?.edges) ? data.edges : []).map((e) => ({
        source: e.source,
        target: e.target,
        label: e.label || { show: false },
        lineStyle: { curveness: 0.12 },
    }));

    return {
        tooltip: {
            formatter(params) {
                if (params.dataType === 'edge') {
                    return params.data?.label?.formatter || '';
                }
                return params.data?.name || '';
            },
        },
        legend: categories.length
            ? [{ data: categories.map((c) => c.name), bottom: 0 }]
            : undefined,
        series: [
            {
                type: 'graph',
                layout: 'force',
                roam: true,
                draggable: false,
                categories,
                data: nodes,
                links,
                label: { position: 'right', fontSize: 11 },
                force: {
                    repulsion: 220,
                    edgeLength: [60, 140],
                    gravity: 0.08,
                },
                emphasis: { focus: 'adjacency' },
            },
        ],
    };
}

function ensureChart(el) {
    if (! chart) {
        chart = echarts.init(el);
        chart.on('click', (params) => {
            if (params.dataType !== 'node' || ! graph) {
                return;
            }
            const id = params.data?.id;
            const panels = graph.side_panels || {};
            renderSide(panels[id] || params.data);
        });
        window.addEventListener('resize', () => chart?.resize());
    }
    return chart;
}

function mount(el) {
    rootEl = el;
    graph = parseGraph(el);
    if (! graph) {
        return;
    }
    if (chart) {
        try {
            chart.dispose();
        } catch (e) {
            // ignore
        }
        chart = null;
    }
    const instance = ensureChart(el);
    instance.setOption(buildOption(graph), true);
    const center = graph.center;
    if (center && graph.side_panels?.[center]) {
        renderSide(graph.side_panels[center]);
    }
}

function boot() {
    document.querySelectorAll(ROOT_SELECTOR).forEach((el) => {
        const fp = el.getAttribute('data-graph') || '';
        const prev = el.getAttribute('data-kw-rel-fp');
        if (prev === fp && wired.get(el)) {
            return;
        }
        el.setAttribute('data-kw-rel-fp', fp);
        if (chart && rootEl && rootEl !== el) {
            try {
                chart.dispose();
            } catch (e) {
                // ignore
            }
            chart = null;
        }
        wired.set(el, true);
        mount(el);
    });
}

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    if (! window.Livewire) {
        return;
    }
    window.Livewire.hook('morphed', () => {
        requestAnimationFrame(boot);
    });
});

if (window.Livewire) {
    window.Livewire.on('keyword-relationship-filters-changed', () => {
        // Full Livewire re-render updates data-graph; remount on next morph.
        chart = null;
        wired.delete(rootEl);
    });
}
