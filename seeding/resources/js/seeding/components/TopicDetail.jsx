import React from 'react';
import { ArrowLeft, ExternalLink, Pencil, Trash2 } from 'lucide-react';
import ResourceLinks from './ResourceLinks';
import TopicCommentsSection from './TopicCommentsSection';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import { detectPlatformLabel } from '../services/linkExtract';
import { canShareTopic, shareStatusLabel, shareStatusOf, topicDistinctTitle, topicStatusLabel } from '../features/workspace/selectors';

/**
 * Topic detail — still available; feed remains primary surface.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   canDelete: boolean,
 *   canEdit?: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   onBack: () => void,
 *   onDelete: () => void,
 *   onEdit?: () => void,
 *   onCommentsChange: (comments: Array<Record<string, unknown>>) => void,
 *   onShare: () => void,
 *   onClaim: (comment: Record<string, unknown>) => void,
 * }} props
 */
export default function TopicDetail({
    topic,
    canMutate,
    canDelete,
    canEdit = false,
    userId,
    userDisplayName = '',
    onBack,
    onDelete,
    onEdit,
    onCommentsChange,
    onShare,
    onClaim,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const state = topic.state || 'draft';
    const isDraft = state === 'draft';
    const canShare = canShareTopic(topic);
    const shareStatus = shareStatusOf(topic);
    const title = topicDistinctTitle(topic);

    return (
        <div className="seeding-ws__detail" data-view="topic-detail">
            <div className="seeding-ws__detail-bar">
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onBack}>
                    <ArrowLeft size={14} /> Feed
                </button>
                <div className="seeding-ws__detail-bar-actions">
                    {canEdit ? (
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onEdit}>
                            <Pencil size={14} /> Sửa
                        </button>
                    ) : null}
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
                    {title ? <h2 className="seeding-ws__detail-title">{title}</h2> : null}
                    <div className="seeding-ws__detail-meta">
                        <span className={`seeding-ws__badge seeding-ws__badge--${state}`}>
                            {topicStatusLabel(topic)}
                        </span>
                        {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                        <span className={`seeding-ws__share-pill seeding-ws__share-pill--${shareStatus}`}>
                            {shareStatusLabel(shareStatus)}
                        </span>
                    </div>
                </header>

                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Nội dung gốc</div>
                    <ContentWithLinkPreviews text={topic.full_text || '—'} links={topic.links || []} />
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
                    userDisplayName={userDisplayName}
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
