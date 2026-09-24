import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import RelationshipChrome from '../components/RelationshipChrome';
import RelationshipChart from '../components/RelationshipChart';
import { topicDetailUrl } from '../api/client';
import {
    createRelationshipApi,
    filterGraphByCategories,
    issueLabel,
    readRelQuery,
    writeRelQuery,
} from '../state/relationshipFilters';

export default function KeywordRelationshipPage({ config }) {
    const labels = config.labels || {};
    const api = useMemo(() => createRelationshipApi(config), [config]);
    const chartRef = useRef(null);

    const [rawGraph, setRawGraph] = useState(null);
    const [relationship, setRelationship] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [filters, setFilters] = useState(() => readRelQuery());
    const [zoomPercent, setZoomPercent] = useState(100);

    const siteId = Number(config.siteId || 0);
    const keywordId = Number(config.keywordId || 0);

    const reload = useCallback(async () => {
        if (siteId <= 0 || keywordId <= 0) {
            setLoading(false);
            return;
        }
        setLoading(true);
        setError('');
        try {
            const res = await api.fetchRelationship(keywordId);
            setRelationship(res.relationship || null);
            setRawGraph(res.graph || null);
        } catch (err) {
            setError(err.message || 'Failed to load relationship');
            setRelationship(null);
            setRawGraph(null);
        } finally {
            setLoading(false);
        }
    }, [api, siteId, keywordId]);

    useEffect(() => {
        reload();
    }, [reload]);

    useEffect(() => {
        writeRelQuery(filters);
    }, [filters]);

    const availableSections = useMemo(() => {
        const meta = relationship?.meta || {};
        return Array.isArray(meta.available_sections) ? meta.available_sections : [];
    }, [relationship]);

    const filteredGraph = useMemo(
        () => filterGraphByCategories(rawGraph, filters),
        [rawGraph, filters],
    );

    const issueLabels = useMemo(() => {
        const issues = relationship?.meta?.relation_issues;
        if (!Array.isArray(issues)) {
            return [];
        }
        return issues.map((code) => issueLabel(code, labels));
    }, [relationship, labels]);

    const mcpExcluded = Boolean(
        config.mcpExcluded
        || relationship?.keyword?.mcp_excluded,
    );

    const phrase = config.keywordPhrase
        || relationship?.keyword?.phrase
        || '';

    const topicId = Number(config.topicId || relationship?.topics?.[0]?.id || 0);
    const topicName = config.topicName || relationship?.topics?.[0]?.name || '';
    const topicUrl = topicId > 0
        ? topicDetailUrl(config.topicDetailUrlTemplate, topicId)
        : '';

    const toggleFilter = (key) => {
        setFilters((prev) => ({
            ...prev,
            [key]: !prev[key],
        }));
    };

    const onZoomChange = useCallback((zoom) => {
        setZoomPercent(Math.round(zoom * 100));
    }, []);

    if (siteId <= 0 || keywordId <= 0) {
        return (
            <div className="tm-app tm-app--empty">
                <p>{labels.needSite || labels.empty}</p>
            </div>
        );
    }

    const empty = !loading && !error && (!filteredGraph || (filteredGraph.nodes || []).length === 0);
    const zoomDisabled = loading || empty || Boolean(error);

    return (
        <div className="tm-app tm-app--relationship" data-mode="keyword-relationship">
            <RelationshipChrome
                title={labels.title}
                phrase={phrase}
                siteDomain={config.siteDomain}
                labels={labels}
                dictionaryUrl={config.dictionaryUrl}
                topicalMapUrl={config.topicalMapUrl}
                topicDetailUrl={topicUrl}
                topicName={topicName}
                mcpExcluded={mcpExcluded}
                issueLabels={issueLabels}
                filters={filters}
                availableSections={availableSections}
                onToggleFilter={toggleFilter}
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
                {!loading && !error && !empty && filteredGraph ? (
                    <RelationshipChart
                        ref={chartRef}
                        graph={filteredGraph}
                        topicDetailUrlTemplate={config.topicDetailUrlTemplate}
                        articleEditUrlTemplate={config.articleEditUrlTemplate}
                        relationshipUrlTemplate={config.relationshipUrlTemplate}
                        labels={labels}
                        onZoomChange={onZoomChange}
                    />
                ) : null}
            </div>
        </div>
    );
}
