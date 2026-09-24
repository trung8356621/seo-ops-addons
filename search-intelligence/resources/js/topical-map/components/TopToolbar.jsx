export default function TopToolbar({
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
}) {
    const status = String(auditStatus?.status || 'never_run');
    let aiLabel = labels.aiAction;
    if (status === 'current') {
        aiLabel = labels.aiCurrent;
    } else if (status === 'stale') {
        aiLabel = labels.aiStale;
    }

    const canRun = Boolean(canMutate && auditStatus?.can_run && !auditRunning);

    return (
        <header className="tm-toolbar">
            <div className="tm-toolbar__title-row">
                <h1 className="tm-toolbar__title">
                    {title}
                    {siteDomain ? <span className="tm-toolbar__domain">— {siteDomain}</span> : null}
                </h1>
                <div className="tm-toolbar__actions">
                    {topicsPageUrl ? (
                        <a
                            className="tm-btn"
                            href={topicsPageUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {labels.openTopics}
                        </a>
                    ) : null}
                    {hasAuditResult ? (
                        <button type="button" className="tm-btn" onClick={onOpenAudit}>
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
        </header>
    );
}
