import { auditT } from '../../i18n-audit.js';
import React, { useEffect, useMemo, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { fetchLinkAssignments } from '../api';
import { normalizeLink } from '../services/storage';

/**
 * @deprecated Legacy Topic Composer picker (snapshot into topic.links).
 * Current FLOW B manages assignments via LinkPoolPanel → SeedingLinkAssignment only.
 * Kept on disk for historical reference; not mounted in TopicComposer.
 */
export default function AssignedLinksEditor({
    links = [],
    canMutate = true,
    availableAssignments = null,
    onChange,
}) {
    const [catalog, setCatalog] = useState(() => (
        Array.isArray(availableAssignments) ? availableAssignments : []
    ));
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (Array.isArray(availableAssignments)) {
            setCatalog(availableAssignments);
            return;
        }
        if (!canMutate) return;
        let cancelled = false;
        setLoading(true);
        fetchLinkAssignments(true)
            .then((data) => {
                if (cancelled) return;
                setCatalog(Array.isArray(data?.assignments) ? data.assignments : []);
            })
            .catch(() => {
                if (!cancelled) setCatalog([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [availableAssignments, canMutate]);

    const selected = useMemo(() => {
        const rows = Array.isArray(links) ? links : [];
        return rows.map((row) => normalizeLink({
            ...row,
            title: row.title || row.label || '',
            target_per_day: Math.max(0, Number(row.target_per_day) || 0),
        })).filter(Boolean);
    }, [links]);

    const selectedIds = useMemo(
        () => new Set(selected.map((r) => String(r.id))),
        [selected],
    );

    const commit = (next) => {
        onChange(next.map((row) => normalizeLink({
            ...row,
            title: row.title || row.label || '',
            target_per_day: Math.max(1, Number(row.target_per_day) || 5),
        })).filter(Boolean));
    };

    const toggleAssignment = (assignment) => {
        if (!canMutate || !assignment?.id) return;
        const id = String(assignment.id);
        if (selectedIds.has(id)) {
            commit(selected.filter((r) => String(r.id) !== id));
            return;
        }
        commit([
            ...selected,
            {
                id,
                title: assignment.title || assignment.label || '',
                label: assignment.title || assignment.label || '',
                url: assignment.url,
                target_per_day: Math.max(1, Number(assignment.target_per_day) || 5),
            },
        ]);
    };

    const updateSelected = (index, patch) => {
        if (!canMutate) return;
        commit(selected.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const removeSelected = (index) => {
        if (!canMutate) return;
        commit(selected.filter((_, i) => i !== index));
    };

    const activeCatalog = catalog.filter((a) => a && a.is_active !== false);

    return (
        <section className="seeding-ws__section" data-assigned-links-editor>
            <div className="seeding-ws__section-title">Link seeding</div>
            <p className="seeding-ws__sidebar-lead seeding-ws__sidebar-lead--tight">
                Chọn từ danh sách DB. Snapshot title / URL / target/ngày vào Topic khi chia sẻ.
            </p>

            {canMutate && activeCatalog.length > 0 ? (
                <div className="seeding-ws__assigned-catalog" data-assignment-catalog>
                    {loading ? <div className="seeding-ws__muted">Đang tải…</div> : null}
                    {activeCatalog.map((assignment) => {
                        const id = String(assignment.id);
                        const checked = selectedIds.has(id);
                        return (
                            <label key={id} className="seeding-ws__assigned-catalog-row">
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={() => toggleAssignment(assignment)}
                                />
                                <span className="seeding-ws__assigned-catalog-title">
                                    {assignment.title || assignment.label || assignment.url}
                                </span>
                                <span className="seeding-ws__muted">
                                    {Number(assignment.target_per_day) || 5} / ngày
                                </span>
                            </label>
                        );
                    })}
                </div>
            ) : null}

            {canMutate && activeCatalog.length === 0 && !loading ? (
                <p className="seeding-ws__muted">
                    Chưa có link trong danh sách DB. Mở «Danh sách link» để thêm trước.
                </p>
            ) : null}

            <div className="seeding-ws__assigned-editor">
                {selected.map((row, index) => (
                    <div key={String(row.id || index)} className="seeding-ws__assigned-editor-row">
                        <input
                            className="seeding-ws__input"
                            value={row.title || row.label || ''}
                            disabled={!canMutate}
                            placeholder={auditT('audit_6bf2f1a186ad')}
                            onChange={(e) => updateSelected(index, { title: e.target.value, label: e.target.value })}
                        />
                        <input
                            className="seeding-ws__input"
                            value={row.url || ''}
                            disabled={!canMutate}
                            placeholder="https://…"
                            onChange={(e) => updateSelected(index, { url: e.target.value })}
                        />
                        <input
                            className="seeding-ws__input"
                            type="number"
                            min={1}
                            max={10000}
                            value={row.target_per_day > 0 ? row.target_per_day : 5}
                            disabled={!canMutate}
                            title={auditT('audit_512ba5e5dbeb')}
                            aria-label={auditT('audit_d58183c6be82')}
                            onChange={(e) => updateSelected(index, {
                                target_per_day: Math.max(1, Number(e.target.value) || 1),
                            })}
                            style={{ maxWidth: '6.5rem' }}
                        />
                        {canMutate ? (
                            <button
                                type="button"
                                className="seeding-ws__icon-btn"
                                onClick={() => removeSelected(index)}
                                title={auditT('audit_c926e111457e')}
                                aria-label={auditT('audit_c926e111457e')}
                            >
                                <Trash2 size={14} />
                            </button>
                        ) : null}
                    </div>
                ))}
                {canMutate && selected.length === 0 && activeCatalog.length > 0 ? (
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--ghost"
                        onClick={() => {
                            const first = activeCatalog[0];
                            if (first) toggleAssignment(first);
                        }}
                    >
                        <Plus size={14} /> Chọn link đầu tiên
                    </button>
                ) : null}
            </div>
        </section>
    );
}
