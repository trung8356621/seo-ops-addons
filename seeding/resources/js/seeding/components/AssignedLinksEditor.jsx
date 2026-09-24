import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { makeId, normalizeLink } from '../services/storage';

/**
 * Topic Creator: DB-backed assigned seeding links (title, url, target_per_day).
 *
 * @param {{
 *   links: Array<Record<string, unknown>>,
 *   canMutate?: boolean,
 *   onChange: (links: Array<Record<string, unknown>>) => void,
 * }} props
 */
export default function AssignedLinksEditor({ links = [], canMutate = true, onChange }) {
    const rows = Array.isArray(links) ? links : [];

    const commit = (next) => {
        onChange(next.map((row) => {
            const url = String(row?.url || '').trim();
            if (!url) {
                return {
                    id: String(row?.id || makeId('tlink')),
                    title: String(row?.title || row?.label || '').trim(),
                    label: String(row?.title || row?.label || '').trim(),
                    url: '',
                    target_per_day: Math.max(1, Number(row?.target_per_day) || 5),
                };
            }
            return normalizeLink({
                ...row,
                title: row.title || row.label || '',
                target_per_day: Math.max(1, Number(row.target_per_day) || 5),
            });
        }).filter(Boolean));
    };

    const updateRow = (index, patch) => {
        commit(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const removeRow = (index) => {
        commit(rows.filter((_, i) => i !== index));
    };

    const addRow = () => {
        commit([
            ...rows,
            {
                id: makeId('tlink'),
                title: '',
                url: '',
                target_per_day: 5,
            },
        ]);
    };

    return (
        <section className="seeding-ws__section" data-assigned-links-editor>
            <div className="seeding-ws__section-title">Link seeding giao cho Seeder</div>
            <p className="seeding-ws__sidebar-lead seeding-ws__sidebar-lead--tight">
                Title, URL và Limit/ngày lưu trên Topic (DB). Seeder chỉ ghi tiến độ local theo ngày.
            </p>
            <div className="seeding-ws__assigned-editor">
                {rows.map((row, index) => (
                    <div key={String(row.id || index)} className="seeding-ws__assigned-editor-row">
                        <input
                            className="seeding-ws__input"
                            value={row.title || row.label || ''}
                            disabled={!canMutate}
                            placeholder="Tiêu đề (vd. Shopee - Balo laptop)"
                            onChange={(e) => updateRow(index, { title: e.target.value, label: e.target.value })}
                        />
                        <input
                            className="seeding-ws__input"
                            value={row.url || ''}
                            disabled={!canMutate}
                            placeholder="https://…"
                            onChange={(e) => updateRow(index, { url: e.target.value })}
                        />
                        <input
                            className="seeding-ws__input"
                            type="number"
                            min={1}
                            max={10000}
                            value={row.target_per_day > 0 ? row.target_per_day : 5}
                            disabled={!canMutate}
                            title="Limit / ngày"
                            aria-label="Limit mỗi ngày"
                            onChange={(e) => updateRow(index, {
                                target_per_day: Math.max(1, Number(e.target.value) || 1),
                            })}
                            style={{ maxWidth: '6.5rem' }}
                        />
                        {canMutate ? (
                            <button
                                type="button"
                                className="seeding-ws__icon-btn"
                                onClick={() => removeRow(index)}
                                title="Xóa link"
                                aria-label="Xóa link"
                            >
                                <Trash2 size={14} />
                            </button>
                        ) : null}
                    </div>
                ))}
                {canMutate ? (
                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={addRow}>
                        <Plus size={14} /> Thêm link seeding
                    </button>
                ) : null}
            </div>
        </section>
    );
}
