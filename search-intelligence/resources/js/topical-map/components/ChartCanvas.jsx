import { useEffect, useImperativeHandle, useRef, forwardRef, useCallback } from 'react';
import * as echarts from 'echarts/core';
import { TreeChart, GraphChart, TreemapChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { buildTreeOption, buildTreemapOption, buildNetworkOption, formatTreemapMcpPercent } from '../charts/options';
import { topicStructureLabel } from '../charts/theme';
import { topicDetailUrl } from '../api/client';

echarts.use([
    TreeChart,
    GraphChart,
    TreemapChart,
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
    CanvasRenderer,
]);

const ZOOM_STEP = 1.2;
const ZOOM_MIN = 0.2;
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

/**
 * Full-flex ECharts canvas — wheel zoom, toolbar zoom API, ResizeObserver.
 */
const ChartCanvas = forwardRef(function ChartCanvas({
    overview,
    renderer,
    neighborhood,
    topicDetailUrlTemplate,
    focusedTopicId,
    onFocusTopic,
    preferredTagIds = [],
    siteDomain = '',
    untaggedBucketLabel = '',
    untaggedBucketTooltip = '',
    onZoomChange,
    meta,
}, ref) {
    const hostRef = useRef(null);
    const chartRef = useRef(null);
    const zoomRef = useRef(1);
    const onZoomChangeRef = useRef(onZoomChange);
    onZoomChangeRef.current = onZoomChange;
    const lastRendererRef = useRef(null);
    const applyZoomFactorRef = useRef(() => {});

    const propsRef = useRef({});
    propsRef.current = {
        renderer,
        topicDetailUrlTemplate,
        onFocusTopic,
        preferredTagIds,
        siteDomain,
        untaggedBucketLabel,
        untaggedBucketTooltip,
        topicCount: Array.isArray(overview?.topics) ? overview.topics.length : 0,
    };

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
        const mode = propsRef.current.renderer;
        // Treemap is a fixed overview — no scale / roam.
        if (mode === 'treemap') {
            return;
        }
        const current = readSeriesZoom(chart);
        const next = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, current * factor));
        const center = (() => {
            try {
                const opt = chart.getOption();
                return opt?.series?.[0]?.center;
            } catch {
                return undefined;
            }
        })();

        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', zoom: next, center }],
            });
        } else if (mode === 'network') {
            chart.setOption({
                series: [{ id: 'topical-map-network', zoom: next, center }],
            });
        }
        emitZoom(next);
    }, [emitZoom]);
    applyZoomFactorRef.current = applyZoomFactor;

    const resetZoom = useCallback(() => {
        const chart = chartRef.current;
        if (!chart) {
            return;
        }
        const mode = propsRef.current.renderer;
        // Treemap has no zoom/drill state — Fit is a no-op (toolbar hidden).
        if (mode === 'treemap') {
            return;
        }
        // Structure BT overview: FIT = full taxonomy at zoom 1 (not leaf-count shrink).
        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', zoom: 1, center: undefined }],
            });
            emitZoom(1);
            return;
        }
        if (mode === 'network') {
            // FIT = full Site → Topic → DNA overview (no Topic auto-focus).
            chart.setOption({
                series: [{ id: 'topical-map-network', zoom: 1, center: undefined }],
            });
            emitZoom(1);
        }
    }, [emitZoom]);

    useImperativeHandle(ref, () => ({
        zoomIn: () => applyZoomFactor(ZOOM_STEP),
        zoomOut: () => applyZoomFactor(1 / ZOOM_STEP),
        resetZoom,
        getZoom: () => zoomRef.current,
        supportsZoom: () => propsRef.current.renderer !== 'treemap',
        supportsFit: () => propsRef.current.renderer !== 'treemap',
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
            const mode = propsRef.current.renderer;
            // Treemap: fixed overview — no wheel zoom; keep blocking page scroll over canvas.
            if (mode === 'treemap') {
                event.preventDefault();
                return;
            }
            // Structure / Network: host-level zoom anywhere inside `.tm-chart-canvas`
            // (including empty whitespace). Native roam is pan-only (`roam: 'move'`).
            if (mode !== 'tree' && mode !== 'network') {
                return;
            }
            if (event.deltaY === 0) {
                return;
            }
            event.preventDefault();
            const factor = event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP;
            applyZoomFactorRef.current(factor);
        };
        el.addEventListener('wheel', onWheel, { passive: false });

        const syncZoomFromEvent = () => {
            emitZoom(readSeriesZoom(chart));
        };
        chart.on('treeroam', syncZoomFromEvent);
        chart.on('graphroam', syncZoomFromEvent);

        const openTopicBlank = (topicId) => {
            const id = Number(topicId);
            if (!Number.isFinite(id) || id <= 0) {
                return;
            }
            propsRef.current.onFocusTopic?.(id);
            const url = topicDetailUrl(propsRef.current.topicDetailUrlTemplate, id);
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
            }
        };

        const onClick = (params) => {
            const data = params?.data || {};
            const latest = propsRef.current;

            // Treemap: fixed overview — single click does not navigate / drill / zoom.
            // Double-click opens Topic via dblclick handler.
            if (latest.renderer === 'treemap') {
                return;
            }

            // Network: Site click = FIT only; DNA = informational (no nav); Topic = open blank.
            if (latest.renderer === 'network') {
                if (data.nodeType === 'site') {
                    resetZoom();
                    return;
                }
                if (data.nodeType === 'dna') {
                    return;
                }
                if (data.nodeType === 'topic' && data.topicId) {
                    openTopicBlank(data.topicId);
                }
                return;
            }

            // Structure (view=tree): Tag/Site clicks do nothing; Topic opens detail.
            if (latest.renderer === 'tree') {
                if (data.nodeType !== 'topic' || !data.topicId) {
                    return;
                }
                openTopicBlank(data.topicId);
            }
        };

        const onDblClick = (params) => {
            const data = params?.data || {};
            // Structure / Network open on single click — avoid duplicate tab on dblclick.
            if (propsRef.current.renderer === 'tree' || propsRef.current.renderer === 'network') {
                return;
            }
            if (data.nodeType !== 'topic' || !data.topicId) {
                return;
            }
            openTopicBlank(data.topicId);
        };

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
            chart.off('treeroam', syncZoomFromEvent);
            chart.off('graphroam', syncZoomFromEvent);
            chart.dispose();
            chartRef.current = null;
        };
    }, [emitZoom, resetZoom]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !overview) {
            return;
        }

        const rendererChanged = lastRendererRef.current !== renderer;
        lastRendererRef.current = renderer;

        if (renderer === 'network') {
            const option = buildNetworkOption(neighborhood || { nodes: [], links: [] }, {
                siteDomain: propsRef.current.siteDomain,
            });
            if (rendererChanged) {
                zoomRef.current = 1;
                onZoomChangeRef.current?.(1);
                chart.clear();
                chart.setOption(option, true);
            } else {
                // Soft update — keep instance; force layoutAnimation is off.
                chart.setOption(option, { notMerge: true, lazyUpdate: false });
            }
            return;
        }

        zoomRef.current = 1;
        onZoomChangeRef.current?.(1);

        if (renderer === 'treemap') {
            chart.clear();
            chart.setOption(buildTreemapOption(overview), true);
            chart.resize();
            return;
        }

        chart.clear();
        chart.setOption(buildTreeOption(overview, {
            preferredTagIds: propsRef.current.preferredTagIds,
            siteDomain: propsRef.current.siteDomain,
            untaggedBucketLabel: propsRef.current.untaggedBucketLabel,
            untaggedBucketTooltip: propsRef.current.untaggedBucketTooltip,
        }), true);
    }, [
        overview,
        renderer,
        neighborhood,
        preferredTagIds,
        siteDomain,
        untaggedBucketLabel,
        untaggedBucketTooltip,
    ]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !focusedTopicId || !overview?.topics || renderer === 'network' || renderer === 'treemap') {
            return;
        }
        const topic = overview.topics.find((row) => Number(row.id) === Number(focusedTopicId));
        if (!topic) {
            return;
        }
        try {
            chart.dispatchAction({
                type: 'highlight',
                seriesIndex: 0,
                name: topicStructureLabel(topic, formatTreemapMcpPercent),
            });
        } catch {
            // ignore
        }
    }, [focusedTopicId, overview, renderer]);

    return (
        <div className="tm-chart-shell">
            <div ref={hostRef} className="tm-chart-canvas" data-renderer={renderer} />
            {meta ? <p className="tm-chart-meta">{meta}</p> : null}
        </div>
    );
});

export default ChartCanvas;
