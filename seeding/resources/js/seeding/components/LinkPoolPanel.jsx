import React, { useMemo, useState } from 'react';
import { Link2, Pause, Pencil, Play, Plus, Trash2, X } from 'lucide-react';
import {
    createSeedLink,
    linkUsageLabel,
    usedTodayByLinkId,
    DEFAULT_DAILY_LIMIT,
    MIN_DAILY_LIMIT,
} from '../services/linkPool';

/**
 * Personal Link Pool manager — current user document only.
 *
 * @param {{
 *   open: boolean,
 *   seedLinks: Array<Record<string, unknown>>,
 *   seedOutputs: Array<Record<string, unknown>>,
 *   canManage: boolean,
 *   onClose: () => void,
 *   onChange: (links: Array<Record<string, unknown>>) => void,
 * }} props
 */
export default function LinkPoolPanel({
    open,
    seedLinks,
    seedOutputs,
    canManage,
    onClose,
    onChange,
}) {
    const usedMap = useMemo(() => usedTodayByLinkId(seedOutputs), [seedOutputs]);
    const [draftUrl, setDraftUrl] = useState('');
    const [draftLimit, setDraftLimit] = useState(DEFAULT_DAILY_LIMIT);
    const [editingId, setEditingId] = useState(null);
    const [editUrl, setEditUrl] = useState('');
    const [editLimit, setEditLimit] = useState(DEFAULT_DAILY_LIMIT);

    if (!open) return null;

    const addLink = () => {
        if (!canManage) return;
        const url = draftUrl.trim();
        if (!url) return;
        const link = createSeedLink({ url, daily_limit: draftLimit });
        if (!link) return;
        onChange([link, ...seedLinks]);
        setDraftUrl('');
        setDraftLimit(DEFAULT_DAILY_LIMIT);
    };

    const startEdit = (link) => {
        setEditingId(String(link.id));
        setEditUrl(String(link.url || ''));
        setEditLimit(Number(link.daily_limit) || DEFAULT_DAILY_LIMIT);
    };

    const saveEdit = () => {
        if (!canManage || !editingId) return;
        const url = editUrl.trim();
        if (!url) return;
        const now = new Date().toISOString();
        onChange(seedLinks.map((l) => (
            String(l.id) === editingId
                ? {
                    ...l,
                    url,
                    daily_limit: Math.max(MIN_DAILY_LIMIT, Number(editLimit) || DEFAULT_DAILY_LIMIT),
                    updated_at: now,
                }
                : l
        )));
        setEditingId(null);
    };

    const toggleActive = (link) => {
        if (!canManage) return;
        onChange(seedLinks.map((l) => (
            String(l.id) === String(link.id)
                ? { ...l, is_active: !l.is_active, updated_at: new Date().toISOString() }
                : l
        )));
    };

    const removeLink = (link) => {
        if (!canManage) return;
        if (!window.confirm('Xóa link khỏi pool của bạn?')) return;
        onChange(seedLinks.filter((l) => String(l.id) !== String(link.id)));
    };

    return (
        <div className="seeding-ws__panel seeding-ws__panel--link-pool" data-panel="link-pool">
            <div className="seeding-ws__panel-head">
                <h2>Link của tôi</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label="Đóng">
                    <X size={16} />
                </button>
            </div>
            <p className="seeding-ws__muted">
                Link Pool cá nhân — ngưỡng mỗi ngày chỉ để gợi ý random, không phải quota cứng.
            </p>

            {canManage ? (
                <div className="seeding-ws__link-pool-add">
                    <input
                        className="seeding-ws__input"
                        value={draftUrl}
                        placeholder="https://…"
                        onChange={(e) => setDraftUrl(e.target.value)}
                    />
                    <label className="seeding-ws__inline-label">
                        Limit/ngày
                        <input
                            className="seeding-ws__input seeding-ws__input--num"
                            type="number"
                            min={MIN_DAILY_LIMIT}
                            value={draftLimit}
                            onChange={(e) => setDraftLimit(Math.max(MIN_DAILY_LIMIT, Number(e.target.value) || MIN_DAILY_LIMIT))}
                        />
                    </label>
                    <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={addLink}>
                        <Plus size={14} /> Thêm link
                    </button>
                </div>
            ) : null}

            {seedLinks.length === 0 ? (
                <div className="seeding-ws__sidebar-empty">Chưa có link trong Link Pool.</div>
            ) : (
                <ul className="seeding-ws__link-pool-list">
                    {seedLinks.map((link) => {
                        const used = usedMap.get(String(link.id)) || 0;
                        const usage = linkUsageLabel(link, used);
                        const isEditing = editingId === String(link.id);
                        return (
                            <li key={String(link.id)} className={`seeding-ws__link-pool-row${!link.is_active ? ' is-paused' : ''}`}>
                                {isEditing ? (
                                    <div className="seeding-ws__link-pool-edit">
                                        <input
                                            className="seeding-ws__input"
                                            value={editUrl}
                                            onChange={(e) => setEditUrl(e.target.value)}
                                        />
                                        <input
                                            className="seeding-ws__input seeding-ws__input--num"
                                            type="number"
                                            min={MIN_DAILY_LIMIT}
                                            value={editLimit}
                                            onChange={(e) => setEditLimit(Math.max(MIN_DAILY_LIMIT, Number(e.target.value) || MIN_DAILY_LIMIT))}
                                        />
                                        <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={saveEdit}>Lưu</button>
                                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setEditingId(null)}>Hủy</button>
                                    </div>
                                ) : (
                                    <>
                                        <div className="seeding-ws__link-pool-main">
                                            <Link2 size={14} />
                                            <a href={String(link.url)} target="_blank" rel="noreferrer" className="seeding-ws__link-pool-url">
                                                {link.label || link.url}
                                            </a>
                                        </div>
                                        <div className="seeding-ws__link-pool-meta">
                                            <span>{usage.text} hôm nay</span>
                                            {usage.atLimit ? (
                                                <span className="seeding-ws__chip seeding-ws__chip--ok">Đủ hôm nay</span>
                                            ) : null}
                                            {!link.is_active ? (
                                                <span className="seeding-ws__chip">Tạm dừng</span>
                                            ) : null}
                                        </div>
                                        {canManage ? (
                                            <div className="seeding-ws__link-pool-actions">
                                                <button type="button" className="seeding-ws__icon-btn" title="Sửa" onClick={() => startEdit(link)}>
                                                    <Pencil size={14} />
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__icon-btn"
                                                    title={link.is_active ? 'Tạm dừng' : 'Bật lại'}
                                                    onClick={() => toggleActive(link)}
                                                >
                                                    {link.is_active ? <Pause size={14} /> : <Play size={14} />}
                                                </button>
                                                <button type="button" className="seeding-ws__icon-btn is-danger" title="Xóa" onClick={() => removeLink(link)}>
                                                    <Trash2 size={14} />
                                                </button>
                                            </div>
                                        ) : null}
                                    </>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
