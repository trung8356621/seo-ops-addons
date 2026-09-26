import { auditT } from '../../i18n-audit.js';
import React, { useState } from 'react';
import { MoreHorizontal, Pencil, Plus, Sparkles, Trash2 } from 'lucide-react';
import { makeId } from '../services/storage';
import { generateSampleComments } from '../api';
import { detectPlatformLabel } from '../services/linkExtract';
import { canDeleteComment, canEditComment } from '../features/workspace/auth';
import { latestCommentsPreview } from '../features/workspace/content';
import { buildCommentRecord } from '../services/linkPreviewPipeline';
import CommentRichBody from './CommentRichBody';

/**
 * Inline comment preview + add/gen/edit/delete for feed cards.
 * Link previews reuse the shared Topic pipeline (compact variant).
 *
 * @param {{
 *   topic: Record<string, unknown>,
 *   canMutate: boolean,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   previewLimit?: number,
 *   compact?: boolean,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   onCommentsChange: (comments: Array<Record<string, unknown>>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 *   onExpandAll?: () => void,
 * }} props
 */
export default function FeedCommentsBlock({
    topic,
    canMutate,
    userId,
    userDisplayName = '',
    previewLimit = 2,
    compact = true,
    linkPreviewCache = {},
    onCommentsChange,
    onCacheUpdate,
    onExpandAll,
}) {
    const all = Array.isArray(topic.comments) ? topic.comments : [];
    const preview = latestCommentsPreview(all, previewLimit);
    const hidden = Math.max(0, all.length - preview.length);

    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState('');
    const [generating, setGenerating] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [editText, setEditText] = useState('');
    const [menuId, setMenuId] = useState(null);
    const [error, setError] = useState(null);

    const stop = (e) => e.stopPropagation();

    const patchCommentLinks = (commentId, links) => {
        onCommentsChange(all.map((c) => (String(c.id) === String(commentId) ? { ...c, links } : c)));
    };

    const addComment = () => {
        const text = draft.trim();
        if (!text) return;
        onCommentsChange([
            ...all,
            buildCommentRecord(text, {
                id: makeId('cmt'),
                state: 'available',
                source: 'manual',
                author_user_id: userId,
                author_display_name: userDisplayName,
            }, linkPreviewCache),
        ]);
        setDraft('');
        setAdding(false);
        setError(null);
    };

    const saveEdit = (id) => {
        const text = editText.trim();
        if (!text) return;
        const target = all.find((c) => c.id === id);
        if (!target || !canEditComment(target, userId, canMutate)) return;
        onCommentsChange(all.map((c) => (
            c.id === id
                ? buildCommentRecord(text, { ...c, id: c.id }, linkPreviewCache)
                : c
        )));
        setEditingId(null);
        setEditText('');
        setMenuId(null);
    };

    const remove = (id) => {
        const target = all.find((c) => c.id === id);
        if (!target || !canDeleteComment(target, userId, canMutate)) return;
        if (!window.confirm(auditT('audit_4c5f55c970a5'))) return;
        onCommentsChange(all.filter((c) => c.id !== id));
        setMenuId(null);
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
                setError(auditT('audit_a0574f73db1f'));
                return;
            }
            onCommentsChange([
                ...all,
                ...incoming.map((text) => buildCommentRecord(String(text), {
                    id: makeId('cmt'),
                    state: 'available',
                    source: 'ai',
                    author_user_id: userId,
                    author_display_name: userDisplayName,
                }, linkPreviewCache)),
            ]);
        } catch (e) {
            setError(e?.message || auditT('audit_9a92ec7fcee3'));
        } finally {
            setGenerating(false);
        }
    };

    return (
        <div className={`seeding-ws__feed-comments${compact ? ' is-compact' : ''}`} onClick={stop} data-section="feed-comments">
            <div className="seeding-ws__feed-comments-head">
                <span className="seeding-ws__section-title">Bình luận · {all.length}</span>
            </div>

            {preview.length === 0 ? (
                <div className="seeding-ws__muted seeding-ws__feed-comments-empty">Chưa có bình luận</div>
            ) : (
                <ul className="seeding-ws__feed-comment-list">
                    {preview.map((c) => {
                        const name = c.author_display_name || (c.source === 'ai' ? 'AI' : auditT('audit_40ffd8d0f1c7'));
                        const editing = editingId === c.id;
                        return (
                            <li key={c.id} className="seeding-ws__feed-comment-item">
                                {editing ? (
                                    <div className="seeding-ws__sample-add">
                                        <textarea
                                            className="seeding-ws__textarea seeding-ws__textarea--sm"
                                            value={editText}
                                            onChange={(e) => setEditText(e.target.value)}
                                            autoFocus
                                        />
                                        <div className="seeding-ws__sample-actions-row">
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setEditingId(null)}>Hủy</button>
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={() => saveEdit(c.id)} disabled={!editText.trim()}>Lưu</button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="seeding-ws__feed-comment-row">
                                        <div className="seeding-ws__feed-comment-main">
                                            <div className="seeding-ws__feed-comment-author">{name}</div>
                                            <CommentRichBody
                                                comment={c}
                                                variant="comment"
                                                clampLines={2}
                                                maxRichPreviews={1}
                                                linkPreviewCache={linkPreviewCache}
                                                onCommentLinksChange={patchCommentLinks}
                                                onCacheUpdate={onCacheUpdate}
                                                className="seeding-ws__feed-comment-text"
                                            />
                                        </div>
                                        {(canEditComment(c, userId, canMutate) || canDeleteComment(c, userId, canMutate)) ? (
                                            <div className="seeding-ws__menu">
                                                <button
                                                    type="button"
                                                    className="seeding-ws__icon-btn"
                                                    aria-label="Comment menu"
                                                    onClick={() => setMenuId(menuId === c.id ? null : c.id)}
                                                >
                                                    <MoreHorizontal size={14} />
                                                </button>
                                                {menuId === c.id ? (
                                                    <div className="seeding-ws__menu-pop">
                                                        {canEditComment(c, userId, canMutate) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setEditingId(c.id);
                                                                    setEditText(c.text);
                                                                    setMenuId(null);
                                                                }}
                                                            >
                                                                <Pencil size={12} /> Sửa
                                                            </button>
                                                        ) : null}
                                                        {canDeleteComment(c, userId, canMutate) ? (
                                                            <button type="button" className="is-danger" onClick={() => remove(c.id)}>
                                                                <Trash2 size={12} /> Xóa
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {hidden > 0 ? (
                <button
                    type="button"
                    className="seeding-ws__linkish"
                    onClick={() => onExpandAll?.()}
                >
                    Xem thêm {hidden} bình luận
                </button>
            ) : null}

            {canMutate ? (
                <div className="seeding-ws__sample-actions-row" data-actions="comment-create">
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--ghost"
                        onClick={() => { setAdding(true); setError(null); }}
                    >
                        <Plus size={14} /> Bình luận
                    </button>
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={gen}
                        disabled={generating}
                    >
                        <Sparkles size={14} /> {generating ? auditT('audit_fa0c2d747d07') : auditT('audit_cffb42e67b79')}
                    </button>
                </div>
            ) : null}

            {adding ? (
                <div className="seeding-ws__sample-add" data-form="manual-add">
                    <textarea
                        className="seeding-ws__textarea seeding-ws__textarea--sm"
                        value={draft}
                        placeholder={auditT('audit_be4f29907b8d')}
                        autoFocus
                        onChange={(e) => setDraft(e.target.value)}
                    />
                    <div className="seeding-ws__sample-actions-row">
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={addComment} disabled={!draft.trim()}>Thêm</button>
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => { setAdding(false); setDraft(''); }}>Hủy</button>
                    </div>
                </div>
            ) : null}

            {error ? <div className="seeding-ws__error">{error}</div> : null}
        </div>
    );
}
