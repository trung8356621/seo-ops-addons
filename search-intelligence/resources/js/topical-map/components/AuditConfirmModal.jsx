export default function AuditConfirmModal({ labels, snapshot, onCancel, onConfirm, running }) {
    if (!snapshot) {
        return null;
    }

    return (
        <div className="tm-modal" role="dialog" aria-modal="true">
            <div className="tm-modal__backdrop" onClick={onCancel} />
            <div className="tm-modal__panel">
                <h3 className="tm-modal__title">{labels.aiModalTitle}</h3>
                <p className="tm-modal__site">
                    {labels.aiSite}:{' '}
                    <strong>
                        {snapshot.site_domain
                            ? snapshot.site_domain
                            : `#${snapshot.site_id}`}
                    </strong>
                </p>
                <ul className="tm-modal__stats">
                    <li>{Number(snapshot.topic_count || 0).toLocaleString()} Topics</li>
                    <li>
                        {Number(snapshot.assigned_keywords || 0).toLocaleString()}{' '}
                        {labels.aiAssignedKw}
                    </li>
                    <li>
                        {Number(snapshot.unassigned_keywords || 0).toLocaleString()}{' '}
                        {labels.aiUnassignedKw}
                    </li>
                    <li>
                        {labels.aiExisting}: {Number(snapshot.existing_tags || 0).toLocaleString()}
                    </li>
                    <li>
                        {labels.aiTopicsTagged}:{' '}
                        {Number(snapshot.topics_with_tags || 0).toLocaleString()} /{' '}
                        {Number(snapshot.topic_count || 0).toLocaleString()}
                    </li>
                    <li>
                        {labels.aiUntagged}: {Number(snapshot.untagged_topics || 0).toLocaleString()}
                    </li>
                </ul>
                <p className="tm-modal__notice">{labels.aiCostNotice}</p>
                <div className="tm-modal__actions">
                    <button type="button" className="tm-btn" onClick={onCancel} disabled={running}>
                        {labels.cancel}
                    </button>
                    <button
                        type="button"
                        className="tm-btn tm-btn--ai"
                        onClick={onConfirm}
                        disabled={running}
                    >
                        {running ? labels.aiRunning : labels.aiRun}
                    </button>
                </div>
            </div>
        </div>
    );
}
