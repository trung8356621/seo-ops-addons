import React from 'react';
import { ArrowLeft, ExternalLink, Pencil, Share2, Trash2 } from 'lucide-react';
import ResourceLinks from './ResourceLinks';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import { detectPlatformLabel } from '../services/linkExtract';
import {
    isTopicTrending,
    seedStatusLabel,
    seedStatusOf,
    topicDistinctTitle,
} from '../features/workspace/selectors';
import { canSeedTopic } from '../features/workspace/auth';

/**
 * Topic detail — idea context only; no sample comments / claim.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   canDelete: boolean,
 *   canEdit?: boolean,
 *   hasWorkspaceAccess?: boolean,
 *   onBack: () => void,
 *   onDelete: () => void,
 *   onEdit?: () => void,
 *   onShare: () => void,
 * }} props
 */
export default function TopicDetail({
    topic,
    canMutate,
    canDelete,
    canEdit = false,
    hasWorkspaceAccess = true,
    onBack,
    onDelete,
    onEdit,
    onShare,
}) {
    const platform = detectPlatformLabel(topic.social_url);
    const status = seedStatusOf(topic);
    const title = topicDistinctTitle(topic);
    const canSeed = canSeedTopic(topic, { hasWorkspaceAccess });
    const trending = isTopicTrending(topic);

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
                        disabled={!canSeed}
                    >
                        <Share2 size={14} /> Chia sẻ
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

                <section className="seeding-ws__section">
                    <div className="seeding-ws__section-title">Nội dung gốc</div>
                    <ContentWithLinkPreviews text={topic.full_text || '—'} links={topic.links || []} variant="topic" />
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
            </div>
        </div>
    );
}
