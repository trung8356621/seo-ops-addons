import React from 'react';
import TopicCard from './TopicCard';

/**
 * @param {{
 *   topics: Array<Record<string, unknown>>,
 *   reports: Array<Record<string, unknown>>,
 *   onOpen: (topic: Record<string, unknown>) => void,
 *   onCreate: () => void,
 *   canMutate: boolean,
 * }} props
 */
export default function TopicFeed({ topics, reports, onOpen, onCreate, canMutate }) {
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
        <div className="seeding-ws__feed" data-feed="topics">
            {topics.map((topic) => (
                <TopicCard
                    key={String(topic.localId || topic.id)}
                    topic={topic}
                    reports={reports}
                    onOpen={onOpen}
                />
            ))}
        </div>
    );
}
