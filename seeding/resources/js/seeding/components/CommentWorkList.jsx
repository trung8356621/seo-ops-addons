import React from 'react';
import { visibleWorkComments } from '../features/workspace/selectors';

/**
 * Shared-topic work list. Click available → claim (parent handles).
 *
 * @param {{
 *   comments: Array<Record<string, unknown>>,
 *   userId: number|string,
 *   onClaim: (comment: Record<string, unknown>) => void,
 * }} props
 */
export default function CommentWorkList({ comments, userId, onClaim }) {
    const list = visibleWorkComments(comments, userId);

    return (
        <section className="seeding-ws__section" data-section="comment-work">
            <div className="seeding-ws__section-head">
                <div className="seeding-ws__section-title">Bình luận</div>
                <span className="seeding-ws__muted">{list.length} việc</span>
            </div>
            {list.length === 0 ? (
                <div className="seeding-ws__muted">Không còn việc trong hàng đợi.</div>
            ) : (
                <div className="seeding-ws__work-list">
                    {list.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            className={`seeding-ws__work-item${c.state === 'in_progress' ? ' is-mine' : ''}`}
                            onClick={() => onClaim(c)}
                        >
                            <span className="seeding-ws__work-text">{c.text}</span>
                            <span className="seeding-ws__work-action">
                                {c.state === 'in_progress' ? 'Tiếp tục' : 'Nhận'}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </section>
    );
}
