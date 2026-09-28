function destinationLabel(item, labels) {
    const category = String(item?.ui_category || '');
    if (category === 'managed_cross_site') {
        const site = String(item?.target_site_domain || '').trim()
            || (Number(item?.target_site_id) > 0 ? `Site #${item.target_site_id}` : 'Managed site');
        const article = String(item?.target_article_title || '').trim()
            || (labels.topicCrossSiteUnresolvedArticle || 'Target article unresolved');
        const keyword = String(item?.target_keyword_phrase || '').trim();
        return {
            site,
            article,
            keyword: keyword || (labels.topicCrossSiteUnresolvedKeyword || 'Target keyword unresolved'),
            keywordResolved: keyword !== '',
        };
    }
    const url = String(item?.target_external_url || '').trim();
    let host = url;
    try {
        if (url) {
            host = new URL(url).host || url;
        }
    } catch {
        host = url;
    }
    return {
        site: host || 'External',
        article: '',
        keyword: '',
        keywordResolved: false,
    };
}

function statusLabel(category) {
    if (category === 'managed_cross_site') {
        return 'Managed Cross-Site';
    }
    if (category === 'reference') {
        return 'Trusted / Reference';
    }
    return 'Needs Review';
}

export default function TopicCrossSitePanel({ items, loading, error, labels, onClose }) {
    return (
        <aside className="tm-cross-site-panel" data-topic-cross-site>
            <div className="tm-cross-site-panel__head">
                <h2>{labels.topicCrossSiteHeading || 'Cross-site keyword relationships'}</h2>
                <button type="button" className="tm-btn tm-btn--ghost" onClick={onClose}>
                    {labels.closeAudit || 'Close'}
                </button>
            </div>
            {loading ? <p className="tm-site-network__note">Loading…</p> : null}
            {error ? <p className="tm-site-network__note">{error}</p> : null}
            {!loading && !error && (!items || items.length === 0) ? (
                <p className="tm-site-network__note" data-topic-cross-site-empty>
                    {labels.topicCrossSiteEmpty || 'No cross-site keyword relationships for this topic.'}
                </p>
            ) : null}
            <ul className="tm-cross-site-panel__list">
                {(items || []).map((item) => {
                    const dest = destinationLabel(item, labels);
                    const category = String(item.ui_category || 'needs_review');
                    return (
                        <li key={item.map_id} data-topic-cross-site-row={category}>
                            <p className="tm-cross-site-panel__keyword">
                                {item.source_keyword_phrase || 'Keyword'}
                                <span> → </span>
                                {dest.site}
                            </p>
                            <p className="tm-cross-site-panel__meta">{statusLabel(category)}</p>
                            {category === 'managed_cross_site' ? (
                                <>
                                    <p className="tm-cross-site-panel__meta">{dest.article}</p>
                                    <p className="tm-cross-site-panel__meta" data-target-keyword-resolved={dest.keywordResolved ? '1' : '0'}>
                                        {dest.keyword}
                                    </p>
                                </>
                            ) : (
                                <p className="tm-cross-site-panel__meta">{item.target_external_url || dest.site}</p>
                            )}
                        </li>
                    );
                })}
            </ul>
        </aside>
    );
}
