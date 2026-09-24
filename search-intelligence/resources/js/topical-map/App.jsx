import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createApi } from './api/client';
import {
    filterTopics,
    normalizeMcpRange,
    readFilterQuery,
    writeFilterQuery,
} from './state/filters';
import AppChrome from './components/AppChrome';
import ChartCanvas from './components/ChartCanvas';
import AuditConfirmModal from './components/AuditConfirmModal';
import AuditOverlay from './components/AuditOverlay';

export default function App({ config }) {
    const labels = config.labels || {};
    const api = useMemo(() => createApi(config), [config]);
    const initialFilters = useMemo(() => readFilterQuery(), []);
    const chartRef = useRef(null);

    const [overviewRaw, setOverviewRaw] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [meta, setMeta] = useState('');
    const [neighborhood, setNeighborhood] = useState(null);
    const [childrenCacheVersion, setChildrenCacheVersion] = useState(0);
    const childrenCacheRef = useRef(new Map());

    const [tagFilterAll, setTagFilterAll] = useState(initialFilters.tagFilterAll);
    const [showUntagged, setShowUntagged] = useState(initialFilters.showUntagged);
    const [selectedTagIds, setSelectedTagIds] = useState(initialFilters.selectedTagIds);
    const [mcpMin, setMcpMin] = useState(initialFilters.mcpMin);
    const [mcpMax, setMcpMax] = useState(initialFilters.mcpMax);
    const [renderer, setRenderer] = useState(initialFilters.renderer);
    const [focusedTopicId, setFocusedTopicId] = useState(null);
    const [zoomPercent, setZoomPercent] = useState(100);

    const [auditStatus, setAuditStatus] = useState(null);
    const [confirmAi, setConfirmAi] = useState(false);
    const [auditRunning, setAuditRunning] = useState(false);
    const [auditResult, setAuditResult] = useState(null);
    const [auditError, setAuditError] = useState('');
    const [showAuditOverlay, setShowAuditOverlay] = useState(false);

    const siteId = Number(config.siteId || 0);

    const reloadOverview = useCallback(async () => {
        if (siteId <= 0) {
            setLoading(false);
            return;
        }
        setLoading(true);
        setError('');
        try {
            const [overviewRes, statusRes] = await Promise.all([
                api.fetchOverview(),
                api.fetchAuditStatus().catch(() => null),
            ]);
            setOverviewRaw(overviewRes.overview || null);
            childrenCacheRef.current = new Map();
            setChildrenCacheVersion((v) => v + 1);
            if (statusRes?.status) {
                setAuditStatus(statusRes.status);
            }
        } catch (err) {
            setError(err.message || 'Failed to load overview');
        } finally {
            setLoading(false);
        }
    }, [api, siteId]);

    useEffect(() => {
        reloadOverview();
    }, [reloadOverview]);

    useEffect(() => {
        writeFilterQuery({
            tagFilterAll,
            showUntagged,
            selectedTagIds,
            mcpMin,
            mcpMax,
            renderer,
        });
    }, [tagFilterAll, showUntagged, selectedTagIds, mcpMin, mcpMax, renderer]);

    const filteredTopics = useMemo(() => {
        const topics = overviewRaw?.topics || [];
        return filterTopics(topics, {
            tagFilterAll,
            showUntagged,
            selectedTagIds,
            mcpMin,
            mcpMax,
        });
    }, [overviewRaw, tagFilterAll, showUntagged, selectedTagIds, mcpMin, mcpMax]);

    const filteredOverview = useMemo(() => {
        if (!overviewRaw) {
            return null;
        }
        return {
            ...overviewRaw,
            topics: filteredTopics,
        };
    }, [overviewRaw, filteredTopics]);

    const loadNetwork = useCallback(async (topicId = null) => {
        try {
            const res = await api.fetchNetwork(topicId);
            setNeighborhood(res.neighborhood || null);
            const n = res.neighborhood;
            if (n?.truncated) {
                setMeta(`Showing ${n.showing_topics} of ${n.total_topics} Topics (membership neighborhood)`);
            } else {
                setMeta(n ? 'Network semantics: Topic membership' : '');
            }
        } catch (err) {
            setMeta(err.message || 'Network load failed');
            setNeighborhood({ nodes: [], links: [], truncated: false });
        }
    }, [api]);

    useEffect(() => {
        if (renderer === 'network' && filteredOverview) {
            loadNetwork(null);
        }
    }, [renderer, filteredOverview, loadNetwork]);

    const onLoadChildren = useCallback(async (topicId) => {
        if (childrenCacheRef.current.has(topicId)) {
            return childrenCacheRef.current.get(topicId);
        }
        try {
            const result = await api.fetchTopicChildren(topicId);
            if (!result?.ok) {
                setMeta(result?.error || 'Failed to load Topic children.');
                return null;
            }
            childrenCacheRef.current.set(topicId, result);
            setChildrenCacheVersion((v) => v + 1);
            if (result.truncated) {
                setMeta(`Showing ${result.showing} of ${result.total} keywords`);
            }
            return result;
        } catch (err) {
            setMeta(err.message || 'Failed to load Topic children.');
            return null;
        }
    }, [api]);

    const syncFilters = useCallback((next) => {
        setTagFilterAll(next.tagFilterAll);
        setShowUntagged(next.showUntagged);
        setSelectedTagIds(next.selectedTagIds);
    }, []);

    const toggleAll = () => {
        syncFilters({ tagFilterAll: true, showUntagged: false, selectedTagIds: [] });
    };

    const toggleUntagged = () => {
        if (tagFilterAll) {
            syncFilters({ tagFilterAll: false, showUntagged: true, selectedTagIds: [] });
            return;
        }
        const next = !showUntagged;
        if (!next && selectedTagIds.length === 0) {
            syncFilters({ tagFilterAll: true, showUntagged: false, selectedTagIds: [] });
            return;
        }
        setShowUntagged(next);
        setTagFilterAll(false);
    };

    const toggleTag = (tagId) => {
        if (tagId <= 0) {
            return;
        }
        let nextIds;
        if (tagFilterAll) {
            nextIds = [tagId];
        } else if (selectedTagIds.includes(tagId)) {
            nextIds = selectedTagIds.filter((id) => id !== tagId);
        } else {
            nextIds = [...selectedTagIds, tagId];
        }
        if (nextIds.length === 0 && !showUntagged) {
            syncFilters({ tagFilterAll: true, showUntagged: false, selectedTagIds: [] });
            return;
        }
        syncFilters({ tagFilterAll: false, showUntagged, selectedTagIds: nextIds });
    };

    const onMcpChange = (min, max) => {
        const range = normalizeMcpRange(min, max);
        setMcpMin(range.mcpMin);
        setMcpMax(range.mcpMax);
    };

    const beginAiAudit = () => {
        if (!config.canMutate || !auditStatus?.can_run) {
            return;
        }
        setConfirmAi(true);
    };

    const confirmAiAudit = async () => {
        setConfirmAi(false);
        setAuditRunning(true);
        setAuditError('');
        try {
            const res = await api.runAudit();
            if (res.status) {
                setAuditStatus(res.status);
            }
            if (!res.ok) {
                setAuditError(res.message || labels.aiDisabled);
                setShowAuditOverlay(true);
                return;
            }
            setAuditResult(res.payload || null);
            setShowAuditOverlay(true);
            await reloadOverview();
        } catch (err) {
            setAuditError(err.message || 'Audit failed');
            setShowAuditOverlay(true);
        } finally {
            setAuditRunning(false);
        }
    };

    const focusFromAudit = (topicId) => {
        setShowAuditOverlay(false);
        setFocusedTopicId(topicId);
    };

    const onZoomChange = useCallback((zoom) => {
        setZoomPercent(Math.round(zoom * 100));
    }, []);

    if (siteId <= 0) {
        return (
            <div className="tm-app tm-app--empty">
                <p>{labels.needSite}</p>
            </div>
        );
    }

    const empty = !loading && (!filteredOverview || (filteredOverview.topics || []).length === 0)
        && Number(overviewRaw?.summary?.topic_count || 0) === 0;

    const zoomDisabled = loading || empty || renderer === 'sunburst';

    return (
        <div className="tm-app">
            <AppChrome
                title={labels.title}
                siteDomain={config.siteDomain}
                labels={labels}
                auditStatus={auditStatus}
                canMutate={Boolean(config.canMutate)}
                auditRunning={auditRunning}
                hasAuditResult={Boolean(auditResult)}
                onOpenAudit={() => setShowAuditOverlay(true)}
                onBeginAiAudit={beginAiAudit}
                topicsPageUrl={config.topicsPageUrl}
                tagFacets={overviewRaw?.tag_facets || []}
                untaggedCount={overviewRaw?.summary?.untagged_count || 0}
                selectedTagIds={selectedTagIds}
                showUntagged={showUntagged}
                tagFilterAll={tagFilterAll}
                mcpMin={mcpMin}
                mcpMax={mcpMax}
                renderer={renderer}
                onToggleAll={toggleAll}
                onToggleUntagged={toggleUntagged}
                onToggleTag={toggleTag}
                onMcpChange={onMcpChange}
                onRendererChange={setRenderer}
                zoomPercent={zoomPercent}
                zoomDisabled={zoomDisabled}
                onZoomIn={() => chartRef.current?.zoomIn?.()}
                onZoomOut={() => chartRef.current?.zoomOut?.()}
                onZoomReset={() => chartRef.current?.resetZoom?.()}
            />

            <div className="tm-canvas-wrap">
                {loading ? <div className="tm-empty">Loading…</div> : null}
                {error ? <div className="tm-empty tm-empty--error">{error}</div> : null}
                {!loading && !error && empty ? (
                    <div className="tm-empty">{labels.empty}</div>
                ) : null}
                {!loading && !error && !empty && filteredOverview ? (
                    <ChartCanvas
                        ref={chartRef}
                        overview={filteredOverview}
                        renderer={renderer}
                        neighborhood={neighborhood}
                        childrenCache={childrenCacheRef.current}
                        childrenCacheVersion={childrenCacheVersion}
                        topicDetailUrlTemplate={config.topicDetailUrlTemplate}
                        focusedTopicId={focusedTopicId}
                        onFocusTopic={setFocusedTopicId}
                        onLoadChildren={onLoadChildren}
                        onNetworkFocus={loadNetwork}
                        onZoomChange={onZoomChange}
                        meta={meta}
                    />
                ) : null}

                {showAuditOverlay ? (
                    <AuditOverlay
                        labels={labels}
                        result={auditResult}
                        error={auditError}
                        onClose={() => {
                            setShowAuditOverlay(false);
                            setAuditError('');
                        }}
                        onFocusTopic={focusFromAudit}
                    />
                ) : null}
            </div>

            {confirmAi ? (
                <AuditConfirmModal
                    labels={labels}
                    snapshot={auditStatus}
                    onCancel={() => setConfirmAi(false)}
                    onConfirm={confirmAiAudit}
                    running={auditRunning}
                />
            ) : null}
        </div>
    );
}
