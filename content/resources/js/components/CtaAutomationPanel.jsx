import React, { useState } from 'react';
import { Brackets, Loader2, MapPin, Trash2 } from 'lucide-react';
import { t } from '../utils/i18n';
import { executeEditorCommand, getEditorCommandHost } from '../utils/editorCommands';
import { parseHtmlToBlocks } from '../utils/contentDocumentHelpers';
import { requestCtaHeadingNavigation, scrollEditorTargetIntoView } from '../utils/ctaSectionNavigator';
import {
    detectCtas,
    deleteTextCtaInHtml,
    locateCtaCandidateInDom,
    wrapTextCtaInHtml,
} from '../utils/ctaDetector';

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
    const [scanned, setScanned] = useState(false);
    const [shortcodes, setShortcodes] = useState([]);
    const [texts, setTexts] = useState([]);

    function refreshFromHtml(html) {
        const next = detectCtas(html);
        setShortcodes(next.shortcodes);
        setTexts(next.texts);
        setScanned(true);
    }

    function scan() {
        setBusy(true);
        setError('');
        try {
            refreshFromHtml(currentEditorHtml());
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : t('cta_detect_failed'));
        } finally {
            setBusy(false);
        }
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
                setError(t('cta_detect_delete_mixed'));
            }
            return;
        }
        setBusy(true);
        setError('');
        try {
            const html = currentEditorHtml();
            const result = deleteTextCtaInHtml(html, candidate);
            if (!result.ok) {
                setError(
                    result.code === 'mixed_content'
                        ? t('cta_detect_delete_mixed')
                        : t('cta_detect_delete_failed'),
                );
                return;
            }
            const replaced = applyHtml(result.html, 'Delete CTA text');
            if (!replaced?.ok) {
                setError(String(replaced?.error || replaced?.code || t('cta_detect_delete_failed')));
                return;
            }
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
            </div>
            {error ? <p className="seo-cta-automation__error" role="alert">{error}</p> : null}
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
                                        </div>
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
