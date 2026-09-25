import React from 'react';
import { ExternalLink, Sparkles } from 'lucide-react';

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
    genOpen = false,
    onGenToggle,
    compact = false,
}) {
    if (!job) return null;
    const scheduled = job.status === 'scheduled';
    const hasTargetContent = Array.isArray(job.targets)
        && job.targets.some((target) => String(target.share_content || '').trim() !== '');

    return (
        <article
            className={`seeding-ws__ws-card seeding-ws__vcard${genOpen ? ' is-gen-open' : ''}${compact ? ' seeding-ws__ws-card--compact' : ''}`}
            data-website-share-card
            data-gen-open={genOpen ? '1' : '0'}
        >
            {job.thumbnail_url ? (
                <img className="seeding-ws__ws-thumb" src={job.thumbnail_url} alt="" />
            ) : null}
            <div>
                <strong>
                    <span className="seeding-ws__type-badge">Website Share</span>{' '}
                    {job.title || 'Bài website'}
                </strong>
                <div className="seeding-ws__page-sub">{job.domain || '—'}</div>
            </div>
            <div className="seeding-ws__vcard-chips">
                <span className="seeding-ws__chip">Index: {job.indexed_at_label || '—'}</span>
                <span className="seeding-ws__chip">{job.status_label || job.status}</span>
                {Array.isArray(job.targets) && job.targets.length > 0 ? (
                    <span className="seeding-ws__chip">Social: {job.targets.map((target) => target.social_label || target.social).join(' · ')}</span>
                ) : null}
            </div>

            {scheduled ? (
                <p className="seeding-ws__page-sub">
                    Chờ tạo nhiệm vụ — {formatCountdown(job.seconds_until_eligible)}
                </p>
            ) : null}

            {!scheduled ? (
                <div className="seeding-ws__vcard-foot">
                    <span className="seeding-ws__time">{job.share_content || hasTargetContent ? 'Có nội dung' : 'Chờ tạo nội dung'}</span>
                    <div className="seeding-ws__page-head-actions">
                        {job.article_url ? (
                            <a className="seeding-ws__icon-btn" href={job.article_url} target="_blank" rel="noreferrer" aria-label="Mở bài">
                                <ExternalLink size={14} />
                            </a>
                        ) : null}
                        <button
                            type="button"
                            className={`seeding-ws__btn seeding-ws__btn--primary${genOpen ? ' is-active' : ''}`}
                            disabled={!canMutate || busy}
                            aria-expanded={genOpen}
                            onClick={() => onGenToggle?.(job)}
                        >
                            <Sparkles size={14} /> {genOpen ? 'Đóng Gen' : 'Gen share'}
                        </button>
                    </div>
                </div>
            ) : null}
        </article>
    );
}
