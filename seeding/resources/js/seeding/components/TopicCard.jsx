import React, { useEffect, useRef, useState } from 'react';
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import FeedCommentsBlock from './FeedCommentsBlock';
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
import { fetchLinkPreview } from '../api';

/**
 * Vertical feed card — actions live on the card; no select→sidebar side effect.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   reports: Array<Record<string, unknown>>,
 *   canMutate: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   onOpenDetail: (topic: Record<string, unknown>) => void,
 *   onCommentsChange: (topic: Record<string, unknown>, comments: Array<Record<string, unknown>>) => void,
 *   onLinksChange: (topic: Record<string, unknown>, links: Array<Record<string, unknown>>) => void,
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
    onOpenDetail,
    onCommentsChange,
    onLinksChange,
    onEdit,
    onDelete,
    onShare,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const state = topic.state || 'draft';
    const title = topicDistinctTitle(topic);
    const shareStatus = shareStatusOf(topic);
    const [menuOpen, setMenuOpen] = useState(false);
    const fetchedRef = useRef(false);

    const canEdit = canEditTopic(topic, userId, canMutate);
    const canDel = canDeleteTopic(topic, userId, canMutate, reports, topicHasWorkHistory);
    useEffect(() => {
        if (fetchedRef.current) return;
        const links = Array.isArray(topic.links) ? topic.links : [];
        const pending = links.filter((l) => l.url && !l.preview_fetched_at);
        if (pending.length === 0) return;
        fetchedRef.current = true;
        let cancelled = false;
        (async () => {
            const next = [...links];
            for (let i = 0; i < next.length; i += 1) {
                const link = next[i];
                if (link.preview_fetched_at) continue;
                const meta = await fetchLinkPreview(link.url);
                if (cancelled) return;
                next[i] = {
                    ...link,
                    preview_url: meta.preview_url || link.url,
                    preview_title: meta.preview_title,
                    preview_description: meta.preview_description,
                    preview_image_url: meta.preview_image_url,
                    preview_domain: meta.preview_domain,
                    preview_fetched_at: meta.preview_fetched_at || new Date().toISOString(),
                    preview_status: meta.preview_status || (meta.ok ? 'ok' : 'error'),
                };
            }
            if (!cancelled) onLinksChange(topic, next);
        })();
        return () => { cancelled = true; };
    }, [topic, onLinksChange]);

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
                className="seeding-ws__vcard-body"
            />

            <FeedCommentsBlock
                topic={topic}
                canMutate={canMutate}
                userId={userId}
                userDisplayName={userDisplayName}
                previewLimit={2}
                onCommentsChange={(comments) => onCommentsChange(topic, comments)}
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
