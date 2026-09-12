import React from 'react';
import { ExternalLink } from 'lucide-react';
import ResourceLinks from './ResourceLinks';

const SOCIAL_OPTIONS = [
    { value: 'facebook', label: 'Facebook' },
    { value: 'threads', label: 'Threads' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'pinterest', label: 'Pinterest' },
    { value: 'reddit', label: 'Reddit' },
    { value: 'other', label: 'Other' },
];

/**
 * Manager create/edit topic — social required; multi-social → nhiều execution.
 */
export default function TopicComposer({
    topic,
    canMutate,
    mode = 'create',
    onChange,
    onPasteContent,
    onCancel,
    onCreate,
}) {
    const isEdit = mode === 'edit';
    const rows = Array.isArray(topic.social_targets) && topic.social_targets.length > 0
        ? topic.social_targets
        : [{ social_platform: topic.social_platform || 'facebook', target_comments: topic.target_comments || 5 }];

    const setRows = (nextRows) => {
        onChange({
            social_targets: nextRows,
            social_platform: nextRows[0]?.social_platform || '',
            target_comments: Number(nextRows[0]?.target_comments) || 1,
        });
    };

    const updateRow = (index, patch) => {
        setRows(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const addRow = () => {
        setRows([...rows, { social_platform: 'threads', target_comments: 3 }]);
    };

    const removeRow = (index) => {
        if (rows.length <= 1) return;
        setRows(rows.filter((_, i) => i !== index));
    };

    const canSubmit = canMutate
        && String(topic.full_text || '').trim()
        && rows.every((r) => r.social_platform && Number(r.target_comments) > 0);

    return (
        <div className="seeding-ws__composer" data-composer={isEdit ? 'edit' : 'step1'}>
            <div className="seeding-ws__composer-head">
                <h2>{isEdit ? 'Sửa chủ đề' : 'Tạo chủ đề'}</h2>
                <span className="seeding-ws__meta-pill seeding-ws__meta-pill--ok">1 social / execution</span>
            </div>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Tiêu đề</div>
                <input
                    className="seeding-ws__input"
                    value={topic.title || ''}
                    disabled={!canMutate}
                    placeholder="Túi canvas đi học"
                    onChange={(e) => onChange({ title: e.target.value })}
                />
            </section>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Nội dung / mô tả</div>
                <textarea
                    className="seeding-ws__textarea"
                    value={topic.full_text || ''}
                    disabled={!canMutate}
                    placeholder="Nội dung chủ đề…"
                    onPaste={onPasteContent}
                    onChange={(e) => onChange({ full_text: e.target.value })}
                    autoFocus
                />
            </section>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Link</div>
                <div className="seeding-ws__social-row">
                    <input
                        className="seeding-ws__input"
                        value={topic.social_url || ''}
                        disabled={!canMutate}
                        placeholder="https://example.com/…"
                        onChange={(e) => onChange({ social_url: e.target.value })}
                    />
                    {topic.social_url ? (
                        <a className="seeding-ws__icon-btn" href={topic.social_url} target="_blank" rel="noreferrer" title="Mở">
                            <ExternalLink size={14} />
                        </a>
                    ) : null}
                </div>
            </section>

            <section className="seeding-ws__section">
                <div className="seeding-ws__section-title">Social + Max comments (mỗi dòng = 1 topic độc lập)</div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                    {rows.map((row, index) => (
                        <div key={`social-row-${index}`} className="seeding-ws__social-row" style={{ gap: '0.45rem' }}>
                            <select
                                className="seeding-ws__input"
                                value={row.social_platform || ''}
                                disabled={!canMutate}
                                onChange={(e) => updateRow(index, { social_platform: e.target.value })}
                                style={{ maxWidth: '10rem' }}
                            >
                                {SOCIAL_OPTIONS.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </select>
                            <input
                                className="seeding-ws__input"
                                type="number"
                                min={1}
                                max={10000}
                                value={row.target_comments ?? 5}
                                disabled={!canMutate}
                                placeholder="Max comments"
                                onChange={(e) => updateRow(index, { target_comments: Number(e.target.value) || 1 })}
                                style={{ maxWidth: '8rem' }}
                            />
                            {rows.length > 1 ? (
                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => removeRow(index)}>
                                    Xóa
                                </button>
                            ) : null}
                        </div>
                    ))}
                    {!isEdit ? (
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={addRow} disabled={!canMutate}>
                            + Thêm social
                        </button>
                    ) : null}
                </div>
            </section>

            <ResourceLinks links={topic.links || []} />

            <footer className="seeding-ws__composer-footer">
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onCancel}>
                    Hủy
                </button>
                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--primary"
                    onClick={onCreate}
                    disabled={!canSubmit}
                >
                    {isEdit ? 'Lưu' : 'Tạo & chia sẻ'}
                </button>
            </footer>
        </div>
    );
}
