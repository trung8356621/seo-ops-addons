import React, { useState } from 'react';
import { Brackets, Check, Loader2, MapPin, Sparkles, Trash2, X } from 'lucide-react';
import { t } from '../utils/i18n';
import { executeEditorCommand, getEditorCommandHost } from '../utils/editorCommands';
import { parseHtmlToBlocks } from '../utils/contentDocumentHelpers';
import { requestCtaHeadingNavigation, scrollEditorTargetIntoView } from '../utils/ctaSectionNavigator';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import {
    detectCtas,
    deleteCtaInHtml,
    locateCtaCandidateInDom,
    wrapTextCtaInHtml,
} from '../utils/ctaDetector';
import { applyCtaGenerationPlan, planCtaGeneration } from '../utils/ctaGenerator';

function currentEditorHtml() {
    const host = getEditorCommandHost();
    const live = host?.actions?.getExportHtml?.();
    return String(live ?? '');
}

function currentDocumentVersion() {
    const host = getEditorCommandHost();
    return host?.getDocumentVersion?.() ?? null;
}

function applyHtml(html, historyNote) {
    return executeEditorCommand('replace_article_document', {
        blocks: parseHtmlToBlocks(html),
        expectedDocumentVersion: currentDocumentVersion(),
        historyNote,
    });
}

function truncate(text) {
    const value = String(text ?? '').replace(/\s+/g, ' ').trim();
    if (value.length <= 140) {
        return value;
    }
    return `${value.slice(0, 139)}…`;
}

/**
 * @param {{ articleId?: number }} props
 */
export function CtaAutomationPanel({ articleId: _articleId }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [statusNote, setStatusNote] = useState('');
    const [scanned, setScanned] = useState(false);
    const [shortcodes, setShortcodes] = useState([]);
    const [texts, setTexts] = useState([]);
    const [genPlan, setGenPlan] = useState(null);

    function refreshFromHtml(html) {
        const next = detectCtas(html);
        setShortcodes(next.shortcodes);
        setTexts(next.texts);
        setScanned(true);
    }

    function scan() {
        setBusy(true);
        setError('');
        setStatusNote('');
        try {
            refreshFromHtml(currentEditorHtml());
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_detect_failed'));
        } finally {
            setBusy(false);
        }
    }

    async function previewGenerator() {
        if (busy) {
            return;
        }
        const articleId = Number(_articleId || 0);
        if (articleId <= 0) {
            setError(t('cta_gen_failed'));
            return;
        }
        setBusy(true);
        setError('');
        setStatusNote('');
        setGenPlan(null);
        try {
            const { response, data } = await seoArticleApiFetch(
                `/api/seo/articles/${articleId}/editor/cta-automation/pool`,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok || data?.success === false) {
                setError(String(data?.message || t('cta_gen_failed')));
                return;
            }
            const poolTemplates = Array.isArray(data?.templates) ? data.templates : [];
            const html = currentEditorHtml();
            refreshFromHtml(html);
            const plan = planCtaGeneration(html, poolTemplates);
            setGenPlan(plan);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_gen_failed'));
        } finally {
            setBusy(false);
        }
    }

    function confirmGenerator() {
        if (busy || !genPlan?.canInsert) {
            return;
        }
        setBusy(true);
        setError('');
        try {
            const html = currentEditorHtml();
            const applied = applyCtaGenerationPlan(html, genPlan);
            if (!applied.ok) {
                setError(t('cta_gen_failed'));
                return;
            }
            const replaced = applyHtml(applied.html, 'Generate CTA from pool');
            if (!replaced?.ok) {
                setError(String(replaced?.error || replaced?.code || t('cta_gen_failed')));
                return;
            }
            setGenPlan(null);
            setStatusNote(t('cta_gen_applied').replace('{count}', String(applied.inserted)));
            refreshFromHtml(applied.html);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_gen_failed'));
        } finally {
            setBusy(false);
        }
    }

    function cancelGenerator() {
        setGenPlan(null);
        setError('');
    }

    function goTo(candidate) {
        setError('');
        const located = locateCtaCandidateInDom(candidate);
        if (located) {
            return;
        }
        const host = getEditorCommandHost();
        const ok = requestCtaHeadingNavigation({
            sectionId: candidate.sectionId,
            heading: candidate.heading,
            html: currentEditorHtml(),
            blocks: host?.actions?.getBlocks?.() ?? [],
            jump: (node) => host?.actions?.jumpToOutlineHeading?.(node),
        });
        if (!ok) {
            const pane = document.querySelector('.seo-article-editor-mainpane');
            const section = pane?.querySelector?.(`section[data-seo-section-id]`);
            if (section) {
                scrollEditorTargetIntoView(section, { highlightClass: 'is-cta-jump-highlight' });
            }
            setError(t('cta_heading_unresolved'));
        }
    }

    function wrap(candidate) {
        if (busy || !candidate?.canWrap) {
            if (candidate && !candidate.canWrap) {
                setError(t('cta_detect_already_wrapped'));
            }
            return;
        }
        setBusy(true);
        setError('');
        try {
            const html = currentEditorHtml();
            const result = wrapTextCtaInHtml(html, candidate);
            if (!result.ok) {
                setError(
                    result.code === 'already_wrapped'
                        ? t('cta_detect_already_wrapped')
                        : result.code === 'mixed_content'
                            ? t('cta_detect_delete_mixed')
                            : t('cta_detect_wrap_failed'),
                );
                return;
            }
            if (!result.html.includes('seo-managed-cta') || !/\[seo_ops_cta\b/i.test(result.html)) {
                setError(t('cta_detect_wrap_failed'));
                return;
            }
            const replaced = applyHtml(result.html, 'Wrap CTA shortcode');
            if (!replaced?.ok) {
                setError(String(replaced?.error || replaced?.code || t('cta_detect_wrap_failed')));
                return;
            }
            refreshFromHtml(result.html);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_detect_wrap_failed'));
        } finally {
            setBusy(false);
        }
    }

    function remove(candidate) {
        if (busy || !candidate?.canDelete) {
            if (candidate && !candidate.canDelete) {
                setError(
                    candidate.kind === 'shortcode'
                        ? t('cta_detect_delete_ambiguous')
                        : t('cta_detect_delete_mixed'),
                );
            }
            return;
        }
        if (!window.confirm(t('cta_detect_delete_confirm'))) {
            return;
        }
        setBusy(true);
        setError('');
        try {
            const html = currentEditorHtml();
            const result = deleteCtaInHtml(html, candidate);
            if (!result.ok) {
                setError(
                    result.code === 'mixed_content'
                        ? t('cta_detect_delete_mixed')
                        : result.code === 'ambiguous'
                            ? t('cta_detect_delete_ambiguous')
                            : t('cta_detect_delete_failed'),
                );
                return;
            }
            const replaced = applyHtml(result.html, 'Delete CTA');
            if (!replaced?.ok) {
                setError(String(replaced?.error || replaced?.code || t('cta_detect_delete_failed')));
                return;
            }
            setGenPlan(null);
            refreshFromHtml(result.html);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_detect_delete_failed'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="seo-cta-automation">
            <p className="seo-cta-automation__hint">{t('cta_widget_hint')}</p>
            <div className="seo-cta-automation__actions">
                <button
                    type="button"
                    className="seo-cta-automation__btn seo-cta-automation__btn--primary"
                    disabled={busy}
                    onClick={scan}
                >
                    {busy ? <Loader2 size={14} className="animate-spin" aria-hidden /> : null}
                    <span>{t('cta_detect_scan')}</span>
                </button>
                <button
                    type="button"
                    className="seo-cta-automation__btn"
                    disabled={busy}
                    onClick={previewGenerator}
                >
                    <Sparkles size={14} aria-hidden />
                    <span>{t('cta_gen_button')}</span>
                </button>
            </div>
            {error ? <p className="seo-cta-automation__error" role="alert">{error}</p> : null}
            {statusNote ? <p className="seo-cta-automation__summary">{statusNote}</p> : null}
            {genPlan ? (
                <div className="seo-cta-automation__preview">
                    <p className="seo-cta-automation__label">{t('cta_gen_preview_title')}</p>
                    <p className="seo-cta-automation__summary">
                        {t('cta_gen_density_summary')
                            .replace('{words}', String(genPlan.analysis?.wordCount ?? 0))
                            .replace('{existing}', String(genPlan.analysis?.existingCount ?? 0))
                            .replace('{max}', String(genPlan.analysis?.maxAllowed ?? genPlan.analysis?.targetCtas ?? 0))
                            .replace('{safeH2}', String(genPlan.analysis?.safeH2Count ?? 0))
                            .replace('{safeH3}', String(genPlan.analysis?.safeH3Count ?? 0))
                            .replace('{remaining}', String(genPlan.analysis?.remainingQuota ?? 0))}
                    </p>
                    {!genPlan.canInsert ? (
                        <p className="seo-cta-automation__hint">
                            {genPlan.reason === 'empty_pool'
                                ? t('cta_gen_empty_pool')
                                : genPlan.reason === 'no_safe_position'
                                    ? t('cta_gen_no_safe_position')
                                    : t('cta_gen_no_need')}
                        </p>
                    ) : (
                        <>
                            <ul className="seo-cta-automation__changes">
                                {genPlan.proposals.map((item) => (
                                    <li key={item.id} className="seo-cta-automation__change seo-cta-automation__change--insert">
                                        <p className="seo-cta-automation__meta">
                                            {item.slotId}
                                            {item.intent ? ` · ${item.intent}` : ''}
                                            {item.heading ? ` · ${truncate(item.heading)}` : ''}
                                        </p>
                                        <p className="seo-cta-automation__text">{truncate(item.content)}</p>
                                    </li>
                                ))}
                            </ul>
                            <div className="seo-cta-automation__actions seo-cta-automation__actions--row">
                                <button
                                    type="button"
                                    className="seo-cta-automation__btn seo-cta-automation__btn--primary"
                                    disabled={busy}
                                    onClick={confirmGenerator}
                                >
                                    <Check size={14} aria-hidden />
                                    <span>{t('cta_gen_confirm').replace('{count}', String(genPlan.proposals.length))}</span>
                                </button>
                                <button
                                    type="button"
                                    className="seo-cta-automation__btn"
                                    disabled={busy}
                                    onClick={cancelGenerator}
                                >
                                    <X size={14} aria-hidden />
                                    <span>{t('cta_gen_cancel')}</span>
                                </button>
                            </div>
                        </>
                    )}
                </div>
            ) : null}
            {scanned ? (
                <div className="seo-cta-automation__preview">
                    <p className="seo-cta-automation__summary">
                        {t('cta_detect_shortcode_count')}: {shortcodes.length}
                        {' · '}
                        {t('cta_detect_text_count')}: {texts.length}
                    </p>
                    {shortcodes.length === 0 && texts.length === 0 ? (
                        <p className="seo-cta-automation__hint">{t('cta_detect_empty')}</p>
                    ) : null}

                    {shortcodes.length > 0 ? (
                        <section>
                            <p className="seo-cta-automation__label">{t('cta_detect_shortcode_group')}</p>
                            <ul className="seo-cta-automation__changes">
                                {shortcodes.map((item) => (
                                    <li key={item.id} className="seo-cta-automation__change seo-cta-automation__change--insert">
                                        <p className="seo-cta-automation__meta">
                                            {t('cta_detect_type_shortcode')}
                                            {item.form === 'self_closing' ? ` · ${t('cta_detect_form_self')}` : ` · ${t('cta_detect_form_container')}`}
                                        </p>
                                        <p className="seo-cta-automation__text">{truncate(item.text)}</p>
                                        <div className="seo-cta-automation__actions seo-cta-automation__actions--row">
                                            <button type="button" className="seo-cta-automation__btn" disabled={busy} onClick={() => goTo(item)}>
                                                <MapPin size={14} aria-hidden />
                                                <span>{t('cta_detect_goto')}</span>
                                            </button>
                                            <button
                                                type="button"
                                                className="seo-cta-automation__btn"
                                                disabled={busy || !item.canDelete}
                                                onClick={() => remove(item)}
                                                title={!item.canDelete ? t('cta_detect_delete_ambiguous') : undefined}
                                            >
                                                <Trash2 size={14} aria-hidden />
                                                <span>{t('cta_detect_delete')}</span>
                                            </button>
                                        </div>
                                        {!item.canDelete ? (
                                            <p className="seo-cta-automation__warning">{t('cta_detect_delete_ambiguous')}</p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}

                    {texts.length > 0 ? (
                        <section>
                            <p className="seo-cta-automation__label">{t('cta_detect_text_group')}</p>
                            <ul className="seo-cta-automation__changes">
                                {texts.map((item) => (
                                    <li
                                        key={item.id}
                                        className={`seo-cta-automation__change ${item.confidence === 'uncertain' ? 'seo-cta-automation__change--review' : 'seo-cta-automation__change--remove'}`}
                                    >
                                        <p className="seo-cta-automation__meta">
                                            {t('cta_detect_type_text')}
                                            {item.confidence === 'uncertain' ? ` · ${t('cta_detect_uncertain')}` : ''}
                                        </p>
                                        <p className="seo-cta-automation__text">{truncate(item.excerpt || item.text)}</p>
                                        <div className="seo-cta-automation__actions seo-cta-automation__actions--row">
                                            <button type="button" className="seo-cta-automation__btn" disabled={busy} onClick={() => goTo(item)}>
                                                <MapPin size={14} aria-hidden />
                                                <span>{t('cta_detect_goto')}</span>
                                            </button>
                                            <button
                                                type="button"
                                                className="seo-cta-automation__btn seo-cta-automation__btn--primary"
                                                disabled={busy || !item.canWrap}
                                                onClick={() => wrap(item)}
                                                title={!item.canWrap ? t('cta_detect_already_wrapped') : undefined}
                                            >
                                                <Brackets size={14} aria-hidden />
                                                <span>{t('cta_detect_wrap')}</span>
                                            </button>
                                            <button
                                                type="button"
                                                className="seo-cta-automation__btn"
                                                disabled={busy || !item.canDelete}
                                                onClick={() => remove(item)}
                                                title={!item.canDelete ? t('cta_detect_delete_mixed') : undefined}
                                            >
                                                <Trash2 size={14} aria-hidden />
                                                <span>{t('cta_detect_delete')}</span>
                                            </button>
                                        </div>
                                        {!item.canDelete ? (
                                            <p className="seo-cta-automation__warning">{t('cta_detect_delete_mixed')}</p>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
