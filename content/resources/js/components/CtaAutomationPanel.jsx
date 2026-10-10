import React, { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { t } from '../utils/i18n';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { executeEditorCommand, getEditorCommandHost } from '../utils/editorCommands';
import { parseHtmlToBlocks } from '../utils/contentDocumentHelpers';
import { requestCtaHeadingNavigation } from '../utils/ctaSectionNavigator';
import SeoSelect from './SeoSelect';

function currentEditorHtml() {
    const host = getEditorCommandHost();
    const live = host?.actions?.getExportHtml?.();
    return String(live ?? '');
}

function currentDocumentVersion() {
    const host = getEditorCommandHost();
    return host?.getDocumentVersion?.() ?? null;
}

const STYLE_VALUES = ['soft', 'consultation', 'conversion'];

function headingLabel(heading) {
    const text = String(heading ?? '').replace(/\s+/g, ' ').trim();
    return text !== '' ? text : t('editor_intro');
}

function kindLabel(kind) {
    if (kind === 'remove') {
        return t('cta_kind_remove');
    }
    if (kind === 'insert') {
        return t('cta_kind_insert');
    }
    return t('cta_kind_review');
}

function intentLabel(intent) {
    const key = `cta_intent_${intent}`;
    const label = t(key);
    return label === key ? '' : label;
}

function reviewMessage(reason) {
    if (reason === 'mixed_content_requires_review') {
        return t('cta_review_mixed');
    }
    return t('cta_review_generic');
}

function styleHelp(style) {
    if (style === 'consultation') {
        return t('cta_style_consultation_help');
    }
    if (style === 'conversion') {
        return t('cta_style_conversion_help');
    }
    return t('cta_style_soft_help');
}

function modeLabel(mode) {
    if (mode === 'regenerate') {
        return t('cta_auto_regenerate');
    }
    if (mode === 'generate') {
        return t('cta_mode_generate');
    }
    return t('cta_auto_improve');
}

function statusLabel(status) {
    const key = `cta_status_${status}`;
    const label = t(key);
    return label === key ? status : label;
}

async function sourceFingerprint(html) {
    if (!window.crypto?.subtle) {
        return '';
    }
    const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(html ?? '')));
    return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function HeadingButton({ sectionId, heading, onMiss }) {
    return (
        <button
            type="button"
            className="seo-cta-automation__heading"
            onClick={() => {
                const host = getEditorCommandHost();
                const ok = requestCtaHeadingNavigation({
                    sectionId,
                    heading,
                    html: currentEditorHtml(),
                    blocks: host?.actions?.getBlocks?.() ?? [],
                    jump: (node) => host?.actions?.jumpToOutlineHeading?.(node),
                });
                if (!ok) {
                    onMiss?.();
                }
            }}
        >
            {headingLabel(heading)}
        </button>
    );
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
    const [latest, setLatest] = useState(null);
    const [runs, setRuns] = useState([]);

    function rememberRun(body) {
        const selectedMap = body?.selections?.selected || {};
        const styleMap = body?.selections?.styles || {};
        const next = {};
        const nextStyles = {};
        (body?.changes || []).forEach((change) => {
            next[change.id] = selectedMap[change.id] !== false;
            if (change.style || styleMap[change.id]) {
                nextStyles[change.id] = styleMap[change.id] || change.style;
            }
        });
        setSelected(next);
        setStyles(nextStyles);
        setPreview({ ...body, editor_html: currentEditorHtml() });
        setLatest(body);
    }

    async function loadRuns() {
        const id = Number(articleId || 0);
        if (!id) {
            return;
        }
        const fingerprint = await sourceFingerprint(currentEditorHtml());
        const query = fingerprint ? `?source_fingerprint=${fingerprint}` : '';
        const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${id}/editor/cta-automation/runs${query}`);
        if (!response.ok || data?.success !== true) {
            return;
        }
        setRuns(Array.isArray(data.runs) ? data.runs : []);
        setLatest(data.run || null);
    }

    useEffect(() => {
        loadRuns().catch(() => {});
    }, [articleId]);

    async function persistSelections(runId, nextSelected, nextStyles) {
        if (!runId) {
            return;
        }
        await seoArticleApiFetch(`/api/seo/articles/${articleId}/editor/cta-automation/runs/${runId}/selections`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ selected: nextSelected, styles: nextStyles }),
        });
    }

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
                        idempotency_key: (window.crypto?.randomUUID?.() || String(Date.now())),
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
                const saved = data.selections?.selected || {};
                const savedStyles = data.selections?.styles || {};
                next[change.id] = saved[change.id] !== false;
                if (change.style || savedStyles[change.id]) {
                    nextStyles[change.id] = savedStyles[change.id] || change.style;
                }
            });
            setSelected(next);
            setStyles(nextStyles);
            setPreview({ ...data, editor_html: editorHtml });
            setLatest(data);
            loadRuns().catch(() => {});
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_auto_failed'));
        } finally {
            setBusy(false);
        }
    }

    async function applyPreview() {
        if (!(preview?.run_id || preview?.preview_token) || busy) {
            return;
        }
        const htmlNow = currentEditorHtml();
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
                        preview_token: preview.run_id || preview.preview_token,
                        run_id: preview.run_id || preview.preview_token,
                        approved_ids: approved,
                        style_overrides: styles,
                        document_version: currentDocumentVersion(),
                        acknowledge_stale: Boolean(latest?.stale),
                    }),
                },
            );
            if (!response.ok || data?.success !== true || typeof data.html !== 'string') {
                if (data?.status === 'stale_preview') {
                    setLatest((current) => ({ ...(current || latest || {}), stale: true }));
                }
                setError(String(data?.message || t('cta_auto_failed')));
                return;
            }
            const replaced = executeEditorCommand('replace_article_document', {
                blocks: parseHtmlToBlocks(data.html),
                expectedDocumentVersion: currentDocumentVersion(),
                historyNote: 'Apply CTA automation',
            });
            if (!replaced?.ok) {
                setError(String(replaced?.error || replaced?.code || t('cta_auto_failed')));
                return;
            }
            const runId = data.run_id || preview.run_id || preview.preview_token;
            if (runId) {
                await seoArticleApiFetch(`/api/seo/articles/${articleId}/editor/cta-automation/runs/${runId}/confirm`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ editor_html: data.html }),
                });
            }
            setPreview(null);
            loadRuns().catch(() => {});
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
            {latest ? (
                <section className="seo-cta-automation__run">
                    <p className="seo-cta-automation__label">{t('cta_run_latest')}</p>
                    <p className="seo-cta-automation__meta">{t('cta_run_mode')}: {modeLabel(latest.mode)}</p>
                    <p className="seo-cta-automation__meta">{t('cta_run_status')}: {statusLabel(latest.status)}</p>
                    {latest.created_at ? <p className="seo-cta-automation__meta">{t('cta_run_time')}: {latest.created_at}</p> : null}
                    <p className="seo-cta-automation__meta">{t('cta_auto_insertions')}: {latest.summary?.insertions || 0}</p>
                    <p className="seo-cta-automation__meta">{t('cta_auto_replacements')}: {latest.summary?.replacements || 0}</p>
                    <p className="seo-cta-automation__meta">{t('cta_run_model')}: {latest.execution?.model || t('cta_run_unknown')}</p>
                    <p className="seo-cta-automation__meta">{t('cta_run_provider')}: {latest.execution?.provider || t('cta_run_unknown')}</p>
                    {latest.stale ? <p className="seo-cta-automation__warning">{t('cta_run_stale')}</p> : null}
                    <button
                        type="button"
                        className="seo-cta-automation__btn"
                        disabled={busy}
                        onClick={async () => {
                            const id = latest.run_id;
                            const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/editor/cta-automation/runs/${id}`);
                            if (response.ok && data?.run) {
                                rememberRun(data.run);
                            }
                        }}
                    >
                        <span>{t('cta_run_reopen')}</span>
                    </button>
                    {runs.length > 1 ? (
                        <SeoSelect
                            size="compact"
                            aria-label={t('cta_run_previous')}
                            value={latest.run_id || ''}
                            options={runs.map((run) => ({
                                value: run.run_id,
                                label: `${modeLabel(run.mode)} · ${statusLabel(run.status)}`,
                            }))}
                            onChange={async (event) => {
                                const id = event.target.value;
                                const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/editor/cta-automation/runs/${id}`);
                                if (response.ok && data?.run) {
                                    rememberRun(data.run);
                                    setLatest(data.run);
                                }
                            }}
                        />
                    ) : null}
                </section>
            ) : null}
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
                    {(preview.changes || []).length === 0 && (preview.review || []).length === 0 ? <p className="seo-cta-automation__hint">{t('cta_auto_empty')}</p> : null}
                    <ul className="seo-cta-automation__changes">
                        {(preview.changes || []).map((change) => {
                            const style = styles[change.id] || change.style || 'soft';
                            const intent = intentLabel(change.intent);
                            return (
                                <li key={change.id} className={`seo-cta-automation__change seo-cta-automation__change--${change.kind}`}>
                                    <div className="seo-cta-automation__change-head">
                                        <input
                                            type="checkbox"
                                            checked={selected[change.id] !== false}
                                            aria-label={headingLabel(change.heading)}
                                        onChange={(event) => {
                                            const next = { ...selected, [change.id]: event.target.checked };
                                            setSelected(next);
                                            const runId = preview.run_id || preview.preview_token;
                                            persistSelections(runId, next, styles);
                                        }}
                                        />
                                        <div className="seo-cta-automation__change-copy">
                                            <HeadingButton sectionId={change.section_id} heading={change.heading} onMiss={() => setError(t('cta_heading_unresolved'))} />
                                            <p className="seo-cta-automation__meta">
                                                {kindLabel(change.kind)}
                                                {intent ? ` · ${intent}` : ''}
                                            </p>
                                        </div>
                                    </div>
                                    {change.original ? (
                                        <p className="seo-cta-automation__text">
                                            <span className="seo-cta-automation__label">{t('cta_text_original')}</span>
                                            {change.original}
                                        </p>
                                    ) : null}
                                    {change.replacement ? (
                                        <p className="seo-cta-automation__text">
                                            <span className="seo-cta-automation__label">{t('cta_text_proposed')}</span>
                                            {change.replacement}
                                        </p>
                                    ) : null}
                                    {change.kind === 'insert' ? (
                                        <div className="seo-cta-automation__style">
                                            <span className="seo-cta-automation__label">{t('cta_style_label')}</span>
                                            <SeoSelect
                                                size="compact"
                                                aria-label={t('cta_style_label')}
                                                value={STYLE_VALUES.includes(style) ? style : 'soft'}
                                                options={[
                                                    { value: 'soft', label: t('cta_style_soft') },
                                                    { value: 'consultation', label: t('cta_style_consultation') },
                                                    { value: 'conversion', label: t('cta_style_conversion') },
                                                ]}
                                                onChange={(event) => {
                                                    const next = { ...styles, [change.id]: event.target.value };
                                                    setStyles(next);
                                                    persistSelections(preview.run_id || preview.preview_token, selected, next);
                                                }}
                                            />
                                            <p className="seo-cta-automation__style-help">{styleHelp(style)}</p>
                                            <p className="seo-cta-automation__style-help">{t('cta_style_scope')}</p>
                                        </div>
                                    ) : null}
                                </li>
                            );
                        })}
                        {(preview.review || []).map((item) => (
                            <li key={item.id} className="seo-cta-automation__change seo-cta-automation__change--review">
                                <HeadingButton sectionId={item.section_id} heading={item.heading} onMiss={() => setError(t('cta_heading_unresolved'))} />
                                <p className="seo-cta-automation__meta">{kindLabel('review')}</p>
                                <p className="seo-cta-automation__warning">{reviewMessage(item.reason)}</p>
                            </li>
                        ))}
                    </ul>
                    <button type="button" className="seo-cta-automation__btn seo-cta-automation__btn--primary" disabled={busy} onClick={applyPreview}>
                        {busy ? <Loader2 size={14} className="animate-spin" aria-hidden /> : null}
                        <span>{busy ? t('cta_auto_working') : t('cta_auto_apply')}</span>
                    </button>
                </div>
            ) : null}
        </div>
    );
}
