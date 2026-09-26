import { auditT } from '../../i18n-audit.js';
import React from 'react';
import { Copy, Loader2, Sparkles, X } from 'lucide-react';

/**
 * Inline Website Share work panel. Each fixed target owns its generated body.
 */
export default function WebsiteShareGeneratePanel({
    open,
    job,
    canMutate,
    busy = false,
    editingTargetId = null,
    draft = '',
    onDraftChange,
    onGenerate,
    onStartEdit,
    onSaveEdit,
    onCancelEdit,
    onCopy,
    onReport,
    onClose,
}) {
    if (!open || !job) return null;

    const targets = Array.isArray(job.targets) ? job.targets : [];
    const hasTargets = job.has_social_targets !== false && targets.length > 0;
    const hasContent = targets.some((target) => String(target.share_content || '').trim() !== '') || Boolean(job.share_content);

    return (
        <section className="seeding-ws__panel seeding-ws__panel--inline seeding-ws__panel--website-share" data-panel="website-share-generate">
            <div className="seeding-ws__panel-head">
                <h2>Gen share</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label={auditT('audit_a92bd53b48ad')}>
                    <X size={16} />
                </button>
            </div>

            <section className="seeding-ws__section" data-section="website-share-socials">
                <div className="seeding-ws__section-title">Social</div>
                {hasTargets ? (
                    <div className="seeding-ws__ws-social-list">
                        {targets.map((target) => (
                            <span
                                key={target.id || target.social}
                                className={`seeding-ws__ws-social${target.is_complete ? ' is-complete' : ''}`}
                                data-complete={target.is_complete ? 'true' : 'false'}
                            >
                                {target.is_complete ? '✓ ' : ''}{target.social_label || target.social}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="seeding-ws__ws-warning">
                        Chưa cấu hình Social Account active cho domain này.
                    </p>
                )}
            </section>

            <button
                type="button"
                className="seeding-ws__btn seeding-ws__btn--primary seeding-ws__btn--block"
                disabled={!canMutate || busy || job.status === 'scheduled'}
                onClick={() => onGenerate?.(job)}
            >
                {busy ? <Loader2 size={14} className="seeding-ws__spin" /> : <Sparkles size={14} />}
                {busy ? auditT('audit_92d939564357') : (hasContent ? auditT('audit_8ed244690be3') : 'Gen share')}
            </button>

            {hasTargets ? (
                <section className="seeding-ws__section" data-section="website-share-output">
                    <div className="seeding-ws__section-title">Nội dung đã Gen</div>
                    {targets.map((target) => {
                        const content = String(target.share_content || '').trim();
                        const editing = String(editingTargetId) === String(target.id);
                        return (
                            <article key={target.id || target.social} className="seeding-ws__output-card seeding-ws__ws-output" data-social-output={target.social}>
                                <strong>{target.social_label || target.social}</strong>
                                {editing ? (
                                    <textarea className="seeding-ws__textarea" value={draft} onChange={(event) => onDraftChange?.(event.target.value)} rows={5} />
                                ) : (
                                    <p>{content || auditT('audit_bdd3db004085')}</p>
                                )}
                                <div className="seeding-ws__output-actions">
                                    {editing ? (
                                        <>
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" disabled={busy} onClick={() => onSaveEdit?.(job, target)}>Lưu</button>
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" disabled={busy} onClick={onCancelEdit}>Hủy</button>
                                        </>
                                    ) : (
                                        <>
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" disabled={!content} onClick={() => onCopy?.(target)}><Copy size={12} /> Copy</button>
                                            {!target.is_complete ? <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" disabled={!canMutate || busy} onClick={() => onStartEdit?.(target)}>Sửa</button> : null}
                                        </>
                                    )}
                                    {target.is_complete ? <span className="seeding-ws__ws-social is-complete">✓ Đã báo cáo</span> : (
                                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" disabled={!canMutate || busy || !content} onClick={() => onReport?.(job, target)}>Báo cáo</button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </section>
            ) : null}
        </section>
    );
}