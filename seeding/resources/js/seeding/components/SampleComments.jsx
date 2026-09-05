import React, { useState } from 'react';
import { Plus, Sparkles, Trash2 } from 'lucide-react';
import { makeId } from '../services/storage';
import { generateSampleComments } from '../api';
import { detectPlatformLabel } from '../services/linkExtract';

/**
 * Draft-only sample comment editor (before share).
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   onChange: (comments: Array<Record<string, unknown>>) => void,
 * }} props
 */
export default function SampleComments({ topic, canMutate, onChange }) {
    const list = Array.isArray(topic.comments) ? topic.comments : [];
    const [draft, setDraft] = useState('');
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState(null);

    const add = () => {
        const text = draft.trim();
        if (!text) return;
        onChange([
            ...list,
            {
                id: makeId('cmt'),
                text,
                state: 'available',
                claimed_by_user_id: null,
                claimed_at: null,
                completed_at: null,
                created_at: new Date().toISOString(),
                source: 'manual',
            },
        ]);
        setDraft('');
    };

    const remove = (id) => {
        const target = list.find((c) => c.id === id);
        if (!target || target.state !== 'available') return;
        onChange(list.filter((c) => c.id !== id));
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
            const next = [
                ...list,
                ...incoming.map((text) => ({
                    id: makeId('cmt'),
                    text: String(text),
                    state: 'available',
                    claimed_by_user_id: null,
                    claimed_at: null,
                    completed_at: null,
                    created_at: new Date().toISOString(),
                    source: 'ai',
                })),
            ];
            onChange(next);
        } catch (e) {
            setError(e?.message || 'Gen bình luận thất bại.');
        } finally {
            setGenerating(false);
        }
    };

    return (
        <section className="seeding-ws__section" data-section="sample-comments">
            <div className="seeding-ws__section-head">
                <div className="seeding-ws__section-title">Bình luận mẫu</div>
                <span className="seeding-ws__muted">{list.length} mục</span>
            </div>

            {list.length === 0 ? (
                <div className="seeding-ws__muted">Chưa có bình luận — thêm thủ công hoặc gen AI.</div>
            ) : (
                <ol className="seeding-ws__sample-list">
                    {list.map((c, i) => (
                        <li key={c.id || i}>
                            <span className="seeding-ws__sample-text">
                                {c.text}
                                {c.source === 'ai' ? <span className="seeding-ws__chip">AI</span> : null}
                            </span>
                            {canMutate && c.state === 'available' ? (
                                <button type="button" className="seeding-ws__icon-btn" title="Xóa" onClick={() => remove(c.id)}>
                                    <Trash2 size={12} />
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ol>
            )}

            {canMutate ? (
                <div className="seeding-ws__sample-add">
                    <textarea
                        className="seeding-ws__textarea seeding-ws__textarea--sm"
                        value={draft}
                        placeholder="Viết bình luận mẫu…"
                        onChange={(e) => setDraft(e.target.value)}
                    />
                    <div className="seeding-ws__sample-actions-row">
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={add} disabled={!draft.trim()}>
                            <Plus size={14} /> Thêm bình luận
                        </button>
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={gen} disabled={generating}>
                            <Sparkles size={14} /> {generating ? 'Đang gen…' : 'Gen bình luận'}
                        </button>
                    </div>
                    {error ? <div className="seeding-ws__error">{error}</div> : null}
                </div>
            ) : null}
        </section>
    );
}
