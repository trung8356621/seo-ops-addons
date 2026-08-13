import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    BookText,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Link2,
    Loader2,
} from 'lucide-react';
import ArticleAssistantWidget from '@ai-prompt-addon/components/ArticleAssistantWidget.jsx';
import { t } from '../utils/i18n';
import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { readCoreArticleIdentity } from '../utils/articleEditorPayloadAdapters';
import {
    collectEditorBlocksFromDom,
    findPhraseOccurrencesInBlocks,
    scrollToPhraseOccurrence,
} from '../utils/articlePhraseOccurrences';
import { searchInternalLinkArticlesCached } from '../utils/internalLinkArticleSearch';
import { getEditorCommandHost } from '../utils/editorCommands';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { normalizeHrefForCompare } from '../utils/articleLinkSuggestionFilter';
import SeoSelect from './SeoSelect';

async function fetchEditorVocabulary(articleId, signal) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        throw new Error('Invalid article id');
    }

    const url =
        window.__SEO_EDITOR_LAZY_ENDPOINTS__?.vocabulary
        || `/api/seo/articles/${id}/editor/vocabulary`;

    const { response, data } = await seoArticleApiFetch(url, {
        method: 'GET',
        signal,
        headers: {
            Accept: 'application/json',
            ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
    });

    if (!response.ok || data?.success === false) {
        throw new Error(String(data?.message ?? 'vocabulary_failed'));
    }

    const src = data?.data && typeof data.data === 'object' ? data.data : data;
    const groups = src?.groups && typeof src.groups === 'object' ? src.groups : {};
    const planning = src?.planning && typeof src.planning === 'object' ? src.planning : {};

    return {
        groups,
        groupCount: Number(src?.group_count ?? Object.keys(groups).length),
        itemCount: Number(src?.item_count ?? 0),
        planning: {
            projectOptions: planning.project_options && typeof planning.project_options === 'object'
                ? planning.project_options
                : {},
            selectedProjectId: planning.selected_project_id != null
                ? Number(planning.selected_project_id)
                : null,
            siteId: Number(planning.site_id ?? 0),
        },
    };
}

function selectionKey(group, phrase) {
    return `${group}\u0000${phrase}`;
}

function occurrenceIdentity(occurrence) {
    return `${occurrence?.blockId ?? ''}:${occurrence?.matchIndex ?? 0}`;
}

function articlePathLabel(url) {
    const raw = String(url ?? '').trim();
    if (!raw) {
        return '';
    }
    try {
        const parsed = new URL(raw, typeof window !== 'undefined' ? window.location.origin : 'https://local.test');
        const path = parsed.pathname || '/';
        return path.length > 48 ? `${path.slice(0, 45)}…` : path;
    } catch {
        return raw.length > 48 ? `${raw.slice(0, 45)}…` : raw;
    }
}

/**
 * current_target + top suggestions, dedupe by href, limit 3.
 *
 * @param {Array<Record<string, unknown>>|null|undefined} suggestions
 * @param {string} currentHref
 */
function mergeCandidatesWithCurrentLink(suggestions, currentHref) {
    const limit = 3;
    const rows = [];
    const seen = new Set();
    const current = String(currentHref ?? '').trim();
    const currentKey = normalizeHrefForCompare(current);
    const list = Array.isArray(suggestions) ? suggestions : [];

    if (current !== '' && currentKey !== '') {
        const matched = list.find((row) => normalizeHrefForCompare(row?.url) === currentKey);
        rows.push({
            id: matched?.id ?? null,
            title: matched?.title || matched?.label || '',
            url: matched?.url || current,
            label: matched?.label || matched?.title || '',
            isCurrentLink: true,
        });
        seen.add(currentKey);
    }

    for (const row of list) {
        if (rows.length >= limit) {
            break;
        }
        const key = normalizeHrefForCompare(row?.url);
        if (!key || seen.has(key)) {
            continue;
        }
        seen.add(key);
        rows.push({
            id: row?.id ?? null,
            title: row?.title || row?.label || row?.url || '',
            url: row?.url || '',
            label: row?.label || row?.title || '',
            isCurrentLink: false,
        });
    }

    return rows;
}

function insertVocabularyLink({ phrase, href, occurrenceIndex, blockId }) {
    const text = String(phrase ?? '').trim();
    const url = String(href ?? '').trim();
    if (!text || !url) {
        return;
    }

    const detail = {
        text,
        href: url,
        occurrence_index: Math.max(0, Number(occurrenceIndex) || 0),
        blockId: String(blockId ?? '').trim() || undefined,
    };
    const actions = getEditorCommandHost()?.actions;
    if (typeof actions?.insertSuggestedLink === 'function') {
        actions.insertSuggestedLink(detail);
        return;
    }
    window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
}

function resolveActiveOccurrence(phraseKey, occurrences, activeOccurrenceByPhraseKey) {
    if (!Array.isArray(occurrences) || occurrences.length === 0) {
        return { occurrence: null, index: 0 };
    }
    const selected = activeOccurrenceByPhraseKey.get(phraseKey);
    if (selected) {
        const index = occurrences.findIndex(
            (row) => occurrenceIdentity(row) === occurrenceIdentity(selected),
        );
        if (index >= 0) {
            return { occurrence: occurrences[index], index };
        }
    }
    return { occurrence: occurrences[0], index: 0 };
}

function VocabularyInArticleAnchor({
    phraseKey,
    phrase,
    occurrences,
    activeOccurrenceByPhraseKey,
    onActivateOccurrence,
    suggestions,
    loadingSuggestions,
    onApplyArticle,
}) {
    const { occurrence: activeOccurrence, index: activeIndex } = resolveActiveOccurrence(
        phraseKey,
        occurrences,
        activeOccurrenceByPhraseKey,
    );
    const count = occurrences.length;
    const multi = count > 1;
    const candidates = mergeCandidatesWithCurrentLink(suggestions, activeOccurrence?.href ?? '');

    const activateIndex = (nextIndex) => {
        const target = occurrences[nextIndex];
        if (!target) {
            return;
        }
        onActivateOccurrence(phraseKey, target);
    };

    return (
        <li className="wp-article-vocabulary-anchor">
            <div className="wp-article-vocabulary-anchor-row">
                <button
                    type="button"
                    className="wp-article-vocabulary-anchor-phrase"
                    title={activeOccurrence?.preview || phrase}
                    onClick={() => {
                        if (activeOccurrence) {
                            onActivateOccurrence(phraseKey, activeOccurrence);
                        }
                    }}
                >
                    {phrase}
                </button>
                <div className="wp-article-vocabulary-anchor-meta">
                    {multi ? (
                        <div className="wp-article-vocabulary-occ-nav" aria-label={t('vocabulary_occurrence_nav_label')}>
                            <button
                                type="button"
                                className="wp-article-vocabulary-occ-nav-btn"
                                aria-label={t('vocabulary_occurrence_prev')}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    activateIndex((activeIndex - 1 + count) % count);
                                }}
                            >
                                <ChevronLeft size={13} aria-hidden />
                            </button>
                            <span className="wp-article-vocabulary-occ-nav-index">
                                {activeIndex + 1}/{count}
                            </span>
                            <button
                                type="button"
                                className="wp-article-vocabulary-occ-nav-btn"
                                aria-label={t('vocabulary_occurrence_next')}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    activateIndex((activeIndex + 1) % count);
                                }}
                            >
                                <ChevronRight size={13} aria-hidden />
                            </button>
                        </div>
                    ) : (
                        <span className="wp-article-vocabulary-occ-count">{t('vocabulary_occurrence_count', { count })}</span>
                    )}
                </div>
            </div>

            <div className="wp-article-vocabulary-candidates">
                {loadingSuggestions && suggestions == null && candidates.length === 0 ? (
                    <span className="wp-article-vocabulary-related-loading">
                        <Loader2 size={12} className="animate-spin" aria-hidden />
                        {t('vocabulary_related_loading')}
                    </span>
                ) : null}
                {candidates.length > 0 ? (
                    <ul className="wp-article-vocabulary-candidate-list">
                        {candidates.map((article) => {
                            const title = article.title || article.label || articlePathLabel(article.url) || article.url;
                            const path = articlePathLabel(article.url);
                            return (
                                <li key={`${phrase}-${article.id || article.url}-${article.isCurrentLink ? 'current' : 'sug'}`}>
                                    <button
                                        type="button"
                                        className={`wp-article-vocabulary-candidate${article.isCurrentLink ? ' is-current-link' : ''}`}
                                        title={t('vocabulary_apply_link_to_phrase', { phrase: title })}
                                        onClick={() => onApplyArticle(phraseKey, phrase, article)}
                                    >
                                        <span className="wp-article-vocabulary-candidate-main">
                                            <span className="wp-article-vocabulary-candidate-title-row">
                                                {article.isCurrentLink ? (
                                                    <Check size={13} className="wp-article-vocabulary-candidate-check" aria-hidden />
                                                ) : null}
                                                <span className="wp-article-vocabulary-candidate-title">{title}</span>
                                            </span>
                                            {path ? (
                                                <span className="wp-article-vocabulary-candidate-path">{path}</span>
                                            ) : null}
                                            {article.isCurrentLink ? (
                                                <span className="wp-article-vocabulary-candidate-current">
                                                    {t('vocabulary_current_link')}
                                                </span>
                                            ) : null}
                                        </span>
                                        {!article.isCurrentLink ? (
                                            <Link2 size={13} className="wp-article-vocabulary-candidate-icon" aria-hidden />
                                        ) : null}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                ) : null}
                {Array.isArray(suggestions) && suggestions.length === 0 && !loadingSuggestions && candidates.length === 0 ? (
                    <span className="wp-article-vocabulary-related-empty">
                        {t('vocabulary_related_empty')}
                    </span>
                ) : null}
            </div>
        </li>
    );
}

function VocabularyGroupSection({
    groupName,
    items,
    collapsed,
    onToggle,
    mode,
    selectedKeys,
    onToggleItem,
    onToggleGroup,
    occurrenceMap,
    activeOccurrenceByPhraseKey,
    onActivateOccurrence,
    articleSuggestionsByPhrase,
    articleSuggestionsLoading,
    onApplyArticle,
}) {
    const groupKeys = items.map((phrase) => selectionKey(groupName, phrase));
    const selectedInGroup = groupKeys.filter((key) => selectedKeys.has(key)).length;
    const allSelected = items.length > 0 && selectedInGroup === items.length;

    return (
        <div className="wp-article-vocabulary-group">
            <button
                type="button"
                className="wp-article-vocabulary-group-toggle"
                aria-expanded={!collapsed}
                onClick={onToggle}
            >
                {collapsed ? <ChevronRight size={15} aria-hidden /> : <ChevronDown size={15} aria-hidden />}
                <span className="wp-article-vocabulary-group-title">{groupName}</span>
                <span className="seo-assistant-widget__badge">{items.length}</span>
            </button>
            {!collapsed ? (
                <div className="wp-article-vocabulary-group-body">
                    {mode === 'planning' ? (
                        <label className="wp-article-vocabulary-select-all">
                            <input
                                type="checkbox"
                                checked={allSelected}
                                onChange={() => onToggleGroup(groupName, items, !allSelected)}
                            />
                            <span>{t('vocabulary_select_all')}</span>
                        </label>
                    ) : null}
                    <ul className={`wp-article-vocabulary-items${mode === 'in_article' ? ' is-in-article' : ''}`}>
                        {items.map((phrase) => {
                            const key = selectionKey(groupName, phrase);

                            if (mode === 'planning') {
                                return (
                                    <li key={key} className="wp-article-vocabulary-item">
                                        <label className="wp-article-vocabulary-item-row">
                                            <input
                                                type="checkbox"
                                                checked={selectedKeys.has(key)}
                                                onChange={() => onToggleItem(key)}
                                            />
                                            <span>{phrase}</span>
                                        </label>
                                    </li>
                                );
                            }

                            const occurrences = occurrenceMap.get(key) ?? [];
                            return (
                                <VocabularyInArticleAnchor
                                    key={key}
                                    phraseKey={key}
                                    phrase={phrase}
                                    occurrences={occurrences}
                                    activeOccurrenceByPhraseKey={activeOccurrenceByPhraseKey}
                                    onActivateOccurrence={onActivateOccurrence}
                                    suggestions={articleSuggestionsByPhrase.get(phrase) ?? null}
                                    loadingSuggestions={articleSuggestionsLoading.has(phrase)}
                                    onApplyArticle={onApplyArticle}
                                />
                            );
                        })}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

export default function ArticleVocabularySidebar({
    articleId: articleIdProp = null,
    siteId: siteIdProp = null,
    active = true,
}) {
    const identity = readCoreArticleIdentity();
    const articleId = Number(articleIdProp ?? identity.articleId ?? 0);
    const siteId = Number(siteIdProp ?? identity.siteId ?? 0);
    const activeRef = useRef(active);
    activeRef.current = active;

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [groups, setGroups] = useState({});
    const [mode, setMode] = useState('in_article');
    const [collapsedGroups, setCollapsedGroups] = useState(() => new Set());
    const [selectedKeys, setSelectedKeys] = useState(() => new Set());
    const [occurrenceMap, setOccurrenceMap] = useState(() => new Map());
    const [activeOccurrenceByPhraseKey, setActiveOccurrenceByPhraseKey] = useState(() => new Map());
    const [blocksTick, setBlocksTick] = useState(0);
    const [articleSuggestionsByPhrase, setArticleSuggestionsByPhrase] = useState(() => new Map());
    const [articleSuggestionsLoading, setArticleSuggestionsLoading] = useState(() => new Set());
    const [projectOptions, setProjectOptions] = useState({});
    const [selectedProjectId, setSelectedProjectId] = useState(null);
    const [assigning, setAssigning] = useState(false);
    const [assignFeedback, setAssignFeedback] = useState(null);
    const suggestionsRequestedRef = useRef(new Set());
    const fetchGenRef = useRef(0);
    const vocabLoadGenRef = useRef(0);

    useEffect(() => {
        if (!active || articleId <= 0) {
            return undefined;
        }

        const controller = new AbortController();
        const loadGen = ++vocabLoadGenRef.current;
        setLoading(true);
        setError(null);

        void (async () => {
            try {
                const payload = await fetchEditorVocabulary(articleId, controller.signal);
                if (
                    controller.signal.aborted
                    || loadGen !== vocabLoadGenRef.current
                    || !activeRef.current
                ) {
                    return;
                }
                setGroups(payload.groups);
                setCollapsedGroups(new Set(Object.keys(payload.groups)));
                setProjectOptions(payload.planning.projectOptions || {});
                const preselect = payload.planning.selectedProjectId;
                setSelectedProjectId(
                    preselect != null && preselect > 0 && payload.planning.projectOptions?.[preselect]
                        ? preselect
                        : (Object.keys(payload.planning.projectOptions || {})[0]
                            ? Number(Object.keys(payload.planning.projectOptions)[0])
                            : null),
                );
                setLoading(false);
            } catch (fetchError) {
                if (
                    fetchError?.name === 'AbortError'
                    || controller.signal.aborted
                    || loadGen !== vocabLoadGenRef.current
                ) {
                    return;
                }
                setError(t('vocabulary_load_error'));
                setLoading(false);
            }
        })();

        return () => {
            controller.abort();
        };
    }, [active, articleId]);

    useEffect(() => {
        if (mode !== 'in_article') {
            return undefined;
        }

        const refresh = () => setBlocksTick((value) => value + 1);
        window.addEventListener('seo-editor-links-updated', refresh);
        window.addEventListener('seo-editor-suggested-link-inserted', refresh);
        window.addEventListener('seo-editor-document-html', refresh);

        return () => {
            window.removeEventListener('seo-editor-links-updated', refresh);
            window.removeEventListener('seo-editor-suggested-link-inserted', refresh);
            window.removeEventListener('seo-editor-document-html', refresh);
        };
    }, [mode]);

    const flatEntries = useMemo(() => {
        const entries = [];
        Object.entries(groups).forEach(([groupName, items]) => {
            (Array.isArray(items) ? items : []).forEach((phrase) => {
                entries.push({ groupName, phrase, key: selectionKey(groupName, phrase) });
            });
        });
        return entries;
    }, [groups]);

    useEffect(() => {
        if (mode !== 'in_article' || flatEntries.length === 0) {
            setOccurrenceMap(new Map());
            return;
        }

        const blocks = collectEditorBlocksFromDom();
        const next = new Map();

        flatEntries.forEach(({ phrase, key }) => {
            const occurrences = findPhraseOccurrencesInBlocks(blocks, phrase, 2);
            if (occurrences.length > 0) {
                next.set(key, occurrences);
            }
        });

        setOccurrenceMap(next);
    }, [mode, flatEntries, blocksTick]);

    const displayGroups = useMemo(() => {
        if (mode === 'planning') {
            return Object.entries(groups)
                .map(([groupName, items]) => [groupName, Array.isArray(items) ? items : []])
                .filter(([, items]) => items.length > 0);
        }

        return Object.entries(groups)
            .map(([groupName, items]) => {
                const filtered = (Array.isArray(items) ? items : []).filter((phrase) => {
                    const key = selectionKey(groupName, phrase);
                    return (occurrenceMap.get(key) ?? []).length > 0;
                });
                return [groupName, filtered];
            })
            .filter(([, items]) => items.length > 0);
    }, [groups, mode, occurrenceMap]);

    const visibleInArticlePhrases = useMemo(() => {
        if (mode !== 'in_article') {
            return [];
        }
        const phrases = [];
        const seen = new Set();
        displayGroups.forEach(([groupName, items]) => {
            if (collapsedGroups.has(groupName)) {
                return;
            }
            items.forEach((phrase) => {
                if (seen.has(phrase)) {
                    return;
                }
                seen.add(phrase);
                phrases.push(phrase);
            });
        });
        return phrases;
    }, [mode, displayGroups, collapsedGroups]);

    useEffect(() => {
        if (mode !== 'in_article' || visibleInArticlePhrases.length === 0) {
            return undefined;
        }

        const missing = visibleInArticlePhrases.filter((phrase) => !suggestionsRequestedRef.current.has(phrase));
        if (missing.length === 0) {
            return undefined;
        }

        missing.forEach((phrase) => suggestionsRequestedRef.current.add(phrase));

        const gen = ++fetchGenRef.current;
        let cancelled = false;

        setArticleSuggestionsLoading((prev) => {
            const next = new Set(prev);
            missing.forEach((phrase) => next.add(phrase));
            return next;
        });

        void (async () => {
            await Promise.all(
                missing.map(async (phrase) => {
                    try {
                        const rows = await searchInternalLinkArticlesCached(phrase, {
                            siteId,
                            articleId,
                            limit: 3,
                        });
                        if (cancelled || gen !== fetchGenRef.current) {
                            return;
                        }
                        setArticleSuggestionsByPhrase((prev) => {
                            const next = new Map(prev);
                            next.set(phrase, rows);
                            return next;
                        });
                    } catch {
                        if (cancelled || gen !== fetchGenRef.current) {
                            return;
                        }
                        suggestionsRequestedRef.current.delete(phrase);
                        setArticleSuggestionsByPhrase((prev) => {
                            const next = new Map(prev);
                            next.set(phrase, []);
                            return next;
                        });
                    } finally {
                        if (!cancelled && gen === fetchGenRef.current) {
                            setArticleSuggestionsLoading((prev) => {
                                const next = new Set(prev);
                                next.delete(phrase);
                                return next;
                            });
                        }
                    }
                }),
            );
        })();

        return () => {
            cancelled = true;
        };
    }, [mode, visibleInArticlePhrases, siteId, articleId]);

    const itemCount = mode === 'planning'
        ? flatEntries.length
        : displayGroups.reduce((sum, [, items]) => sum + items.length, 0);
    const selectedCount = selectedKeys.size;
    const canAssign = selectedCount > 0 && Number(selectedProjectId) > 0 && !assigning;

    const toggleGroupCollapsed = useCallback((groupName) => {
        setCollapsedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(groupName)) {
                next.delete(groupName);
            } else {
                next.add(groupName);
            }
            return next;
        });
    }, []);

    const toggleItem = useCallback((key) => {
        setSelectedKeys((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }, []);

    const toggleGroupSelection = useCallback((groupName, items, selectAll) => {
        setSelectedKeys((prev) => {
            const next = new Set(prev);
            items.forEach((phrase) => {
                const key = selectionKey(groupName, phrase);
                if (selectAll) {
                    next.add(key);
                } else {
                    next.delete(key);
                }
            });
            return next;
        });
    }, []);

    const handleActivateOccurrence = useCallback((phraseKey, occurrence) => {
        setActiveOccurrenceByPhraseKey((prev) => {
            const next = new Map(prev);
            next.set(phraseKey, occurrence);
            return next;
        });
        scrollToPhraseOccurrence(occurrence);
    }, []);

    const handleApplyArticle = useCallback((phraseKey, phrase, article) => {
        const occurrences = occurrenceMap.get(phraseKey) ?? [];
        const { occurrence } = resolveActiveOccurrence(
            phraseKey,
            occurrences,
            activeOccurrenceByPhraseKey,
        );
        if (occurrence) {
            scrollToPhraseOccurrence(occurrence);
        }
        insertVocabularyLink({
            phrase,
            href: article?.url,
            occurrenceIndex: occurrence?.matchIndex ?? 0,
            blockId: occurrence?.blockId,
        });
    }, [activeOccurrenceByPhraseKey, occurrenceMap]);

    const handleAssign = useCallback(async () => {
        const projectId = Number(selectedProjectId);
        if (projectId <= 0 || selectedCount === 0 || assigning) {
            return;
        }

        const selectedEntries = flatEntries.filter(({ key }) => selectedKeys.has(key));
        const items = selectedEntries.map(({ phrase }) => ({
            keyword: phrase,
            title: phrase,
        }));

        setAssigning(true);
        setAssignFeedback(null);

        try {
            const result = await callEditArticleLivewire(
                'assignVocabularyItemsToContentProject',
                projectId,
                items,
            );
            const summary = result?.summary && typeof result.summary === 'object' ? result.summary : {};
            const added = Number(summary.added ?? 0);
            if (added > 0) {
                setSelectedKeys((prev) => {
                    const next = new Set(prev);
                    selectedEntries.forEach(({ key }) => next.delete(key));
                    return next;
                });
                setAssignFeedback(t('vocabulary_assign_success', { count: added }));
            } else {
                setAssignFeedback(String(result?.message || t('vocabulary_assign_failed')));
            }
        } catch {
            setAssignFeedback(t('vocabulary_assign_failed'));
        } finally {
            setAssigning(false);
        }
    }, [assigning, flatEntries, selectedCount, selectedKeys, selectedProjectId]);

    const projectSelectOptions = useMemo(
        () => Object.entries(projectOptions).map(([id, label]) => ({
            value: String(id),
            label: String(label),
        })),
        [projectOptions],
    );

    return (
        <ArticleAssistantWidget
            widgetId="vocabulary"
            title={t('vocabulary_title')}
            icon={BookText}
            badge={itemCount > 0 ? itemCount : null}
            defaultCollapsed={false}
            className="seo-assistant-widget--vocabulary"
        >
            <div className="wp-article-vocabulary">
                {loading ? (
                    <div className="seo-module-loading p-3 text-sm text-gray-500 dark:text-gray-400">
                        <Loader2 size={14} className="animate-spin inline mr-2" aria-hidden />
                        {t('vocabulary_loading')}
                    </div>
                ) : null}
                {error ? (
                    <div className="seo-module-error p-3 text-sm text-rose-600 dark:text-rose-400">
                        <p>{error}</p>
                    </div>
                ) : null}
                {!loading && !error && flatEntries.length === 0 ? (
                    <p className="wp-article-vocabulary-empty">{t('vocabulary_empty')}</p>
                ) : null}
                {!loading && !error && flatEntries.length > 0 ? (
                    <>
                        <div className="wp-article-vocabulary-sticky-top">
                            <div className="wp-article-vocabulary-mode-toggle" role="tablist" aria-label={t('vocabulary_title')}>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === 'in_article'}
                                    className={`wp-article-vocabulary-mode-btn${mode === 'in_article' ? ' is-active' : ''}`}
                                    onClick={() => setMode('in_article')}
                                >
                                    {t('vocabulary_mode_in_article')}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === 'planning'}
                                    className={`wp-article-vocabulary-mode-btn${mode === 'planning' ? ' is-active' : ''}`}
                                    onClick={() => setMode('planning')}
                                >
                                    {t('vocabulary_mode_planning')}
                                </button>
                            </div>
                            {mode === 'planning' ? (
                                <div className="wp-article-vocabulary-project-select">
                                    <label className="wp-article-vocabulary-project-label" htmlFor="vocabulary-plan-project">
                                        {t('vocabulary_content_project_label')}
                                    </label>
                                    <SeoSelect
                                        id="vocabulary-plan-project"
                                        value={selectedProjectId != null ? String(selectedProjectId) : ''}
                                        onChange={(event) => {
                                            const next = Number(event?.target?.value ?? 0);
                                            setSelectedProjectId(Number.isFinite(next) && next > 0 ? next : null);
                                        }}
                                        placeholder={t('vocabulary_content_project_placeholder')}
                                        options={projectSelectOptions}
                                        size="compact"
                                    />
                                    {projectSelectOptions.length === 0 ? (
                                        <p className="wp-article-vocabulary-project-hint">
                                            {t('vocabulary_content_project_empty')}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                        <div className="wp-article-vocabulary-scroll">
                            {mode === 'in_article' && displayGroups.length === 0 ? (
                                <p className="wp-article-vocabulary-empty">{t('vocabulary_in_article_empty')}</p>
                            ) : (
                                <div className="wp-article-vocabulary-groups">
                                    {displayGroups.map(([groupName, items]) => (
                                        <VocabularyGroupSection
                                            key={groupName}
                                            groupName={groupName}
                                            items={items}
                                            collapsed={collapsedGroups.has(groupName)}
                                            onToggle={() => toggleGroupCollapsed(groupName)}
                                            mode={mode}
                                            selectedKeys={selectedKeys}
                                            onToggleItem={toggleItem}
                                            onToggleGroup={toggleGroupSelection}
                                            occurrenceMap={occurrenceMap}
                                            activeOccurrenceByPhraseKey={activeOccurrenceByPhraseKey}
                                            onActivateOccurrence={handleActivateOccurrence}
                                            articleSuggestionsByPhrase={articleSuggestionsByPhrase}
                                            articleSuggestionsLoading={articleSuggestionsLoading}
                                            onApplyArticle={handleApplyArticle}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                        {mode === 'planning' ? (
                            <div className="wp-article-vocabulary-assign-sticky">
                                {assignFeedback ? (
                                    <p className="wp-article-vocabulary-assign-feedback">{assignFeedback}</p>
                                ) : null}
                                <button
                                    type="button"
                                    className="wp-article-vocabulary-assign-btn"
                                    disabled={!canAssign}
                                    onClick={() => {
                                        void handleAssign();
                                    }}
                                >
                                    {assigning ? (
                                        <Loader2 size={14} className="animate-spin inline mr-2" aria-hidden />
                                    ) : null}
                                    {t('vocabulary_assign_to_project', { count: selectedCount })}
                                </button>
                            </div>
                        ) : null}
                    </>
                ) : null}
            </div>
        </ArticleAssistantWidget>
    );
}
