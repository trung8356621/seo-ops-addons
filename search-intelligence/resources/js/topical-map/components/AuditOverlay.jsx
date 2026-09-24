function topicIdFromRef(topicRef) {
    if (!topicRef) {
        return null;
    }
    const raw = String(topicRef).trim();
    const match = raw.match(/(\d+)/);
    if (!match) {
        return null;
    }
    const id = Number(match[1]);
    return id > 0 ? id : null;
}

export default function AuditOverlay({ labels, result, error, onClose, onFocusTopic }) {
    if (!result && !error) {
        return null;
    }

    return (
        <div className="tm-audit-overlay" role="dialog" aria-modal="true">
            <div className="tm-audit-overlay__panel">
                <div className="tm-audit-overlay__head">
                    <h3>{labels.openAudit}</h3>
                    <button type="button" className="tm-btn" onClick={onClose}>
                        {labels.closeAudit}
                    </button>
                </div>

                {error ? <p className="tm-audit-error">{error}</p> : null}

                {result ? (
                    <>
                        <h4>{labels.auditSummary}</h4>
                        <p className="tm-audit-summary">{result.summary || ''}</p>

                        {Array.isArray(result.findings) && result.findings.length > 0 ? (
                            <>
                                <h4>{labels.auditFindings}</h4>
                                <ul className="tm-audit-list">
                                    {result.findings.map((finding, idx) => {
                                        const topicRef = String(finding.topic_ref || '');
                                        const topicId = topicIdFromRef(topicRef);
                                        return (
                                            <li key={`f-${idx}`} className="tm-audit-item">
                                                <div className="tm-audit-item__head">
                                                    <span className={`tm-badge tm-badge--${String(finding.severity || 'medium').toLowerCase()}`}>
                                                        {finding.severity || 'medium'}
                                                    </span>
                                                    <span>{finding.type || ''}</span>
                                                </div>
                                                <strong>{finding.title || ''}</strong>
                                                {finding.observation ? (
                                                    <div className="tm-audit-body">{finding.observation}</div>
                                                ) : null}
                                                {topicId ? (
                                                    <button
                                                        type="button"
                                                        className="tm-audit-focus"
                                                        onClick={() => onFocusTopic(topicId)}
                                                    >
                                                        {finding.topic_name || topicRef}
                                                    </button>
                                                ) : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </>
                        ) : null}

                        {Array.isArray(result.opportunities) && result.opportunities.length > 0 ? (
                            <>
                                <h4>{labels.auditOpportunities}</h4>
                                <ul className="tm-audit-list">
                                    {result.opportunities.map((opp, idx) => {
                                        const topicRef = String(opp.topic_ref || '');
                                        const topicId = topicIdFromRef(topicRef);
                                        return (
                                            <li key={`o-${idx}`} className="tm-audit-item">
                                                <strong>{opp.title || opp.topic_name || ''}</strong>
                                                {opp.reason ? (
                                                    <div className="tm-audit-body">{opp.reason}</div>
                                                ) : null}
                                                {topicId ? (
                                                    <button
                                                        type="button"
                                                        className="tm-audit-focus"
                                                        onClick={() => onFocusTopic(topicId)}
                                                    >
                                                        {opp.topic_name || topicRef}
                                                    </button>
                                                ) : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </>
                        ) : null}

                        {Array.isArray(result.recommended_actions) && result.recommended_actions.length > 0 ? (
                            <>
                                <h4>{labels.auditActions}</h4>
                                <ol className="tm-audit-list">
                                    {result.recommended_actions.map((action, idx) => {
                                        const topicRef = typeof action === 'object'
                                            ? String(action.topic_ref || '')
                                            : '';
                                        const topicId = topicIdFromRef(topicRef);
                                        return (
                                            <li key={`a-${idx}`} className="tm-audit-item">
                                                {typeof action === 'object' ? (
                                                    <>
                                                        <strong>{action.title || ''}</strong>
                                                        {topicId ? (
                                                            <button
                                                                type="button"
                                                                className="tm-audit-focus"
                                                                onClick={() => onFocusTopic(topicId)}
                                                            >
                                                                {topicRef}
                                                            </button>
                                                        ) : null}
                                                    </>
                                                ) : (
                                                    String(action)
                                                )}
                                            </li>
                                        );
                                    })}
                                </ol>
                            </>
                        ) : null}
                    </>
                ) : null}
            </div>
        </div>
    );
}
