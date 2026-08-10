import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Check, Copy, X } from 'lucide-react';
import {
    analyzeInlineLinks,
    normalizeInlineLinksWithReport,
    prettyPrintHtml,
} from '../utils/inlineLinkNormalizer';
import { t } from '../utils/i18n';

/**
 * Modal xem HTML hiện tại của block editor + diagnostics anchor.
 *
 * @param {{
 *   open: boolean,
 *   html: string,
 *   onClose: () => void,
 *   onApplyHtml?: (html: string) => { ok: boolean, error?: string },
 * }} props
 */
export default function ArticleHtmlInspectorModal({ open, html, onClose, onApplyHtml }) {
    const [tab, setTab] = useState('source');
    const [draft, setDraft] = useState('');
    const [editing, setEditing] = useState(false);
    const [copied, setCopied] = useState(false);
    const [editError, setEditError] = useState('');
    const [applyNotice, setApplyNotice] = useState('');

    useEffect(() => {
        if (!open) {
            return;
        }

        setTab('source');
        setEditing(false);
        setEditError('');
        setApplyNotice('');
        setCopied(false);
        setDraft(prettyPrintHtml(html));
    }, [open, html]);

    const analysis = useMemo(() => analyzeInlineLinks(html), [html]);
    const previewNormalize = useMemo(() => normalizeInlineLinksWithReport(html), [html]);

    const handleCopy = useCallback(async () => {
        const value = editing ? draft : prettyPrintHtml(html);
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            setCopied(false);
        }
    }, [draft, editing, html]);

    const enableEdit = () => {
        const ok = window.confirm(t('html_inspector_edit_confirm'));
        if (!ok) {
            return;
        }
        setEditing(true);
        setEditError('');
        setApplyNotice('');
        setDraft(prettyPrintHtml(html));
    };

    const applyEdit = () => {
        if (!onApplyHtml) {
            return;
        }

        setEditError('');
        setApplyNotice('');
        const result = onApplyHtml(draft);
        if (!result?.ok) {
            setEditError(result?.error || t('html_inspector_invalid_html'));
            return;
        }

        setEditing(false);
        setApplyNotice(t('html_inspector_applied'));
    };

    if (!open) {
        return null;
    }

    return (
        <div className="seo-html-inspector-overlay" role="presentation" onMouseDown={onClose}>
            <div
                className="seo-html-inspector-modal"
                role="dialog"
                aria-modal="true"
                aria-label={t('html_inspector_title')}
                onMouseDown={(e) => e.stopPropagation()}
            >
                <div className="seo-html-inspector-header">
                    <div className="seo-html-inspector-title-wrap">
                        <span className="seo-html-inspector-badge">&lt;/&gt;</span>
                        <div>
                            <h2 className="seo-html-inspector-title">{t('html_inspector_title')}</h2>
                            <p className="seo-html-inspector-subtitle">{t('html_inspector_subtitle')}</p>
                        </div>
                    </div>
                    <button type="button" className="seo-html-inspector-icon-btn" onClick={onClose} title={t('common_close')}>
                        <X size={16} />
                    </button>
                </div>

                <div className="seo-html-inspector-tabs" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'source'}
                        className={`seo-html-inspector-tab${tab === 'source' ? ' is-active' : ''}`}
                        onClick={() => setTab('source')}
                    >
                        {t('html_inspector_tab_source')}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'diagnostics'}
                        className={`seo-html-inspector-tab${tab === 'diagnostics' ? ' is-active' : ''}`}
                        onClick={() => setTab('diagnostics')}
                    >
                        {t('html_inspector_tab_diagnostics')}
                        {analysis.duplicateAdjacentCount > 0 || analysis.nestedAnchorCount > 0 ? (
                            <span className="seo-html-inspector-tab-dot" aria-hidden />
                        ) : null}
                    </button>
                </div>

                {tab === 'source' ? (
                    <div className="seo-html-inspector-body">
                        <div className="seo-html-inspector-stats">
                            <span>{t('html_inspector_stat_anchors', { count: analysis.anchors })}</span>
                            <span>{t('html_inspector_stat_dup', { count: analysis.duplicateAdjacentCount })}</span>
                            <span>{t('html_inspector_stat_nested', { count: analysis.nestedAnchorCount })}</span>
                            <span>{t('html_inspector_stat_invalid', { count: analysis.invalidHrefCount })}</span>
                        </div>
                        <textarea
                            className="seo-html-inspector-code"
                            value={editing ? draft : prettyPrintHtml(html)}
                            readOnly={!editing}
                            spellCheck={false}
                            wrap="soft"
                            onChange={(e) => setDraft(e.target.value)}
                        />
                        {editError ? <p className="seo-html-inspector-error">{editError}</p> : null}
                        {applyNotice ? <p className="seo-html-inspector-ok">{applyNotice}</p> : null}
                    </div>
                ) : (
                    <div className="seo-html-inspector-body seo-html-inspector-body--diag">
                        <div className="seo-html-inspector-stats">
                            <span>{t('html_inspector_stat_anchors', { count: analysis.anchors })}</span>
                            <span>{t('html_inspector_stat_dup', { count: analysis.duplicateAdjacentCount })}</span>
                            <span>{t('html_inspector_stat_nested', { count: analysis.nestedAnchorCount })}</span>
                            <span>{t('html_inspector_stat_invalid', { count: analysis.invalidHrefCount })}</span>
                        </div>

                        {analysis.warnings.length === 0 ? (
                            <p className="seo-html-inspector-ok">{t('html_inspector_no_issues')}</p>
                        ) : (
                            <ul className="seo-html-inspector-warnings">
                                {analysis.warnings.map((warning) => (
                                    <li key={warning}>{warning}</li>
                                ))}
                            </ul>
                        )}

                        {analysis.splitGroups.length > 0 ? (
                            <div className="seo-html-inspector-split-list">
                                <h3>{t('html_inspector_split_heading')}</h3>
                                <ul>
                                    {analysis.splitGroups.map((group) => (
                                        <li key={`${group.href}-${group.sample}`}>
                                            <code>{group.href}</code>
                                            <span>×{group.count}</span>
                                            <em>«{group.sample}»</em>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        {previewNormalize.changed ? (
                            <div className="seo-html-inspector-preview">
                                <h3>{t('html_inspector_normalize_preview')}</h3>
                                <pre className="seo-html-inspector-pre">{prettyPrintHtml(previewNormalize.html)}</pre>
                            </div>
                        ) : null}
                    </div>
                )}

                <div className="seo-html-inspector-footer">
                    <button type="button" className="seo-html-inspector-btn" onClick={handleCopy}>
                        {copied ? <Check size={14} /> : <Copy size={14} />}
                        <span>{copied ? t('html_inspector_copied') : t('html_inspector_copy')}</span>
                    </button>
                    {!editing ? (
                        <button type="button" className="seo-html-inspector-btn" onClick={enableEdit}>
                            {t('html_inspector_edit')}
                        </button>
                    ) : (
                        <button type="button" className="seo-html-inspector-btn is-primary" onClick={applyEdit}>
                            {t('html_inspector_apply')}
                        </button>
                    )}
                    <button type="button" className="seo-html-inspector-btn is-primary" onClick={onClose}>
                        {t('common_close')}
                    </button>
                </div>
            </div>
        </div>
    );
}
