import ZoomControls from './ZoomControls';
import TagFilterControl from './TagFilterControl';

function filterSummary({ tagFilterAll, selectedTagIds, showUntagged, tagFacets, mcpMin, mcpMax }) {
    const parts = [];
    if (tagFilterAll) {
        parts.push('All tags');
    } else {
        const names = (tagFacets || [])
            .filter((f) => selectedTagIds.includes(Number(f.id)))
            .map((f) => f.name);
        if (showUntagged) {
            names.push('Untagged');
        }
        if (names.length) {
            parts.push(names.slice(0, 3).join(' · ') + (names.length > 3 ? ` +${names.length - 3}` : ''));
        }
    }
    if (mcpMin > 0 || mcpMax < 100) {
        parts.push(`MCP ${Math.round(mcpMin)}–${Math.round(mcpMax)}%`);
    }
    return parts.join(' · ');
}

/**
 * Unified header: row1 title/actions, row2 filters + view + zoom.
 */
export default function AppChrome({
    title,
    siteDomain,
    labels,
    auditStatus,
    canMutate,
    auditRunning,
    hasAuditResult,
    onOpenAudit,
    onBeginAiAudit,
    topicsPageUrl,
    tagFacets,
    untaggedCount,
    selectedTagIds,
    showUntagged,
    tagFilterAll,
    mcpMin,
    mcpMax,
    renderer,
    onToggleAll,
    onToggleUntagged,
    onToggleTag,
    onMcpChange,
    onRendererChange,
    zoomPercent,
    zoomDisabled,
    zoomScaleDisabled = false,
    onZoomIn,
    onZoomOut,
    onZoomReset,
}) {
    const status = String(auditStatus?.status || 'never_run');
    let aiLabel = labels.aiAction;
    if (status === 'current') {
        aiLabel = labels.aiCurrent;
    } else if (status === 'stale') {
        aiLabel = labels.aiStale;
    }
    const canRun = Boolean(canMutate && auditStatus?.can_run && !auditRunning);
    const summary = filterSummary({
        tagFilterAll,
        selectedTagIds,
        showUntagged,
        tagFacets,
        mcpMin,
        mcpMax,
    });

    return (
        <header className="tm-chrome">
            <div className="tm-chrome__row tm-chrome__row--primary">
                <div className="tm-chrome__brand">
                    <h1 className="tm-chrome__title">
                        <span className="tm-chrome__title-main">{title || 'Topical Map'}</span>
                        {siteDomain ? (
                            <span className="tm-chrome__title-domain">— {siteDomain}</span>
                        ) : null}
                    </h1>
                    {summary ? (
                        <p className="tm-chrome__summary">{summary}</p>
                    ) : null}
                </div>
                <div className="tm-chrome__actions">
                    {topicsPageUrl ? (
                        <a
                            className="tm-btn tm-btn--ghost"
                            href={topicsPageUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            ← {labels.openTopics}
                        </a>
                    ) : null}
                    {hasAuditResult ? (
                        <button type="button" className="tm-btn tm-btn--ghost" onClick={onOpenAudit}>
                            {labels.openAudit}
                        </button>
                    ) : null}
                    <button
                        type="button"
                        className="tm-btn tm-btn--ai"
                        onClick={onBeginAiAudit}
                        disabled={!canRun}
                    >
                        {auditRunning ? labels.aiRunning : aiLabel}
                    </button>
                </div>
            </div>

            <div className="tm-chrome__row tm-chrome__row--controls">
                <TagFilterControl
                    labels={labels}
                    tagFacets={tagFacets}
                    untaggedCount={untaggedCount}
                    selectedTagIds={selectedTagIds}
                    showUntagged={showUntagged}
                    tagFilterAll={tagFilterAll}
                    onToggleAll={onToggleAll}
                    onToggleUntagged={onToggleUntagged}
                    onToggleTag={onToggleTag}
                />

                <div className="tm-chrome__tools">
                    <div className="tm-mcp-filter" aria-label="MCP %">
                        <span className="tm-mcp-filter__label">
                            MCP {Math.round(mcpMin)}–{Math.round(mcpMax)}%
                        </span>
                        <div className="tm-mcp-filter__sliders">
                            <input
                                type="range"
                                min={0}
                                max={100}
                                value={mcpMin}
                                aria-label="Min MCP"
                                onChange={(e) => onMcpChange(Number(e.target.value), mcpMax)}
                            />
                            <input
                                type="range"
                                min={0}
                                max={100}
                                value={mcpMax}
                                aria-label="Max MCP"
                                onChange={(e) => onMcpChange(mcpMin, Number(e.target.value))}
                            />
                        </div>
                    </div>

                    <div className="tm-renderer" role="tablist" aria-label="Topical Map renderer">
                        {['tree', 'network', 'treemap'].map((mode) => (
                            <button
                                key={mode}
                                type="button"
                                role="tab"
                                className={`tm-btn ${renderer === mode ? 'is-active' : ''}`}
                                aria-selected={renderer === mode ? 'true' : 'false'}
                                onClick={() => onRendererChange(mode)}
                            >
                                {labels[mode]}
                            </button>
                        ))}
                    </div>

                    <ZoomControls
                        zoomPercent={zoomPercent}
                        disabled={zoomDisabled}
                        scaleDisabled={zoomScaleDisabled}
                        onZoomIn={onZoomIn}
                        onZoomOut={onZoomOut}
                        onReset={onZoomReset}
                    />
                </div>
            </div>
        </header>
    );
}
