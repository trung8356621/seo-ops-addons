import { useEffect, useImperativeHandle, useRef, forwardRef, useMemo, useState } from 'react';
import * as echarts from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import {
    buildSiteNetworkGraph,
    edgeSelectionKey,
    siteNetworkEmptyReason,
    sourceTopicsFromPair,
} from '../charts/siteNetworkGraph';

echarts.use([GraphChart, TooltipComponent, CanvasRenderer]);

const ZOOM_STEP = 1.2;

function emptyCopy(reason, labels) {
    if (reason === 'no_accessible_sites') {
        return labels.siteNetworkEmptyNone || 'No accessible sites. The site network stays empty.';
    }
    if (reason === 'single_site') {
        return labels.siteNetworkEmptySingle || 'Only one managed site is accessible, so there is no cross-site direction to draw.';
    }
    return labels.siteNetworkEmptyLinks || 'No managed cross-site relationships yet.';
}

function buildOption(graph, selectedKey, theme) {
    const dark = theme === 'dark';
    const text = dark ? '#e5e7eb' : '#0f172a';
    const muted = dark ? '#94a3b8' : '#64748b';
    const nodeFill = dark ? '#312e81' : '#e0e7ff';
    const nodeBorder = dark ? '#a5b4fc' : '#4f46e5';
    const edge = dark ? '#94a3b8' : '#64748b';
    const active = '#4f46e5';

    return {
        backgroundColor: 'transparent',
        animation: false,
        tooltip: {
            confine: true,
            trigger: 'item',
            formatter(params) {
                if (params.dataType !== 'edge') {
                    return params.data?.name || '';
                }
                const data = params.data || {};
                const sourceName = graph.nodes.find((n) => n.id === data.sourceSiteRef)?.name || data.sourceSiteRef;
                const targetName = graph.nodes.find((n) => n.id === data.targetSiteRef)?.name || data.targetSiteRef;
                const lines = [
                    `${sourceName} → ${targetName}`,
                    `${data.article_link_count ?? 0} article links`,
                    `${data.source_article_count ?? 0} source articles`,
                    `${data.target_article_count ?? 0} target articles`,
                    `${data.source_keyword_count ?? 0} source keywords`,
                ];
                if ((data.target_article_count ?? 0) === 0 && (data.article_link_count ?? 0) > 0) {
                    lines.push('Target articles unresolved. This direction still counts.');
                }
                return lines.join('<br/>');
            },
        },
        series: [
            {
                id: 'site-network-graph',
                type: 'graph',
                layout: 'none',
                roam: 'move',
                draggable: false,
                edgeSymbol: ['none', 'arrow'],
                edgeSymbolSize: [0, 12],
                label: {
                    show: true,
                    position: 'bottom',
                    color: text,
                    fontSize: 12,
                },
                data: graph.nodes.map((node) => ({
                    ...node,
                    itemStyle: {
                        color: nodeFill,
                        borderColor: nodeBorder,
                        borderWidth: 1.5,
                    },
                })),
                links: graph.links.map((link) => {
                    const selected = link.selectionKey === selectedKey;
                    return {
                        ...link,
                        label: {
                            show: true,
                            formatter: String(link.article_link_count),
                            color: selected ? active : muted,
                            fontSize: 11,
                        },
                        lineStyle: {
                            ...link.lineStyle,
                            color: selected ? active : edge,
                            opacity: 1,
                        },
                    };
                }),
                emphasis: { focus: 'adjacency' },
            },
        ],
    };
}

const SiteNetworkView = forwardRef(function SiteNetworkView({
    api,
    labels,
    theme,
    currentSiteId,
    onEnterTopic,
}, ref) {
    const hostRef = useRef(null);
    const chartRef = useRef(null);
    const zoomRef = useRef(1);
    const [payload, setPayload] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selectedKey, setSelectedKey] = useState('');
    const [topics, setTopics] = useState(null);
    const [topicsLoading, setTopicsLoading] = useState(false);
    const [topicsError, setTopicsError] = useState('');

    const graph = useMemo(() => buildSiteNetworkGraph(payload), [payload]);
    const emptyReason = siteNetworkEmptyReason(payload);
    const selected = graph.links.find((link) => link.selectionKey === selectedKey) || null;

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError('');
        api.fetchSiteNetwork()
            .then((data) => {
                if (!cancelled) {
                    setPayload(data);
                }
            })
            .catch((err) => {
                if (!cancelled) {
                    setError(err.message || 'Failed to load site network');
                    setPayload(null);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [api]);

    useEffect(() => {
        const host = hostRef.current;
        if (!host || emptyReason || loading || error) {
            chartRef.current?.dispose();
            chartRef.current = null;
            return undefined;
        }
        const chart = chartRef.current || echarts.init(host);
        chartRef.current = chart;
        chart.setOption(buildOption(graph, selectedKey, theme), true);
        chart.off('click');
        chart.on('click', (params) => {
            if (params.dataType !== 'edge') {
                return;
            }
            const data = params.data || {};
            const key = data.selectionKey || edgeSelectionKey({
                source_site_ref: data.sourceSiteRef,
                target_site_ref: data.targetSiteRef,
            });
            setSelectedKey(key);
        });
        const onResize = () => chart.resize();
        const observer = new ResizeObserver(onResize);
        observer.observe(host);
        return () => {
            observer.disconnect();
            chart.off('click');
        };
    }, [graph, selectedKey, theme, emptyReason, loading, error]);

    useEffect(() => () => {
        chartRef.current?.dispose();
        chartRef.current = null;
    }, []);

    useEffect(() => {
        if (!selectedKey) {
            setTopics(null);
            setTopicsError('');
            return undefined;
        }
        const link = graph.links.find((item) => item.selectionKey === selectedKey);
        if (!link) {
            setTopics(null);
            return undefined;
        }
        let cancelled = false;
        setTopicsLoading(true);
        setTopicsError('');
        api.fetchSiteNetworkTopics(link.sourceSiteId, link.targetSiteId)
            .then((data) => {
                if (!cancelled) {
                    setTopics(sourceTopicsFromPair(data));
                }
            })
            .catch((err) => {
                if (!cancelled) {
                    setTopics([]);
                    setTopicsError(err.message || 'Failed to load topics');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setTopicsLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [api, graph.links, selectedKey]);

    useImperativeHandle(ref, () => ({
        zoomIn() {
            const chart = chartRef.current;
            if (!chart) {
                return;
            }
            zoomRef.current = Math.min(8, zoomRef.current * ZOOM_STEP);
            chart.setOption({ series: [{ id: 'site-network-graph', zoom: zoomRef.current }] });
        },
        zoomOut() {
            const chart = chartRef.current;
            if (!chart) {
                return;
            }
            zoomRef.current = Math.max(0.3, zoomRef.current / ZOOM_STEP);
            chart.setOption({ series: [{ id: 'site-network-graph', zoom: zoomRef.current }] });
        },
        resetZoom() {
            zoomRef.current = 1;
            chartRef.current?.setOption({ series: [{ id: 'site-network-graph', zoom: 1 }] });
        },
    }), []);

    const sourceName = selected
        ? (graph.nodes.find((node) => node.id === selected.sourceSiteRef)?.name || selected.sourceSiteRef)
        : '';
    const targetName = selected
        ? (graph.nodes.find((node) => node.id === selected.targetSiteRef)?.name || selected.targetSiteRef)
        : '';

    return (
        <div className="tm-site-network" data-site-network>
            {loading ? <div className="tm-empty">{labels.siteNetworkLoading || 'Loading site network…'}</div> : null}
            {error ? <div className="tm-empty tm-empty--error">{error}</div> : null}
            {!loading && !error && emptyReason ? (
                <div className="tm-empty" data-site-network-empty={emptyReason}>{emptyCopy(emptyReason, labels)}</div>
            ) : null}
            {!loading && !error && !emptyReason ? (
                <div className="tm-site-network__layout">
                    <div className="tm-site-network__canvas" ref={hostRef} data-site-network-canvas />
                    <aside className="tm-site-network__panel" data-site-network-panel>
                        <ul className="tm-site-network__edges" data-site-network-edges>
                            {graph.links.map((link) => {
                                const from = graph.nodes.find((node) => node.id === link.sourceSiteRef)?.name || link.sourceSiteRef;
                                const to = graph.nodes.find((node) => node.id === link.targetSiteRef)?.name || link.targetSiteRef;
                                return (
                                    <li key={link.selectionKey}>
                                        <button
                                            type="button"
                                            className={`tm-site-network__edge ${link.selectionKey === selectedKey ? 'is-active' : ''}`}
                                            data-site-network-edge={`${link.sourceSiteId}>${link.targetSiteId}`}
                                            aria-pressed={link.selectionKey === selectedKey}
                                            onClick={() => setSelectedKey(link.selectionKey)}
                                        >
                                            <span>{from} → {to}</span>
                                            <span className="tm-site-network__count">{link.article_link_count}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        {selected ? (
                            <>
                                <h2 className="tm-site-network__title" data-site-network-direction>
                                    {sourceName} → {targetName}
                                </h2>
                                <dl className="tm-site-network__metrics">
                                    <div>
                                        <dt>{labels.siteNetworkEdgeLinks || 'Article links'}</dt>
                                        <dd data-metric="article_link_count">{selected.article_link_count}</dd>
                                    </div>
                                    <div>
                                        <dt>{labels.siteNetworkEdgeSourceArticles || 'Source articles'}</dt>
                                        <dd>{selected.source_article_count}</dd>
                                    </div>
                                    <div>
                                        <dt>{labels.siteNetworkEdgeTargetArticles || 'Target articles'}</dt>
                                        <dd>{selected.target_article_count}</dd>
                                    </div>
                                    <div>
                                        <dt>{labels.siteNetworkEdgeSourceKeywords || 'Source keywords'}</dt>
                                        <dd>{selected.source_keyword_count}</dd>
                                    </div>
                                </dl>
                                {selected.target_article_count === 0 && selected.article_link_count > 0 ? (
                                    <p className="tm-site-network__note" data-site-network-unresolved>
                                        {labels.siteNetworkUnresolvedArticles || 'Some target articles are unresolved. Those links still count on this direction.'}
                                    </p>
                                ) : null}
                                <h3 className="tm-site-network__subtitle">{labels.siteNetworkTopicsHeading || 'Source topics'}</h3>
                                {topicsLoading ? <p className="tm-site-network__note">{labels.siteNetworkLoading || 'Loading…'}</p> : null}
                                {topicsError ? <p className="tm-site-network__note">{topicsError}</p> : null}
                                {!topicsLoading && !topicsError && topics && topics.length === 0 ? (
                                    <p className="tm-site-network__note" data-site-network-topics-empty>
                                        {labels.siteNetworkTopicsEmpty || 'No source topics participate in this direction.'}
                                    </p>
                                ) : null}
                                <ul className="tm-site-network__topics">
                                    {(topics || []).map((topic) => (
                                        <li key={topic.topic_id}>
                                            <button
                                                type="button"
                                                className="tm-site-network__topic"
                                                data-site-network-topic={topic.topic_id}
                                                onClick={() => onEnterTopic?.({
                                                    topicId: topic.topic_id,
                                                    sourceSiteId: selected.sourceSiteId,
                                                    currentSiteId,
                                                })}
                                            >
                                                <span>{topic.name}</span>
                                                <span className="tm-site-network__count">{topic.cross_site_link_count}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        ) : (
                            <p className="tm-site-network__note">
                                {labels.siteNetworkSelectEdge || 'Select a direction to see source topics.'}
                            </p>
                        )}
                    </aside>
                </div>
            ) : null}
        </div>
    );
});

export default SiteNetworkView;
