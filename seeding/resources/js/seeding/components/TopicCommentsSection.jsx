import React, { useState } from 'react';
import { Plus, Sparkles, Trash2 } from 'lucide-react';
import { makeId } from '../services/storage';
import { generateSampleComments } from '../api';
import { detectPlatformLabel } from '../services/linkExtract';
import { visibleWorkComments } from '../features/workspace/selectors';

/**
 * Unified comments section for Topic Detail.
 * Always shows Add + Gen even when work queue is empty.
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   userId: number|string,
 *   onChange: (comments: Array<Record<string, unknown>>) => void,
 *   onClaim: (comment: Record<string, unknown>) => void,
 * }} props
 */
export default function TopicCommentsSection({
    topic,
    canMutate,
    userId,
    onChange,
    onClaim,
}) {
    const all = Array.isArray(topic.comments) ? topic.comments : [];
    const workList = visibleWorkComments(all, userId);
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState('');
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState(null);

    const addComment = () => {
        const text = draft.trim();
        if (!text) return;
        onChange([
            ...all,
            {
                id: makeId('cmt'),
                text,
                state: 'available',
                source: 'manual',
                claimed_by_user_id: null,
                claimed_at: null,
                completed_at: null,
                created_at: new Date().toISOString(),
            },
        ]);
        setDraft('');
        setAdding(false);
        setError(null);
    };

    const remove = (id) => {
        const target = all.find((c) => c.id === id);
        if (!target || target.state !== 'available') return;
        if (target.claimed_by_user_id || target.completed_at) return;
        onChange(all.filter((c) => c.id !== id));
    };

    const gen = async () => {
        setGenerating(true);
        setError(null);
        try {
            const data = await generateSampleComments({
                full_text: topic.full_text || '',
                social_url: topic.social_url || '',
                platform: detectPlatformLabel(topic.social_url),
                count: 5,
            });
            const incoming = Array.isArray(data?.comments) ? data.comments : [];
            if (incoming.length === 0) {
                setError('AI không trả về bình luận.');
                return;
            }
            onChange([
                ...all,
                ...incoming.map((text) => ({
                    id: makeId('cmt'),
                    text: String(text),
                    state: 'available',
                    source: 'ai',
                    claimed_by_user_id: null,
                    claimed_at: null,
                    completed_at: null,
                    created_at: new Date().toISOString(),
                })),
            ]);
        } catch (e) {
            setError(e?.message || 'Gen bình luận thất bại.');
        } finally {
            setGenerating(false);
        }
    };

    return (
        <section className="seeding-ws__section" data-section="topic-comments">
            <div className="seeding-ws__section-head">
                <div className="seeding-ws__section-title">Bình luận mẫu</div>
                <span className="seeding-ws__muted">{workList.length} việc</span>
            </div>

            {canMutate ? (
                <div className="seeding-ws__sample-actions-row" data-actions="comment-create">
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--ghost"
                        onClick={() => {
                            setAdding(true);
                            setError(null);
                        }}
                    >
                        <Plus size={14} /> Thêm bình luận
                    </button>
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={gen}
                        disabled={generating}
                    >
                        <Sparkles size={14} /> {generating ? 'Đang gen…' : 'Gen bình luận'}
                    </button>
                </div>
            ) : null}

            {adding ? (
                <div className="seeding-ws__sample-add" data-form="manual-add">
                    <textarea
                        className="seeding-ws__textarea seeding-ws__textarea--sm"
                        value={draft}
                        placeholder="Viết bình luận mẫu…"
                        autoFocus
                        onChange={(e) => setDraft(e.target.value)}
                    />
                    <div className="seeding-ws__sample-actions-row">
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--primary"
                            onClick={addComment}
                            disabled={!draft.trim()}
                        >
                            Thêm
                        </button>
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost"
                            onClick={() => {
                                setAdding(false);
                                setDraft('');
                            }}
                        >
                            Hủy
                        </button>
                    </div>
                </div>
            ) : null}

            {error ? <div className="seeding-ws__error">{error}</div> : null}

            {workList.length === 0 ? (
                <div className="seeding-ws__muted" data-empty="comment-work">
                    Chưa có việc trong hàng đợi — thêm hoặc gen bình luận.
                </div>
            ) : (
                <div className="seeding-ws__work-list" data-list="comment-work">
                    {workList.map((c) => {
                        const isShared = (topic.state || 'draft') === 'shared'
                            || (topic.state || '') === 'completed';
                        return (
                            <div
                                key={c.id}
                                className={`seeding-ws__work-item-row${c.state === 'in_progress' ? ' is-mine' : ''}`}
                            >
                                <div className="seeding-ws__work-text">
                                    {c.text}
                                    <div className="seeding-ws__work-badges">
                                        {c.source === 'ai' ? <span className="seeding-ws__chip">AI</span> : (
                                            <span className="seeding-ws__meta-pill">manual</span>
                                        )}
                                        {c.state === 'in_progress' ? (
                                            <span className="seeding-ws__badge seeding-ws__badge--shared">Đang làm</span>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="seeding-ws__work-item-actions">
                                    {canMutate && c.state === 'available' ? (
                                        <button
                                            type="button"
                                            className="seeding-ws__icon-btn"
                                            title="Xóa"
                                            onClick={() => remove(c.id)}
                                        >
                                            <Trash2 size={14} />
                                        </button>
                                    ) : null}
                                    {isShared ? (
                                        <button
                                            type="button"
                                            className="seeding-ws__btn seeding-ws__btn--primary"
                                            onClick={() => onClaim(c)}
                                            disabled={!canMutate}
                                        >
                                            {c.state === 'in_progress' ? 'Tiếp tục' : 'Nhận'}
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
