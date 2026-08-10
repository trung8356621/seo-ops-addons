import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Loader2, X } from 'lucide-react';
import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { t } from '@content-addon/utils/i18n.js';

function basenameFromUrl(url) {
    const raw = String(url || '').trim();
    if (!raw) {
        return '';
    }
    try {
        const path = new URL(raw, window.location.origin).pathname;
        const base = path.split('/').pop() || '';
        return decodeURIComponent(base);
    } catch {
        const parts = raw.split('?')[0].split('/').filter(Boolean);
        return parts.length > 0 ? parts[parts.length - 1] : '';
    }
}

/**
 * Strong-confirm modal for explicit WordPress media rename (shared editor + library).
 */
export default function WordPressMediaRenameModal() {
    const [open, setOpen] = useState(false);
    const [payload, setPayload] = useState(null);
    const [newSlug, setNewSlug] = useState('');
    const [ack, setAck] = useState(false);
    const [phrase, setPhrase] = useState('');
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    const close = useCallback(() => {
        setOpen(false);
        setPayload(null);
        setPreview(null);
        setAck(false);
        setPhrase('');
        setError('');
        setNewSlug('');
    }, []);

    const loadPreview = useCallback(async (detail, slug) => {
        if (!detail?.siteId || !detail?.attachmentId) {
            return;
        }
        setLoading(true);
        setError('');
        try {
            const { response, data } = await seoArticleApiFetch('/api/seo/media/wordpress/rename/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    site_id: detail.siteId,
                    attachment_id: detail.attachmentId,
                    old_url: detail.oldUrl || '',
                    proposed_slug: slug || '',
                }),
            });
            if (!response.ok || data?.success === false) {
                throw new Error(String(data?.message ?? data?.block_reason ?? 'Không quét được usage.'));
            }
            setPreview(data);
            if (!slug && data.filename) {
                const base = String(data.filename).replace(/\.[^.]+$/, '');
                setNewSlug(base);
            }
        } catch (e) {
            setError(String(e?.message ?? 'Preview failed'));
            setPreview(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        const onOpen = (event) => {
            const detail = event?.detail ?? null;
            if (!detail?.attachmentId || !detail?.siteId) {
                return;
            }
            setPayload(detail);
            setOpen(true);
            setAck(false);
            setPhrase('');
            setError('');
            setNewSlug(String(detail.currentSlug ?? '').trim());
            void loadPreview(detail, String(detail.currentSlug ?? '').trim());
        };
        window.addEventListener('seo-wordpress-media-rename-open', onOpen);
        return () => window.removeEventListener('seo-wordpress-media-rename-open', onOpen);
    }, [loadPreview]);

    const currentFilename = useMemo(() => {
        const fromPreview = String(preview?.filename ?? '').trim();
        if (fromPreview) {
            return fromPreview;
        }
        const fromSlug = String(payload?.currentSlug ?? '').trim();
        if (fromSlug) {
            return fromSlug.includes('.') ? fromSlug : `${fromSlug}.jpg`;
        }
        return basenameFromUrl(preview?.old_url || payload?.oldUrl || '');
    }, [preview, payload]);

    const disableReason = useMemo(() => {
        if (loading) {
            return 'Đang quét usage…';
        }
        if (submitting) {
            return 'Đang đổi tên…';
        }
        if (!preview) {
            return error || 'Chưa có kết quả usage scan.';
        }
        if (preview.scan_complete !== true) {
            return String(
                preview.block_reason
                || preview.message
                || 'Usage scan WordPress chưa hoàn thành — không đổi tên được.',
            );
        }
        if (!String(newSlug).trim()) {
            return 'Nhập filename/slug mới.';
        }
        if (!ack) {
            return 'Cần tick xác nhận URL sẽ đổi.';
        }
        if (phrase.trim() !== 'RENAME') {
            return 'Nhập chính xác RENAME.';
        }
        return '';
    }, [loading, submitting, preview, error, newSlug, ack, phrase]);

    const canSubmit = disableReason === '';

    const submit = async () => {
        if (!canSubmit || !payload) {
            return;
        }
        setSubmitting(true);
        setError('');
        try {
            const { response, data } = await seoArticleApiFetch('/api/seo/media/wordpress/rename', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    site_id: payload.siteId,
                    attachment_id: payload.attachmentId,
                    new_slug: newSlug.trim(),
                    old_url: payload.oldUrl || preview?.old_url || '',
                    acknowledge_url_change: true,
                    confirmation_phrase: 'RENAME',
                    source_action: payload.sourceAction || 'article_editor',
                    article_id: payload.articleId || null,
                }),
            });
            if (!response.ok || data?.success === false) {
                throw new Error(String(data?.message ?? 'Rename failed'));
            }
            // Deprecated zero-listener bus; keep for potential non-editor adapters.
            // Canonical refresh: seo-assistant-widget-health-refresh (host listens).
            window.dispatchEvent(new CustomEvent('seo-wordpress-media-renamed', { detail: data }));
            window.dispatchEvent(new CustomEvent('seo-assistant-widget-health-refresh'));
            close();
        } catch (e) {
            setError(String(e?.message ?? 'Rename failed'));
        } finally {
            setSubmitting(false);
        }
    };

    if (!open) {
        return null;
    }

    const usageCount = Number(preview?.usage_count ?? 0);
    const danger = usageCount > 1;

    return (
        <div className="seo-wp-rename-modal" role="dialog" aria-modal="true">
            <div className="seo-wp-rename-modal__backdrop" onClick={close} />
            <div className={`seo-wp-rename-modal__panel${danger ? ' is-danger' : ''}`}>
                <div className="seo-wp-rename-modal__head">
                    <h3>{t('wp_media_rename_title')}</h3>
                    <button type="button" className="seo-wp-rename-modal__icon-btn" onClick={close} aria-label="Close">
                        <X size={18} />
                    </button>
                </div>
                <div className="seo-wp-rename-modal__body">
                    {(payload?.previewUrl || preview?.old_url) ? (
                        <img
                            src={payload?.previewUrl || preview?.old_url}
                            alt=""
                            className="seo-wp-rename-modal__preview"
                        />
                    ) : null}
                    <p className="seo-wp-rename-modal__warn">
                        <AlertTriangle size={16} />
                        {preview?.warning || t('wp_media_rename_warning')}
                    </p>
                    {usageCount > 1 ? (
                        <p className="seo-wp-rename-modal__usage">
                            {t('wp_media_rename_usage_many', { count: usageCount })}
                        </p>
                    ) : null}
                    <dl className="seo-wp-rename-modal__meta">
                        <div><dt>Attachment ID</dt><dd>{payload?.attachmentId}</dd></div>
                        <div><dt>{t('wp_media_rename_current_file')}</dt><dd>{currentFilename || '—'}</dd></div>
                        <div><dt>URL</dt><dd className="truncate">{preview?.old_url || payload?.oldUrl || '—'}</dd></div>
                    </dl>
                    {Array.isArray(preview?.samples) && preview.samples.length > 0 ? (
                        <ul className="seo-wp-rename-modal__samples">
                            {preview.samples.slice(0, 8).map((sample, index) => (
                                <li key={`${sample.post_id ?? sample.article_id ?? index}`}>
                                    {sample.reference_type}: {sample.title || sample.post_id || sample.article_id}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <p className="seo-wp-rename-modal__note">{preview?.coverage_note}</p>
                    {!preview?.supports_redirect ? (
                        <p className="seo-wp-rename-modal__note">{t('wp_media_rename_no_redirect')}</p>
                    ) : null}
                    <label className="seo-wp-rename-modal__field">
                        <span>{t('wp_media_rename_new_slug')}</span>
                        <input
                            type="text"
                            value={newSlug}
                            onChange={(e) => setNewSlug(e.target.value)}
                            onBlur={() => loadPreview(payload, newSlug)}
                        />
                    </label>
                    <label className="seo-wp-rename-modal__check">
                        <input
                            type="checkbox"
                            checked={ack}
                            onChange={(e) => setAck(e.target.checked)}
                        />
                        <span>{t('wp_media_rename_ack')}</span>
                    </label>
                    <label className="seo-wp-rename-modal__field">
                        <span>{t('wp_media_rename_type_rename')}</span>
                        <input
                            type="text"
                            value={phrase}
                            onChange={(e) => setPhrase(e.target.value)}
                            placeholder="RENAME"
                            autoComplete="off"
                        />
                    </label>
                    {loading ? (
                        <p className="seo-wp-rename-modal__loading">
                            <Loader2 size={14} className="animate-spin" /> Scanning usage…
                        </p>
                    ) : null}
                    {error ? <p className="seo-wp-rename-modal__error">{error}</p> : null}
                    {!canSubmit && !loading ? (
                        <p className="seo-wp-rename-modal__block">{disableReason}</p>
                    ) : null}
                </div>
                <div className="seo-wp-rename-modal__actions">
                    <button type="button" className="seo-wp-rename-modal__btn is-ghost" onClick={close}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="seo-wp-rename-modal__btn is-danger"
                        disabled={!canSubmit}
                        title={canSubmit ? t('wp_media_rename_submit') : disableReason}
                        onClick={submit}
                    >
                        {submitting ? <Loader2 size={14} className="animate-spin" /> : null}
                        <span>{t('wp_media_rename_submit')}</span>
                    </button>
                </div>
            </div>
        </div>
    );
}
