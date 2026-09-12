import React, { useCallback, memo } from 'react';
import { MoreHorizontal, Pencil, Share2, Sparkles, Trash2 } from 'lucide-react';
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
import {
    canDeleteTopic,
    canEditTopic,
    canSeedTopic,
    canShareDraftTopic,
} from '../features/workspace/auth';
import { topicHasWorkHistory } from '../services/storage';

/**
 * Vertical feed card — draft: Chia sẻ→DB; shared: Gen comment.
 */
function TopicCard({
    topic,
    reports,
    seedBatches = [],
    seedOutputs = [],
    canMutate,
    hasWorkspaceAccess = true,
    isManager = false,
    userId,
    linkPreviewCache = {},
    sharing = false,
    genOpen = false,
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShareDraft,
    onGenComment,
}) {
    const platform = topic.social_platform_label
        || detectPlatformLabel(topic.social_url)
        || topic.social_platform
        || null;
    const title = topicDistinctTitle(topic);
    const status = seedStatusOf(topic);
    const trending = isTopicTrending(topic);
    const [menuOpen, setMenuOpen] = React.useState(false);

    const isDraft = status === 'draft' || String(topic.localId || '').startsWith('draft:');
    const canEdit = canEditTopic(topic, userId, canMutate);
    const canDel = canDeleteTopic(
        topic,
        userId,
        canMutate,
        reports,
        topicHasWorkHistory,
        { seed_batches: seedBatches, seed_outputs: seedOutputs },
    );
    const canShare = canShareDraftTopic(topic, userId, canMutate, isManager);
    const canGen = canSeedTopic(topic, { hasWorkspaceAccess, userId });

    const onTopicLinksChange = useCallback((next) => {
        onLinksChange(topic, next);
    }, [topic, onLinksChange]);

    useEnsureLinkPreviews(topic.links || [], {
        cache: linkPreviewCache,
        onLinksChange: onTopicLinksChange,
        onCacheUpdate,
    });

    const progress = Number(topic.completed_comments ?? topic.current_user_report_count ?? 0);
    const required = Number(topic.target_comments || topic.max_comments_target || topic.required_report_count || 0);

    return (
        <article
            className={`seeding-ws__vcard${genOpen ? ' is-gen-open' : ''}`}
            data-topic-card
            data-topic-id={String(topic.id || topic.localId)}
            data-gen-open={genOpen ? '1' : '0'}
        >
            <div className="seeding-ws__vcard-head">
                <div className="seeding-ws__vcard-chips">
                    {trending ? <span className="seeding-ws__chip seeding-ws__chip--hot">Trending</span> : null}
                    {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                    <span className="seeding-ws__chip">Comment</span>
                    <span className={`seeding-ws__share-pill seeding-ws__share-pill--${status}`}>
                        {seedStatusLabel(status)}
                    </span>
                    {!isDraft && required > 0 ? (
                        <span className="seeding-ws__chip">{progress} / {required}</span>
                    ) : null}
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
                <time className="seeding-ws__time">{relativeTime(topic.updated_at || topic.shared_at)}</time>
                {isDraft ? (
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        disabled={!canShare || sharing}
                        onClick={() => onShareDraft(topic)}
                    >
                        <Share2 size={14} /> {sharing ? 'Đang chia sẻ…' : 'Chia sẻ'}
                    </button>
                ) : (
                    <button
                        type="button"
                        className={`seeding-ws__btn seeding-ws__btn--primary${genOpen ? ' is-active' : ''}`}
                        disabled={!canGen}
                        aria-expanded={genOpen}
                        onClick={() => onGenComment(topic)}
                    >
                        <Sparkles size={14} /> {genOpen ? 'Đóng Gen' : 'Gen comment'}
                    </button>
                )}
            </div>
        </article>
    );
}

export default memo(TopicCard);
