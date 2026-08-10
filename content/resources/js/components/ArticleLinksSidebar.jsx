import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Copy, Link2, Loader2, OctagonAlert, Phone, RotateCcw, Settings2, Trash2, TriangleAlert } from 'lucide-react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { t } from '../utils/i18n';
import ArticleAssistantWidget from '@ai-prompt-addon/components/ArticleAssistantWidget.jsx';
import KeywordReviewPopover from '@seo-addon/components/KeywordReviewPopover.jsx';
import {
    ctaDisplayLabel,
    formatCtaHref,
    isCtaItemInsertable,
} from '../utils/ctaLinkFormat';
import {
    buildVisibleInternalSuggestions,
    filterDomainLinksInArticleContent,
    filterSuggestedInternalLinks,
    isSpecialOrContactHref,
    isSuggestionExcluded,
    mergeSuggestionCatalog,
    normalizeHrefForCompare,
    normalizeLinkLabel,
    partitionSuggestionCatalogBySite,
} from '../utils/articleLinkSuggestionFilter';
import {
    clearExcludedLinkSuggestions,
    loadExcludedLinkSuggestions,
    saveExcludedLinkSuggestions,
} from '../utils/articleExcludedLinkSuggestionsStorage';
import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { ensureKeywordForReview } from '@seo-addon/utils/keywordReviewApi.js';
import {
    normalizeLinksPayload,
    readCoreArticleIdentity,
} from '../utils/articleEditorPayloadAdapters';
import { filterUsableCtaContacts } from '../utils/ctaContactUsability';
import { getEditorInsertionContext } from '../utils/editorInsertionContext';
import { getEditorCommandHost } from '../utils/editorCommands';
import {
    CtaContactInsertList,
    CtaQuickTemplateSettingsPopover,
    dispatchCtaInsert,
    useCtaQuickTemplates,
} from './CtaContactInsertList';

/**
 * Links panel base payload (extracted + domain lists) — no keyword suggestion scan.
 * @param {number} articleId
 * @param {AbortSignal} [signal]
 */
async function fetchEditorLinksBase(articleId, signal) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        throw new Error('Invalid article id');
    }

    const url =
        window.__SEO_EDITOR_LAZY_ENDPOINTS__?.links
        || `/api/seo/articles/${id}/editor/links`;

    const { response, data } = await seoArticleApiFetch(url, {
        method: 'GET',
        signal,
        headers: {
            Accept: 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
    });

    if (!response.ok || data?.success === false) {
        throw new Error(String(data?.message ?? 'links_base_failed'));
    }

    const normalized = normalizeLinksPayload(data);
    const src =
        data && typeof data === 'object' && data.data && typeof data.data === 'object'
            ? data.data
            : data;
    const ctaQuickTemplates =
        src?.cta_quick_templates && typeof src.cta_quick_templates === 'object'
            ? src.cta_quick_templates
            : null;

    return {
        ...normalized,
        ctaQuickTemplates,
    };
}

/**
 * Keyword suggestion catalogs — only after explicit Generate Suggestions.
 * Sends live editor HTML when available (articles.body often empty after WP sync).
 * @param {number} articleId
 * @param {{ content?: string, mode?: 'full'|'fallback', existingInternal?: unknown[], signal?: AbortSignal }} [options]
 */
async function fetchEditorLinksSuggestions(articleId, options = {}) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        throw new Error('Invalid article id');
    }

    const url =
        window.__SEO_EDITOR_LAZY_ENDPOINTS__?.linksSuggestions
        || `/api/seo/articles/${id}/editor/links/suggestions`;

    const mode = options.mode === 'fallback' ? 'fallback' : 'full';
    const body = {
        mode,
        content: typeof options.content === 'string' ? options.content : '',
        existing_internal: Array.isArray(options.existingInternal) ? options.existingInternal : [],
    };

    const { response, data } = await seoArticleApiFetch(url, {
        method: 'POST',
        signal: options.signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || data?.success === false) {
        throw new Error(String(data?.message ?? 'links_suggestions_failed'));
    }

    return normalizeLinksPayload(data);
}

/**
 * Ask SeoArticleEditor for merged HTML of all blocks (sync via custom event).
 * @returns {Promise<string>}
 */
function requestEditorDocumentHtml() {
    return new Promise((resolve) => {
        let settled = false;
        const finish = (html) => {
            if (settled) {
                return;
            }
            settled = true;
            window.removeEventListener('seo-editor-document-html', onHtml);
            resolve(typeof html === 'string' ? html : '');
        };
        const onHtml = (event) => {
            finish(event?.detail?.html ?? '');
        };
        window.addEventListener('seo-editor-document-html', onHtml);
        window.dispatchEvent(new CustomEvent('seo-editor-document-html-request'));
        window.setTimeout(() => finish(''), 400);
    });
}

/**
 * @typedef {{ href?: string, text: string, offset?: number, is_nofollow?: boolean, is_suggestion?: boolean, target_url?: string|null, can_insert?: boolean, keyword_id?: number, occurrence_count?: number }} ExtractedLink
 * @typedef {{ text: string, index: number }} FaqLinkItem
 */

function LinkAssistantSection({ title, count, collapsed, onToggle, children, sectionKey = '' }) {
    return (
        <div className="seo-link-assistant__section" data-assistant-link-section={sectionKey || undefined}>
            <button
                type="button"
                className="seo-link-assistant__section-toggle"
                aria-expanded={!collapsed}
                onClick={onToggle}
            >
                {collapsed ? <ChevronRight size={15} aria-hidden /> : <ChevronDown size={15} aria-hidden />}
                <span className="seo-link-assistant__section-title">{title}</span>
                <span className="seo-assistant-widget__badge">{count}</span>
            </button>
            {!collapsed ? <div className="seo-link-assistant__section-body">{children}</div> : null}
        </div>
    );
}

function DomainInsertableList({
    items,
    variant,
    activeKey,
    hiddenRowKeys,
    onKeywordClick,
    onInsert,
    emptyText,
}) {
    if (!items.length) {
        return <p className="wp-article-links-empty">{emptyText}</p>;
    }

    return (
        <ul className="wp-article-links-keywords">
            {items.map((item, index) => {
                const label =
                    variant === 'cta'
                        ? ctaDisplayLabel(item)
                        : String(item?.text ?? '').trim();
                const href =
                    variant === 'cta'
                        ? String(item?.href ?? formatCtaHref(item?.type, item?.value)).trim()
                        : String(item?.href ?? item?.target_url ?? '').trim();
                const count = Number(item?.article_count ?? 0);
                const countSuffix =
                    variant === 'domain-link' && Number.isFinite(count) && count > 0
                        ? ` (${count})`
                        : '';
                const itemKey = `${variant}-${label}-${index}`;
                const isActive = activeKey === itemKey;
                const insertable =
                    variant === 'cta'
                        ? isCtaItemInsertable(item)
                        : item?.can_insert !== false && label !== '' && href !== '';
                const isCtaBlank = variant === 'cta' && item?.is_blank === true;
                const isRowHiding = hiddenRowKeys?.has(itemKey) === true;

                return (
                    <li
                        key={itemKey}
                        className={`wp-article-links-keyword-row${isCtaBlank ? ' is-cta-blank' : ''}${isRowHiding ? ' is-row-hiding' : ''}`}
                        aria-hidden={isRowHiding}
                    >
                        <button
                            type="button"
                            className={`wp-article-links-keyword${isActive ? ' is-active' : ''} is-suggestion`}
                            title={
                                variant === 'cta'
                                    ? t('cta_widget_find', { label, type: item?.type ?? '' })
                                    : t('domain_link_widget_find', { label, count })
                            }
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => onKeywordClick(item, index, itemKey)}
                        >
                            {variant === 'cta' ? (
                                <span className="wp-article-domain-cta-label">
                                    <span className="wp-article-domain-cta-type">{item?.type ?? 'cta'}</span>
                                    {label}
                                </span>
                            ) : (
                                `${label}${countSuffix}`
                            )}
                        </button>
                        {onInsert ? (
                            <button
                                type="button"
                                className="wp-article-links-insert-btn"
                                aria-label={
                                    variant === 'cta'
                                        ? t('cta_widget_insert_for', { label })
                                        : t('domain_link_widget_insert_for', { label })
                                }
                                title={
                                    variant === 'cta'
                                        ? t('cta_widget_insert_for', { label })
                                        : t('domain_link_widget_insert_for', { label })
                                }
                                disabled={!insertable}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if (insertable) {
                                        onInsert(item, itemKey);
                                    }
                                }}
                            >
                                {variant === 'cta' ? (
                                    <Phone size={14} aria-hidden />
                                ) : (
                                    <Link2 size={14} aria-hidden />
                                )}
                            </button>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

const applyDomainLinkFilters = (allLinks, articlePlainText, internalLinks, externalLinks = []) => {
    const inArticle = filterDomainLinksInArticleContent(allLinks, articlePlainText).filter(
        (item) => !isSpecialOrContactHref(item?.href ?? item?.target_url),
    );

    return filterSuggestedInternalLinks(inArticle, internalLinks, externalLinks).map((item) => ({
        ...item,
        can_insert: item.can_insert !== false,
    }));
};

function keywordLabel(item) {
    const text = String(item?.text ?? '').trim();
    if (text !== '') {
        return text;
    }
    if (item?.href) {
        try {
            const url = new URL(item.href, window.location.origin);
            const path = url.pathname || '/';
            return `Link: ${path}`;
        } catch {
            return `Link: ${item.href}`;
        }
    }
    return '—';
}

function fullTitle(item, hint) {
    const label = keywordLabel(item);
    const parts = [hint, label];
    if (item?.href) {
        parts.push(item.href);
    }
    if (item?.target_url && item.target_url !== item.href) {
        parts.push(t('links_target_url_hint', { url: item.target_url }));
    }
    if (item?.keyword_type) {
        parts.push(t('links_source_hint', { type: item.keyword_type }));
    }
    return parts.filter(Boolean).join('\n');
}

function canInsertSuggestion(item) {
    if (item?.can_insert === false) {
        return false;
    }
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    return href !== '';
}

function hasAnchorText(item) {
    return String(item?.text ?? '').trim() !== '';
}

function occurrenceCount(item) {
    const value = Number(item?.occurrence_count ?? 1);
    return Number.isFinite(value) && value > 1 ? Math.floor(value) : 1;
}

/** Stable row key — suggestion keys must not depend on editable anchor text. */
function keywordRowKey(variant, target, item, index) {
    if (variant === 'suggestion') {
        const keywordId = Number(item?.keyword_id ?? 0);
        if (keywordId > 0) {
            return `${variant}-${target}-kw-${keywordId}`;
        }
        const href = String(item?.href ?? item?.target_url ?? '').trim();

        return `${variant}-${target}-s-${href || 'none'}-${index}`;
    }

    return `${variant}-${target}-${item.text}-${index}`;
}

function patchSuggestionAnchorInList(list, item, nextText) {
    const keywordId = Number(item?.keyword_id ?? 0);
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    const prevText = String(item?.text ?? '');

    return (Array.isArray(list) ? list : []).map((entry) => {
        const sameKeyword = keywordId > 0 && Number(entry?.keyword_id ?? 0) === keywordId;
        const sameHrefAnchor =
            href !== ''
            && String(entry?.href ?? entry?.target_url ?? '').trim() === href
            && String(entry?.text ?? '') === prevText;
        if (!sameKeyword && !sameHrefAnchor) {
            return entry;
        }

        return { ...entry, text: nextText };
    });
}

/**
 * @param {{ items: Array<ExtractedLink|FaqLinkItem>, title: string, activeKey: string, target: 'editor'|'faq', variant?: 'default'|'suggestion', suggestionKind?: 'internal'|'external', hideTitle?: boolean, interactive?: boolean, hiddenRowKeys?: Set<string>, reviewLoadingKey?: string, reviewPopoverItemKey?: string, onKeywordClick: Function, onInsertSuggestion?: Function, onUpdateSuggestionAnchor?: Function, onCopyKeyword?: Function, onRemoveInternalLink?: Function, onReviewWarning?: Function, onReviewDanger?: Function }} props
 */
function KeywordList({
    items,
    title,
    activeKey,
    target,
    variant = 'default',
    suggestionKind = 'internal',
    hideTitle = false,
    interactive = true,
    hiddenRowKeys,
    reviewLoadingKey = '',
    reviewPopoverItemKey = '',
    onKeywordClick,
    onInsertSuggestion,
    onUpdateSuggestionAnchor,
    onCopyKeyword,
    onRemoveInternalLink,
    onReviewWarning,
    onReviewDanger,
}) {
    const [editingKey, setEditingKey] = useState('');
    const [draftAnchor, setDraftAnchor] = useState('');
    const editOriginalRef = useRef('');
    const skipBlurCommitRef = useRef(false);
    const clickTimerRef = useRef(null);
    const suppressClickRef = useRef(false);
    const editInputRef = useRef(null);

    useEffect(() => () => {
        if (clickTimerRef.current) {
            window.clearTimeout(clickTimerRef.current);
        }
    }, []);

    useEffect(() => {
        if (!editingKey || !(editInputRef.current instanceof HTMLInputElement)) {
            return;
        }
        const input = editInputRef.current;
        input.focus();
        input.select();
    }, [editingKey]);

    const cancelAnchorEdit = () => {
        skipBlurCommitRef.current = true;
        setEditingKey('');
        setDraftAnchor('');
        editOriginalRef.current = '';
    };

    const commitAnchorEdit = (item, itemKey) => {
        if (skipBlurCommitRef.current) {
            skipBlurCommitRef.current = false;
            return;
        }
        if (editingKey !== itemKey) {
            return;
        }
        skipBlurCommitRef.current = true;
        const trimmed = String(draftAnchor ?? '').trim();
        const original = String(editOriginalRef.current ?? '');
        setEditingKey('');
        setDraftAnchor('');
        editOriginalRef.current = '';
        if (trimmed === '') {
            return;
        }
        if (trimmed === original.trim()) {
            return;
        }
        if (typeof onUpdateSuggestionAnchor === 'function') {
            onUpdateSuggestionAnchor(item, itemKey, trimmed);
        }
    };

    const startAnchorEdit = (item, itemKey, event) => {
        event.preventDefault();
        event.stopPropagation();
        suppressClickRef.current = true;
        skipBlurCommitRef.current = false;
        if (clickTimerRef.current) {
            window.clearTimeout(clickTimerRef.current);
            clickTimerRef.current = null;
        }
        const current = String(item?.text ?? '');
        editOriginalRef.current = current;
        setDraftAnchor(current);
        setEditingKey(itemKey);
    };

    if (!items.length) {
        return (
            <div className="wp-article-links-group">
                {!hideTitle ? <h3 className="wp-article-links-group__title">{title}</h3> : null}
                <p className="wp-article-links-empty">{t('links_none')}</p>
            </div>
        );
    }

    return (
        <div className={`wp-article-links-group${hideTitle ? ' wp-article-links-group--nested' : ''}`}>
            {!hideTitle ? <h3 className="wp-article-links-group__title">{title}</h3> : null}
            <ul className="wp-article-links-keywords">
                {items.map((item, index) => {
                    const itemKey = keywordRowKey(variant, target, item, index);
                    const isActive = activeKey === itemKey;
                    const label = keywordLabel(item);
                    const count = occurrenceCount(item);
                    const labelWithCount = count > 1 ? `${label} (${count})` : label;
                    const insertable = variant === 'suggestion' && canInsertSuggestion(item);
                    const anchorTextPresent = hasAnchorText(item);
                    const canEditAnchor =
                        variant === 'suggestion'
                        && interactive
                        && typeof onUpdateSuggestionAnchor === 'function'
                        && anchorTextPresent;
                    const isEditing = editingKey === itemKey;
                    const hint =
                        variant === 'suggestion'
                            ? insertable
                                ? t(
                                      suggestionKind === 'external'
                                          ? 'links_suggestion_insert_external_ready'
                                          : 'links_suggestion_insert_ready',
                                      { label },
                                  )
                                : t('links_suggestion_insert_missing', { label })
                            : target === 'faq'
                              ? t('links_find_in_faq', { label })
                              : anchorTextPresent
                                ? t('links_find_keyword', { label })
                                : t('links_find_link', { label });
                    const titleHint = canEditAnchor
                        ? `${hint}\n${t('links_suggestion_edit_anchor_hint')}`
                        : hint;

                    const isRowHiding = hiddenRowKeys?.has(itemKey) === true;
                    const isReviewLoading = reviewLoadingKey === itemKey;
                    const isReviewOpen = reviewPopoverItemKey === itemKey;

                    return (
                        <li
                            key={itemKey}
                            data-keyword-row-key={itemKey}
                            className={`wp-article-links-keyword-row${isRowHiding ? ' is-row-hiding' : ''}${isReviewLoading ? ' is-review-loading' : ''}${isReviewOpen ? ' is-review-open' : ''}`}
                            aria-hidden={isRowHiding}
                        >
                            {interactive && isEditing ? (
                                <input
                                    ref={editInputRef}
                                    type="text"
                                    className="wp-article-links-keyword-edit"
                                    value={draftAnchor}
                                    aria-label={t('links_suggestion_edit_anchor_aria', { label })}
                                    title={t('links_suggestion_edit_anchor_hint')}
                                    onChange={(e) => setDraftAnchor(e.target.value)}
                                    onClick={(e) => e.stopPropagation()}
                                    onMouseDown={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            commitAnchorEdit(item, itemKey);
                                            return;
                                        }
                                        if (e.key === 'Escape') {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            cancelAnchorEdit();
                                        }
                                    }}
                                    onBlur={() => commitAnchorEdit(item, itemKey)}
                                />
                            ) : interactive ? (
                                <button
                                    type="button"
                                    className={`wp-article-links-keyword${isActive ? ' is-active' : ''}${target === 'faq' ? ' is-faq' : ''}${variant === 'suggestion' ? ' is-suggestion' : ''}${canEditAnchor ? ' is-editable-anchor' : ''}`}
                                    title={fullTitle(item, titleHint)}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => {
                                        if (variant === 'suggestion' && canEditAnchor) {
                                            if (suppressClickRef.current) {
                                                suppressClickRef.current = false;
                                                return;
                                            }
                                            if (clickTimerRef.current) {
                                                window.clearTimeout(clickTimerRef.current);
                                            }
                                            clickTimerRef.current = window.setTimeout(() => {
                                                clickTimerRef.current = null;
                                                if (suppressClickRef.current) {
                                                    suppressClickRef.current = false;
                                                    return;
                                                }
                                                onKeywordClick(item, index, itemKey, target);
                                            }, 250);
                                            return;
                                        }
                                        onKeywordClick(item, index, itemKey, target);
                                    }}
                                    onDoubleClick={
                                        canEditAnchor
                                            ? (e) => startAnchorEdit(item, itemKey, e)
                                            : undefined
                                    }
                                >
                                    {labelWithCount}
                                </button>
                            ) : (
                                <span
                                    className={`wp-article-links-keyword is-readonly${target === 'faq' ? ' is-faq' : ''}`}
                                    title={label}
                                >
                                    {labelWithCount}
                                </span>
                            )}
                            {variant === 'suggestion' && onInsertSuggestion ? (
                                <button
                                    type="button"
                                    className="wp-article-links-insert-btn"
                                    aria-label={
                                        insertable
                                            ? t(
                                                  suggestionKind === 'external'
                                                      ? 'links_insert_external_for'
                                                      : 'links_insert_internal_for',
                                                  { label },
                                              )
                                            : t('links_missing_target_url')
                                    }
                                    title={
                                        insertable
                                            ? t(
                                                  suggestionKind === 'external'
                                                      ? 'links_insert_external_for_label'
                                                      : 'links_insert_internal_for_label',
                                                  { label },
                                              )
                                            : t('links_missing_target_mapping')
                                    }
                                    disabled={!insertable || isEditing}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (insertable) {
                                            onInsertSuggestion(item, index, itemKey);
                                        }
                                    }}
                                >
                                    <Link2 size={14} aria-hidden />
                                </button>
                            ) : null}
                            {onCopyKeyword ? (
                                <button
                                    type="button"
                                    className={`wp-article-links-copy-btn${target === 'faq' ? ' is-faq' : ''}${variant === 'suggestion' ? ' is-suggestion' : ''}`}
                                    aria-label={t('links_copy_keyword', { label })}
                                    title={t('links_copy_title', { label })}
                                    disabled={isEditing}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onCopyKeyword(label);
                                    }}
                                >
                                    <Copy size={14} aria-hidden />
                                </button>
                            ) : null}
                            {variant === 'suggestion' && (onReviewWarning || onReviewDanger) ? (
                                <>
                                    {onReviewWarning ? (
                                        <button
                                            type="button"
                                            className="wp-article-links-review-btn is-warning"
                                            aria-label={t('keyword_review_warning_button_label', { label })}
                                            title={t('keyword_review_warning_button_title')}
                                            disabled={isReviewLoading || isEditing}
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const rowEl = e.currentTarget.closest('.wp-article-links-keyword-row');
                                                onReviewWarning(item, index, itemKey, rowEl);
                                            }}
                                        >
                                            {isReviewLoading ? (
                                                <Loader2 size={13} className="is-spinning" aria-hidden />
                                            ) : (
                                                <TriangleAlert size={13} aria-hidden />
                                            )}
                                        </button>
                                    ) : null}
                                    {onReviewDanger ? (
                                        <button
                                            type="button"
                                            className="wp-article-links-review-btn is-danger"
                                            aria-label={t('keyword_review_danger_button_label', { label })}
                                            title={t('keyword_review_danger_button_title')}
                                            disabled={isReviewLoading || isEditing}
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const rowEl = e.currentTarget.closest('.wp-article-links-keyword-row');
                                                onReviewDanger(item, index, itemKey, rowEl);
                                            }}
                                        >
                                            {isReviewLoading ? (
                                                <Loader2 size={13} className="is-spinning" aria-hidden />
                                            ) : (
                                                <OctagonAlert size={13} aria-hidden />
                                            )}
                                        </button>
                                    ) : null}
                                </>
                            ) : null}
                            {variant === 'default' && target === 'editor' && onRemoveInternalLink ? (
                                <button
                                    type="button"
                                    className="wp-article-links-delete-btn"
                                    aria-label={t('links_remove_keyword', { label })}
                                    title={t('links_remove_title', { label })}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onRemoveInternalLink(item, index, itemKey);
                                    }}
                                >
                                    <Trash2 size={14} aria-hidden />
                                </button>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function InternalLinksSection({
    internal,
    suggestedInternal,
    activeKey,
    hiddenRowKeys,
    excludedCount = 0,
    onClearExcluded,
    onGenerateSuggestions,
    onGenerateFallbackSuggestions,
    suggestionsLoading = false,
    reviewLoadingKey = '',
    reviewPopoverItemKey = '',
    onKeywordClick,
    onSuggestionClick,
    onInsertSuggestion,
    onUpdateSuggestionAnchor,
    onCopyKeyword,
    onRemoveInternalLink,
    onReviewWarning,
    onReviewDanger,
}) {
    const showSuggestions = internal.length < 10 && suggestedInternal.length > 0;
    const showExcludedClear = excludedCount > 0;
    const showGenerate = typeof onGenerateSuggestions === 'function';

    if (internal.length === 0 && !showSuggestions && !showExcludedClear && !showGenerate) {
        return (
            <KeywordList
                items={[]}
                title={t('links_internal_title_zero')}
                activeKey={activeKey}
                target="editor"
                onKeywordClick={onKeywordClick}
                onCopyKeyword={onCopyKeyword}
            />
        );
    }

    return (
        <div className="wp-article-links-group">
            <h3 className="wp-article-links-group__title">{t('links_internal_title', { count: internal.length })}</h3>
            {internal.length > 0 ? (
                <KeywordList
                    items={internal}
                    title=""
                    activeKey={activeKey}
                    target="editor"
                    hideTitle
                    onKeywordClick={onKeywordClick}
                    onCopyKeyword={onCopyKeyword}
                    onRemoveInternalLink={onRemoveInternalLink}
                />
            ) : (
                <p className="wp-article-links-empty">{t('links_internal_empty')}</p>
            )}
            {showGenerate ? (
                <div className="wp-article-links-generate-row" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    <button
                        type="button"
                        className="wp-article-links-clear-excluded-btn"
                        disabled={suggestionsLoading}
                        onClick={onGenerateSuggestions}
                    >
                        {suggestionsLoading ? <Loader2 size={13} className="animate-spin" aria-hidden /> : <RotateCcw size={13} aria-hidden />}
                        {suggestionsLoading
                            ? t('links_suggestions_loading')
                            : t('links_generate_suggestions')}
                    </button>
                    {typeof onGenerateFallbackSuggestions === 'function' ? (
                        <button
                            type="button"
                            className="wp-article-links-clear-excluded-btn"
                            disabled={suggestionsLoading}
                            onClick={onGenerateFallbackSuggestions}
                            title={t('links_generate_fallback_hint')}
                        >
                            {t('links_generate_fallback')}
                        </button>
                    ) : null}
                </div>
            ) : null}
            {showSuggestions || showExcludedClear ? (
                <div className="wp-article-links-suggestions-head">
                    {showSuggestions ? (
                        <KeywordList
                            items={suggestedInternal}
                            title={t('links_suggestion_title', { count: suggestedInternal.length })}
                            activeKey={activeKey}
                            target="editor"
                            variant="suggestion"
                            hideTitle
                            hiddenRowKeys={hiddenRowKeys}
                            reviewLoadingKey={reviewLoadingKey}
                            reviewPopoverItemKey={reviewPopoverItemKey}
                            onKeywordClick={onSuggestionClick}
                            onInsertSuggestion={onInsertSuggestion}
                            onUpdateSuggestionAnchor={onUpdateSuggestionAnchor}
                            onCopyKeyword={onCopyKeyword}
                            onReviewWarning={onReviewWarning}
                            onReviewDanger={onReviewDanger}
                        />
                    ) : (
                        <p className="wp-article-links-empty">{t('links_suggestions_all_excluded')}</p>
                    )}
                    {showExcludedClear ? (
                        <button
                            type="button"
                            className="wp-article-links-clear-excluded-btn"
                            title={t('links_clear_excluded_title')}
                            onClick={onClearExcluded}
                        >
                            <RotateCcw size={13} aria-hidden />
                            {t('links_clear_excluded', { count: excludedCount })}
                        </button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function readEditorSeoBootstrap() {
    try {
        const el = document.getElementById('seo-article-initial-seo');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return null;
        }

        const data = JSON.parse(raw);

        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

function readArticleMetaIds(propArticleId = null, propSiteId = null) {
    const fromPropId = Number(propArticleId ?? 0);
    const fromPropSite = Number(propSiteId ?? 0);
    if (Number.isFinite(fromPropId) && fromPropId > 0) {
        const core = readCoreArticleIdentity();
        return {
            articleId: fromPropId,
            siteId: Number.isFinite(fromPropSite) && fromPropSite > 0 ? fromPropSite : core.siteId,
        };
    }

    const core = readCoreArticleIdentity();
    if (core.articleId > 0) {
        return core;
    }

    // Legacy fallback (older cached HTML).
    try {
        const el = document.getElementById('seo-article-meta');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return { articleId: 0, siteId: 0 };
        }

        const meta = JSON.parse(raw);

        return {
            articleId: Number(meta?.id ?? 0),
            siteId: Number(meta?.site_id ?? meta?.siteId ?? 0),
        };
    } catch {
        return { articleId: 0, siteId: 0 };
    }
}

function readSuggestionCatalogBootstrap() {
    const data = readEditorSeoBootstrap();

    return {
        domainCatalog: Array.isArray(data?.domain_link_list_catalog) ? data.domain_link_list_catalog : [],
        externalCatalog: mergeSuggestionCatalog(
            data?.suggested_external_links_catalog ?? [],
            data?.suggested_external_links ?? [],
        ),
        siteDomain: String(data?.site_domain ?? '').trim(),
    };
}

export default function ArticleLinksSidebar({
    articleId: articleIdProp = null,
    siteId: siteIdProp = null,
    initialDomainLinkList = [],
    initialDomainLinkCatalog = [],
    initialDomainCtaList = [],
    initialCtaQuickTemplates = null,
}) {
    const articleMetaRef = useRef(readArticleMetaIds(articleIdProp, siteIdProp));
    const [reviewPopover, setReviewPopover] = useState(null);
    const [reviewLoadingKey, setReviewLoadingKey] = useState('');
    const [reviewedKeywordIds, setReviewedKeywordIds] = useState(() => new Set());
    const editorSeoBootstrap = useRef(readEditorSeoBootstrap());
    const suggestionBootstrap = useRef(readSuggestionCatalogBootstrap());
    const siteDomainRef = useRef(suggestionBootstrap.current.siteDomain);
    const bootPartitioned = partitionSuggestionCatalogBySite(
        mergeSuggestionCatalog(
            editorSeoBootstrap.current?.suggested_internal_links_catalog ?? [],
            editorSeoBootstrap.current?.suggested_internal_links ?? [],
        ),
        suggestionBootstrap.current.siteDomain,
    );
    const keywordCatalogRef = useRef(bootPartitioned.internal);
    const externalKeywordCatalogRef = useRef(
        mergeSuggestionCatalog(
            suggestionBootstrap.current.externalCatalog,
            bootPartitioned.external,
        ),
    );
    const domainCatalogRef = useRef(suggestionBootstrap.current.domainCatalog);
    const [catalogVersion, setCatalogVersion] = useState(0);
    const [anchorEditTick, setAnchorEditTick] = useState(0);
    const stableSuggestionsRef = useRef([]);
    const stableSuggestionsKeyRef = useRef('');
    const stableExternalSuggestionsRef = useRef([]);
    const stableExternalSuggestionsKeyRef = useRef('');
    const [links, setLinks] = useState(() => ({
        internal: editorSeoBootstrap.current?.extracted_links?.internal ?? [],
        external: (editorSeoBootstrap.current?.extracted_links?.external ?? []).filter(
            (item) => !isSpecialOrContactHref(item?.href),
        ),
    }));
    const linksRef = useRef(links);
    linksRef.current = links;
    const [articlePlainText, setArticlePlainText] = useState('');
    const [excludedSuggestionLabels, setExcludedSuggestionLabels] = useState(() => {
        const { articleId, siteId } = articleMetaRef.current;

        return new Set(loadExcludedLinkSuggestions(articleId, siteId));
    });
    const excludedPersistRef = useRef(excludedSuggestionLabels);
    const [activeKey, setActiveKey] = useState('');
    const [cycleByKey, setCycleByKey] = useState({});
    const [internalCollapsed, setInternalCollapsed] = useState(true);
    const [externalCollapsed, setExternalCollapsed] = useState(true);
    const [domainLinksCollapsed, setDomainLinksCollapsed] = useState(true);
    const [ctaCollapsed, setCtaCollapsed] = useState(true);
    const [linkSectionFilter, setLinkSectionFilter] = useState('all');
    const [baseLoading, setBaseLoading] = useState(true);
    const [baseError, setBaseError] = useState(null);
    const [suggestionsError, setSuggestionsError] = useState(null);
    const [suggestionsEmpty, setSuggestionsEmpty] = useState(false);
    const suggestionsAbortRef = useRef(null);

    useEffect(() => {
        // Lazy mount often misses prior client-document scans — ask editor to republish.
        window.dispatchEvent(new CustomEvent('seo-editor-links-rescan-request'));
    }, []);

    useEffect(() => {
        const onLinkSection = (event) => {
            const section = String(event?.detail?.section ?? 'all');
            setLinkSectionFilter(section);

            if (section === 'links') {
                setInternalCollapsed(false);
                setExternalCollapsed(false);
                setDomainLinksCollapsed(false);
                return;
            }

            if (section === 'cta') {
                setCtaCollapsed(false);
            }
        };

        window.addEventListener('seo-assistant-link-section', onLinkSection);

        return () => window.removeEventListener('seo-assistant-link-section', onLinkSection);
    }, []);

    // Phase 2: Links open → base payload only (no collectCandidates / Keyword::forSite).
    // Suggestions load only via explicit "Generate suggestions" button.
    useEffect(() => {
        const { articleId } = articleMetaRef.current;
        const controller = new AbortController();
        setBaseLoading(true);
        setBaseError(null);

        void (async () => {
            try {
                const payload = await fetchEditorLinksBase(articleId, controller.signal);
                if (controller.signal.aborted) {
                    return;
                }

                if (payload.ctaQuickTemplates) {
                    setServerCtaTemplates(payload.ctaQuickTemplates);
                }

                window.dispatchEvent(
                    new CustomEvent('seo-editor-links-updated', {
                        detail: {
                            // Existing links come from client document scan — do not overwrite with server body.
                            source: 'links-base',
                            suggested_internal: [],
                            suggested_internal_links_catalog: [],
                            suggested_external_links: [],
                            suggested_external_links_catalog: [],
                            domain_link_list: payload.domainLinkList,
                            domain_link_list_catalog: payload.domainLinkListCatalog,
                            domain_cta_list: payload.domainCtaList,
                            cta_quick_templates: payload.ctaQuickTemplates,
                        },
                    }),
                );
                setBaseError(null);
            } catch (error) {
                if (error?.name === 'AbortError' || controller.signal.aborted) {
                    return;
                }
                setBaseError(t('editor_links_load_error'));
            } finally {
                if (!controller.signal.aborted) {
                    setBaseLoading(false);
                }
            }
        })();

        return () => {
            controller.abort();
            suggestionsAbortRef.current?.abort();
        };
    }, []);

    const [suggestionsLoading, setSuggestionsLoading] = useState(false);
    const applySuggestionPayload = (payload, source) => {
        if (payload?.suggestionDebug && typeof window !== 'undefined') {
            // eslint-disable-next-line no-console
            console.info('[LINK_FALLBACK_DEBUG]', {
                source,
                contentSource: payload.contentSource,
                debug: payload.suggestionDebug,
            });
        }
        const empty =
            payload.suggestedInternalLinks.length === 0
            && payload.suggestedExternalLinks.length === 0
            && payload.suggestedInternalLinksCatalog.length === 0
            && payload.suggestedExternalLinksCatalog.length === 0;
        setSuggestionsEmpty(empty);
        window.dispatchEvent(
            new CustomEvent('seo-editor-links-updated', {
                detail: {
                    source,
                    suggested_internal: payload.suggestedInternalLinks,
                    suggested_internal_links_catalog: payload.suggestedInternalLinksCatalog,
                    suggested_external_links: payload.suggestedExternalLinks,
                    suggested_external_links_catalog: payload.suggestedExternalLinksCatalog,
                    domain_link_list: payload.domainLinkList,
                    domain_link_list_catalog: payload.domainLinkListCatalog,
                    domain_cta_list: payload.domainCtaList,
                },
            }),
        );
    };

    const loadLinkSuggestions = async (mode = 'full') => {
        const { articleId } = articleMetaRef.current;
        if (suggestionsLoading) {
            return;
        }
        suggestionsAbortRef.current?.abort();
        const controller = new AbortController();
        suggestionsAbortRef.current = controller;
        setSuggestionsLoading(true);
        setSuggestionsError(null);
        setSuggestionsEmpty(false);
        try {
            const content = await requestEditorDocumentHtml();
            const payload = await fetchEditorLinksSuggestions(articleId, {
                content,
                mode: mode === 'fallback' ? 'fallback' : 'full',
                existingInternal: mode === 'fallback' ? (suggestedInternalRef.current ?? []) : [],
                signal: controller.signal,
            });
            if (controller.signal.aborted) {
                return;
            }
            applySuggestionPayload(
                payload,
                mode === 'fallback' ? 'links-suggestions-fallback' : 'links-suggestions',
            );
        } catch (error) {
            if (error?.name === 'AbortError' || controller.signal.aborted) {
                return;
            }
            setSuggestionsError(t('editor_links_suggestions_error'));
        } finally {
            if (!controller.signal.aborted) {
                setSuggestionsLoading(false);
            }
        }
    };

    const [hiddenRowKeys, setHiddenRowKeys] = useState(() => new Set());
    const allDomainLinksRef = useRef(
        initialDomainLinkCatalog.length > 0 ? initialDomainLinkCatalog : initialDomainLinkList,
    );
    const [domainLinkCatalogCount, setDomainLinkCatalogCount] = useState(
        initialDomainLinkCatalog.length > 0
            ? initialDomainLinkCatalog.length
            : initialDomainLinkList.length,
    );
    const [domainLinks, setDomainLinks] = useState(initialDomainLinkList);
    const [domainCtas, setDomainCtas] = useState(initialDomainCtaList);
    const [domainLinkActiveKey, setDomainLinkActiveKey] = useState('');
    const [ctaActiveKey, setCtaActiveKey] = useState('');
    const [ctaSettingsOpen, setCtaSettingsOpen] = useState(false);
    const [serverCtaTemplates, setServerCtaTemplates] = useState(initialCtaQuickTemplates);
    const effectiveSiteId = Number(siteIdProp ?? articleMetaRef.current.siteId ?? 0);
    const [templatesByType, setTemplatesByType] = useCtaQuickTemplates(effectiveSiteId, serverCtaTemplates);
    const usableDomainCtas = useMemo(() => filterUsableCtaContacts(domainCtas), [domainCtas]);
    const [domainHiddenRowKeys, setDomainHiddenRowKeys] = useState(() => new Set());

    const { debounced: debouncedPersistExcluded } = useDebouncedCallback(() => {
        const { articleId, siteId } = articleMetaRef.current;
        saveExcludedLinkSuggestions(articleId, siteId, [...excludedPersistRef.current]);
    }, 400);

    const hideSuggestionRow = (itemKey) => {
        if (!itemKey) {
            return;
        }
        setHiddenRowKeys((prev) => {
            if (prev.has(itemKey)) {
                return prev;
            }
            const next = new Set(prev);
            next.add(itemKey);
            return next;
        });
    };

    const openReviewPopover = async (item, itemKey, severity, anchorEl) => {
        const text = String(item?.text ?? '').trim();
        if (text === '' || !(anchorEl instanceof HTMLElement)) {
            return;
        }

        let keywordId = Number(item?.keyword_id ?? 0);
        if (keywordId <= 0) {
            const { siteId } = articleMetaRef.current;
            if (siteId <= 0) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('keyword_review_missing_keyword_title'),
                            body: t('keyword_review_missing_keyword_body'),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            setReviewLoadingKey(itemKey);
            try {
                const ensured = await ensureKeywordForReview({
                    phrase: text,
                    site_id: siteId,
                    target_url: item?.href ?? item?.target_url ?? null,
                    target_article_id: item?.target_article_id ?? null,
                });
                keywordId = Number(ensured.id ?? 0);
                if (keywordId > 0) {
                    const labelKey = normalizeLinkLabel(text);
                    const patchList = (list) => (Array.isArray(list) ? list.map((row) => (
                        normalizeLinkLabel(row?.text) === labelKey
                            ? { ...row, keyword_id: keywordId }
                            : row
                    )) : list);
                    keywordCatalogRef.current = patchList(keywordCatalogRef.current);
                    stableSuggestionsRef.current = patchList(stableSuggestionsRef.current);
                    stableExternalSuggestionsRef.current = patchList(stableExternalSuggestionsRef.current);
                    setCatalogVersion((value) => value + 1);
                }
            } catch (error) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('keyword_review_missing_keyword_title'),
                            body: String(error?.message ?? t('keyword_review_missing_keyword_body')),
                            status: 'danger',
                        },
                    }),
                );
                return;
            } finally {
                setReviewLoadingKey('');
            }
        }

        if (keywordId <= 0) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('keyword_review_missing_keyword_title'),
                        body: t('keyword_review_missing_keyword_body'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        setReviewPopover({
            itemKey,
            keywordId,
            text,
            severity,
            anchorEl,
        });
    };

    const focusNextSuggestionAfter = (currentItemKey) => {
        window.requestAnimationFrame(() => {
            const currentRow = document.querySelector(`[data-keyword-row-key="${currentItemKey}"]`);
            if (!(currentRow instanceof HTMLElement)) {
                return;
            }

            let sibling = currentRow.nextElementSibling;
            while (sibling instanceof HTMLElement) {
                if (sibling.matches('.wp-article-links-keyword-row')) {
                    const button = sibling.querySelector('.wp-article-links-keyword');
                    if (button instanceof HTMLElement) {
                        button.focus();
                        return;
                    }
                }

                sibling = sibling.nextElementSibling;
            }
        });
    };

    const handleReviewSubmitted = ({ keywordId, itemKey, text }) => {
        if (keywordId > 0) {
            setReviewedKeywordIds((prev) => {
                const next = new Set(prev);
                next.add(keywordId);
                return next;
            });
        }

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('keyword_review_submitted_title'),
                    body: t('keyword_review_submitted_body', { label: String(text ?? '').trim() }),
                    status: 'success',
                },
            }),
        );

        focusNextSuggestionAfter(itemKey);
    };

    const clearExcludedSuggestions = () => {
        const { articleId, siteId } = articleMetaRef.current;
        clearExcludedLinkSuggestions(articleId, siteId);
        excludedPersistRef.current = new Set();
        setExcludedSuggestionLabels(new Set());

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('links_clear_excluded_done_title'),
                    body: t('links_clear_excluded_done_body'),
                    status: 'success',
                },
            }),
        );
    };

    useEffect(() => {
        const onLinksUpdate = (event) => {
            const detail = event.detail ?? {};
            // Server base/catalog events must not wipe client existing-link scan.
            if (detail.source === 'links-base' || detail.source === 'links-suggestions') {
                setArticlePlainText(String(detail.article_plain_text ?? ''));
                if (Array.isArray(detail.domain_link_list_catalog) && detail.domain_link_list_catalog.length > 0) {
                    allDomainLinksRef.current = detail.domain_link_list_catalog;
                    setDomainLinkCatalogCount(detail.domain_link_list_catalog.length);
                } else if (Array.isArray(detail.domain_link_list)) {
                    allDomainLinksRef.current = detail.domain_link_list;
                    setDomainLinkCatalogCount(detail.domain_link_list.length);
                }
                if (
                    Array.isArray(detail.domain_link_list_catalog)
                    || Array.isArray(detail.domain_link_list)
                ) {
                    setDomainLinks(
                        applyDomainLinkFilters(
                            allDomainLinksRef.current,
                            String(detail.article_plain_text ?? ''),
                            linksRef.current.internal ?? [],
                            linksRef.current.external ?? [],
                        ),
                    );
                }
                if (Array.isArray(detail.domain_cta_list)) {
                    setDomainCtas(detail.domain_cta_list);
                }
                if (detail.cta_quick_templates && typeof detail.cta_quick_templates === 'object') {
                    setServerCtaTemplates(detail.cta_quick_templates);
                }

                if (detail.source === 'links-suggestions') {
                    const incomingSuggested = Array.isArray(detail.suggested_internal)
                        ? detail.suggested_internal
                        : [];
                    const incomingKeywordCatalog = Array.isArray(detail.suggested_internal_links_catalog)
                        ? detail.suggested_internal_links_catalog
                        : [];
                    const incomingExternalSuggested = Array.isArray(detail.suggested_external)
                        ? detail.suggested_external
                        : Array.isArray(detail.suggested_external_links)
                          ? detail.suggested_external_links
                          : [];
                    const incomingExternalCatalog = Array.isArray(detail.suggested_external_links_catalog)
                        ? detail.suggested_external_links_catalog
                        : [];
                    if (incomingKeywordCatalog.length > 0) {
                        const partitioned = partitionSuggestionCatalogBySite(
                            mergeSuggestionCatalog(incomingKeywordCatalog, incomingSuggested),
                            siteDomainRef.current,
                        );
                        keywordCatalogRef.current = mergeSuggestionCatalog(
                            keywordCatalogRef.current,
                            partitioned.internal,
                        );
                        externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                            externalKeywordCatalogRef.current,
                            partitioned.external,
                            incomingExternalCatalog,
                            incomingExternalSuggested,
                        );
                    } else if (incomingSuggested.length > 0) {
                        const partitioned = partitionSuggestionCatalogBySite(
                            incomingSuggested,
                            siteDomainRef.current,
                        );
                        keywordCatalogRef.current = mergeSuggestionCatalog(
                            keywordCatalogRef.current,
                            partitioned.internal,
                        );
                        externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                            externalKeywordCatalogRef.current,
                            partitioned.external,
                        );
                    }
                    if (incomingExternalCatalog.length > 0 || incomingExternalSuggested.length > 0) {
                        externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                            externalKeywordCatalogRef.current,
                            incomingExternalCatalog,
                            incomingExternalSuggested,
                        );
                    }
                    setCatalogVersion((value) => value + 1);
                    setHiddenRowKeys(new Set());
                }
                return;
            }

            const payload = detail.links ?? detail.extracted_links;
            const articlePlain = String(detail.article_plain_text ?? '');
            if (payload && typeof payload === 'object') {
                setLinks((prev) => ({
                    ...prev,
                    internal: Array.isArray(payload.internal) ? payload.internal : [],
                    external: (Array.isArray(payload.external) ? payload.external : []).filter(
                        (item) => !isSpecialOrContactHref(item?.href),
                    ),
                }));
                setCycleByKey({});
                setHiddenRowKeys(new Set());
            }
            setArticlePlainText(articlePlain);

            const internal = Array.isArray(payload?.internal)
                ? payload.internal
                : Array.isArray(event.detail?.extracted_links?.internal)
                  ? event.detail.extracted_links.internal
                  : [];
            const external = Array.isArray(payload?.external)
                ? payload.external
                : Array.isArray(event.detail?.extracted_links?.external)
                  ? event.detail.extracted_links.external
                  : [];

            const incomingSuggested = Array.isArray(event.detail?.suggested_internal)
                ? event.detail.suggested_internal
                : [];
            const incomingKeywordCatalog = Array.isArray(event.detail?.suggested_internal_links_catalog)
                ? event.detail.suggested_internal_links_catalog
                : [];
            const incomingExternalSuggested = Array.isArray(event.detail?.suggested_external)
                ? event.detail.suggested_external
                : Array.isArray(event.detail?.suggested_external_links)
                  ? event.detail.suggested_external_links
                  : [];
            const incomingExternalCatalog = Array.isArray(event.detail?.suggested_external_links_catalog)
                ? event.detail.suggested_external_links_catalog
                : [];
            const incomingCatalog = Array.isArray(event.detail?.domain_link_list_catalog)
                ? event.detail.domain_link_list_catalog
                : [];
            const incomingDomainList = Array.isArray(event.detail?.domain_link_list)
                ? event.detail.domain_link_list
                : null;
            const incomingSiteDomain = String(event.detail?.site_domain ?? '').trim();
            if (incomingSiteDomain !== '') {
                siteDomainRef.current = incomingSiteDomain;
            }

            if (incomingKeywordCatalog.length > 0) {
                const partitioned = partitionSuggestionCatalogBySite(
                    mergeSuggestionCatalog(incomingKeywordCatalog, incomingSuggested),
                    siteDomainRef.current,
                );
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    partitioned.internal,
                );
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    partitioned.external,
                    incomingExternalCatalog,
                    incomingExternalSuggested,
                );
            } else if (incomingSuggested.length > 0) {
                const partitioned = partitionSuggestionCatalogBySite(
                    incomingSuggested,
                    siteDomainRef.current,
                );
                keywordCatalogRef.current = mergeSuggestionCatalog(
                    keywordCatalogRef.current,
                    partitioned.internal,
                );
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    partitioned.external,
                );
            }

            if (incomingExternalCatalog.length > 0 || incomingExternalSuggested.length > 0) {
                externalKeywordCatalogRef.current = mergeSuggestionCatalog(
                    externalKeywordCatalogRef.current,
                    incomingExternalCatalog,
                    incomingExternalSuggested,
                );
            }

            if (incomingCatalog.length > 0) {
                domainCatalogRef.current = mergeSuggestionCatalog(domainCatalogRef.current, incomingCatalog);
            }
            setCatalogVersion((version) => version + 1);

            // Catalog/list first — never filter against empty plain before refs update.
            if (incomingCatalog.length > 0) {
                allDomainLinksRef.current = incomingCatalog;
                setDomainLinkCatalogCount(incomingCatalog.length);
            } else if (incomingDomainList) {
                allDomainLinksRef.current = incomingDomainList;
                setDomainLinkCatalogCount(incomingDomainList.length);
            }

            // domain_link_list from /editor/links is already forArticle — use when no plain text yet.
            if (incomingDomainList && incomingDomainList.length > 0 && articlePlain.trim() === '') {
                setDomainLinks(
                    incomingDomainList
                        .filter((item) => !isSpecialOrContactHref(item?.href ?? item?.target_url))
                        .map((item) => ({
                            ...item,
                            can_insert: item.can_insert !== false,
                        })),
                );
                setDomainLinksCollapsed(false);
            } else {
                setDomainLinks(
                    applyDomainLinkFilters(allDomainLinksRef.current, articlePlain, internal, external),
                );
            }

            if (Array.isArray(event.detail?.domain_cta_list)) {
                setDomainCtas(event.detail.domain_cta_list);
            }
            if (
                event.detail?.cta_quick_templates
                && typeof event.detail.cta_quick_templates === 'object'
            ) {
                setServerCtaTemplates(event.detail.cta_quick_templates);
            }
        };

        const onDomainInserted = (event) => {
            const text = normalizeLinkLabel(event.detail?.text);
            const hrefKey = normalizeHrefForCompare(event.detail?.href);
            if (!text && !hrefKey) {
                return;
            }

            setDomainLinks((prev) =>
                prev.filter((item) => {
                    if (text && normalizeLinkLabel(item.text) === text) {
                        return false;
                    }
                    if (hrefKey && normalizeHrefForCompare(item.href ?? item.target_url) === hrefKey) {
                        return false;
                    }
                    return true;
                }),
            );
            setDomainHiddenRowKeys(new Set());
        };

        const onInserted = () => {
            setHiddenRowKeys(new Set());
            setDomainHiddenRowKeys(new Set());
        };

        window.addEventListener('seo-editor-links-updated', onLinksUpdate);
        window.addEventListener('seo-editor-suggested-link-inserted', onInserted);
        window.addEventListener('seo-editor-suggested-link-inserted', onDomainInserted);

        return () => {
            window.removeEventListener('seo-editor-links-updated', onLinksUpdate);
            window.removeEventListener('seo-editor-suggested-link-inserted', onInserted);
            window.removeEventListener('seo-editor-suggested-link-inserted', onDomainInserted);
        };
    }, []);

    const internal = links.internal ?? [];
    const external = links.external ?? [];

    const suggestedInternal = useMemo(() => {
        const plain = articlePlainText.trim();
        const partitioned = partitionSuggestionCatalogBySite(
            keywordCatalogRef.current,
            siteDomainRef.current,
        );
        const pool = partitioned.internal;
        const internalSignature = (internal ?? [])
            .map((item) => {
                const label = normalizeLinkLabel(item?.text);
                const href = normalizeHrefForCompare(item?.href);

                return `${label}|${href}`;
            })
            .join(';');
        const externalSignature = (external ?? [])
            .map((item) => {
                const label = normalizeLinkLabel(item?.text);
                const href = normalizeHrefForCompare(item?.href);

                return `${label}|${href}`;
            })
            .join(';');
        const poolKey = `${catalogVersion}:${internalSignature}:${externalSignature}:${plain}:internal`;

        if (stableSuggestionsKeyRef.current !== poolKey) {
            stableSuggestionsKeyRef.current = poolKey;
            stableSuggestionsRef.current = buildVisibleInternalSuggestions({
                catalog: pool,
                internal,
                external,
                excludedLabels: [],
                skipContentFilter: true,
            });
        }

        return stableSuggestionsRef.current.filter((item) => {
            const keywordId = Number(item?.keyword_id ?? 0);
            if (keywordId > 0 && reviewedKeywordIds.has(keywordId)) {
                return false;
            }

            return !isSuggestionExcluded(String(item?.text ?? ''), excludedSuggestionLabels);
        });
    }, [internal, external, excludedSuggestionLabels, reviewedKeywordIds, articlePlainText, catalogVersion, anchorEditTick]);

    const suggestedInternalRef = useRef(suggestedInternal);
    suggestedInternalRef.current = suggestedInternal;

    const suggestedExternal = useMemo(() => {
        const plain = articlePlainText.trim();
        const fromKeywords = partitionSuggestionCatalogBySite(
            keywordCatalogRef.current,
            siteDomainRef.current,
        ).external;
        const fromExternalCatalog = externalKeywordCatalogRef.current;
        const pool = mergeSuggestionCatalog(fromExternalCatalog, fromKeywords);
        const internalSignature = (internal ?? [])
            .map((item) => `${normalizeLinkLabel(item?.text)}|${normalizeHrefForCompare(item?.href)}`)
            .join(';');
        const externalSignature = (external ?? [])
            .map((item) => `${normalizeLinkLabel(item?.text)}|${normalizeHrefForCompare(item?.href)}`)
            .join(';');
        const poolKey = `${catalogVersion}:${internalSignature}:${externalSignature}:${plain}:external`;

        if (stableExternalSuggestionsKeyRef.current !== poolKey) {
            stableExternalSuggestionsKeyRef.current = poolKey;
            stableExternalSuggestionsRef.current = buildVisibleInternalSuggestions({
                catalog: pool,
                internal,
                external,
                excludedLabels: [],
                skipContentFilter: true,
                maxSlots: Number.MAX_SAFE_INTEGER,
            });
        }

        return stableExternalSuggestionsRef.current.filter((item) => {
            const href = String(item?.href ?? item?.target_url ?? '').trim();
            if (href === '' || isSpecialOrContactHref(href)) {
                return false;
            }

            const keywordId = Number(item?.keyword_id ?? 0);
            if (keywordId > 0 && reviewedKeywordIds.has(keywordId)) {
                return false;
            }

            return !isSuggestionExcluded(String(item?.text ?? ''), excludedSuggestionLabels);
        });
    }, [internal, external, excludedSuggestionLabels, reviewedKeywordIds, articlePlainText, catalogVersion, anchorEditTick]);

    const copyKeyword = async (value) => {
        const text = String(value ?? '').trim();
        if (!text) {
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_copied_title'),
                        body: `«${text}»`,
                        status: 'success',
                    },
                }),
            );
        } catch {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_copy_failed_title'),
                        body: t('links_copy_failed_body'),
                        status: 'warning',
                    },
                }),
            );
        }
    };

    const scrollToKeyword = (item, type, listIndex, itemKey, options = {}) => {
        setActiveKey(itemKey);
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? '').trim();
        const count = occurrenceCount(item);
        const currentCycle = Number(cycleByKey[itemKey] ?? 0);
        const nextIndex = count > 1 ? currentCycle % count : 0;

        setCycleByKey((prev) => ({
            ...prev,
            [itemKey]: currentCycle + 1,
        }));

        const detail = {
            href,
            text,
            offset: item.offset,
            type,
            index: type === 'faq'
                ? (typeof item.index === 'number' ? item.index : nextIndex)
                : nextIndex,
            faqIndex: type === 'faq'
                ? (typeof item.index === 'number' ? item.index : nextIndex)
                : undefined,
            searchPlainText: options.searchPlainText === true,
            preferHrefMatch: !text && !!href,
        };
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.scrollToLink === 'function') {
            actions.scrollToLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-scroll-to-link', { detail }));
    };

    const removeInternalLink = (item) => {
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? '').trim();
        if (!text && !href) {
            return;
        }

        const detail = { text, href };
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.removeInternalLink === 'function') {
            actions.removeInternalLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-remove-internal-link', { detail }));
    };

    const updateSuggestionAnchor = (item, _itemKey, nextText) => {
        const trimmed = String(nextText ?? '').trim();
        if (trimmed === '') {
            return;
        }

        keywordCatalogRef.current = patchSuggestionAnchorInList(
            keywordCatalogRef.current,
            item,
            trimmed,
        );
        externalKeywordCatalogRef.current = patchSuggestionAnchorInList(
            externalKeywordCatalogRef.current,
            item,
            trimmed,
        );
        stableSuggestionsRef.current = patchSuggestionAnchorInList(
            stableSuggestionsRef.current,
            item,
            trimmed,
        );
        stableExternalSuggestionsRef.current = patchSuggestionAnchorInList(
            stableExternalSuggestionsRef.current,
            item,
            trimmed,
        );
        setAnchorEditTick((tick) => tick + 1);
    };

    const insertSuggestedLink = (item, _index, itemKey) => {
        hideSuggestionRow(itemKey);

        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        const count = occurrenceCount(item);
        const cycle = Number(cycleByKey[itemKey] ?? 0);
        const occurrenceIndex = cycle > 0 && count > 1 ? (cycle - 1) % count : 0;
        if (!text || !href) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_insert_failed_title'),
                        body: t('links_insert_failed_body'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        const detail = {
            text,
            href,
            keyword_id: item.keyword_id ?? null,
            occurrence_index: occurrenceIndex,
        };
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.insertSuggestedLink === 'function') {
            actions.insertSuggestedLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
    };

    const hideDomainRow = (itemKey) => {
        if (!itemKey) {
            return;
        }
        setDomainHiddenRowKeys((prev) => {
            if (prev.has(itemKey)) {
                return prev;
            }
            const next = new Set(prev);
            next.add(itemKey);
            return next;
        });
    };

    const scrollToDomainItem = (item, itemKey, variant) => {
        if (variant === 'cta') {
            setCtaActiveKey(itemKey);
        } else {
            setDomainLinkActiveKey(itemKey);
        }
        const text = variant === 'cta' ? ctaDisplayLabel(item) : String(item?.text ?? '').trim();
        let listIndex = 0;
        if (variant === 'cta') {
            // Same cycle pattern as internal-link find → wrap insert uses matching occurrence.
            const currentCycle = Number(cycleByKey[itemKey] ?? 0);
            listIndex = currentCycle;
            setCycleByKey((prev) => ({
                ...prev,
                [itemKey]: currentCycle + 1,
            }));
        }

        const detail = {
            href:
                variant === 'cta'
                    ? String(item?.href ?? formatCtaHref(item?.type, item?.value)).trim()
                    : String(item?.href ?? item?.target_url ?? '').trim(),
            text,
            type: 'internal',
            index: listIndex,
            searchPlainText: true,
        };
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.scrollToLink === 'function') {
            actions.scrollToLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-scroll-to-link', { detail }));
    };

    const insertDomainLink = (item, itemKey) => {
        hideDomainRow(itemKey);

        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (!text || !href) {
            return;
        }

        const ctx = getEditorInsertionContext();
        const detail = {
            text,
            href,
            keyword_id: item.keyword_id ?? null,
            insert_mode: 'caret',
            target: {
                sectionId: ctx.activeSectionId,
                blockId: ctx.activeBlockId,
                selectionBookmark: ctx.selection,
            },
        };
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.insertSuggestedLink === 'function') {
            actions.insertSuggestedLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
    };

    const linkCountBadge = internal.length + external.length + domainLinks.length;
    const showAllLinkSections = linkSectionFilter === 'all';
    const showLinksCluster = showAllLinkSections || linkSectionFilter === 'links';
    const showCtaSection = showAllLinkSections || linkSectionFilter === 'cta';

    useEffect(() => {
        // Links item/issue counts owned by assistantWidgetHealth (valid HTTP links).
        // CTA chip still uses contact count from this sidebar.
        window.dispatchEvent(
            new CustomEvent('seo-assistant-navigator-badges', {
                detail: {
                    cta: usableDomainCtas.length > 0 ? usableDomainCtas.length : null,
                },
            }),
        );
    }, [usableDomainCtas.length]);

    return (
        <ArticleAssistantWidget
            widgetId="links"
            title="Link Assistant"
            icon={Link2}
            badge={linkCountBadge > 0 ? linkCountBadge : null}
            defaultCollapsed={false}
            className="seo-assistant-widget--links"
        >
            <div className="seo-link-assistant">
                {baseLoading ? (
                    <div className="seo-module-loading p-3 text-sm text-gray-500 dark:text-gray-400">
                        {t('editor_module_loading')}
                    </div>
                ) : null}
                {baseError ? (
                    <div className="seo-module-error p-3 text-sm text-rose-600 dark:text-rose-400">
                        <p>{baseError}</p>
                    </div>
                ) : null}
                {suggestionsError ? (
                    <div className="seo-module-error p-2 text-sm text-rose-600 dark:text-rose-400">
                        <p>{suggestionsError}</p>
                    </div>
                ) : null}
                {suggestionsEmpty && !suggestionsLoading && !suggestionsError ? (
                    <p className="wp-article-links-empty px-2">{t('editor_links_suggestions_empty')}</p>
                ) : null}
                {showLinksCluster ? (
                    <>
                <LinkAssistantSection
                    title={`Internal Links (${internal.length})`}
                    count={internal.length}
                    collapsed={internalCollapsed}
                    onToggle={() => setInternalCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <InternalLinksSection
                        internal={internal}
                        suggestedInternal={suggestedInternal}
                        activeKey={activeKey}
                        hiddenRowKeys={hiddenRowKeys}
                        excludedCount={excludedSuggestionLabels.size}
                        onClearExcluded={clearExcludedSuggestions}
                        onGenerateSuggestions={() => loadLinkSuggestions('full')}
                        onGenerateFallbackSuggestions={() => loadLinkSuggestions('fallback')}
                        suggestionsLoading={suggestionsLoading}
                        onKeywordClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey)
                        }
                        onCopyKeyword={copyKeyword}
                        onRemoveInternalLink={removeInternalLink}
                        onSuggestionClick={(item, index, itemKey) =>
                            scrollToKeyword(item, 'internal', index, itemKey, { searchPlainText: true })
                        }
                        onInsertSuggestion={insertSuggestedLink}
                        onUpdateSuggestionAnchor={updateSuggestionAnchor}
                        reviewLoadingKey={reviewLoadingKey}
                        reviewPopoverItemKey={reviewPopover?.itemKey ?? ''}
                        onReviewWarning={(item, _index, itemKey, anchorEl) =>
                            openReviewPopover(item, itemKey, 'warning', anchorEl)
                        }
                        onReviewDanger={(item, _index, itemKey, anchorEl) =>
                            openReviewPopover(item, itemKey, 'danger', anchorEl)
                        }
                    />
                </LinkAssistantSection>

                <LinkAssistantSection
                    title={`External Links (${external.length})`}
                    count={external.length}
                    collapsed={externalCollapsed}
                    onToggle={() => setExternalCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <div className="wp-article-links-group">
                        <h3 className="wp-article-links-group__title">
                            {t('links_external_title', { count: external.length })}
                        </h3>
                        {external.length > 0 ? (
                            <KeywordList
                                items={external}
                                title=""
                                activeKey={activeKey}
                                target="editor"
                                hideTitle
                                onKeywordClick={(item, index, itemKey) =>
                                    scrollToKeyword(item, 'external', index, itemKey)
                                }
                                onCopyKeyword={copyKeyword}
                            />
                        ) : (
                            <p className="wp-article-links-empty">{t('links_external_empty')}</p>
                        )}
                        {suggestedExternal.length > 0 ? (
                            <KeywordList
                                items={suggestedExternal}
                                title={t('links_external_suggestion_title', {
                                    count: suggestedExternal.length,
                                })}
                                activeKey={activeKey}
                                target="editor"
                                variant="suggestion"
                                suggestionKind="external"
                                hideTitle
                                hiddenRowKeys={hiddenRowKeys}
                                reviewLoadingKey={reviewLoadingKey}
                                reviewPopoverItemKey={reviewPopover?.itemKey ?? ''}
                                onKeywordClick={(item, index, itemKey) =>
                                    scrollToKeyword(item, 'external', index, itemKey, {
                                        searchPlainText: true,
                                    })
                                }
                                onInsertSuggestion={insertSuggestedLink}
                                onUpdateSuggestionAnchor={updateSuggestionAnchor}
                                onCopyKeyword={copyKeyword}
                                onReviewWarning={(item, _index, itemKey, anchorEl) =>
                                    openReviewPopover(item, itemKey, 'warning', anchorEl)
                                }
                                onReviewDanger={(item, _index, itemKey, anchorEl) =>
                                    openReviewPopover(item, itemKey, 'danger', anchorEl)
                                }
                            />
                        ) : null}
                    </div>
                </LinkAssistantSection>

                <LinkAssistantSection
                    title={`${t('domain_link_widget_title')} (${domainLinks.length})`}
                    count={domainLinks.length}
                    collapsed={domainLinksCollapsed}
                    onToggle={() => setDomainLinksCollapsed((value) => !value)}
                    sectionKey="links"
                >
                    <p className="wp-article-links-hint">{t('domain_link_widget_hint')}</p>
                    <DomainInsertableList
                        items={domainLinks}
                        variant="domain-link"
                        activeKey={domainLinkActiveKey}
                        hiddenRowKeys={domainHiddenRowKeys}
                        emptyText={
                            domainLinkCatalogCount > 0
                                ? t('domain_link_widget_empty_in_article')
                                : t('domain_link_widget_empty')
                        }
                        onKeywordClick={(item, _index, itemKey) => scrollToDomainItem(item, itemKey, 'domain-link')}
                        onInsert={insertDomainLink}
                    />
                </LinkAssistantSection>
                    </>
                ) : null}

                {showCtaSection ? (
                <LinkAssistantSection
                    title={`${t('cta_widget_title')} (${usableDomainCtas.length})`}
                    count={usableDomainCtas.length}
                    collapsed={ctaCollapsed}
                    onToggle={() => setCtaCollapsed((value) => !value)}
                    sectionKey="cta"
                >
                    <div className="wp-postbox-header-actions wp-article-links-cta-section-head">
                        <p className="wp-article-links-hint">{t('cta_widget_hint')}</p>
                        <div className="wp-article-links-cta-quick-wrap">
                            <button
                                type="button"
                                className="wp-article-links-insert-btn"
                                aria-label={t('cta_widget_settings_title')}
                                title={t('cta_widget_settings_title')}
                                onClick={() => setCtaSettingsOpen(true)}
                            >
                                <Settings2 size={14} aria-hidden />
                            </button>
                            <CtaQuickTemplateSettingsPopover
                                siteId={effectiveSiteId}
                                open={ctaSettingsOpen}
                                onClose={() => setCtaSettingsOpen(false)}
                                settings={templatesByType}
                                onSave={setTemplatesByType}
                            />
                        </div>
                    </div>
                    <CtaContactInsertList
                        items={domainCtas}
                        activeKey={ctaActiveKey}
                        templatesByType={templatesByType}
                        emptyText={t('cta_widget_empty')}
                        onKeywordClick={(item, _index, itemKey) => scrollToDomainItem(item, itemKey, 'cta')}
                        onInsertQuickCta={(item, itemKey, templateOverride, mode = 'sentence') => {
                            const cycle = Number(cycleByKey[itemKey] ?? 0);
                            const occurrenceIndex = cycle > 0 ? (cycle - 1) : 0;
                            dispatchCtaInsert(
                                item,
                                mode,
                                templateOverride,
                                templatesByType,
                                mode === 'value' ? occurrenceIndex : 0,
                            );
                        }}
                    />
                </LinkAssistantSection>
                ) : null}
            </div>
            <KeywordReviewPopover
                state={reviewPopover}
                articleId={articleMetaRef.current.articleId}
                onClose={() => setReviewPopover(null)}
                onSubmitted={handleReviewSubmitted}
                onError={() => {}}
                onLoadingChange={setReviewLoadingKey}
            />
        </ArticleAssistantWidget>
    );
}
