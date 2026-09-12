import React, { useMemo, useState } from 'react';
import { Copy, ExternalLink, Loader2, Minus, Plus, RefreshCw, Sparkles, Trash2, X } from 'lucide-react';
import ContentWithLinkPreviews from './ContentWithLinkPreviews';
import { linkPoolCapacity } from '../services/linkPool';
import { DEFAULT_SEED_QUANTITY } from '../services/seedGenerate';
import { buildCopyPayload, writeClipboard } from '../services/copyComment';
import { normalizeLink, normalizeUrlKey } from '../services/storage';
import { notifySuccess, notifyWarning } from '../services/toast';

/**
 * Gen comment panel for shared topics (not draft share).
 *
 * @param {{
 *   open: boolean,
 *   inline?: boolean,
 *   topic: Record<string, unknown>|null,
 *   seedLinks: Array<Record<string, unknown>>,
 *   linkUsageToday?: Record<string, number>,
 *   topicOutputs: Array<Record<string, unknown>>,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   canSeed: boolean,
 *   generating?: boolean,
 *   onClose: () => void,
 *   onGenerate: (quantity: number) => void|Promise<void>,
 *   onUpdateOutput: (output: Record<string, unknown>) => void,
 *   onRegenerateOutput: (output: Record<string, unknown>) => void|Promise<void>,
 *   onDeleteOutput: (output: Record<string, unknown>) => void,
 *   onReport: (output: Record<string, unknown>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 * }} props
 */
export default function ShareGeneratePanel({
    open,
    inline = false,
    topic,
    seedLinks,
    linkUsageToday = {},
    topicOutputs,
    linkPreviewCache = {},
    canSeed,
    generating = false,
    onClose,
    onGenerate,
    onUpdateOutput,
    onRegenerateOutput,
    onDeleteOutput,
    onReport,
}) {
    const [quantity, setQuantity] = useState(DEFAULT_SEED_QUANTITY);
    const [editingId, setEditingId] = useState(null);
    const [editText, setEditText] = useState('');
    const [regenId, setRegenId] = useState(null);
    const [appendLink, setAppendLink] = useState(true);

    const capacity = useMemo(
        () => linkPoolCapacity(seedLinks, linkUsageToday),
        [seedLinks, linkUsageToday],
    );

    if (!open || !topic) return null;

    const emptyPool = (seedLinks || []).filter((l) => l.is_active !== false).length === 0;
    const allAtLimit = !emptyPool && capacity.available === 0 && capacity.active > 0;
    const progress = Number(topic.current_user_report_count || 0);
    const required = Number(topic.required_report_count || topic.required_comments_per_user || 0);

    const bump = (delta) => {
        setQuantity((q) => Math.max(1, Math.min(12, q + delta)));
    };

    const copyOutput = async (out) => {
        const payload = buildCopyPayload({
            content: String(out.content || ''),
            appendLink: appendLink || Boolean(out.append_link),
            selectedSeedLinkId: out.selected_seed_link_id || out.seed_link_id,
            selectedSeedUrl: out.selected_seed_url || out.url,
            seedLinks,
            linkUsageToday,
        });

        await writeClipboard(payload.text);

        const next = {
            ...out,
            append_link: appendLink,
            selected_seed_link_id: payload.selected_seed_link_id,
            selected_seed_url: payload.selected_seed_url,
            seed_link_id: payload.selected_seed_link_id,
            url: payload.selected_seed_url,
            copied_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
        };
        onUpdateOutput(next);

        if (payload.softLimitReached) {
            notifyWarning('Các link hôm nay đã đủ ngưỡng');
        } else if (payload.appended) {
            notifySuccess('Đã copy comment');
        } else {
            notifySuccess('Đã copy comment');
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
        <div
            className={`seeding-ws__panel seeding-ws__panel--share${inline ? ' seeding-ws__panel--inline' : ''}`}
            data-panel="share-generate"
            data-inline={inline ? '1' : '0'}
        >
            <div className="seeding-ws__panel-head">
                <h2>Gen comment</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label="Đóng">
                    <X size={16} />
                </button>
            </div>

            {required > 0 ? (
                <div className="seeding-ws__progress-line">
                    Tiến độ: {progress} / {required}
                </div>
            ) : null}

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

            <label className="seeding-ws__check">
                <input
                    type="checkbox"
                    checked={appendLink}
                    onChange={(e) => setAppendLink(e.target.checked)}
                />
                Append link khi Copy
            </label>

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
                <div className="seeding-ws__hint">Các link của bạn đều đã đạt ngưỡng hôm nay.</div>
            ) : null}

            <button
                type="button"
                className="seeding-ws__btn seeding-ws__btn--primary seeding-ws__btn--block"
                disabled={!canSeed || generating}
                onClick={() => onGenerate(quantity)}
            >
                {generating ? <Loader2 size={14} className="seeding-ws__spin" /> : <Sparkles size={14} />}
                {generating ? 'Đang Gen…' : 'Gen comment'}
            </button>

            {topicOutputs.length > 0 ? (
                <section className="seeding-ws__section" data-section="seed-outputs">
                    <div className="seeding-ws__section-title">Comment đã Gen</div>
                    <ul className="seeding-ws__output-list">
                        {topicOutputs.map((out) => {
                            const linkUrl = out.selected_seed_url || out.url;
                            const linkStub = linkUrl
                                ? normalizeLink({
                                    url: linkUrl,
                                    normalized_url: normalizeUrlKey(linkUrl),
                                    ...(linkPreviewCache[normalizeUrlKey(linkUrl)] || {}),
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
                                            {linkUrl ? (
                                                <a
                                                    className="seeding-ws__output-link"
                                                    href={String(linkUrl)}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    {linkUrl} <ExternalLink size={12} />
                                                </a>
                                            ) : null}
                                            <div className="seeding-ws__output-actions">
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => copyOutput(out)}>
                                                    <Copy size={12} /> Copy
                                                </button>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={() => onReport(out)}>
                                                    Báo cáo
                                                </button>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => startEdit(out)}>
                                                    Sửa
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__icon-btn"
                                                    disabled={isRegen}
                                                    onClick={() => doRegen(out)}
                                                    aria-label="Gen lại"
                                                >
                                                    {isRegen ? <Loader2 size={14} className="seeding-ws__spin" /> : <RefreshCw size={14} />}
                                                </button>
                                                <button type="button" className="seeding-ws__icon-btn" onClick={() => onDeleteOutput(out)} aria-label="Xóa">
                                                    <Trash2 size={14} />
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
