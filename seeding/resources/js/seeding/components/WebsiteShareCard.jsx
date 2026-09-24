import React from 'react';

function formatCountdown(seconds) {
    const s = Math.max(0, Number(seconds) || 0);
    const m = Math.floor(s / 60);
    const r = s % 60;
    if (m <= 0) return `Còn ${r} giây`;
    return `Còn ${m} phút`;
}

/**
 * Website Share card — shared by WebsiteShareFeed and Seeder quick feed.
 * Presentation only; callers own mutate handlers.
 */
export default function WebsiteShareCard({
    job,
    canMutate,
    busy = false,
    editing = false,
    draft = '',
    onDraftChange,
    onGen,
    onStartEdit,
    onSaveEdit,
    onCopy,
    onReport,
    compact = false,
}) {
    if (!job) return null;
    const scheduled = job.status === 'scheduled';

    return (
        <article
            className={`seeding-ws__ws-card seeding-ws__vcard${compact ? ' seeding-ws__ws-card--compact' : ''}`}
            data-website-share-card
        >
            {job.thumbnail_url ? (
                <img className="seeding-ws__ws-thumb" src={job.thumbnail_url} alt="" />
            ) : null}
            <div>
                <strong>{job.title || 'Bài website'}</strong>
                <div className="seeding-ws__page-sub">{job.domain || '—'}</div>
            </div>
            <div className="seeding-ws__vcard-chips">
                <span className="seeding-ws__chip">Website</span>
                <span className="seeding-ws__chip">Index: {job.indexed_at_label || '—'}</span>
                <span className="seeding-ws__chip">{job.status_label || job.status}</span>
                {(job.targets || []).map((t) => (
                    <span key={t.id || t.social} className="seeding-ws__chip">
                        {t.social_label || t.social} {t.completed_count}/{t.target_count}
                    </span>
                ))}
            </div>

            {scheduled ? (
                <p className="seeding-ws__page-sub">
                    Chờ tạo nhiệm vụ — {formatCountdown(job.seconds_until_eligible)}
                </p>
            ) : (
                <>
                    {editing ? (
                        <textarea
                            className="seeding-ws__textarea"
                            value={draft}
                            onChange={(e) => onDraftChange?.(e.target.value)}
                        />
                    ) : (
                        <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                            {job.share_content || 'Chưa có nội dung share.'}
                        </p>
                    )}

                    <div className="seeding-ws__vcard-actions">
                        {editing ? (
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" disabled={busy} onClick={() => onSaveEdit?.(job)}>
                                Lưu
                            </button>
                        ) : (
                            <>
                                <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" disabled={!canMutate || busy} onClick={() => onGen?.(job)}>
                                    {job.share_content ? 'Gen lại' : 'Tạo nội dung'}
                                </button>
                                <button
                                    type="button"
                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                    disabled={!canMutate}
                                    onClick={() => onStartEdit?.(job)}
                                >
                                    Sửa
                                </button>
                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onCopy?.(job)}>
                                    Copy
                                </button>
                                {(job.targets || []).filter((t) => !t.is_complete).map((t) => (
                                    <button
                                        key={`report-${t.social}`}
                                        type="button"
                                        className="seeding-ws__btn seeding-ws__btn--ghost"
                                        disabled={!canMutate || busy || !job.share_content}
                                        onClick={() => onReport?.(job, t.social)}
                                    >
                                        Báo cáo {t.social_label || t.social}
                                    </button>
                                ))}
                                {job.article_url ? (
                                    <a className="seeding-ws__btn seeding-ws__btn--ghost" href={job.article_url} target="_blank" rel="noreferrer">
                                        Mở bài
                                    </a>
                                ) : null}
                            </>
                        )}
                    </div>
                </>
            )}
        </article>
    );
}
