import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { ExternalLink, Loader2, Search, Trash2 } from 'lucide-react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { computeLinkBubblePosition } from '../utils/linkEditorAnchor';
import { resolveLinkableSelectionRange } from '../utils/linkAnchorValidation';
import { applyLinkToSelection } from '../utils/inlineLinkNormalizer';
import { executeEditorCommand } from '../utils/editorCommands';
import { openAssignToContentProject, AssignToContentProjectContract } from '../utils/assignToContentProject';
import { t } from '../utils/i18n';

const ARTICLE_SEARCH_DEBOUNCE_MS = 450;
const ARTICLE_SEARCH_MIN_CHARS = 2;

function readEditorSelectionText(editor) {
    if (!editor) {
        return '';
    }

    const range = resolveLinkableSelectionRange(editor);
    if (range && !range.empty) {
        return range.text;
    }

    const { from, to, empty } = editor.state.selection;
    if (empty) {
        return '';
    }

    return editor.state.doc.textBetween(from, to, ' ').replace(/\s+/gu, ' ').trim();
}

function isFilamentModalTarget(target) {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(
        target.closest('.fi-modal-window, .fi-modal, .fi-modal-close-overlay, [role="dialog"]'),
    );
}

export default function LinkEditBubble({ editor, anchorRect, containerRef, onClose, articleId = null, siteId = null }) {
    const [url, setUrl] = useState('');
    const [anchorPhrase, setAnchorPhrase] = useState('');
    const [articleQuery, setArticleQuery] = useState('');
    const [articleResults, setArticleResults] = useState([]);
    const [articleLoading, setArticleLoading] = useState(false);
    const [articleSearchError, setArticleSearchError] = useState('');
    const [ordinarySearchEmpty, setOrdinarySearchEmpty] = useState(false);
    const [semanticLoading, setSemanticLoading] = useState(false);
    const [semanticTried, setSemanticTried] = useState(false);
    const [assignNotice, setAssignNotice] = useState('');
    const [assigning, setAssigning] = useState(false);
    const [position, setPosition] = useState({ top: 0, left: 0 });
    const inputRef = useRef(null);
    const articleInputRef = useRef(null);
    const panelRef = useRef(null);
    const searchSeqRef = useRef(0);
    const savedSelectionRef = useRef(null);
    const positionLockedRef = useRef(false);
    const canSearchArticles = articleId != null && Number(articleId) > 0;

    const captureEditorSelection = useCallback(() => {
        if (!editor) {
            savedSelectionRef.current = null;
            return;
        }

        const range = resolveLinkableSelectionRange(editor);
        if (range && !range.empty) {
            savedSelectionRef.current = { from: range.from, to: range.to };
            return;
        }

        const { from, to, empty } = editor.state.selection;
        savedSelectionRef.current = empty ? null : { from, to };
    }, [editor]);

    const restoreEditorSelection = useCallback((options = {}) => {
        const { focusEditor = true } = options;
        const saved = savedSelectionRef.current;
        if (!editor || !saved) {
            return false;
        }

        const docSize = editor.state.doc.content.size;
        const from = Math.min(Math.max(0, saved.from), docSize);
        const to = Math.min(Math.max(from, saved.to), docSize);
        if (from === to) {
            return false;
        }

        // focusEditor=false keeps Search/URL inputs editable (PM selection only).
        const chain = focusEditor ? editor.chain().focus() : editor.chain();
        chain.setTextSelection({ from, to }).run();
        return true;
    }, [editor]);

    const applyPlaceholderLink = useCallback(
        (href) => {
            const trimmed = String(href ?? '').trim();
            if (trimmed === '') {
                return false;
            }

            restoreEditorSelection();

            if (applyLinkToSelection(editor, trimmed)) {
                setUrl(trimmed);
                return true;
            }

            setUrl(trimmed);
            setArticleSearchError(t('link_bubble_assign_select_text'));
            return false;
        },
        [editor, restoreEditorSelection],
    );

    useEffect(() => {
        if (!editor) {
            return;
        }

        setArticleResults([]);
        setArticleSearchError('');
        setOrdinarySearchEmpty(false);
        setSemanticLoading(false);
        setSemanticTried(false);
        setAssignNotice('');
        setArticleLoading(false);
        searchSeqRef.current += 1;
        positionLockedRef.current = false;
        captureEditorSelection();

        const selectedPhrase = readEditorSelectionText(editor);
        const { empty } = editor.state.selection;

        if (editor.isActive('link') && empty) {
            editor.chain().focus().extendMarkRange('link').run();
            setUrl(editor.getAttributes('link').href ?? '');
            setAnchorPhrase(readEditorSelectionText(editor) || selectedPhrase);
            setArticleQuery(readEditorSelectionText(editor) || selectedPhrase);
            setTimeout(() => inputRef.current?.focus(), 0);
        } else {
            setUrl(editor.isActive('link') ? (editor.getAttributes('link').href ?? '') : '');
            setAnchorPhrase(selectedPhrase);
            setArticleQuery(selectedPhrase);
            setTimeout(() => {
                if (canSearchArticles) {
                    articleInputRef.current?.focus();
                } else {
                    inputRef.current?.focus();
                }
            }, 0);
        }
    }, [editor, anchorRect, canSearchArticles, captureEditorSelection]);

    useEffect(() => {
        const onDocMouseDown = (e) => {
            if (panelRef.current?.contains(e.target)) {
                return;
            }

            if (isFilamentModalTarget(e.target)) {
                return;
            }

            onClose();
        };

        document.addEventListener('mousedown', onDocMouseDown);

        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [onClose]);

    useEffect(() => {
        const onPendingLinkReady = (event) => {
            const detail = event?.detail ?? {};
            const href = String(detail.placeholderHref ?? detail.placeholder_href ?? '').trim();
            const message = String(detail.message ?? '').trim();
            if (message !== '') {
                setAssignNotice(message);
            }

            if (href === '') {
                return;
            }

            applyPlaceholderLink(href);
            inputRef.current?.focus();
        };

        window.addEventListener('pending-internal-link-ready', onPendingLinkReady);

        return () => window.removeEventListener('pending-internal-link-ready', onPendingLinkReady);
    }, [applyPlaceholderLink]);

    useLayoutEffect(() => {
        const container = containerRef?.current;
        const panel = panelRef.current;
        if (!anchorRect || !container || !panel) {
            return;
        }

        // Lock position after first layout so result/loading height changes do not jump the popup.
        if (positionLockedRef.current) {
            return;
        }

        const next = computeLinkBubblePosition(anchorRect, container, {
            width: panel.offsetWidth,
            height: panel.offsetHeight,
        });
        setPosition(next);
        positionLockedRef.current = true;
    }, [anchorRect, containerRef]);

    const fetchArticleResults = useCallback(async (query) => {
        if (!canSearchArticles) {
            setArticleResults([]);
            setOrdinarySearchEmpty(false);
            return;
        }

        const trimmed = String(query ?? '').replace(/\s+/gu, ' ').trim();
        if (trimmed === '' || trimmed.length < ARTICLE_SEARCH_MIN_CHARS) {
            setArticleResults([]);
            setArticleSearchError('');
            setOrdinarySearchEmpty(false);
            setSemanticTried(false);
            setArticleLoading(false);
            return;
        }

        const seq = ++searchSeqRef.current;
        setArticleLoading(true);
        setArticleSearchError('');
        setOrdinarySearchEmpty(false);
        setSemanticTried(false);
        setSemanticLoading(false);

        try {
            // Ordinary same-domain keyword search only — never auto-run semantic.
            const results = await callEditArticleLivewire('searchInternalLinkArticles', trimmed);
            if (seq !== searchSeqRef.current) {
                return;
            }
            const rows = Array.isArray(results) ? results : [];
            setArticleResults(rows);
            setOrdinarySearchEmpty(rows.length === 0);
        } catch {
            if (seq !== searchSeqRef.current) {
                return;
            }
            setArticleResults([]);
            setOrdinarySearchEmpty(false);
            setArticleSearchError(t('link_bubble_article_search_failed'));
        } finally {
            if (seq === searchSeqRef.current) {
                setArticleLoading(false);
            }
        }
    }, [canSearchArticles]);

    const runSemanticSearch = useCallback(async () => {
        if (!canSearchArticles || semanticLoading) {
            return;
        }
        const trimmed = String(articleQuery ?? '').replace(/\s+/gu, ' ').trim();
        if (trimmed.length < ARTICLE_SEARCH_MIN_CHARS) {
            return;
        }

        const seq = ++searchSeqRef.current;
        setSemanticLoading(true);
        setArticleSearchError('');
        setSemanticTried(true);

        try {
            const results = await callEditArticleLivewire('searchInternalLinkArticlesSemantic', trimmed);
            if (seq !== searchSeqRef.current) {
                return;
            }
            const rows = Array.isArray(results) ? results : [];
            setArticleResults(rows);
            if (rows.length === 0) {
                setArticleSearchError(t('link_bubble_article_search_semantic_empty'));
            }
        } catch {
            if (seq !== searchSeqRef.current) {
                return;
            }
            setArticleResults([]);
            setArticleSearchError(t('link_bubble_article_search_semantic_failed'));
        } finally {
            if (seq === searchSeqRef.current) {
                setSemanticLoading(false);
            }
        }
    }, [articleQuery, canSearchArticles, semanticLoading]);

    const { debounced: debouncedFetchArticles, cancel: cancelArticleSearch } = useDebouncedCallback((query) => {
        void fetchArticleResults(query);
    }, ARTICLE_SEARCH_DEBOUNCE_MS);

    useEffect(() => {
        if (!canSearchArticles || !anchorRect) {
            return undefined;
        }

        debouncedFetchArticles(articleQuery);

        return () => {
            cancelArticleSearch();
        };
    }, [articleQuery, canSearchArticles, anchorRect, debouncedFetchArticles, cancelArticleSearch]);

    const applyLink = () => {
        const trimmed = url.trim();
        restoreEditorSelection();
        if (trimmed === '') {
            if (editor.isActive('link')) {
                executeEditorCommand('remove_link_keep_text', { editor }, { notifyOnFailure: false });
            }
        } else {
            const range = resolveLinkableSelectionRange(editor);
            if (!range && !editor.isActive('link')) {
                setArticleSearchError(t('link_bubble_assign_select_text'));
                return;
            }

            const commandName = editor.isActive('link') && (!range || range.empty) ? 'update_link' : 'create_link';
            const result = executeEditorCommand(commandName, {
                editor,
                href: trimmed,
                url: trimmed,
            }, { notifyOnFailure: false });
            // Fallback only if command registry missing handler (should not happen in Phase 4+).
            if (!(result?.ok && result.transaction_applied)) {
                if (range && !range.empty) {
                    editor.chain().focus().setTextSelection({ from: range.from, to: range.to }).run();
                }
                applyLinkToSelection(editor, trimmed);
            }
        }

        onClose();
    };

    const removeLink = () => {
        restoreEditorSelection();
        executeEditorCommand('remove_link_keep_text', { editor }, { notifyOnFailure: false });
        onClose();
    };

    const openHref = () => {
        const trimmed = url.trim();
        if (trimmed) {
            window.open(trimmed, '_blank', 'noopener,noreferrer');
        }
    };

    const selectArticleResult = (item) => {
        const nextUrl = String(item?.url ?? '').trim();
        if (nextUrl === '') {
            return;
        }

        // Fill URL only — never rewrite Search input (avoids re-search + layout jump).
        setUrl(nextUrl);
        setArticleSearchError('');
        inputRef.current?.focus();
    };

    const handleAssignToContentProject = async () => {
        const phrase = (readEditorSelectionText(editor) || anchorPhrase).trim();
        if (!canSearchArticles || phrase === '') {
            setArticleSearchError(t('link_bubble_assign_select_text'));
            return;
        }

        captureEditorSelection();
        setAssigning(true);
        setArticleSearchError('');
        setAssignNotice('');

        try {
            openAssignToContentProject({
                mode: AssignToContentProjectContract.MODE_PENDING_LINK,
                source: 'link_edit_bubble',
                article_ids: [Number(articleId)],
                site_ids: siteId != null && Number(siteId) > 0 ? [Number(siteId)] : [],
                anchor_phrase: phrase,
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : t('link_bubble_assign_failed');
            setArticleSearchError(message);
        } finally {
            setAssigning(false);
        }
    };

    if (!anchorRect || !containerRef?.current) {
        return null;
    }

    return (
        <div
            ref={panelRef}
            className="seo-link-bubble"
            style={{ top: `${position.top}px`, left: `${position.left}px` }}
            onMouseDown={(e) => {
                // Never preventDefault on inputs — that blocks focus and looks "readonly".
                e.stopPropagation();
            }}
        >
            {canSearchArticles ? (
                <div className="seo-link-bubble-section">
                    <label className="seo-link-bubble-label" htmlFor="seo-link-article-search-input">
                        {t('link_bubble_article_search_label')}
                    </label>
                    <div className="seo-link-bubble-search-row">
                        <div className="seo-link-bubble-search-wrap">
                            <Search size={14} className="seo-link-bubble-search-icon" aria-hidden />
                            <input
                                id="seo-link-article-search-input"
                                ref={articleInputRef}
                                type="search"
                                className="seo-link-bubble-input seo-link-bubble-input--search"
                                value={articleQuery}
                                onChange={(e) => setArticleQuery(e.target.value)}
                                onFocus={() => restoreEditorSelection({ focusEditor: false })}
                                placeholder={t('link_bubble_article_search_placeholder')}
                                autoComplete="off"
                                readOnly={false}
                            />
                        </div>
                        <button
                            type="button"
                            className="seo-link-bubble-assign-btn"
                            disabled={assigning || anchorPhrase.trim() === ''}
                            title={t('link_bubble_assign_content_project')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => void handleAssignToContentProject()}
                        >
                            {assigning ? <Loader2 size={14} className="animate-spin" aria-hidden /> : null}
                            <span>{t('link_bubble_assign_content_project')}</span>
                        </button>
                    </div>
                    {assignNotice ? <p className="seo-link-bubble-assign-notice">{assignNotice}</p> : null}
                    <div className="seo-link-bubble-results" role="listbox" aria-label={t('link_bubble_article_search_label')}>
                        {articleLoading || semanticLoading ? (
                            <p className="seo-link-bubble-results-empty is-loading">
                                <Loader2 size={14} className="seo-link-bubble-results-spinner animate-spin" aria-hidden />
                                <span>
                                    {semanticLoading
                                        ? t('link_bubble_article_search_semantic_loading')
                                        : t('link_bubble_article_search_loading')}
                                </span>
                            </p>
                        ) : articleResults.length > 0 ? (
                            articleResults.map((item) => {
                                const key = `${item.id ?? item.url}-${item.title}`;
                                const isSelected = url.trim() !== '' && url.trim() === String(item.url ?? '').trim();

                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        role="option"
                                        className={`seo-link-bubble-result${isSelected ? ' is-selected' : ''}`}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => selectArticleResult(item)}
                                    >
                                        <span className="seo-link-bubble-result__title">{item.title}</span>
                                        <span className="seo-link-bubble-result__url">{item.url}</span>
                                    </button>
                                );
                            })
                        ) : (
                            <div className="seo-link-bubble-results-empty">
                                <p>
                                    {articleSearchError
                                        || (articleQuery.trim().length === 0
                                            ? t('link_bubble_article_search_type_to_search', {
                                                  count: ARTICLE_SEARCH_MIN_CHARS,
                                              })
                                            : articleQuery.trim().length < ARTICLE_SEARCH_MIN_CHARS
                                              ? t('link_bubble_article_search_min_chars', {
                                                    count: ARTICLE_SEARCH_MIN_CHARS,
                                                })
                                              : t('link_bubble_article_search_empty'))}
                                </p>
                                {ordinarySearchEmpty
                                && !semanticTried
                                && articleQuery.trim().length >= ARTICLE_SEARCH_MIN_CHARS ? (
                                    <button
                                        type="button"
                                        className="seo-link-bubble-semantic-btn"
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => void runSemanticSearch()}
                                    >
                                        {t('link_bubble_article_search_semantic')}
                                    </button>
                                ) : null}
                            </div>
                        )}
                    </div>
                    {siteId ? (
                        <p className="seo-link-bubble-hint">{t('link_bubble_article_search_hint')}</p>
                    ) : null}
                </div>
            ) : null}

            <label className="seo-link-bubble-label" htmlFor="seo-link-url-input">
                URL
            </label>
            <div className="seo-link-bubble-row">
                <input
                    id="seo-link-url-input"
                    ref={inputRef}
                    type="text"
                    inputMode="url"
                    className="seo-link-bubble-input"
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    onFocus={() => restoreEditorSelection({ focusEditor: false })}
                    readOnly={false}
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    name="seo-editor-link-url"
                    data-lpignore="true"
                    data-form-type="other"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            applyLink();
                        }

                        if (e.key === 'Escape') {
                            e.preventDefault();
                            onClose();
                        }
                    }}
                    placeholder="https://"
                />
                <button type="button" className="seo-link-bubble-icon-btn" title="Open link" onClick={openHref}>
                    <ExternalLink size={15} />
                </button>
                <button type="button" className="seo-link-bubble-icon-btn is-danger" title="Remove link" onClick={removeLink}>
                    <Trash2 size={15} />
                </button>
            </div>
            <div className="seo-link-bubble-actions">
                <button type="button" className="seo-link-bubble-btn" onClick={onClose}>
                    {t('cancel')}
                </button>
                <button type="button" className="seo-link-bubble-btn is-primary" onClick={applyLink}>
                    {t('apply')}
                </button>
            </div>
        </div>
    );
}
