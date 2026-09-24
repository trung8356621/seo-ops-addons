import { useEffect, useRef } from 'react';
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

/**
 * Full-flex ECharts canvas — wheel zoom, ResizeObserver, click/dblclick disambiguation.
 */
export default function ChartCanvas({
    overview,
    renderer,
    neighborhood,
    childrenCache,
    childrenCacheVersion = 0,
    topicDetailUrlTemplate,
    focusedTopicId,
    onFocusTopic,
    onLoadChildren,
    onNetworkFocus,
    meta,
}) {
    const hostRef = useRef(null);
    const chartRef = useRef(null);
    const clickRef = useRef({ topicId: null, at: 0 });
    const childrenCacheRef = useRef(childrenCache);
    childrenCacheRef.current = childrenCache;

    const propsRef = useRef({});
    propsRef.current = {
        renderer,
        topicDetailUrlTemplate,
        onFocusTopic,
        onLoadChildren,
        onNetworkFocus,
    };

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

        const onClick = async (params) => {
            const data = params?.data || {};
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

            const latest = propsRef.current;
            latest.onFocusTopic?.(topicId);

            if (latest.renderer === 'tree' || latest.renderer === 'sunburst') {
                await latest.onLoadChildren?.(topicId);
            }
            if (latest.renderer === 'network') {
                await latest.onNetworkFocus?.(topicId);
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
            chart.dispose();
            chartRef.current = null;
        };
    }, []);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !overview) {
            return;
        }

        if (renderer === 'network') {
            chart.clear();
            chart.setOption(buildNetworkOption(neighborhood || { nodes: [], links: [] }), true);
            return;
        }

        if (renderer === 'sunburst') {
            chart.clear();
            chart.setOption(buildSunburstOption(overview, childrenCacheRef.current), true);
            return;
        }

        chart.clear();
        chart.setOption(buildTreeOption(overview, childrenCacheRef.current), true);
    }, [overview, renderer, neighborhood, childrenCache, childrenCacheVersion]);

    useEffect(() => {
        const chart = chartRef.current;
        if (!chart || !focusedTopicId || !overview?.topics) {
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
    }, [focusedTopicId, overview]);

    return (
        <div className="tm-chart-shell">
            <div ref={hostRef} className="tm-chart-canvas" data-renderer={renderer} />
            {meta ? <p className="tm-chart-meta">{meta}</p> : null}
        </div>
    );
}
