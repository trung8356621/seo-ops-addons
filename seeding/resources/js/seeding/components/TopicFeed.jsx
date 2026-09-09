import React from 'react';
import TopicCard from './TopicCard';
import { topicKeyOf } from '../services/storage';

/**
 * Vertical CSS grid feed — stable keys; no remount on panel/stats updates.
 */
export default function TopicFeed({
    topics,
    reports,
    seedBatches = [],
    seedOutputs = [],
    canMutate,
    hasWorkspaceAccess = true,
    userId,
    linkPreviewCache = {},
    sharingTopicKey = null,
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShareDraft,
    onGenComment,
    onCreate,
}) {
    if (topics.length === 0) {
        return (
            <div className="seeding-ws__empty-feed">
                <p>Chưa có chủ đề nào.</p>
                {canMutate ? (
                    <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={onCreate}>
                        + Tạo chủ đề
                    </button>
                ) : null}
            </div>
        );
    }

    return (
        <div className="seeding-ws__feed-host" data-feed-host>
            <div className="seeding-ws__feed-grid" data-feed="topics">
                {topics.map((topic) => {
                    const id = topicKeyOf(topic);
                    return (
                        <TopicCard
                            key={id}
                            topic={topic}
                            reports={reports}
                            seedBatches={seedBatches}
                            seedOutputs={seedOutputs}
                            canMutate={canMutate}
                            hasWorkspaceAccess={hasWorkspaceAccess}
                            userId={userId}
                            linkPreviewCache={linkPreviewCache}
                            sharing={sharingTopicKey === id}
                            onOpenDetail={onOpenDetail}
                            onLinksChange={onLinksChange}
                            onCacheUpdate={onCacheUpdate}
                            onEdit={onEdit}
                            onDelete={onDelete}
                            onShareDraft={onShareDraft}
                            onGenComment={onGenComment}
                        />
                    );
                })}
            </div>
        </div>
    );
}
