import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createApi } from '../api/client';
import {
    filterTopics,
    normalizeMcpRange,
    readFilterQuery,
    writeFilterQuery,
} from '../state/filters';
import {
    persistTopicalMapTheme,
    resolveInitialTopicalMapTheme,
} from '../state/theme';
import { buildOverviewNeighborhood, buildFocusedNetworkNeighborhood } from '../charts/options';
import AppChrome from '../components/AppChrome';
import ChartCanvas from '../components/ChartCanvas';
import AuditConfirmModal from '../components/AuditConfirmModal';
import AuditOverlay from '../components/AuditOverlay';

function formatNetworkMeta(neighborhood) {
    const focusedName = String(neighborhood?.focused_topic_name || '').trim();
    const showingDna = Number(neighborhood?.showing_dna ?? 0);
    if (focusedName) {
        return `${focusedName} · ${showingDna} DNA`;
    }
    const topics = Number(neighborhood?.showing_topics ?? 0);
    const totalDna = Number(neighborhood?.total_dna ?? showingDna);
    if (topics <= 0) {
        return '';
    }
    if (neighborhood?.dna_truncated || totalDna > showingDna) {
        return `${topics} Topics · Showing ${showingDna} of ${totalDna} DNA`;
    }
    return `${topics} Topics · ${showingDna} DNA`;
}

export default function SiteTopicalMapPage({ config }) {
    const labels = config.labels || {};
    const api = useMemo(() => createApi(config), [config]);
    const initialFilters = useMemo(() => readFilterQuery(), []);
    const chartRef = useRef(null);
    const [theme, setTheme] = useState(resolveInitialTopicalMapTheme);

    const [overviewRaw, setOverviewRaw] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [meta, setMeta] = useState('');
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
            site_domain: String(config.siteDomain || '').trim(),
        };
    }, [overviewRaw, filteredTopics, config.siteDomain]);

    /** Structure primary-Tag preference when Tags filter is active. */
    const structurePreferredTagIds = useMemo(() => {
        if (tagFilterAll) {
            return [];
        }
        return selectedTagIds;
    }, [tagFilterAll, selectedTagIds]);

    /** Full-site Network neighborhood from filtered Topics + DNA (no drill API). */
    const fullNetworkNeighborhood = useMemo(
        () => buildOverviewNeighborhood(siteId, filteredTopics, {
            siteDomain: String(config.siteDomain || '').trim(),
        }),
        [siteId, filteredTopics, config.siteDomain],
    );

    /** Clear focus when filters remove the focused Topic. */
    useEffect(() => {
        if (focusedTopicId == null) {
            return;
        }
        const stillVisible = filteredTopics.some(
            (topic) => Number(topic.id) === Number(focusedTopicId),
        );
        if (!stillVisible) {
            setFocusedTopicId(null);
        }
    }, [filteredTopics, focusedTopicId]);

    /**
     * Visible Network graph: full neighborhood, or client-side focused subset.
     * Focus rebuilds option once — never on wheel/pan/hover.
     */
    const networkNeighborhood = useMemo(() => {
        if (focusedTopicId == null) {
            return fullNetworkNeighborhood;
        }
        const focused = buildFocusedNetworkNeighborhood(fullNetworkNeighborhood, focusedTopicId);
        // Invalid focus → full graph (effect above also clears state).
        return focused || fullNetworkNeighborhood;
    }, [fullNetworkNeighborhood, focusedTopicId]);

    useEffect(() => {
        if (renderer === 'treemap') {
            setMeta(labels.treemapByMcp || 'Topic distribution by MCP share');
            return;
        }
        if (renderer === 'tree') {
            setMeta('');
            return;
        }
        if (renderer === 'network') {
            setMeta(formatNetworkMeta(networkNeighborhood));
        }
    }, [renderer, labels.treemapByMcp, networkNeighborhood]);

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
        const id = Number(topicId);
        if (!Number.isFinite(id) || id <= 0) {
            return;
        }
        setFocusedTopicId(id);
        // Network: focus constellation only (detail via double-click).
        // Structure / Treemap: highlight helper; Treemap has no highlight path.
        if (renderer === 'network') {
            return;
        }
    };

    const onZoomChange = useCallback((zoom) => {
        setZoomPercent(Math.round(zoom * 100));
    }, []);

    const onRendererChange = (mode) => {
        setRenderer(mode);
    };

    const toggleTheme = () => {
        setTheme((current) => {
            const next = current === 'dark' ? 'light' : 'dark';
            persistTopicalMapTheme(next);
            return next;
        });
    };

    if (siteId <= 0) {
        return (
            <div className="tm-app tm-app--empty" data-theme={theme}>
                <p>{labels.needSite}</p>
            </div>
        );
    }

    const empty = !loading && (!filteredOverview || (filteredOverview.topics || []).length === 0)
        && Number(overviewRaw?.summary?.topic_count || 0) === 0;

    const zoomDisabled = loading || empty;
    const zoomScaleDisabled = renderer === 'treemap';

    return (
        <div className="tm-app" data-theme={theme}>
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
                aiHistoryUrl={config.aiHistoryUrl || null}
                seoAuditUrl={config.seoAuditUrl || null}
                theme={theme}
                onToggleTheme={toggleTheme}
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
                onRendererChange={onRendererChange}
                zoomPercent={zoomPercent}
                zoomDisabled={zoomDisabled}
                zoomScaleDisabled={zoomScaleDisabled}
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
                        theme={theme}
                        neighborhood={networkNeighborhood}
                        topicDetailUrlTemplate={config.topicDetailUrlTemplate}
                        focusedTopicId={focusedTopicId}
                        onFocusTopic={setFocusedTopicId}
                        preferredTagIds={structurePreferredTagIds}
                        siteDomain={String(config.siteDomain || '').trim()}
                        untaggedBucketLabel={
                            labels.structureUntaggedBucket
                            || labels.untagged
                            || 'Chưa gắn tag'
                        }
                        untaggedBucketTooltip={
                            labels.structureUntaggedTooltip
                            || 'Các Topic chưa được gắn tag'
                        }
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
