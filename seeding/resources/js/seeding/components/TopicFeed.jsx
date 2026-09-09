import React from 'react';
import TopicCard from './TopicCard';

/**
 * Vertical CSS grid feed — max 3 columns via CSS.
 *
 * @param {{
 *   topics: Array<Record<string, unknown>>,
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
 *   onCreate: () => void,
 * }} props
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
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShare,
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
                    const id = String(topic.localId || topic.id);
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
                            onOpenDetail={onOpenDetail}
                            onLinksChange={onLinksChange}
                            onCacheUpdate={onCacheUpdate}
                            onEdit={onEdit}
                            onDelete={onDelete}
                            onShare={onShare}
                        />
                    );
                })}
            </div>
        </div>
    );
}
