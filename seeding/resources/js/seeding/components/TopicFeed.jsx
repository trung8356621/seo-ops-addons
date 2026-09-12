import React from 'react';
import TopicCard from './TopicCard';
import ShareGeneratePanel from './ShareGeneratePanel';
import { topicKeyOf } from '../services/storage';

/**
 * Vertical CSS grid feed — stable keys; Gen panel expands inline under the active card.
 */
export default function TopicFeed({
    topics,
    reports,
    seedBatches = [],
    seedOutputs = [],
    canMutate,
    hasWorkspaceAccess = true,
    isManager = false,
    userId,
    linkPreviewCache = {},
    sharingTopicKey = null,
    activeGenTopicId = null,
    seedLinks = [],
    linkUsageToday = {},
    generating = false,
    outputsForTopic,
    canSeedTopicFn,
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShareDraft,
    onGenComment,
    onCreate,
    onCloseGen,
    onGenerate,
    onUpdateOutput,
    onRegenerateOutput,
    onDeleteOutput,
    onReport,
}) {
    if (topics.length === 0) {
        return (
            <div className="seeding-ws__empty-feed">
                <p>Chưa có chủ đề nào.</p>
                {canMutate && isManager ? (
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
                    const genOpen = activeGenTopicId != null && String(activeGenTopicId) === String(id);
                    const topicOutputs = typeof outputsForTopic === 'function'
                        ? outputsForTopic(topic)
                        : [];
                    const canSeed = typeof canSeedTopicFn === 'function'
                        ? canSeedTopicFn(topic)
                        : false;

                    return (
                        <div
                            key={id}
                            className={`seeding-ws__feed-item${genOpen ? ' is-gen-open' : ''}`}
                            data-feed-item
                            data-gen-open={genOpen ? '1' : '0'}
                        >
                            <TopicCard
                                topic={topic}
                                reports={reports}
                                seedBatches={seedBatches}
                                seedOutputs={seedOutputs}
                                canMutate={canMutate}
                                hasWorkspaceAccess={hasWorkspaceAccess}
                                isManager={isManager}
                                userId={userId}
                                linkPreviewCache={linkPreviewCache}
                                sharing={sharingTopicKey === id}
                                genOpen={genOpen}
                                onOpenDetail={onOpenDetail}
                                onLinksChange={onLinksChange}
                                onCacheUpdate={onCacheUpdate}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                onShareDraft={onShareDraft}
                                onGenComment={onGenComment}
                            />
                            {genOpen ? (
                                <ShareGeneratePanel
                                    open
                                    inline
                                    topic={topic}
                                    seedLinks={seedLinks}
                                    linkUsageToday={linkUsageToday}
                                    topicOutputs={topicOutputs}
                                    linkPreviewCache={linkPreviewCache}
                                    canSeed={canSeed}
                                    generating={generating}
                                    onClose={onCloseGen}
                                    onGenerate={onGenerate}
                                    onUpdateOutput={onUpdateOutput}
                                    onRegenerateOutput={onRegenerateOutput}
                                    onDeleteOutput={onDeleteOutput}
                                    onReport={onReport}
                                />
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
