import React, { useMemo, useState } from 'react';
import { Copy, ExternalLink, Loader2, Minus, Plus, RefreshCw, Sparkles, Trash2, X } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import { linkPoolCapacity, usedTodayByLinkId } from '../services/linkPool';
import { DEFAULT_SEED_QUANTITY } from '../services/seedGenerate';
import { normalizeLink, normalizeUrlKey } from '../services/storage';

/**
 * Minimal Share → quantity → Gen panel.
 *
 * @param {{
 *   open: boolean,
 *   topic: Record<string, unknown>|null,
 *   seedLinks: Array<Record<string, unknown>>,
 *   seedOutputs: Array<Record<string, unknown>>,
 *   topicOutputs: Array<Record<string, unknown>>,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   canSeed: boolean,
 *   generating?: boolean,
 *   onClose: () => void,
 *   onGenerate: (quantity: number) => void|Promise<void>,
 *   onUpdateOutput: (output: Record<string, unknown>) => void,
 *   onRegenerateOutput: (output: Record<string, unknown>) => void|Promise<void>,
 *   onDeleteOutput: (output: Record<string, unknown>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 * }} props
 */
export default function ShareGeneratePanel({
    open,
    topic,
    seedLinks,
    seedOutputs,
    topicOutputs,
    linkPreviewCache = {},
    canSeed,
    generating = false,
    onClose,
    onGenerate,
    onUpdateOutput,
    onRegenerateOutput,
    onDeleteOutput,
}) {
    const [quantity, setQuantity] = useState(DEFAULT_SEED_QUANTITY);
    const [editingId, setEditingId] = useState(null);
    const [editText, setEditText] = useState('');
    const [regenId, setRegenId] = useState(null);

    const capacity = useMemo(() => {
        const used = usedTodayByLinkId(seedOutputs);
        return linkPoolCapacity(seedLinks, used);
    }, [seedLinks, seedOutputs]);

    if (!open || !topic) return null;

    const emptyPool = (seedLinks || []).filter((l) => l.is_active !== false).length === 0;
    const allAtLimit = !emptyPool && capacity.available === 0 && capacity.active > 0;

    const bump = (delta) => {
        setQuantity((q) => Math.max(1, Math.min(12, q + delta)));
    };

    const copyText = async (text) => {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            /* ignore */
        }
    };

    const startEdit = (out) => {
        setEditingId(String(out.id));
        setEditText(String(out.content || ''));
    };

    const saveEdit = (out) => {
        onUpdateOutput({ ...out, content: editText, updated_at: new Date().toISOString() });
        setEditingId(null);
    };

    const doRegen = async (out) => {
        setRegenId(String(out.id));
        try {
            await onRegenerateOutput(out);
        } finally {
            setRegenId(null);
        }
    };

    return (
        <div className="seeding-ws__panel seeding-ws__panel--share" data-panel="share-generate">
            <div className="seeding-ws__panel-head">
                <h2>Chia sẻ</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label="Đóng">
                    <X size={16} />
                </button>
            </div>

            <div className="seeding-ws__share-qty">
                <span className="seeding-ws__section-title">Số lượng</span>
                <div className="seeding-ws__qty-controls">
                    <button type="button" className="seeding-ws__icon-btn" onClick={() => bump(-1)} disabled={generating}>
                        <Minus size={14} />
                    </button>
                    <strong>{quantity}</strong>
                    <button type="button" className="seeding-ws__icon-btn" onClick={() => bump(1)} disabled={generating}>
                        <Plus size={14} />
                    </button>
                </div>
            </div>

            <dl className="seeding-ws__stat-rows seeding-ws__stat-rows--compact">
                <div className="seeding-ws__stat-row">
                    <dt>Link khả dụng hôm nay</dt>
                    <dd>{capacity.available}</dd>
                </div>
                <div className="seeding-ws__stat-row">
                    <dt>Còn ngưỡng gợi ý</dt>
                    <dd>{capacity.remainingHint}</dd>
                </div>
            </dl>

            {emptyPool ? (
                <div className="seeding-ws__hint">Bạn chưa có link trong Link Pool.</div>
            ) : null}
            {allAtLimit ? (
                <div className="seeding-ws__hint">Các link của bạn đều đã đạt ngưỡng hôm nay. Vẫn có thể Gen.</div>
            ) : null}

            <button
                type="button"
                className="seeding-ws__btn seeding-ws__btn--primary seeding-ws__btn--block"
                disabled={!canSeed || generating}
                onClick={() => onGenerate(quantity)}
            >
                {generating ? <Loader2 size={14} className="seeding-ws__spin" /> : <Sparkles size={14} />}
                {generating ? 'Đang Gen…' : 'Gen'}
            </button>

            {topicOutputs.length > 0 ? (
                <section className="seeding-ws__section" data-section="seed-outputs">
                    <div className="seeding-ws__section-title">Nội dung đã Gen</div>
                    <ul className="seeding-ws__output-list">
                        {topicOutputs.map((out) => {
                            const linkStub = out.url
                                ? normalizeLink({
                                    url: out.url,
                                    normalized_url: normalizeUrlKey(out.url),
                                    ...(linkPreviewCache[normalizeUrlKey(out.url)] || {}),
                                })
                                : null;
                            const links = linkStub ? [linkStub] : [];
                            const isEditing = editingId === String(out.id);
                            const isRegen = regenId === String(out.id);

                            return (
                                <li key={String(out.id)} className="seeding-ws__output-card">
                                    {isEditing ? (
                                        <>
                                            <textarea
                                                className="seeding-ws__textarea"
                                                value={editText}
                                                onChange={(e) => setEditText(e.target.value)}
                                                rows={4}
                                            />
                                            <div className="seeding-ws__output-actions">
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={() => saveEdit(out)}>
                                                    Cập nhật
                                                </button>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setEditingId(null)}>
                                                    Hủy
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <ContentWithLinkPreviews
                                                text={String(out.content || '')}
                                                links={links}
                                                variant="comment"
                                                maxRichPreviews={1}
                                            />
                                            {out.url ? (
                                                <a
                                                    className="seeding-ws__output-link"
                                                    href={String(out.url)}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {out.url} <ExternalLink size={12} />
                                                </a>
                                            ) : null}
                                            <div className="seeding-ws__output-actions">
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => copyText(String(out.content || ''))}>
                                                    <Copy size={12} /> Copy
                                                </button>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => startEdit(out)}>
                                                    Sửa
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    disabled={isRegen}
                                                    onClick={() => doRegen(out)}
                                                >
                                                    {isRegen ? <Loader2 size={12} className="seeding-ws__spin" /> : <RefreshCw size={12} />}
                                                    Gen lại
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost is-danger"
                                                    onClick={() => onDeleteOutput(out)}
                                                >
                                                    <Trash2 size={12} /> Xóa
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
