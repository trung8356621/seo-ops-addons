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

function buildOption(graph, selectedKey, selectedNodeId, theme, labels = {}) {
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
                if (params.dataType === 'node') {
                    const data = params.data || {};
                    const lines = [data.name || ''];
                    if (data.isMain) {
                        lines.unshift('★ ' + (labels.siteNetworkMainDomain || 'Main domain'));
                    }
                    if (data.isIsolated) {
                        lines.push(labels.siteNetworkNoRelationshipsYet || 'No semantic relationships recorded yet');
                    }
                    return lines.join('<br/>');
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
                    formatter(params) {
                        const name = params.data?.name || '';
                        if (params.data?.isMain) {
                            return `★ ${name}`;
                        }
                        return name;
                    },
                },
                data: graph.nodes.map((node) => {
                    const isMain = Boolean(node.isMain);
                    const isIsolated = Boolean(node.isIsolated);
                    const isSelected = selectedNodeId && selectedNodeId === node.id;

                    let fill = nodeFill;
                    let border = nodeBorder;
                    let borderWidth = 1.5;
                    let borderType = 'solid';

                    if (isMain) {
                        fill = dark ? '#78350f' : '#fef3c7';
                        border = dark ? '#f59e0b' : '#d97706';
                        borderWidth = 3;
                    } else if (isIsolated) {
                        fill = dark ? '#1e293b' : '#f8fafc';
                        border = dark ? '#475569' : '#94a3b8';
                        borderWidth = 1.5;
                        borderType = 'dashed';
                    }

                    if (isSelected) {
                        border = active;
                        borderWidth = 3.5;
                    }

                    return {
                        ...node,
                        itemStyle: {
                            color: fill,
                            borderColor: border,
                            borderWidth,
                            borderType,
                        },
                    };
                }),
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
    labels = {},
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
    const [selectedNodeId, setSelectedNodeId] = useState('');
    const [topics, setTopics] = useState(null);
    const [topicsLoading, setTopicsLoading] = useState(false);
    const [topicsError, setTopicsError] = useState('');

    const graph = useMemo(() => buildSiteNetworkGraph(payload), [payload]);
    const emptyReason = siteNetworkEmptyReason(payload);
    const selected = graph.links.find((link) => link.selectionKey === selectedKey) || null;
    const selectedNode = graph.nodes.find((node) => node.id === selectedNodeId) || null;
    const mainNode = graph.nodes.find((node) => node.isMain) || null;
    const isolatedNodes = graph.nodes.filter((node) => node.isIsolated);

    const totalSitesCount = payload?.accessible_site_count ?? graph.nodes.length;
    const connectedCount = payload?.connected_site_count ?? graph.nodes.filter((node) => !node.isIsolated).length;
    const isolatedCount = payload?.isolated_site_count ?? isolatedNodes.length;

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
        chart.setOption(buildOption(graph, selectedKey, selectedNodeId, theme, labels), true);
        chart.off('click');
        chart.on('click', (params) => {
            if (params.dataType === 'edge') {
                const data = params.data || {};
                const key = data.selectionKey || edgeSelectionKey({
                    source_site_ref: data.sourceSiteRef,
                    target_site_ref: data.targetSiteRef,
                });
                setSelectedKey(key);
                setSelectedNodeId('');
            } else if (params.dataType === 'node') {
                const data = params.data || {};
                setSelectedNodeId(data.id || '');
                setSelectedKey('');
            }
        });
        const onResize = () => chart.resize();
        const observer = new ResizeObserver(onResize);
        observer.observe(host);
        return () => {
            observer.disconnect();
            chart.off('click');
        };
    }, [graph, selectedKey, selectedNodeId, theme, emptyReason, loading, error, labels]);

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
                        <div className="tm-site-network__summary" data-site-network-summary>
                            <div className="tm-site-network__stats">
                                <span className="tm-site-network__stat-item" title={labels.siteNetworkTotalManaged || 'Total managed sites'}>
                                    <strong>{totalSitesCount}</strong> {labels.siteNetworkTotalManaged || 'sites'}
                                </span>
                                <span className="tm-site-network__stat-dot">·</span>
                                <span className="tm-site-network__stat-item" title={labels.siteNetworkConnectedCount || 'Connected'}>
                                    <strong>{connectedCount}</strong> {labels.siteNetworkConnectedCount || 'connected'}
                                </span>
                                <span className="tm-site-network__stat-dot">·</span>
                                <span className="tm-site-network__stat-item" title={labels.siteNetworkIsolatedCount || 'Isolated'}>
                                    <strong>{isolatedCount}</strong> {labels.siteNetworkIsolatedCount || 'isolated'}
                                </span>
                            </div>
                            {mainNode ? (
                                <div className="tm-site-network__main-badge" data-site-network-main-domain={mainNode.domain}>
                                    <span className="tm-site-network__main-star">★</span>
                                    <span className="tm-site-network__main-text">
                                        <span className="tm-site-network__main-label">{labels.siteNetworkMainDomain || 'Main'}:</span> {mainNode.name}
                                    </span>
                                </div>
                            ) : null}
                        </div>

                        {graph.links.length > 0 ? (
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
                                                onClick={() => {
                                                    setSelectedKey(link.selectionKey);
                                                    setSelectedNodeId('');
                                                }}
                                            >
                                                <span>{from} → {to}</span>
                                                <span className="tm-site-network__count">{link.article_link_count}</span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : null}

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
                        ) : selectedNode ? (
                            <div className="tm-site-network__node-detail" data-site-network-node-detail={selectedNode.siteId}>
                                <h2 className="tm-site-network__title">
                                    {selectedNode.isMain ? '★ ' : ''}{selectedNode.name}
                                </h2>
                                {selectedNode.isMain ? (
                                    <p className="tm-site-network__main-flag">
                                        ★ {labels.siteNetworkMainDomain || 'Main domain'}
                                    </p>
                                ) : null}
                                {selectedNode.isIsolated ? (
                                    <div className="tm-site-network__isolated-state" data-site-network-isolated-state>
                                        <p className="tm-site-network__note">
                                            {labels.siteNetworkNoRelationshipsYet || 'No semantic relationships recorded yet'}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="tm-site-network__note">
                                        {labels.siteNetworkConnectedCount || 'Connected managed site'}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <p className="tm-site-network__note">
                                {labels.siteNetworkSelectEdge || 'Select a direction to see source topics.'}
                            </p>
                        )}

                        {isolatedNodes.length > 0 ? (
                            <div className="tm-site-network__isolated-section">
                                <h3 className="tm-site-network__subtitle">
                                    {labels.siteNetworkIsolatedHeading || 'Isolated sites'} ({isolatedNodes.length})
                                </h3>
                                <ul className="tm-site-network__isolated-list" data-site-network-isolated-list>
                                    {isolatedNodes.map((node) => (
                                        <li key={node.id}>
                                            <button
                                                type="button"
                                                className={`tm-site-network__edge tm-site-network__edge--isolated ${selectedNodeId === node.id ? 'is-active' : ''}`}
                                                data-site-network-isolated-node={node.siteId}
                                                aria-pressed={selectedNodeId === node.id}
                                                onClick={() => {
                                                    setSelectedNodeId(node.id);
                                                    setSelectedKey('');
                                                }}
                                            >
                                                <span>{node.isMain ? '★ ' : ''}{node.name}</span>
                                                <span className="tm-site-network__count tm-site-network__count--muted">0 links</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                    </aside>
                </div>
            ) : null}
        </div>
    );
});

export default SiteNetworkView;

