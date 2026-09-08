import React, { useCallback, useState } from 'react';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import FeedCommentsBlock from './FeedCommentsBlock';
import useEnsureLinkPreviews from '../hooks/useEnsureLinkPreviews';
import {
    detectPlatformLabel,
    relativeTime,
    shareStatusLabel,
    shareStatusOf,
    topicDistinctTitle,
    topicStatusLabel,
} from '../features/workspace/selectors';
import { canDeleteTopic, canEditTopic } from '../features/workspace/auth';
import { topicHasWorkHistory } from '../services/storage';

/**
 * Vertical feed card — actions live on the card; no select→sidebar side effect.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   reports: Array<Record<string, unknown>>,
 *   canMutate: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   onOpenDetail: (topic: Record<string, unknown>) => void,
 *   onCommentsChange: (topic: Record<string, unknown>, comments: Array<Record<string, unknown>>) => void,
 *   onLinksChange: (topic: Record<string, unknown>, links: Array<Record<string, unknown>>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 *   onEdit: (topic: Record<string, unknown>) => void,
 *   onDelete: (topic: Record<string, unknown>) => void,
 *   onShare: (topic: Record<string, unknown>) => void,
 * }} props
 */
export default function TopicCard({
    topic,
    reports,
    canMutate,
    userId,
    userDisplayName = '',
    linkPreviewCache = {},
    onOpenDetail,
    onCommentsChange,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShare,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const state = topic.state || 'draft';
    const title = topicDistinctTitle(topic);
    const shareStatus = shareStatusOf(topic);
    const [menuOpen, setMenuOpen] = useState(false);

    const canEdit = canEditTopic(topic, userId, canMutate);
    const canDel = canDeleteTopic(topic, userId, canMutate, reports, topicHasWorkHistory);

    const onTopicLinksChange = useCallback((next) => {
        onLinksChange(topic, next);
    }, [topic, onLinksChange]);

    useEnsureLinkPreviews(topic.links || [], {
        cache: linkPreviewCache,
        onLinksChange: onTopicLinksChange,
        onCacheUpdate,
    });

    return (
        <article
            className={`seeding-ws__vcard seeding-ws__vcard--${state}`}
            data-topic-card
        >
            <div className="seeding-ws__vcard-head">
                <div className="seeding-ws__vcard-chips">
                    {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                    <span className={`seeding-ws__badge seeding-ws__badge--${state}`}>{topicStatusLabel(topic)}</span>
                    <span className={`seeding-ws__share-pill seeding-ws__share-pill--${shareStatus}`}>
                        {shareStatusLabel(shareStatus)}
                    </span>
                </div>
                <div className="seeding-ws__menu">
                    <button
                        type="button"
                        className="seeding-ws__icon-btn"
                        aria-label="Topic menu"
                        onClick={() => setMenuOpen((v) => !v)}
                    >
                        <MoreHorizontal size={16} />
                    </button>
                    {menuOpen ? (
                        <div className="seeding-ws__menu-pop">
                            {canEdit ? (
                                <button type="button" onClick={() => { setMenuOpen(false); onEdit(topic); }}>
                                    <Pencil size={12} /> Sửa
                                </button>
                            ) : null}
                            {canDel ? (
                                <button
                                    type="button"
                                    className="is-danger"
                                    onClick={() => { setMenuOpen(false); onDelete(topic); }}
                                >
                                    <Trash2 size={12} /> Xóa
                                </button>
                            ) : null}
                            <button type="button" onClick={() => { setMenuOpen(false); onOpenDetail(topic); }}>
                                Mở chi tiết
                            </button>
                        </div>
                    ) : null}
                </div>
            </div>

            {title ? <h3 className="seeding-ws__vcard-title">{title}</h3> : null}

            <ContentWithLinkPreviews
                text={String(topic.full_text || '').trim() || 'Chưa có nội dung.'}
                links={topic.links || []}
                clampLines={3}
                maxRichPreviews={1}
                variant="topic"
                className="seeding-ws__vcard-body"
            />

            <FeedCommentsBlock
                topic={topic}
                canMutate={canMutate}
                userId={userId}
                userDisplayName={userDisplayName}
                previewLimit={2}
                linkPreviewCache={linkPreviewCache}
                onCommentsChange={(comments) => onCommentsChange(topic, comments)}
                onCacheUpdate={onCacheUpdate}
                onExpandAll={() => onOpenDetail(topic)}
            />

            <div className="seeding-ws__vcard-foot">
                <time className="seeding-ws__time">{relativeTime(topic.updated_at)}</time>
                {state === 'draft' ? (
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        disabled={!canMutate || shareStatus !== 'ready'}
                        title={shareStatus !== 'ready' ? 'Cần ít nhất 1 bình luận.' : undefined}
                        onClick={() => onShare(topic)}
                    >
                        Đẩy chia sẻ
                    </button>
                ) : null}
            </div>
        </article>
    );
}
