import React, { useState } from 'react';
import { Loader2 } from 'lucide-react';
import { t } from '../utils/i18n';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { executeEditorCommand, getEditorCommandHost } from '../utils/editorCommands';
import { parseHtmlToBlocks } from '../utils/contentDocumentHelpers';

function currentEditorHtml() {
    const host = getEditorCommandHost();
    const live = host?.actions?.getExportHtml?.();
    return String(live ?? '');
}

function currentDocumentVersion() {
    const host = getEditorCommandHost();
    return host?.getDocumentVersion?.() ?? null;
}

/**
 * @param {{ articleId: number }} props
 */
export function CtaAutomationPanel({ articleId }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [preview, setPreview] = useState(null);
    const [selected, setSelected] = useState({});
    const [styles, setStyles] = useState({});

    async function run(mode) {
        const id = Number(articleId || 0);
        if (!id || busy) {
            return;
        }
        setBusy(true);
        setError('');
        try {
            const editorHtml = currentEditorHtml();
            const { response, data } = await seoArticleApiFetch(
                `/api/seo/articles/${id}/editor/cta-automation/preview`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        editor_html: editorHtml,
                        mode,
                        document_version: currentDocumentVersion(),
                    }),
                },
            );
            if (!response.ok || data?.success !== true) {
                setPreview(null);
                setError(String(data?.message || t('cta_auto_failed')));
                return;
            }
            const next = {};
            const nextStyles = {};
            (data.changes || []).forEach((change) => {
                next[change.id] = true;
                if (change.style) {
                    nextStyles[change.id] = change.style;
                }
            });
            setSelected(next);
            setStyles(nextStyles);
            setPreview({ ...data, editor_html: editorHtml });
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_auto_failed'));
        } finally {
            setBusy(false);
        }
    }

    async function applyPreview() {
        if (!preview?.preview_token || busy) {
            return;
        }
        const htmlNow = currentEditorHtml();
        if (htmlNow !== preview.editor_html) {
            setError(t('cta_auto_failed'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            const approved = Object.entries(selected)
                .filter(([, on]) => on)
                .map(([id]) => id);
            const { response, data } = await seoArticleApiFetch(
                `/api/seo/articles/${articleId}/editor/cta-automation/apply`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        editor_html: htmlNow,
                        preview_token: preview.preview_token,
                        approved_ids: approved,
                        style_overrides: styles,
                        document_version: currentDocumentVersion(),
                    }),
                },
            );
            if (!response.ok || data?.success !== true || typeof data.html !== 'string') {
                setError(String(data?.message || t('cta_auto_failed')));
                return;
            }
            const replaced = executeEditorCommand('replace_article_document', {
                blocks: parseHtmlToBlocks(data.html),
                expectedDocumentVersion: currentDocumentVersion(),
                historyNote: 'Apply CTA automation',
            });
            if (!replaced?.ok) {
                setError(t('cta_auto_failed'));
                return;
            }
            setPreview(null);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_auto_failed'));
        } finally {
            setBusy(false);
        }
    }

    const summary = preview?.summary || {};

    return (
        <div className="seo-cta-automation">
            <p className="seo-cta-automation__hint">{t('cta_widget_hint')}</p>
            <div className="seo-cta-automation__actions">
                <button type="button" className="seo-cta-automation__btn seo-cta-automation__btn--primary" disabled={busy} onClick={() => run('improve')}>
                    {busy ? <Loader2 size={14} className="animate-spin" aria-hidden /> : null}
                    <span>{t('cta_auto_improve')}</span>
                </button>
                <button type="button" className="seo-cta-automation__btn" disabled={busy} onClick={() => run('regenerate')}>
                    <span>{t('cta_auto_regenerate')}</span>
                </button>
            </div>
            {error ? <p className="seo-cta-automation__error" role="alert">{error}</p> : null}
            {preview ? (
                <div className="seo-cta-automation__preview">
                    <p className="seo-cta-automation__summary">
                        {t('cta_auto_legacy')}: {summary.legacy_detected || 0}
                        {' · '}
                        {t('cta_auto_replacements')}: {summary.replacements || 0}
                        {' · '}
                        {t('cta_auto_insertions')}: {summary.insertions || 0}
                        {' · '}
                        {t('cta_auto_review')}: {summary.needs_review || 0}
                        {' · '}
                        {preview.status}
                    </p>
                    {(preview.changes || []).length === 0 ? <p className="seo-cta-automation__hint">{t('cta_auto_empty')}</p> : null}
                    <ul className="seo-cta-automation__changes">
                        {(preview.changes || []).map((change) => (
                            <li key={change.id} className="seo-cta-automation__change">
                                <label className="seo-cta-automation__check">
                                    <input
                                        type="checkbox"
                                        checked={selected[change.id] !== false}
                                        onChange={(event) => {
                                            setSelected((current) => ({ ...current, [change.id]: event.target.checked }));
                                        }}
                                    />
                                    <span>
                                        {change.kind} · {change.heading || change.section_id}
                                        {change.intent ? ` · ${change.intent}` : ''}
                                        {change.alias ? ` · [${change.alias}]` : ''}
                                    </span>
                                </label>
                                {change.original ? <p className="seo-cta-automation__text">{change.original}</p> : null}
                                {change.replacement ? <p className="seo-cta-automation__text">{change.replacement}</p> : null}
                                {change.kind === 'insert' ? (
                                    <select
                                        className="seo-cta-automation__style"
                                        value={styles[change.id] || change.style || 'soft'}
                                        onChange={(event) => {
                                            setStyles((current) => ({ ...current, [change.id]: event.target.value }));
                                        }}
                                    >
                                        <option value="soft">soft</option>
                                        <option value="consultation">consultation</option>
                                        <option value="conversion">conversion</option>
                                    </select>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                    {(preview.review || []).map((item) => (
                        <p key={item.id} className="seo-cta-automation__warning">
                            {t('cta_auto_review')}: {item.heading || item.section_id} — {item.reason}
                        </p>
                    ))}
                    <button type="button" className="seo-cta-automation__btn seo-cta-automation__btn--primary" disabled={busy} onClick={applyPreview}>
                        {busy ? <Loader2 size={14} className="animate-spin" aria-hidden /> : null}
                        <span>{busy ? t('cta_auto_working') : t('cta_auto_apply')}</span>
                    </button>
                </div>
            ) : null}
        </div>
    );
}
