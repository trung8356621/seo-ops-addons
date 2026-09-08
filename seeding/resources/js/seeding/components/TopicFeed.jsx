import React from 'react';
import TopicCard from './TopicCard';

/**
 * Vertical CSS grid feed — max 3 columns via CSS, never forced 4.
 *
 * @param {{
 *   topics: Array<Record<string, unknown>>,
 *   reports: Array<Record<string, unknown>>,
 *   selectedId: string|null,
 *   canMutate: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   onSelect: (topic: Record<string, unknown>) => void,
 *   onOpenDetail: (topic: Record<string, unknown>) => void,
 *   onCommentsChange: (topic: Record<string, unknown>, comments: Array<Record<string, unknown>>) => void,
 *   onLinksChange: (topic: Record<string, unknown>, links: Array<Record<string, unknown>>) => void,
 *   onEdit: (topic: Record<string, unknown>) => void,
 *   onDelete: (topic: Record<string, unknown>) => void,
 *   onShare: (topic: Record<string, unknown>) => void,
 *   onCreate: () => void,
 * }} props
 */
export default function TopicFeed({
    topics,
    reports,
    selectedId,
    canMutate,
    userId,
    userDisplayName = '',
    onSelect,
    onOpenDetail,
    onCommentsChange,
    onLinksChange,
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
        <div className="seeding-ws__feed-grid" data-feed="topics">
            {topics.map((topic) => {
                const id = String(topic.localId || topic.id);
                return (
                    <TopicCard
                        key={id}
                        topic={topic}
                        reports={reports}
                        selected={selectedId != null && String(selectedId) === id}
                        canMutate={canMutate}
                        userId={userId}
                        userDisplayName={userDisplayName}
                        onSelect={onSelect}
                        onOpenDetail={onOpenDetail}
                        onCommentsChange={onCommentsChange}
                        onLinksChange={onLinksChange}
                        onEdit={onEdit}
                        onDelete={onDelete}
                        onShare={onShare}
                    />
                );
            })}
        </div>
    );
}
