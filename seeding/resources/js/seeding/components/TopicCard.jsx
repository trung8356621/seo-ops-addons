import React, { useCallback } from 'react';
import { MoreHorizontal, Pencil, Share2, Trash2 } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import useEnsureLinkPreviews from '../hooks/useEnsureLinkPreviews';
import {
    detectPlatformLabel,
    isTopicTrending,
    relativeTime,
    seedStatusLabel,
    seedStatusOf,
    topicDistinctTitle,
} from '../features/workspace/selectors';
import { canDeleteTopic, canEditTopic, canSeedTopic } from '../features/workspace/auth';
import { topicHasWorkHistory } from '../services/storage';

/**
 * Vertical feed card — Chia sẻ opens ShareGeneratePanel (no claim / comments).
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   reports: Array<Record<string, unknown>>,
 *   seedBatches?: Array<Record<string, unknown>>,
 *   seedOutputs?: Array<Record<string, unknown>>,
 *   canMutate: boolean,
 *   hasWorkspaceAccess?: boolean,
 *   userId: number|string,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   onOpenDetail: (topic: Record<string, unknown>) => void,
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
    seedBatches = [],
    seedOutputs = [],
    canMutate,
    hasWorkspaceAccess = true,
    userId,
    linkPreviewCache = {},
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShare,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const title = topicDistinctTitle(topic);
    const status = seedStatusOf(topic);
    const trending = isTopicTrending(topic);
    const [menuOpen, setMenuOpen] = React.useState(false);

    const canEdit = canEditTopic(topic, userId, canMutate);
    const canDel = canDeleteTopic(
        topic,
        userId,
        canMutate,
        reports,
        topicHasWorkHistory,
        { seed_batches: seedBatches, seed_outputs: seedOutputs },
    );
    const canSeed = canSeedTopic(topic, { hasWorkspaceAccess });

    const onTopicLinksChange = useCallback((next) => {
        onLinksChange(topic, next);
    }, [topic, onLinksChange]);

    useEnsureLinkPreviews(topic.links || [], {
        cache: linkPreviewCache,
        onLinksChange: onTopicLinksChange,
        onCacheUpdate,
    });

    return (
        <article className="seeding-ws__vcard" data-topic-card>
            <div className="seeding-ws__vcard-head">
                <div className="seeding-ws__vcard-chips">
                    {trending ? <span className="seeding-ws__chip seeding-ws__chip--hot">Trending</span> : null}
                    {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                    <span className={`seeding-ws__share-pill seeding-ws__share-pill--${status}`}>
                        {seedStatusLabel(status)}
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

            <div className="seeding-ws__vcard-foot">
                <time className="seeding-ws__time">{relativeTime(topic.updated_at)}</time>
                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--primary"
                    disabled={!canSeed}
                    onClick={() => onShare(topic)}
                >
                    <Share2 size={14} /> Chia sẻ
                </button>
            </div>
        </article>
    );
}
