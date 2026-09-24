import { useEffect, useImperativeHandle, useRef, forwardRef, useCallback, useState } from 'react';
import * as echarts from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import { TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { topicDetailUrl } from '../api/client';
import {
    articleEditUrl,
    relationshipUrl,
} from '../state/relationshipFilters';

echarts.use([GraphChart, TooltipComponent, LegendComponent, CanvasRenderer]);

const ZOOM_STEP = 1.2;
const ZOOM_MIN = 0.35;
const ZOOM_MAX = 4;

function readSeriesZoom(chart) {
    try {
        const opt = chart.getOption();
        const series = Array.isArray(opt?.series) ? opt.series[0] : null;
        const z = Number(series?.zoom ?? 1);
        return Number.isFinite(z) && z > 0 ? z : 1;
    } catch {
        return 1;
    }
}

function buildOption(graph) {
    const categories = Array.isArray(graph?.categories) ? graph.categories : [];
    const nodes = (Array.isArray(graph?.nodes) ? graph.nodes : []).map((n) => ({
        ...n,
        label: { show: true, formatter: '{b}' },
    }));
    const links = (Array.isArray(graph?.edges) ? graph.edges : []).map((e) => ({
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
                id: 'keyword-relationship-graph',
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

function scalarRows(panel) {
    if (!panel || typeof panel !== 'object') {
        return [];
    }
    return Object.entries(panel).filter(
        ([, v]) => v !== null && v !== undefined && typeof v !== 'object',
    );
}

/**
 * Relationship force-graph canvas — overlay details, no permanent sidebar.
 */
const RelationshipChart = forwardRef(function RelationshipChart({
    graph,
    topicDetailUrlTemplate,
    articleEditUrlTemplate,
    relationshipUrlTemplate,
    labels,
    onZoomChange,
}, ref) {
    const hostRef = useRef(null);
    const chartRef = useRef(null);
    const zoomRef = useRef(1);
    const graphRef = useRef(graph);
    graphRef.current = graph;
    const onZoomChangeRef = useRef(onZoomChange);
    onZoomChangeRef.current = onZoomChange;
    const navRef = useRef({
        topicDetailUrlTemplate,
        articleEditUrlTemplate,
        relationshipUrlTemplate,
    });
    navRef.current = {
        topicDetailUrlTemplate,
        articleEditUrlTemplate,
        relationshipUrlTemplate,
    };

    const [detail, setDetail] = useState(null);

    const emitZoom = useCallback((zoom) => {
        const next = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom));
        zoomRef.current = next;
        onZoomChangeRef.current?.(next);
    }, []);

    const applyZoomFactor = useCallback((factor) => {
        const chart = chartRef.current;
        if (!chart) {
            return;
        }
        const current = readSeriesZoom(chart);
        const next = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, current * factor));
        chart.setOption({
            series: [{ id: 'keyword-relationship-graph', zoom: next }],
        });
        emitZoom(next);
    }, [emitZoom]);

    const resetZoom = useCallback(() => {
        const chart = chartRef.current;
        if (!chart) {
            return;
        }
        chart.setOption({
            series: [{ id: 'keyword-relationship-graph', zoom: 1, center: undefined }],
        });
        emitZoom(1);
    }, [emitZoom]);

    useImperativeHandle(ref, () => ({
        zoomIn: () => applyZoomFactor(ZOOM_STEP),
        zoomOut: () => applyZoomFactor(1 / ZOOM_STEP),
        resetZoom,
        getZoom: () => zoomRef.current,
    }), [applyZoomFactor, resetZoom]);

    useEffect(() => {
        const el = hostRef.current;
        if (!el) {
            return undefined;
        }

        const chart = echarts.init(el, undefined, { renderer: 'canvas' });
        chartRef.current = chart;

        const onWheel = (event) => {
            if (!el.contains(event.target)) {
                return;
            }
            event.preventDefault();
        };
        el.addEventListener('wheel', onWheel, { passive: false });

        const syncZoomFromEvent = () => {
            emitZoom(readSeriesZoom(chart));
        };
        chart.on('graphroam', syncZoomFromEvent);

        const onClick = (params) => {
            if (params.dataType !== 'node') {
                setDetail(null);
                return;
            }
            const id = params.data?.id;
            const panels = graphRef.current?.side_panels || {};
            setDetail({
                id,
                kind: params.data?.kind,
                name: params.data?.name,
                panel: panels[id] || params.data || null,
            });
        };

        const onDblClick = (params) => {
            if (params.dataType !== 'node') {
                return;
            }
            const data = params.data || {};
            const kind = String(data.kind || '');
            const panels = graphRef.current?.side_panels || {};
            const panel = panels[data.id] || {};

            if (kind === 'topic') {
                const tid = Number(panel.id || String(data.id || '').replace(/^topic:/, '') || 0);
                const url = topicDetailUrl(navRef.current.topicDetailUrlTemplate, tid);
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                return;
            }

            if (kind === 'article') {
                const aid = Number(
                    panel.article_id || String(data.id || '').replace(/^article:/, '') || 0,
                );
                const url = articleEditUrl(navRef.current.articleEditUrlTemplate, aid);
                if (url && aid > 0) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                return;
            }

            if (kind === 'related_keyword') {
                const kid = Number(
                    panel.id || String(data.id || '').replace(/^keyword:/, '') || 0,
                );
                const url = relationshipUrl(navRef.current.relationshipUrlTemplate, kid);
                if (url && kid > 0) {
                    window.location.href = url;
                }
            }
        };

        chart.getZr().on('click', (e) => {
            if (!e.target) {
                setDetail(null);
            }
        });

        chart.on('click', onClick);
        chart.on('dblclick', onDblClick);

        const ro = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(() => {
                chart.resize();
            })
            : null;
        ro?.observe(el);

        const onWindowResize = () => chart.resize();
        window.addEventListener('resize', onWindowResize);

        return () => {
            el.removeEventListener('wheel', onWheel);
            window.removeEventListener('resize', onWindowResize);
            ro?.disconnect();
            chart.off('click', onClick);
            chart.off('dblclick', onDblClick);
            chart.off('graphroam', syncZoomFromEvent);
            try {
                chart.getZr().off('click');
            } catch {
                // ignore
            }
            chart.dispose();
            chartRef.current = null;
        };
    }, [emitZoom]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !graph) {
            return;
        }
        chart.setOption(buildOption(graph), true);
        setDetail(null);
        emitZoom(1);
    }, [graph, emitZoom]);

    const rows = scalarRows(detail?.panel);

    return (
        <div className="tm-chart-shell">
            <div
                ref={hostRef}
                className="tm-chart-canvas"
                data-renderer="relationship"
            />
            {detail ? (
                <div className="tm-rel-overlay" role="dialog" aria-label={labels.details || 'Details'}>
                    <div className="tm-rel-overlay__head">
                        <strong>{detail.name || labels.details}</strong>
                        <button
                            type="button"
                            className="tm-btn tm-btn--ghost"
                            onClick={() => setDetail(null)}
                        >
                            {labels.closeDetails || 'Close'}
                        </button>
                    </div>
                    <dl className="tm-rel-overlay__dl">
                        {rows.length === 0 ? (
                            <p className="tm-rel-overlay__empty">No scalar fields.</p>
                        ) : (
                            rows.map(([k, v]) => (
                                <div key={k} className="tm-rel-overlay__row">
                                    <dt>{k}</dt>
                                    <dd>{String(v)}</dd>
                                </div>
                            ))
                        )}
                    </dl>
                </div>
            ) : null}
        </div>
    );
});

export default RelationshipChart;
