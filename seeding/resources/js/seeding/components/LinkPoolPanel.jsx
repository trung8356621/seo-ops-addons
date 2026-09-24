import React, { useEffect, useState } from 'react';
import { Link2, Pause, Pencil, Play, Plus, Trash2, Upload, X } from 'lucide-react';
import {
    assignmentNumericId,
    createLinkAssignment,
    deleteLinkAssignment,
    fetchMyLinkAssignments,
    importLocalLinkAssignments,
    updateLinkAssignment,
} from '../api';
import { DEFAULT_DAILY_LIMIT, MIN_DAILY_LIMIT } from '../services/linkPool';

/**
 * Creator DB-backed link assignment list (FLOW B manage).
 * Seeder must never receive canManage=true.
 */
export default function LinkPoolPanel({
    open,
    canManage,
    legacySeedLinks = [],
    onClose,
    onAssignmentsChange,
}) {
    const [assignments, setAssignments] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [draftUrl, setDraftUrl] = useState('');
    const [draftLabel, setDraftLabel] = useState('');
    const [draftLimit, setDraftLimit] = useState(DEFAULT_DAILY_LIMIT);
    const [editingId, setEditingId] = useState(null);
    const [editUrl, setEditUrl] = useState('');
    const [editLabel, setEditLabel] = useState('');
    const [editLimit, setEditLimit] = useState(DEFAULT_DAILY_LIMIT);

    const reload = async () => {
        if (!canManage) return;
        setLoading(true);
        try {
            const data = await fetchMyLinkAssignments(false);
            const rows = Array.isArray(data?.assignments) ? data.assignments : [];
            setAssignments(rows);
            if (typeof onAssignmentsChange === 'function') {
                onAssignmentsChange(rows);
            }
        } catch {
            setAssignments([]);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open && canManage) {
            reload();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, canManage]);

    if (!open) return null;

    const addLink = async () => {
        if (!canManage || saving) return;
        const url = draftUrl.trim();
        if (!url) return;
        setSaving(true);
        try {
            const data = await createLinkAssignment({
                title: draftLabel.trim(),
                url,
                target_per_day: Math.max(MIN_DAILY_LIMIT, Number(draftLimit) || DEFAULT_DAILY_LIMIT),
                is_active: true,
            });
            const row = data?.assignment;
            if (row) {
                const next = [row, ...assignments.filter((a) => String(a.id) !== String(row.id))];
                setAssignments(next);
                onAssignmentsChange?.(next);
            } else {
                await reload();
            }
            setDraftUrl('');
            setDraftLabel('');
            setDraftLimit(DEFAULT_DAILY_LIMIT);
        } catch (e) {
            window.alert(e?.message || 'Không thêm được link');
        } finally {
            setSaving(false);
        }
    };

    const startEdit = (link) => {
        setEditingId(String(link.id));
        setEditUrl(String(link.url || ''));
        setEditLabel(String(link.title || link.label || ''));
        setEditLimit(Number(link.target_per_day) || DEFAULT_DAILY_LIMIT);
    };

    const saveEdit = async () => {
        if (!canManage || !editingId || saving) return;
        const numericId = assignmentNumericId(editingId);
        if (!numericId) return;
        const url = editUrl.trim();
        if (!url) return;
        setSaving(true);
        try {
            const data = await updateLinkAssignment(numericId, {
                title: editLabel.trim(),
                url,
                target_per_day: Math.max(MIN_DAILY_LIMIT, Number(editLimit) || DEFAULT_DAILY_LIMIT),
            });
            const row = data?.assignment;
            if (row) {
                const next = assignments.map((a) => (String(a.id) === String(row.id) ? row : a));
                setAssignments(next);
                onAssignmentsChange?.(next);
            } else {
                await reload();
            }
            setEditingId(null);
        } catch (e) {
            window.alert(e?.message || 'Không cập nhật được link');
        } finally {
            setSaving(false);
        }
    };

    const toggleActive = async (link) => {
        if (!canManage || saving) return;
        const numericId = assignmentNumericId(link.id);
        if (!numericId) return;
        setSaving(true);
        try {
            const data = await updateLinkAssignment(numericId, {
                is_active: !link.is_active,
            });
            const row = data?.assignment;
            if (row) {
                const next = assignments.map((a) => (String(a.id) === String(row.id) ? row : a));
                setAssignments(next);
                onAssignmentsChange?.(next);
            } else {
                await reload();
            }
        } catch (e) {
            window.alert(e?.message || 'Không đổi trạng thái link');
        } finally {
            setSaving(false);
        }
    };

    const removeLink = async (link) => {
        if (!canManage || saving) return;
        if (!window.confirm('Xóa link khỏi danh sách seeding?')) return;
        const numericId = assignmentNumericId(link.id);
        if (!numericId) return;
        setSaving(true);
        try {
            await deleteLinkAssignment(numericId);
            const next = assignments.filter((a) => String(a.id) !== String(link.id));
            setAssignments(next);
            onAssignmentsChange?.(next);
        } catch (e) {
            window.alert(e?.message || 'Không xóa được link');
        } finally {
            setSaving(false);
        }
    };

    const importLegacy = async () => {
        if (!canManage || saving) return;
        const locals = Array.isArray(legacySeedLinks) ? legacySeedLinks : [];
        if (locals.length === 0) {
            window.alert('Không có link local để import.');
            return;
        }
        if (!window.confirm(`Import ${locals.length} link local vào DB? (bỏ qua URL trùng)`)) return;
        setSaving(true);
        try {
            const data = await importLocalLinkAssignments(locals.map((l) => ({
                url: l.url,
                title: l.label || l.title || '',
                target_per_day: l.target_per_day || l.daily_limit || DEFAULT_DAILY_LIMIT,
                is_active: l.is_active !== false,
            })));
            const rows = Array.isArray(data?.assignments) ? data.assignments : [];
            setAssignments(rows);
            onAssignmentsChange?.(rows);
            window.alert(data?.message || 'Import xong');
        } catch (e) {
            window.alert(e?.message || 'Import thất bại');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="seeding-ws__panel seeding-ws__panel--link-pool" data-panel="link-pool">
            <div className="seeding-ws__panel-head">
                <h2>Danh sách link</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label="Đóng">
                    <X size={16} />
                </button>
            </div>
            <p className="seeding-ws__muted">
                Link seeding giao việc (DB). Snapshot vào Topic khi tạo/chia sẻ — Seeder chỉ đọc, tiến độ local theo ngày.
            </p>

            {canManage ? (
                <div className="seeding-ws__link-pool-add">
                    <input
                        className="seeding-ws__input"
                        value={draftLabel}
                        placeholder="Tiêu đề (Shopee 1…)"
                        onChange={(e) => setDraftLabel(e.target.value)}
                    />
                    <input
                        className="seeding-ws__input"
                        value={draftUrl}
                        placeholder="https://…"
                        onChange={(e) => setDraftUrl(e.target.value)}
                    />
                    <label className="seeding-ws__inline-label">
                        Target/ngày
                        <input
                            className="seeding-ws__input seeding-ws__input--num"
                            type="number"
                            min={MIN_DAILY_LIMIT}
                            value={draftLimit}
                            onChange={(e) => setDraftLimit(Math.max(MIN_DAILY_LIMIT, Number(e.target.value) || MIN_DAILY_LIMIT))}
                        />
                    </label>
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={addLink}
                        disabled={saving}
                    >
                        <Plus size={14} /> Thêm link
                    </button>
                    {legacySeedLinks.length > 0 ? (
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost"
                            onClick={importLegacy}
                            disabled={saving}
                            title="Import một lần từ seed_links local cũ"
                        >
                            <Upload size={14} /> Import local
                        </button>
                    ) : null}
                </div>
            ) : null}

            {loading ? (
                <div className="seeding-ws__sidebar-empty">Đang tải danh sách link…</div>
            ) : assignments.length === 0 ? (
                <div className="seeding-ws__sidebar-empty">Chưa có link seeding trong DB.</div>
            ) : (
                <ul className="seeding-ws__link-pool-list">
                    {assignments.map((link) => {
                        const target = Number(link.target_per_day) || DEFAULT_DAILY_LIMIT;
                        const isEditing = editingId === String(link.id);
                        return (
                            <li key={String(link.id)} className={`seeding-ws__link-pool-row${!link.is_active ? ' is-paused' : ''}`}>
                                {isEditing ? (
                                    <div className="seeding-ws__link-pool-edit">
                                        <input
                                            className="seeding-ws__input"
                                            value={editLabel}
                                            onChange={(e) => setEditLabel(e.target.value)}
                                            placeholder="Tiêu đề"
                                        />
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
                                        <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={saveEdit} disabled={saving}>Lưu</button>
                                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setEditingId(null)}>Hủy</button>
                                    </div>
                                ) : (
                                    <>
                                        <div className="seeding-ws__link-pool-main">
                                            <Link2 size={14} />
                                            <a href={String(link.url)} target="_blank" rel="noreferrer" className="seeding-ws__link-pool-url">
                                                {link.title || link.label || link.url}
                                            </a>
                                        </div>
                                        <div className="seeding-ws__link-pool-meta">
                                            <span>{target} / ngày</span>
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
