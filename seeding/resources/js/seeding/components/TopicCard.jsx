import React from 'react';
import {
    detectPlatformLabel,
    hostOf,
    relativeTime,
    topicCardTitle,
    topicProgress,
    topicStatusLabel,
} from '../features/workspace/selectors';

/**
 * @param {{
 *   topic: Record<string, unknown>,
 *   reports: Array<Record<string, unknown>>,
 *   onOpen: (topic: Record<string, unknown>) => void,
 * }} props
 */
export default function TopicCard({ topic, reports, onOpen }) {
    const platform = detectPlatformLabel(topic.social_url);
    const host = hostOf(topic.social_url);
    const state = topic.state || 'draft';
    const progress = topicProgress(topic, reports);
    const commentTotal = Array.isArray(topic.comments) ? topic.comments.length : 0;

    return (
        <article
            className={`seeding-ws__feed-card seeding-ws__feed-card--${state}`}
            onClick={() => onOpen(topic)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') onOpen(topic);
            }}
            role="button"
            tabIndex={0}
        >
            <div className="seeding-ws__feed-card-head">
                <h3 className="seeding-ws__feed-card-title">{topicCardTitle(topic)}</h3>
                <span className={`seeding-ws__badge seeding-ws__badge--${state}`}>
                    {topicStatusLabel(topic)}
                </span>
            </div>
            <div className="seeding-ws__feed-card-meta">
                {platform ? <span className="seeding-ws__chip">{platform}</span> : null}
                {host ? <span className="seeding-ws__meta-pill">{host}</span> : null}
                {commentTotal > 0 ? (
                    <span className="seeding-ws__meta-pill">{commentTotal} bình luận</span>
                ) : null}
            </div>
            <p className="seeding-ws__feed-card-preview">
                {String(topic.full_text || '').trim() || 'Chưa có nội dung.'}
            </p>
            <div className="seeding-ws__feed-card-foot">
                <span className="seeding-ws__muted">
                    {state === 'shared' || state === 'completed'
                        ? `${progress.done} / ${progress.total} bình luận hoàn tất`
                        : 'Local-first'}
                </span>
                <time className="seeding-ws__time">{relativeTime(topic.updated_at)}</time>
            </div>
        </article>
    );
}
