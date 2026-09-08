import React from 'react';
import { ExternalLink, PanelRightClose, PanelRightOpen, Pencil, Share2, Trash2 } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import FeedCommentsBlock from './FeedCommentsBlock';
import ResourceLinks from './ResourceLinks';
import LinkPreviewCard from './LinkPreviewCard';
import {
    detectPlatformLabel,
    relativeTime,
    shareStatusLabel,
    shareStatusOf,
    topicDistinctTitle,
    topicStatusLabel,
} from '../features/workspace/selectors';
import { canDeleteTopic, canEditTopic, canShareTopic } from '../features/workspace/auth';
import { hasRichPreview } from '../features/workspace/content';
import { topicHasWorkHistory } from '../services/storage';

/**
 * Module-level contextual sidebar (not edit-page chrome).
 *
 * @param {{
 *   open: boolean,
 *   collapsed: boolean,
 *   topic: Record<string, unknown>|null,
 *   reports: Array<Record<string, unknown>>,
 *   canMutate: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   onToggleCollapse: () => void,
 *   onCommentsChange: (comments: Array<Record<string, unknown>>) => void,
 *   onShare: () => void,
 *   onEdit: () => void,
 *   onDelete: () => void,
 *   onOpenDetail: () => void,
 * }} props
 */
export default function TopicContextSidebar({
    open,
    collapsed,
    topic,
    reports,
    canMutate,
    userId,
    userDisplayName = '',
    onToggleCollapse,
    onCommentsChange,
    onShare,
    onEdit,
    onDelete,
    onOpenDetail,
}) {
    if (!open) return null;

    if (collapsed) {
        return (
            <aside className="seeding-ws__sidebar seeding-ws__sidebar--collapsed" data-sidebar="topic-context">
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Mở sidebar">
                    <PanelRightOpen size={18} />
                </button>
            </aside>
        );
    }

    if (!topic) {
        return (
            <aside className="seeding-ws__sidebar" data-sidebar="topic-context">
                <div className="seeding-ws__sidebar-head">
                    <h2>Chi tiết</h2>
                    <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Thu gọn">
                        <PanelRightClose size={16} />
                    </button>
                </div>
                <div className="seeding-ws__sidebar-empty">
                    Chọn một chủ đề trên feed để xem ngữ cảnh, link và thao tác nhanh.
                </div>
            </aside>
        );
    }

    const platform = detectPlatformLabel(topic.social_url);
    const state = topic.state || 'draft';
    const title = topicDistinctTitle(topic);
    const shareStatus = shareStatusOf(topic);
    const canEdit = canEditTopic(topic, userId, canMutate);
    const canDel = canDeleteTopic(topic, userId, canMutate, reports, topicHasWorkHistory);
    const richLinks = (topic.links || []).filter(hasRichPreview);

    return (
        <aside className="seeding-ws__sidebar" data-sidebar="topic-context">
            <div className="seeding-ws__sidebar-head">
                <h2>Chi tiết chủ đề</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Thu gọn">
                    <PanelRightClose size={16} />
                </button>
            </div>

            <div className="seeding-ws__sidebar-meta">
                <span className={`seeding-ws__badge seeding-ws__badge--${state}`}>{topicStatusLabel(topic)}</span>
                {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                <span className={`seeding-ws__share-pill seeding-ws__share-pill--${shareStatus}`}>
                    {shareStatusLabel(shareStatus)}
                </span>
            </div>

            {title ? <h3 className="seeding-ws__sidebar-title">{title}</h3> : null}

            <ContentWithLinkPreviews
                text={String(topic.full_text || '')}
                links={topic.links || []}
                className="seeding-ws__sidebar-body"
            />

            {topic.social_url ? (
                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Link social</div>
                    <a className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__btn--block" href={topic.social_url} target="_blank" rel="noreferrer">
                        Mở bài <ExternalLink size={14} />
                    </a>
                </section>
            ) : null}

            <ResourceLinks links={topic.links || []} />

            {richLinks.length > 0 ? (
                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Rich preview</div>
                    {richLinks.map((link) => (
                        <LinkPreviewCard key={link.normalized_url || link.url} link={link} />
                    ))}
                </section>
            ) : null}

            <div className="seeding-ws__sidebar-stats">
                <div><span className="seeding-ws__muted">Tạo bởi</span><div>{topic.created_by_display_name || 'Bạn'}</div></div>
                <div><span className="seeding-ws__muted">Thời gian</span><div>{relativeTime(topic.created_at)}</div></div>
                <div><span className="seeding-ws__muted">Bình luận</span><div>{(topic.comments || []).length}</div></div>
            </div>

            <FeedCommentsBlock
                topic={topic}
                canMutate={canMutate}
                userId={userId}
                userDisplayName={userDisplayName}
                previewLimit={3}
                compact={false}
                onCommentsChange={onCommentsChange}
                onExpandAll={onOpenDetail}
            />

            <div className="seeding-ws__sidebar-actions">
                {state === 'draft' ? (
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        disabled={!canMutate || !canShareTopic(topic)}
                        title={!canShareTopic(topic) ? 'Cần ít nhất 1 bình luận.' : undefined}
                        onClick={onShare}
                    >
                        <Share2 size={14} /> Đẩy chia sẻ
                    </button>
                ) : null}
                {canEdit ? (
                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onEdit}>
                        <Pencil size={14} /> Sửa
                    </button>
                ) : null}
                {canDel ? (
                    <button type="button" className="seeding-ws__btn seeding-ws__btn--danger" onClick={onDelete}>
                        <Trash2 size={14} /> Xóa
                    </button>
                ) : null}
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onOpenDetail}>
                    Mở chi tiết
                </button>
            </div>
        </aside>
    );
}
