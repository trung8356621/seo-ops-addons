import React from 'react';
import { ArrowLeft, ExternalLink, Pencil, Share2, Sparkles, Trash2 } from 'lucide-react';
import ResourceLinks from './ResourceLinks';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import { detectPlatformLabel } from '../services/linkExtract';
import {
    isTopicTrending,
    seedStatusLabel,
    seedStatusOf,
    topicDistinctTitle,
} from '../features/workspace/selectors';
import { canSeedTopic, canShareDraftTopic } from '../features/workspace/auth';

/**
 * Topic detail — draft share or Gen comment depending on state.
 */
export default function TopicDetail({
    topic,
    canMutate,
    canDelete,
    canEdit = false,
    hasWorkspaceAccess = true,
    userId = 0,
    onBack,
    onDelete,
    onEdit,
    onShare,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const status = seedStatusOf(topic);
    const title = topicDistinctTitle(topic);
    const isDraft = status === 'draft' || String(topic.localId || '').startsWith('draft:');
    const canShare = canShareDraftTopic(topic, userId, canMutate);
    const canGen = canSeedTopic(topic, { hasWorkspaceAccess, userId });
    const trending = isTopicTrending(topic);
    const primaryEnabled = isDraft ? canShare : canGen;

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
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={onShare}
                        disabled={!primaryEnabled}
                    >
                        {isDraft ? <Share2 size={14} /> : <Sparkles size={14} />}
                        {isDraft ? 'Chia sẻ' : 'Gen comment'}
                    </button>
                </div>
            </div>

            <div className="seeding-ws__detail-main seeding-ws__detail-main--wide">
                <header className="seeding-ws__detail-head">
                    {title ? <h2 className="seeding-ws__detail-title">{title}</h2> : null}
                    <div className="seeding-ws__detail-meta">
                        {trending ? <span className="seeding-ws__chip seeding-ws__chip--hot">Trending</span> : null}
                        {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                        <span className={`seeding-ws__share-pill seeding-ws__share-pill--${status}`}>
                            {seedStatusLabel(status)}
                        </span>
                    </div>
                </header>

                <ContentWithLinkPreviews
                    text={String(topic.full_text || '').trim() || 'Chưa có nội dung.'}
                    links={topic.links || []}
                    maxRichPreviews={2}
                    variant="topic"
                />

                {topic.social_url ? (
                    <a className="seeding-ws__social-link" href={String(topic.social_url)} target="_blank" rel="noreferrer">
                        Mở social <ExternalLink size={12} />
                    </a>
                ) : null}

                <ResourceLinks links={topic.links || []} mode="links-readonly" />
            </div>
        </div>
    );
}
