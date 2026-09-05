import React from 'react';
import { ArrowLeft, ExternalLink, Trash2 } from 'lucide-react';
import ResourceLinks from './ResourceLinks';
import TopicCommentsSection from './TopicCommentsSection';
import { detectPlatformLabel } from '../services/linkExtract';
import { topicCardTitle, topicStatusLabel } from '../features/workspace/selectors';

/**
 * Step 2 — immutable Topic context + comment work units.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   canDelete: boolean,
 *   userId: number|string,
 *   onBack: () => void,
 *   onDelete: () => void,
 *   onCommentsChange: (comments: Array<Record<string, unknown>>) => void,
 *   onShare: () => void,
 *   onClaim: (comment: Record<string, unknown>) => void,
 * }} props
 */
export default function TopicDetail({
    topic,
    canMutate,
    canDelete,
    userId,
    onBack,
    onDelete,
    onCommentsChange,
    onShare,
    onClaim,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const state = topic.state || 'draft';
    const isDraft = state === 'draft';
    const commentCount = Array.isArray(topic.comments) ? topic.comments.length : 0;
    const availableOrWork = commentCount > 0;
    const canShare = isDraft && availableOrWork;

    return (
        <div className="seeding-ws__detail" data-view="topic-detail">
            <div className="seeding-ws__detail-bar">
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onBack}>
                    <ArrowLeft size={14} /> Feed
                </button>
                <div className="seeding-ws__detail-bar-actions">
                    {canDelete ? (
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--danger" onClick={onDelete}>
                            <Trash2 size={14} /> Xóa
                        </button>
                    ) : null}
                    {isDraft ? (
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--primary"
                            onClick={onShare}
                            disabled={!canMutate || !canShare}
                            title={!canShare ? 'Cần ít nhất 1 bình luận.' : undefined}
                        >
                            Đẩy chia sẻ
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="seeding-ws__detail-main seeding-ws__detail-main--wide">
                <header className="seeding-ws__detail-head">
                    <h2 className="seeding-ws__detail-title">{topicCardTitle(topic)}</h2>
                    <div className="seeding-ws__detail-meta">
                        <span className={`seeding-ws__badge seeding-ws__badge--${state}`}>
                            {topicStatusLabel(topic)}
                        </span>
                        {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                        <span className="seeding-ws__meta-pill">Chỉ đọc</span>
                    </div>
                </header>

                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Nội dung gốc</div>
                    <div className="seeding-ws__readonly-block">{topic.full_text || '—'}</div>
                </section>

                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Link bài social</div>
                    {topic.social_url ? (
                        <div className="seeding-ws__social-row">
                            <div className="seeding-ws__readonly-inline">{topic.social_url}</div>
                            <a className="seeding-ws__btn seeding-ws__btn--ghost" href={topic.social_url} target="_blank" rel="noreferrer">
                                Mở bài <ExternalLink size={14} />
                            </a>
                        </div>
                    ) : (
                        <div className="seeding-ws__muted">Không có link social.</div>
                    )}
                </section>

                <ResourceLinks links={topic.links || []} />

                <TopicCommentsSection
                    topic={topic}
                    canMutate={canMutate}
                    userId={userId}
                    onChange={onCommentsChange}
                    onClaim={onClaim}
                />

                {isDraft && !canShare ? (
                    <div className="seeding-ws__warn">Cần ít nhất 1 bình luận trước khi đẩy chia sẻ.</div>
                ) : null}
            </div>
        </div>
    );
}
