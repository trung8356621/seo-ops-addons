import { useEffect, useImperativeHandle, useRef, forwardRef, useCallback } from 'react';
import * as echarts from 'echarts/core';
import { TreeChart, GraphChart, SunburstChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { buildTreeOption, buildSunburstOption, buildNetworkOption } from '../charts/options';
import { topicDetailUrl } from '../api/client';

echarts.use([
    TreeChart,
    GraphChart,
    SunburstChart,
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
    CanvasRenderer,
]);

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

/**
 * Full-flex ECharts canvas — wheel zoom, toolbar zoom API, ResizeObserver.
 */
const ChartCanvas = forwardRef(function ChartCanvas({
    overview,
    renderer,
    neighborhood,
    childrenCache,
    childrenCacheVersion = 0,
    topicDetailUrlTemplate,
    focusedTopicId,
    onFocusTopic,
    onLoadChildren,
    onNetworkTopicClick,
    onNetworkSiteClick,
    networkFocused = false,
    networkPendingTopicId = null,
    onZoomChange,
    meta,
}, ref) {
    const hostRef = useRef(null);
    const chartRef = useRef(null);
    const clickRef = useRef({ topicId: null, at: 0 });
    const childrenCacheRef = useRef(childrenCache);
    childrenCacheRef.current = childrenCache;
    const zoomRef = useRef(1);
    const onZoomChangeRef = useRef(onZoomChange);
    onZoomChangeRef.current = onZoomChange;
    const lastRendererRef = useRef(null);

    const propsRef = useRef({});
    propsRef.current = {
        renderer,
        topicDetailUrlTemplate,
        onFocusTopic,
        onLoadChildren,
        onNetworkTopicClick,
        onNetworkSiteClick,
        networkFocused,
        networkPendingTopicId,
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
        if (mode === 'sunburst') {
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

    const resetZoom = useCallback(() => {
        const chart = chartRef.current;
        if (!chart) {
            return;
        }
        const mode = propsRef.current.renderer;
        if (mode === 'sunburst') {
            emitZoom(1);
            return;
        }
        const topicCount = Math.max(1, Number(propsRef.current.topicCount) || 1);
        const fitZoom = Math.max(ZOOM_MIN, Math.min(1, 12 / topicCount));
        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', zoom: fitZoom, center: undefined }],
            });
        } else if (mode === 'network') {
            chart.setOption({
                series: [{ id: 'topical-map-network', zoom: 1, center: undefined }],
            });
            emitZoom(1);
            return;
        }
        emitZoom(fitZoom);
    }, [emitZoom]);

    useImperativeHandle(ref, () => ({
        zoomIn: () => applyZoomFactor(ZOOM_STEP),
        zoomOut: () => applyZoomFactor(1 / ZOOM_STEP),
        resetZoom,
        getZoom: () => zoomRef.current,
        supportsZoom: () => propsRef.current.renderer !== 'sunburst',
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
        chart.on('treeroam', syncZoomFromEvent);
        chart.on('graphroam', syncZoomFromEvent);

        const onClick = async (params) => {
            const data = params?.data || {};
            const latest = propsRef.current;

            if (latest.renderer === 'network' && data.nodeType === 'site') {
                latest.onNetworkSiteClick?.();
                return;
            }

            if (data.nodeType !== 'topic' || !data.topicId) {
                return;
            }
            const topicId = Number(data.topicId);
            const now = Date.now();
            const isDouble = clickRef.current.topicId === topicId && (now - clickRef.current.at) < 350;
            clickRef.current = { topicId, at: now };
            if (isDouble) {
                return;
            }

            latest.onFocusTopic?.(topicId);

            if (latest.renderer === 'tree' || latest.renderer === 'sunburst') {
                await latest.onLoadChildren?.(topicId);
            }
            if (latest.renderer === 'network') {
                latest.onNetworkTopicClick?.(topicId);
            }
        };

        const onDblClick = (params) => {
            const data = params?.data || {};
            if (data.nodeType !== 'topic' || !data.topicId) {
                return;
            }
            const url = topicDetailUrl(propsRef.current.topicDetailUrlTemplate, Number(data.topicId));
            if (!url) {
                return;
            }
            window.open(url, '_blank', 'noopener,noreferrer');
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
    }, [emitZoom]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !overview) {
            return;
        }

        const rendererChanged = lastRendererRef.current !== renderer;
        lastRendererRef.current = renderer;

        if (renderer === 'network') {
            const option = buildNetworkOption(neighborhood || { nodes: [], links: [] }, {
                focused: networkFocused,
                pendingTopicId: networkPendingTopicId,
                siteNavigable: networkFocused || networkPendingTopicId != null,
            });
            if (rendererChanged) {
                zoomRef.current = 1;
                onZoomChangeRef.current?.(1);
                chart.clear();
                chart.setOption(option, true);
            } else {
                // Soft update — keep instance, enable update animation / node id continuity.
                chart.setOption(option, { notMerge: true, lazyUpdate: false });
            }
            return;
        }

        zoomRef.current = 1;
        onZoomChangeRef.current?.(1);

        if (renderer === 'sunburst') {
            chart.clear();
            chart.setOption(buildSunburstOption(overview, childrenCacheRef.current), true);
            return;
        }

        chart.clear();
        chart.setOption(buildTreeOption(overview, childrenCacheRef.current), true);
    }, [
        overview,
        renderer,
        neighborhood,
        childrenCache,
        childrenCacheVersion,
        networkFocused,
        networkPendingTopicId,
    ]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !focusedTopicId || !overview?.topics || renderer === 'network') {
            return;
        }
        const topic = overview.topics.find((row) => Number(row.id) === Number(focusedTopicId));
        if (!topic) {
            return;
        }
        try {
            chart.dispatchAction({ type: 'highlight', seriesIndex: 0, name: topic.name });
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
