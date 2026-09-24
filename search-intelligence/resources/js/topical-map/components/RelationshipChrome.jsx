import ZoomControls from './ZoomControls';
import { FILTER_ORDER, isSectionAvailable } from '../state/relationshipFilters';

const LABEL_KEYS = {
    topic: 'filterTopic',
    article: 'filterArticle',
    dna: 'filterDna',
    related_keyword: 'filterRelated',
    gsc: 'filterGsc',
    internal_link: 'filterLinks',
    planning: 'filterPlanning',
};

/**
 * Keyword Relationship chrome — category toggles + zoom; no site Tag/MCP/view/AI.
 */
export default function RelationshipChrome({
    title,
    phrase,
    siteDomain,
    labels,
    dictionaryUrl,
    topicalMapUrl,
    topicDetailUrl,
    topicName,
    mcpExcluded,
    issueLabels,
    filters,
    availableSections,
    onToggleFilter,
    zoomPercent,
    zoomDisabled,
    onZoomIn,
    onZoomOut,
    onZoomReset,
}) {
    return (
        <header className="tm-chrome tm-chrome--relationship">
            <div className="tm-chrome__row tm-chrome__row--primary">
                <div className="tm-chrome__brand">
                    <h1 className="tm-chrome__title">
                        <span className="tm-chrome__title-main">
                            {title || 'Keyword Relationship'}
                            {phrase ? ` — ${phrase}` : ''}
                        </span>
                        {siteDomain ? (
                            <span className="tm-chrome__title-domain">— {siteDomain}</span>
                        ) : null}
                    </h1>
                    <div className="tm-rel-badges">
                        {mcpExcluded ? (
                            <span className="tm-rel-badge tm-rel-badge--warn">{labels.mcpExcluded}</span>
                        ) : null}
                        {(issueLabels || []).map((text) => (
                            <span key={text} className="tm-rel-badge tm-rel-badge--issue">
                                ⚠ {text}
                            </span>
                        ))}
                    </div>
                </div>
                <div className="tm-chrome__actions">
                    {dictionaryUrl ? (
                        <a className="tm-btn tm-btn--ghost" href={dictionaryUrl}>
                            ← {labels.openKeywords || 'Keywords'}
                        </a>
                    ) : null}
                    {topicalMapUrl ? (
                        <a className="tm-btn tm-btn--ghost" href={topicalMapUrl}>
                            {labels.openTopicalMap || 'Topical Map'}
                        </a>
                    ) : null}
                    {topicDetailUrl ? (
                        <a
                            className="tm-btn tm-btn--ghost"
                            href={topicDetailUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {labels.openTopic || 'Open Topic'}
                            {topicName ? `: ${topicName}` : ''}
                        </a>
                    ) : null}
                </div>
            </div>

            <div className="tm-chrome__row tm-chrome__row--controls">
                <div
                    className="tm-rel-filters"
                    role="group"
                    aria-label={labels.filters || 'Relationship categories'}
                >
                    {FILTER_ORDER.map((key) => {
                        const available = isSectionAvailable(availableSections, key);
                        const active = Boolean(filters[key]);
                        const label = labels[LABEL_KEYS[key]] || key;
                        let titleHint = '';
                        if (!available) {
                            titleHint =
                                key === 'internal_link'
                                    ? labels.noFocusArticle || labels.unavailableSection
                                    : labels.unavailableSection || 'No data available';
                        }
                        return (
                            <button
                                key={key}
                                type="button"
                                className={`tm-btn ${active && available ? 'is-active' : ''}`}
                                disabled={!available}
                                title={titleHint || undefined}
                                aria-pressed={active && available ? 'true' : 'false'}
                                onClick={() => available && onToggleFilter(key)}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>

                <div className="tm-chrome__tools">
                    <ZoomControls
                        zoomPercent={zoomPercent}
                        disabled={zoomDisabled}
                        onZoomIn={onZoomIn}
                        onZoomOut={onZoomOut}
                        onReset={onZoomReset}
                    />
                </div>
            </div>
        </header>
    );
}
