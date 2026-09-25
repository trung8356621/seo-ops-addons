import { useEffect, useImperativeHandle, useRef, forwardRef, useCallback } from 'react';
import * as echarts from 'echarts/core';
import { TreeChart, GraphChart, TreemapChart } from 'echarts/charts';
import {
    TooltipComponent,
    LegendComponent,
    ToolboxComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { buildTreeOption, buildTreemapOption, buildNetworkOption, buildStructureTypographyPatch, buildNetworkTypographyPatch, formatTreemapMcpPercent } from '../charts/options';
import { topicStructureLabel, getChartTypographyBand, getStructureTypographyBand } from '../charts/theme';
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
const STRUCTURE_ZOOM_MAX = 4;
const NETWORK_ZOOM_MAX = 12;

function zoomMaxForMode(mode) {
    return mode === 'network' ? NETWORK_ZOOM_MAX : STRUCTURE_ZOOM_MAX;
}

/**
 * Full-flex ECharts canvas — wheel zoom (RAF-batched), toolbar zoom API, ResizeObserver.
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
    const centerRef = useRef(undefined);
    const typographyBandRef = useRef(getStructureTypographyBand(1));
    const pendingZoomFactorRef = useRef(1);
    const wheelRafRef = useRef(null);
    const onZoomChangeRef = useRef(onZoomChange);
    onZoomChangeRef.current = onZoomChange;
    const lastRendererRef = useRef(null);
    const lastFocusedTopicIdRef = useRef(null);
    const applyZoomFactorRef = useRef(() => {});

    const propsRef = useRef({});
    propsRef.current = {
        renderer,
        topicDetailUrlTemplate,
        onFocusTopic,
        focusedTopicId,
        preferredTagIds,
        siteDomain,
        untaggedBucketLabel,
        untaggedBucketTooltip,
        topicCount: Array.isArray(overview?.topics) ? overview.topics.length : 0,
    };

    const emitZoom = useCallback((zoom) => {
        const next = Math.max(
            ZOOM_MIN,
            Math.min(zoomMaxForMode(propsRef.current.renderer), zoom),
        );
        zoomRef.current = next;
        onZoomChangeRef.current?.(next);
    }, []);

    /**
     * Apply Structure/Network label typography only when the discrete zoom band changes.
     * Treemap is excluded. Never rebuilds graph data.
     * Structure uses its own band scale (xlarge @ 400%); Network keeps approved bands.
     *
     * @param {number} zoom
     * @param {object} [intoPatch] optional series patch object to merge into
     * @returns {boolean} whether band changed (and patch was filled)
     */
    const applyTypographyBandIfNeeded = useCallback((zoom, intoPatch = null) => {
        const mode = propsRef.current.renderer;
        if (mode === 'treemap') {
            return false;
        }
        const band = mode === 'tree'
            ? getStructureTypographyBand(zoom)
            : getChartTypographyBand(zoom);
        if (band === typographyBandRef.current) {
            return false;
        }
        typographyBandRef.current = band;
        const typoPatch = mode === 'tree'
            ? buildStructureTypographyPatch(band)
            : buildNetworkTypographyPatch(band, {
                focused: Boolean(propsRef.current.focusedTopicId),
            });
        if (intoPatch && typeof intoPatch === 'object') {
            Object.assign(intoPatch, typoPatch);
            return true;
        }
        const chart = chartRef.current;
        if (!chart) {
            return true;
        }
        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', ...typoPatch }],
            });
        } else if (mode === 'network') {
            chart.setOption({
                series: [{ id: 'topical-map-network', ...typoPatch }],
            });
        }
        return true;
    }, []);

    /**
     * Zoom from zoomRef (not chart.getOption). Toolbar + RAF-batched wheel share this path.
     */
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
        const current = zoomRef.current;
        const next = Math.max(
            ZOOM_MIN,
            Math.min(zoomMaxForMode(mode), current * factor),
        );
        if (next === current) {
            return;
        }

        const patch = { zoom: next };
        if (centerRef.current !== undefined) {
            patch.center = centerRef.current;
        }
        applyTypographyBandIfNeeded(next, patch);

        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', ...patch }],
            });
        } else if (mode === 'network') {
            chart.setOption({
                series: [{ id: 'topical-map-network', ...patch }],
            });
        }
        emitZoom(next);
    }, [emitZoom, applyTypographyBandIfNeeded]);
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
        centerRef.current = undefined;
        const patch = { zoom: 1, center: undefined };
        // Force overview typography even if band string already matches (stale merge).
        typographyBandRef.current = '';
        applyTypographyBandIfNeeded(1, patch);
        // Structure BT overview: FIT = full taxonomy at zoom 1 (not leaf-count shrink).
        if (mode === 'tree') {
            chart.setOption({
                series: [{ id: 'topical-map-tree', ...patch }],
            });
            emitZoom(1);
            return;
        }
        if (mode === 'network') {
            // FIT = full Site → Topic → DNA overview (no Topic auto-focus).
            chart.setOption({
                series: [{ id: 'topical-map-network', ...patch }],
            });
            emitZoom(1);
        }
    }, [emitZoom, applyTypographyBandIfNeeded]);

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

        const flushPendingWheelZoom = () => {
            wheelRafRef.current = null;
            const pending = pendingZoomFactorRef.current;
            pendingZoomFactorRef.current = 1;
            if (pending === 1) {
                return;
            }
            applyZoomFactorRef.current(pending);
        };

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
            pendingZoomFactorRef.current *= factor;
            if (wheelRafRef.current != null) {
                return;
            }
            wheelRafRef.current = requestAnimationFrame(flushPendingWheelZoom);
        };
        el.addEventListener('wheel', onWheel, { passive: false });

        // Pan via ECharts roam is infrequent — sync zoom/center from option once per roam.
        // Typography only updates when the discrete zoom band changes (not on pure pan).
        const syncRoamFromEvent = () => {
            try {
                const opt = chart.getOption();
                const series = Array.isArray(opt?.series) ? opt.series[0] : null;
                const z = Number(series?.zoom ?? 1);
                if (Number.isFinite(z) && z > 0) {
                    emitZoom(z);
                    applyTypographyBandIfNeeded(z);
                }
                if (series?.center !== undefined) {
                    centerRef.current = series.center;
                }
            } catch {
                // ignore
            }
        };
        chart.on('treeroam', syncRoamFromEvent);
        chart.on('graphroam', syncRoamFromEvent);

        const openTopicBlank = (topicId) => {
            const id = Number(topicId);
            if (!Number.isFinite(id) || id <= 0) {
                return;
            }
            // Open detail only — must NOT set Network focus state.
            const url = topicDetailUrl(propsRef.current.topicDetailUrlTemplate, id);
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
            }
        };

        const focusNetworkTopic = (topicId) => {
            const id = Number(topicId);
            if (!Number.isFinite(id) || id <= 0) {
                return;
            }
            // No-op when already focused on this Topic.
            if (Number(propsRef.current.focusedTopicId) === id) {
                return;
            }
            propsRef.current.onFocusTopic?.(id);
        };

        const clearNetworkFocus = () => {
            if (propsRef.current.focusedTopicId == null) {
                return;
            }
            propsRef.current.onFocusTopic?.(null);
        };

        /** Delay single-click focus so dblclick can cancel it. */
        const NETWORK_CLICK_DELAY_MS = 220;
        let networkClickTimer = null;

        const onClick = (params) => {
            const data = params?.data || {};
            const latest = propsRef.current;

            // Treemap: fixed overview — single click does not navigate / drill / zoom.
            // Double-click opens Topic via dblclick handler.
            if (latest.renderer === 'treemap') {
                return;
            }

            // Network: Topic single-click = focus; Site (when focused) = back; DNA = no-op.
            if (latest.renderer === 'network') {
                if (data.nodeType === 'site') {
                    clearNetworkFocus();
                    return;
                }
                if (data.nodeType === 'dna') {
                    return;
                }
                if (data.nodeType === 'topic' && data.topicId) {
                    if (networkClickTimer != null) {
                        clearTimeout(networkClickTimer);
                    }
                    const tid = data.topicId;
                    networkClickTimer = setTimeout(() => {
                        networkClickTimer = null;
                        focusNetworkTopic(tid);
                    }, NETWORK_CLICK_DELAY_MS);
                }
                return;
            }

            // Structure (view=tree): Tag/Site clicks do nothing; Topic opens detail.
            if (latest.renderer === 'tree') {
                if (data.nodeType !== 'topic' || !data.topicId) {
                    return;
                }
                // Structure highlight helper (separate from Network focus semantics).
                latest.onFocusTopic?.(Number(data.topicId));
                openTopicBlank(data.topicId);
            }
        };

        const onDblClick = (params) => {
            const data = params?.data || {};
            const latest = propsRef.current;

            // Network: double-click Topic opens detail; cancel pending single-click focus.
            if (latest.renderer === 'network') {
                if (networkClickTimer != null) {
                    clearTimeout(networkClickTimer);
                    networkClickTimer = null;
                }
                if (data.nodeType === 'dna' || data.nodeType === 'site') {
                    return;
                }
                if (data.nodeType === 'topic' && data.topicId) {
                    openTopicBlank(data.topicId);
                }
                return;
            }

            // Structure opens on single click — avoid duplicate tab on dblclick.
            if (latest.renderer === 'tree') {
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
            if (networkClickTimer != null) {
                clearTimeout(networkClickTimer);
                networkClickTimer = null;
            }
            if (wheelRafRef.current != null) {
                cancelAnimationFrame(wheelRafRef.current);
                wheelRafRef.current = null;
            }
            pendingZoomFactorRef.current = 1;
            el.removeEventListener('wheel', onWheel);
            window.removeEventListener('resize', onWindowResize);
            ro?.disconnect();
            chart.off('click', onClick);
            chart.off('dblclick', onDblClick);
            chart.off('treeroam', syncRoamFromEvent);
            chart.off('graphroam', syncRoamFromEvent);
            chart.dispose();
            chartRef.current = null;
        };
    }, [emitZoom, resetZoom, applyTypographyBandIfNeeded]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !overview) {
            return;
        }

        const rendererChanged = lastRendererRef.current !== renderer;
        lastRendererRef.current = renderer;

        if (renderer === 'network') {
            if (rendererChanged) {
                const initialZoom = focusedTopicId ? 1 : 4;
                zoomRef.current = initialZoom;
                centerRef.current = undefined;
                lastFocusedTopicIdRef.current = focusedTopicId;
                typographyBandRef.current = getChartTypographyBand(initialZoom);
                onZoomChangeRef.current?.(initialZoom);
                chart.clear();
                chart.setOption(buildNetworkOption(neighborhood || { nodes: [], links: [] }, {
                    siteDomain: propsRef.current.siteDomain,
                    typographyBand: typographyBandRef.current,
                    focused: Boolean(focusedTopicId),
                    zoom: initialZoom,
                }), true);
            } else {
                // Focus enter/exit: re-center on the (new) fixed layout — no pan remnant.
                const focusChanged = lastFocusedTopicIdRef.current !== focusedTopicId;
                lastFocusedTopicIdRef.current = focusedTopicId;
                if (focusChanged) {
                    zoomRef.current = 1;
                    centerRef.current = undefined;
                    onZoomChangeRef.current?.(1);
                }
                const band = getChartTypographyBand(zoomRef.current);
                // Soft replace graph data — fixed coords; restore zoom/center + typography after notMerge.
                chart.setOption(buildNetworkOption(neighborhood || { nodes: [], links: [] }, {
                    siteDomain: propsRef.current.siteDomain,
                    typographyBand: band,
                    focused: Boolean(focusedTopicId),
                    zoom: zoomRef.current,
                }), { notMerge: true, lazyUpdate: false });
                typographyBandRef.current = band;
                const z = zoomRef.current;
                const zoomPatch = {
                    id: 'topical-map-network',
                    zoom: z,
                    center: centerRef.current,
                    ...buildNetworkTypographyPatch(band, {
                        focused: Boolean(focusedTopicId),
                    }),
                };
                chart.setOption({ series: [zoomPatch] });
            }
            return;
        }

        zoomRef.current = 1;
        centerRef.current = undefined;
        typographyBandRef.current = renderer === 'tree'
            ? getStructureTypographyBand(1)
            : getChartTypographyBand(1);
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
            typographyBand: getStructureTypographyBand(1),
        }), true);
    }, [
        overview,
        renderer,
        neighborhood,
        focusedTopicId,
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
