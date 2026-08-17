import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Plus, RefreshCw, Link as LinkIcon, Upload, X } from 'lucide-react';
import { EditorModuleErrorBoundary } from '@content-addon/editor/runtime/EditorModuleErrorBoundary.jsx';
import {
    closeMediaPicker,
    confirmMediaPicker,
    patchMediaPickerSelection,
    subscribeMediaPicker,
} from '@content-addon/editor/runtime/editorMediaPickerStore.js';
import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { t } from '@content-addon/utils/i18n.js';
import { canMutateEditor } from '@content-addon/utils/editorSessionState.js';
import { getEditorCommandHost } from '@content-addon/utils/editorCommands/index.js';
import {
    importSeoMediaFromUrl,
    processClipboardImagePaste,
} from '../../utils/seoMediaApi';
import {
    LOCAL_MEDIA_FILE_ACCEPT,
    uploadLocalMediaFiles,
} from '../../utils/seoLocalMediaUpload';
import {
    addCustomPickerTab,
    loadCustomPickerTabs,
    normalizeArticleDomain,
    removeCustomPickerTab,
} from '../../utils/articleMediaPickerCustomTabs';

export const MEDIA_PICKER_CACHE_TTL_MS = 4 * 60 * 1000;
const MEDIA_PICKER_CACHE_MAX_ENTRIES = 30;
const MEDIA_PICKER_SEARCH_DEBOUNCE_MS = 300;

const mediaPickerResultCache = new Map();
const mediaPickerInFlight = new Map();
const mediaPickerUiState = new Map();
const mediaPickerScrollState = new Map();

function imageKey(image) {
    const explicit = String(image?.asset_key || image?.assetKey || '').trim();
    if (explicit !== '') {
        return explicit;
    }
    const source = String(image?.source || '').toLowerCase();
    const wpId = Number(image?.wp_attachment_id || image?.wpAttachmentId || 0) || 0;
    const seoId = Number(image?.seo_media_id || image?.seoMediaId || image?.media_id || image?.mediaId || 0) || 0;
    if ((source === 'wordpress' || source === 'wp') && wpId > 0) {
        return `wp:${wpId}`;
    }
    if (seoId > 0) {
        return `local:${seoId}`;
    }
    if (wpId > 0) {
        return `wp:${wpId}`;
    }
    const id = Number(image?.id || 0);
    const url = String(image?.url || image?.src || '').trim();
    return id > 0 ? `id:${id}` : `url:${url}`;
}

function normalizePickerItem(image) {
    const url = String(image?.url || image?.src || image?.thumb_url || '').trim();
    const wpAttachmentId = Number(
        image?.wp_attachment_id
        ?? image?.wpAttachmentId
        ?? image?.attachment_id
        ?? image?.attachmentId
        ?? 0,
    ) || 0;
    const seoMediaId = Number(
        image?.seo_media_id
        ?? image?.seoMediaId
        ?? image?.media_id
        ?? image?.mediaId
        ?? 0,
    ) || 0;
    // Prefer real media identity; never invent from array index alone.
    const rawId = Number(image?.id ?? 0) || 0;
    const id = wpAttachmentId > 0
        ? wpAttachmentId
        : (seoMediaId > 0 ? seoMediaId : rawId);
    const source = String(image?.source || (wpAttachmentId > 0 ? 'wordpress' : (seoMediaId > 0 ? 'local' : ''))).trim();
    const assetKey = String(image?.asset_key || image?.assetKey || '').trim()
        || ((source === 'wordpress' || source === 'wp') && wpAttachmentId > 0
            ? `wp:${wpAttachmentId}`
            : (seoMediaId > 0 ? `local:${seoMediaId}` : ''));

    return {
        url,
        alt: String(image?.alt || '').trim(),
        slug: String(image?.slug || '').trim(),
        id: id > 0 ? id : 0,
        asset_key: assetKey,
        wp_attachment_id: wpAttachmentId,
        seo_media_id: seoMediaId,
        media_type: String(image?.media_type || 'image').toLowerCase() === 'video' ? 'video' : 'image',
        source,
    };
}

function dedupePickerImages(list) {
    const seen = new Set();
    const out = [];
    for (const image of Array.isArray(list) ? list : []) {
        const key = imageKey(image);
        if (!key || key === 'url:' || seen.has(key)) continue;
        seen.add(key);
        out.push(image);
    }
    return out;
}

function normalizeSearch(search) {
    return String(search || '').trim().toLowerCase();
}

function cacheScope(articleId) {
    const picker = typeof window !== 'undefined' ? window.__SEO_ARTICLE_MEDIA_PICKER__ : null;
    const configuredScope = picker && typeof picker === 'object' ? String(picker.cacheScope || '').trim() : '';
    return configuredScope || `article:${Number(articleId || 0)}`;
}

export function mediaPickerCacheKey({ articleId, source, query = '', page = 1, perPage = 28 }) {
    return [
        `scope:${cacheScope(articleId)}`,
        `article:${Number(articleId || 0)}`,
        `source:${String(source || 'article')}`,
        `q:${normalizeSearch(query)}`,
        `page:${Math.max(1, Number(page) || 1)}`,
        `perPage:${Math.max(1, Number(perPage) || 28)}`,
    ].join('|');
}

function cacheKey(articleId, tab, page, search) {
    return mediaPickerCacheKey({ articleId, source: tab, query: search, page, perPage: 28 });
}

function touchCacheEntry(key, entry) {
    if (mediaPickerResultCache.has(key)) {
        mediaPickerResultCache.delete(key);
    }
    mediaPickerResultCache.set(key, entry);
    while (mediaPickerResultCache.size > MEDIA_PICKER_CACHE_MAX_ENTRIES) {
        const oldestKey = mediaPickerResultCache.keys().next().value;
        mediaPickerResultCache.delete(oldestKey);
    }
}

function readCachedMedia(key) {
    const cached = mediaPickerResultCache.get(key);
    if (!cached) return null;
    mediaPickerResultCache.delete(key);
    mediaPickerResultCache.set(key, cached);
    return cached;
}

function setCachedMedia(key, payload) {
    if (!payload || !Array.isArray(payload.images)) return;
    touchCacheEntry(key, payload);
}

export function invalidateMediaCache(scope = {}) {
    const articleId = Number(scope.articleId || 0);
    const source = scope.source ? String(scope.source) : '';
    const scopePrefix = articleId > 0 ? `scope:${cacheScope(articleId)}|article:${articleId}|` : '';

    for (const key of [...mediaPickerResultCache.keys()]) {
        if (scopePrefix && !key.startsWith(scopePrefix)) continue;
        if (source && !key.includes(`|source:${source}|`)) continue;
        mediaPickerResultCache.delete(key);
    }
}

function emptyTabState() {
    return {
        images: [],
        page: 1,
        totalPages: 1,
        search: '',
        loading: false,
        error: '',
        loadedAt: 0,
        requestId: 0,
    };
}

/**
 * Shared Media Picker — immediate tab switch + in-memory SWR cache.
 */
function pickerItemFromUpload(data) {
    const seoMediaId = Number(data?.id ?? data?.seo_media_id ?? data?.seoMediaId ?? 0) || 0;
    const url = String(data?.url || data?.src || '').trim();

    return {
        url,
        alt: String(data?.alt_text || data?.alt || data?.slug || '').trim(),
        slug: String(data?.slug || '').trim(),
        id: seoMediaId,
        seo_media_id: seoMediaId,
        wp_attachment_id: Number(data?.wp_attachment_id ?? data?.wpAttachmentId ?? 0) || 0,
        source: 'local',
        media_type: 'image',
        asset_key: seoMediaId > 0 ? `local:${seoMediaId}` : '',
    };
}

export function SharedMediaPicker({
    articleId = null,
    siteId = null,
    rootEl = null,
    wordpressAvailable = true,
    articleDomain = '',
}) {
    const [picker, setPicker] = useState(null);
    const [sessionId, setSessionId] = useState(0);
    const [tab, setTab] = useState('article');
    const [tabStates, setTabStates] = useState(() => ({}));
    const [selectedKeys, setSelectedKeys] = useState([]);
    const [selectedItems, setSelectedItems] = useState({});
    const [confirming, setConfirming] = useState(false);
    const [customTabs, setCustomTabs] = useState([]);
    const [ingestBusy, setIngestBusy] = useState(false);
    const [importMode, setImportMode] = useState(false);
    const [importUrl, setImportUrl] = useState('');

    const wasOpenRef = useRef(false);
    const requestSeqRef = useRef(0);
    const customTabsRef = useRef([]);
    const tabRef = useRef(tab);
    const gridRef = useRef(null);
    const searchDebounceRef = useRef(null);
    const fileInputRef = useRef(null);
    const ingestBusyRef = useRef(false);

    const id = Number(articleId ?? getEditorCommandHost()?.articleId ?? 0) || 0;
    const resolvedSiteId = Number(
        siteId
        ?? getEditorCommandHost()?.siteId
        ?? window.__SEO_EDITOR_SITE_ID__
        ?? 0,
    ) || null;
    const domain = normalizeArticleDomain(articleDomain || window.__SEO_ARTICLE_DOMAIN__ || '');
    const uiScopeKey = cacheScope(id);

    useEffect(() => {
        tabRef.current = tab;
    }, [tab]);

    useEffect(() => {
        ingestBusyRef.current = ingestBusy;
    }, [ingestBusy]);

    useEffect(() => {
        customTabsRef.current = customTabs;
    }, [customTabs]);

    const patchTabState = useCallback((tabId, patch) => {
        setTabStates((prev) => {
            const current = prev[tabId] || emptyTabState();
            return { ...prev, [tabId]: { ...current, ...patch } };
        });
    }, []);

    const applyCachedToTab = useCallback((tabId, entry) => {
        if (!entry) return;
        patchTabState(tabId, {
            images: entry.images,
            page: entry.page,
            totalPages: entry.totalPages,
            search: entry.search,
            error: '',
            loadedAt: entry.loadedAt,
            loading: false,
        });
    }, [patchTabState]);

    const persistUiState = useCallback((patch = {}) => {
        mediaPickerUiState.set(uiScopeKey, {
            ...(mediaPickerUiState.get(uiScopeKey) || {}),
            activeTab: tabRef.current,
            tabStates,
            selectedKeys,
            selectedItems,
            ...patch,
        });
    }, [selectedItems, selectedKeys, tabStates, uiScopeKey]);

    const fetchRemote = useCallback(async (apiTab, tabId, nextPage, nextSearch, { skipCache = false } = {}) => {
        if (!id) {
            patchTabState(tabId, {
                loading: false,
                error: 'missing_article_id',
                images: [],
            });
            return;
        }
        const key = cacheKey(id, tabId, nextPage, nextSearch);
        const cached = readCachedMedia(key);
        const fresh = cached && (Date.now() - cached.loadedAt) <= MEDIA_PICKER_CACHE_TTL_MS;

        if (!skipCache && cached) {
            applyCachedToTab(tabId, cached);
            if (fresh) return;
        }

        if (mediaPickerInFlight.has(key)) {
            patchTabState(tabId, { loading: !cached });
            try {
                await mediaPickerInFlight.get(key);
            } catch {
                // ignore — primary request owns error state
            }
            const settled = readCachedMedia(key);
            if (settled) {
                applyCachedToTab(tabId, settled);
            } else {
                patchTabState(tabId, { loading: false });
            }
            return;
        }

        const requestId = ++requestSeqRef.current;
        patchTabState(tabId, {
            loading: !cached,
            error: '',
            requestId,
            page: nextPage,
            search: nextSearch,
        });

        const cacheBust = skipCache ? `&_=${Date.now()}` : '';
        const url = `/seo/articles/${id}/media-picker?tab=${encodeURIComponent(apiTab)}&page=${nextPage}&search=${encodeURIComponent(nextSearch || '')}${cacheBust}`;

        const promise = (async () => {
            const { response, data } = await seoArticleApiFetch(url, {
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                },
            });
            if (!response.ok) {
                throw new Error(String(data?.error || data?.wordpress_media_unavailable_reason || `HTTP ${response.status}`));
            }
            return {
                images: dedupePickerImages(data?.images),
                page: Math.max(1, Number(data?.page) || nextPage),
                totalPages: Math.max(1, Number(data?.totalPages) || 1),
                search: nextSearch,
                loadedAt: Date.now(),
            };
        })();

        mediaPickerInFlight.set(key, promise);
        try {
            const entry = await promise;
            setCachedMedia(key, entry);
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.requestId > requestId) {
                    return prev;
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        images: entry.images,
                        page: entry.page,
                        totalPages: entry.totalPages,
                        search: entry.search,
                        loadedAt: entry.loadedAt,
                        loading: false,
                        error: '',
                        requestId,
                    },
                };
            });
        } catch (err) {
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.images.length > 0) {
                    return {
                        ...prev,
                        [tabId]: { ...current, loading: false },
                    };
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        loading: false,
                        error: String(err?.message || 'picker_load_failed'),
                        images: current.images,
                    },
                };
            });
        } finally {
            mediaPickerInFlight.delete(key);
        }
    }, [applyCachedToTab, id, patchTabState]);

    const fetchArticle = useCallback((tabId, { skipCache = false } = {}) => {
        const key = cacheKey(id, 'article', 1, '');
        const cached = readCachedMedia(key);
        const fresh = cached && (Date.now() - cached.loadedAt) <= MEDIA_PICKER_CACHE_TTL_MS;
        if (!skipCache && cached) {
            applyCachedToTab(tabId, cached);
            if (fresh) return;
        }

        if (mediaPickerInFlight.has(key)) {
            patchTabState(tabId, { loading: !cached });
            return;
        }

        const requestId = ++requestSeqRef.current;
        patchTabState(tabId, { loading: !cached, error: '', requestId, page: 1, search: '' });

        const onCatalog = (event) => {
            const list = dedupePickerImages(event?.detail?.images);
            const entry = {
                images: list,
                page: 1,
                totalPages: 1,
                search: '',
                loadedAt: Date.now(),
            };
            setCachedMedia(key, entry);
            setTabStates((prev) => {
                const current = prev[tabId] || emptyTabState();
                if (current.requestId !== requestId && current.requestId > requestId) {
                    return prev;
                }
                return {
                    ...prev,
                    [tabId]: {
                        ...current,
                        ...entry,
                        loading: false,
                        error: '',
                        requestId,
                    },
                };
            });
        };

        let settled = false;
        const promise = new Promise((resolve) => {
            const finish = () => {
                if (settled) return;
                settled = true;
                resolve();
            };
            const onEvent = (event) => {
                onCatalog(event);
                finish();
            };
            window.addEventListener('seo-editor-images-catalog', onEvent, { once: true });
            window.dispatchEvent(new CustomEvent('seo-request-editor-images-catalog'));
            window.setTimeout(() => {
                window.removeEventListener('seo-editor-images-catalog', onEvent);
                mediaPickerInFlight.delete(key);
                patchTabState(tabId, { loading: false });
                finish();
            }, 2500);
        });
        mediaPickerInFlight.set(key, promise);
    }, [applyCachedToTab, id, patchTabState]);

    const loadTab = useCallback((nextTab, nextPage, nextSearch, options = {}) => {
        if (nextTab === 'article') {
            fetchArticle(nextTab, options);
            return;
        }
        if (String(nextTab).startsWith('custom:')) {
            const customId = String(nextTab).slice('custom:'.length);
            const row = customTabsRef.current.find((item) => item.id === customId);
            const keyword = String(row?.keyword || nextSearch || '').trim();
            void fetchRemote('original', nextTab, nextPage, keyword || nextSearch, options);
            return;
        }
        void fetchRemote(nextTab, nextTab, nextPage, nextSearch, options);
    }, [fetchArticle, fetchRemote]);

    const switchTab = useCallback((nextTab) => {
        if (!nextTab || nextTab === tabRef.current) return;
        setTab(nextTab);
        const state = emptyTabState();
        const custom = String(nextTab).startsWith('custom:')
            ? customTabsRef.current.find((row) => `custom:${row.id}` === nextTab)
            : null;
        const search = custom ? String(custom.keyword || '') : (tabStates[nextTab]?.search || '');
        // Tab change always resets to page 1 (same contract as search).
        const page = 1;
        const key = cacheKey(id, nextTab, page, search);
        const cached = readCachedMedia(key);
        if (cached) {
            applyCachedToTab(nextTab, cached);
        } else {
            setTabStates((prev) => ({
                ...prev,
                [nextTab]: {
                    ...(prev[nextTab] || state),
                    search,
                    page,
                    loading: true,
                },
            }));
        }
        loadTab(nextTab, page, search, { skipCache: false });
    }, [applyCachedToTab, id, loadTab, tabStates]);

    useEffect(() => subscribeMediaPicker((next) => {
        const nowOpen = Boolean(next?.open);
        const becameOpen = nowOpen && !wasOpenRef.current;
        wasOpenRef.current = nowOpen;
        setPicker(next);

        if (!becameOpen) {
            if (!nowOpen) {
                setImportMode(false);
                setImportUrl('');
                setIngestBusy(false);
            }
            return;
        }

        setSessionId((value) => value + 1);
        const saved = mediaPickerUiState.get(uiScopeKey) || {};
        const savedTab = saved.activeTab || 'article';
        setSelectedKeys(Array.isArray(saved.selectedKeys) ? saved.selectedKeys : []);
        setSelectedItems(saved.selectedItems && typeof saved.selectedItems === 'object' ? saved.selectedItems : {});
        setTab(savedTab);
        tabRef.current = savedTab;
        setTabStates(saved.tabStates && typeof saved.tabStates === 'object' ? saved.tabStates : {});
        const tabs = loadCustomPickerTabs(
            normalizeArticleDomain(articleDomain || window.__SEO_ARTICLE_DOMAIN__ || ''),
            Number(articleId ?? 0) || 0,
        );
        setCustomTabs(tabs);
        customTabsRef.current = tabs;
    }), [articleDomain, articleId, uiScopeKey]);

    // Load/prefetch only on new picker session (not selection patches).
    useEffect(() => {
        if (!picker?.open || !sessionId) return undefined;
        const saved = mediaPickerUiState.get(uiScopeKey) || {};
        const initialTab = saved.activeTab || 'article';
        const initialState = saved.tabStates?.[initialTab] || {};
        loadTab(initialTab, initialState.page || 1, initialState.search || '', { skipCache: false });
        const timer = window.setTimeout(() => {
            if (wordpressAvailable) {
                void fetchRemote('original', 'original', 1, '', { skipCache: false });
            }
            void fetchRemote('local', 'local', 1, '', { skipCache: false });
        }, 120);
        return () => window.clearTimeout(timer);
    }, [sessionId, picker?.open, loadTab, fetchRemote, wordpressAvailable, uiScopeKey]);

    useEffect(() => {
        persistUiState();
    }, [persistUiState]);

    useEffect(() => {
        if (!picker?.open) return;
        patchMediaPickerSelection(selectedKeys, selectedItems);
    }, [picker?.open, selectedItems, selectedKeys]);

    useEffect(() => {
        const onInvalidate = () => {
            invalidateMediaCache({ articleId: id });
        };
        window.addEventListener('seo-article-media-picker-cache-invalidated', onInvalidate);
        return () => window.removeEventListener('seo-article-media-picker-cache-invalidated', onInvalidate);
    }, [id]);

    const active = tabStates[tab] || emptyTabState();
    const multi = picker?.selection === 'multiple';
    const readOnly = !canMutateEditor() || Boolean(getEditorCommandHost()?.isArchived?.());
    const isCustomTab = String(tab || '').startsWith('custom:');
    const activeCustom = isCustomTab
        ? customTabs.find((row) => `custom:${row.id}` === tab)
        : null;

    const setActiveSearch = (value) => {
        patchTabState(tab, { search: value });
    };

    const runSearch = () => {
        const search = String(active.search || '');
        loadTab(tab, 1, search, { skipCache: true });
    };

    const refreshActiveTab = () => {
        loadTab(tab, active.page || 1, active.search || '', { skipCache: true });
    };

    useEffect(() => {
        if (!picker?.open || tab === 'article') return undefined;
        window.clearTimeout(searchDebounceRef.current);
        searchDebounceRef.current = window.setTimeout(() => {
            loadTab(tab, 1, active.search || '', { skipCache: false });
        }, MEDIA_PICKER_SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(searchDebounceRef.current);
    }, [active.search, loadTab, picker?.open, tab]);

    const scrollKey = cacheKey(id, tab, active.page || 1, active.search || '');

    useEffect(() => {
        if (!picker?.open || !gridRef.current) return;
        const savedScroll = Number(mediaPickerScrollState.get(scrollKey) || 0);
        requestAnimationFrame(() => {
            if (gridRef.current) {
                gridRef.current.scrollTop = savedScroll;
            }
        });
    }, [active.images, picker?.open, scrollKey]);

    const toggleSelect = (image) => {
        if (readOnly) return;
        const key = imageKey(image);
        const item = normalizePickerItem(image);
        if (!item.url) return;

        if (!multi) {
            setSelectedKeys([key]);
            setSelectedItems({ [key]: item });
            patchMediaPickerSelection([key], { [key]: item });
            return;
        }

        setSelectedKeys((prev) => {
            const exists = prev.includes(key);
            const nextKeys = exists ? prev.filter((k) => k !== key) : [...prev, key];
            setSelectedItems((prevItems) => {
                const nextItems = { ...prevItems };
                if (exists) delete nextItems[key];
                else nextItems[key] = item;
                patchMediaPickerSelection(nextKeys, nextItems);
                return nextItems;
            });
            return nextKeys;
        });
    };

    const ingestUploadedMedia = useCallback((data) => {
        const image = pickerItemFromUpload(data);
        if (!image.url) {
            throw new Error(t('media_picker_upload_failed_body'));
        }

        invalidateMediaCache({ articleId: id, source: 'local' });
        setTab('local');
        tabRef.current = 'local';
        setImportMode(false);
        setImportUrl('');
        setTabStates((prev) => {
            const current = prev.local || emptyTabState();
            return {
                ...prev,
                local: {
                    ...current,
                    images: dedupePickerImages([image, ...(current.images || [])]),
                    loading: false,
                    error: '',
                },
            };
        });
        toggleSelect(image);
    }, [id, multi, readOnly]);

    const notifyPickerError = useCallback((title, error) => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title,
                    body: error?.message || t('media_picker_upload_failed_body'),
                    status: 'danger',
                },
            }),
        );
    }, []);

    const handleUploadFiles = useCallback(async (fileList) => {
        if (readOnly || ingestBusyRef.current) return;
        const files = Array.from(fileList ?? []);
        if (files.length === 0) return;

        setIngestBusy(true);
        try {
            const uploaded = await uploadLocalMediaFiles(files, {
                articleId: id || null,
                siteId: resolvedSiteId,
                source: 'library',
            });
            const last = uploaded[uploaded.length - 1];
            if (last) {
                ingestUploadedMedia(last);
            }
        } catch (error) {
            notifyPickerError(t('media_picker_upload_failed'), error);
        } finally {
            setIngestBusy(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    }, [id, ingestUploadedMedia, notifyPickerError, readOnly, resolvedSiteId]);

    const handleImportUrl = useCallback(async () => {
        if (readOnly || ingestBusyRef.current) return;
        const url = String(importUrl || '').trim();
        if (!url) return;

        setIngestBusy(true);
        try {
            const data = await importSeoMediaFromUrl(url, {
                articleId: id || null,
                siteId: resolvedSiteId,
                randomFilename: true,
            });
            ingestUploadedMedia(data);
        } catch (error) {
            notifyPickerError(t('image_import_failed'), error);
        } finally {
            setIngestBusy(false);
        }
    }, [id, importUrl, ingestUploadedMedia, notifyPickerError, readOnly, resolvedSiteId]);

    useEffect(() => {
        if (!picker?.open || readOnly) {
            return undefined;
        }

        const onPaste = (event) => {
            if (ingestBusyRef.current) {
                if (event.clipboardData?.items) {
                    for (const item of event.clipboardData.items) {
                        if (item.type.indexOf('image') === 0) {
                            event.preventDefault();
                            break;
                        }
                    }
                }
                return;
            }

            const handled = processClipboardImagePaste(event, {
                articleId: id || null,
                siteId: resolvedSiteId,
                source: 'clipboard',
                preferTextPasteInInputs: true,
                notifyOnSuccess: false,
                onUploaded: (data) => {
                    setIngestBusy(false);
                    try {
                        ingestUploadedMedia(data);
                    } catch (error) {
                        notifyPickerError(t('media_picker_upload_failed'), error);
                    }
                },
                onError: () => setIngestBusy(false),
            });

            if (handled) {
                setIngestBusy(true);
            }
        };

        window.addEventListener('paste', onPaste);
        return () => window.removeEventListener('paste', onPaste);
    }, [id, ingestUploadedMedia, notifyPickerError, picker?.open, readOnly, resolvedSiteId]);

    const onConfirm = async () => {
        if (readOnly || selectedKeys.length === 0 || confirming) return;
        setConfirming(true);
        try {
            patchMediaPickerSelection(selectedKeys, selectedItems);
            await confirmMediaPicker();
        } finally {
            setConfirming(false);
        }
    };

    const onAddCustomTab = () => {
        const keyword = window.prompt(t('media_picker_custom_tab_prompt'));
        if (keyword == null) return;
        const created = addCustomPickerTab(domain, keyword, { articleId: id });
        if (!created) return;
        const nextTabs = loadCustomPickerTabs(domain, id);
        setCustomTabs(nextTabs);
        customTabsRef.current = nextTabs;
        switchTab(`custom:${created.id}`);
    };

    const onRemoveCustomTab = (customId, event) => {
        event.stopPropagation();
        removeCustomPickerTab(domain, customId, id);
        const nextTabs = loadCustomPickerTabs(domain, id);
        setCustomTabs(nextTabs);
        customTabsRef.current = nextTabs;
        if (tab === `custom:${customId}`) {
            switchTab('original');
        }
    };

    const title = useMemo(() => {
        if (picker?.mode === 'featured') return t('media_picker_featured_title');
        if (picker?.mode === 'gallery') return t('media_picker_gallery_title');
        return t('media_picker_content_title');
    }, [picker?.mode]);

    if (!picker?.open || !rootEl) {
        return null;
    }

    const body = (
        <EditorModuleErrorBoundary moduleId="article-editor.media-picker" slotName="media.picker">
            <div className="seo-shared-media-picker-overlay" role="dialog" aria-modal="true" aria-label={title}>
                <div className="seo-shared-media-picker" data-active-tab={tab} data-picker-session={sessionId}>
                    <header className="seo-shared-media-picker__header">
                        <h3 className="seo-shared-media-picker__title">{title}</h3>
                        <button type="button" className="seo-shared-media-picker__close" onClick={() => closeMediaPicker()} aria-label="Close">
                            <X size={18} />
                        </button>
                    </header>
                    <div className="seo-shared-media-picker__tabs">
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'article' ? ' is-active' : ''}`}
                            onClick={() => switchTab('article')}
                            data-media-picker-tab="article"
                        >
                            {t('media_picker_tab_article')}
                        </button>
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'original' ? ' is-active' : ''}`}
                            onClick={() => switchTab('original')}
                            data-media-picker-tab="original"
                            title={wordpressAvailable ? t('media_picker_tab_wp') : (t('wp_media_unavailable') || 'WP library may be unavailable')}
                        >
                            {t('media_picker_tab_wp')}
                        </button>
                        {customTabs.map((row) => {
                            const tabId = `custom:${row.id}`;
                            return (
                                <button
                                    key={tabId}
                                    type="button"
                                    className={`seo-shared-media-picker__tab seo-shared-media-picker__tab--custom${tab === tabId ? ' is-active' : ''}`}
                                    onClick={() => switchTab(tabId)}
                                    data-media-picker-tab={tabId}
                                    title={row.keyword || row.label}
                                >
                                    <span>{row.label || row.keyword || tabId}</span>
                                    <span
                                        className="seo-shared-media-picker__tab-remove"
                                        role="button"
                                        tabIndex={0}
                                        onClick={(event) => onRemoveCustomTab(row.id, event)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                onRemoveCustomTab(row.id, event);
                                            }
                                        }}
                                        aria-label="Remove tab"
                                    >
                                        ×
                                    </span>
                                </button>
                            );
                        })}
                        <button
                            type="button"
                            className={`seo-shared-media-picker__tab${tab === 'local' ? ' is-active' : ''}`}
                            onClick={() => switchTab('local')}
                            data-media-picker-tab="local"
                        >
                            {t('media_picker_tab_local')}
                        </button>
                        {domain ? (
                            <button
                                type="button"
                                className="seo-shared-media-picker__tab seo-shared-media-picker__tab--add"
                                onClick={onAddCustomTab}
                                title={t('media_picker_add_custom_tab')}
                                data-media-picker-tab="add-custom"
                            >
                                <Plus size={14} aria-hidden />
                            </button>
                        ) : null}
                    </div>
                    <div className="seo-shared-media-picker__toolbar">
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept={LOCAL_MEDIA_FILE_ACCEPT}
                            className="seo-shared-media-picker__file"
                            disabled={readOnly || ingestBusy}
                            onChange={(event) => {
                                void handleUploadFiles(event.target.files);
                            }}
                        />
                        <button
                            type="button"
                            className="seo-shared-media-picker__btn"
                            disabled={readOnly || ingestBusy}
                            onClick={() => fileInputRef.current?.click()}
                            title={t('media_picker_upload')}
                            data-media-picker-upload="1"
                        >
                            <Upload size={14} aria-hidden />
                            <span>{ingestBusy ? t('media_picker_uploading') : t('media_picker_upload')}</span>
                        </button>
                        <button
                            type="button"
                            className={`seo-shared-media-picker__btn${importMode ? ' is-open' : ''}`}
                            disabled={readOnly || ingestBusy}
                            onClick={() => setImportMode((open) => !open)}
                            title={t('media_picker_from_url')}
                            data-media-picker-import-url="1"
                        >
                            <LinkIcon size={14} aria-hidden />
                            <span>{t('media_picker_from_url')}</span>
                        </button>
                        <input
                            type="search"
                            className="seo-shared-media-picker__search"
                            placeholder={t('media_picker_search')}
                            value={active.search || ''}
                            disabled={tab === 'article'}
                            onChange={(event) => setActiveSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    runSearch();
                                }
                            }}
                        />
                        <button
                            type="button"
                            className="seo-shared-media-picker__btn"
                            disabled={tab === 'article'}
                            onClick={runSearch}
                        >
                            {t('media_picker_search_btn')}
                        </button>
                        <button
                            type="button"
                            className="seo-shared-media-picker__btn seo-shared-media-picker__refresh"
                            onClick={refreshActiveTab}
                            title={t('media_picker_refresh')}
                            aria-label={t('media_picker_refresh')}
                            data-media-picker-refresh="1"
                            disabled={active.loading}
                        >
                            <RefreshCw size={14} aria-hidden />
                        </button>
                    </div>
                    {importMode ? (
                        <div className="seo-shared-media-picker__url-row">
                            <input
                                type="url"
                                className="seo-shared-media-picker__search"
                                placeholder={t('media_picker_url_placeholder')}
                                value={importUrl}
                                disabled={ingestBusy}
                                onChange={(event) => setImportUrl(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        void handleImportUrl();
                                    }
                                }}
                            />
                            <button
                                type="button"
                                className="seo-shared-media-picker__btn is-primary"
                                disabled={readOnly || ingestBusy || !String(importUrl || '').trim()}
                                onClick={() => void handleImportUrl()}
                            >
                                {ingestBusy ? t('media_picker_uploading') : t('media_picker_import')}
                            </button>
                        </div>
                    ) : null}
                    {ingestBusy ? (
                        <p className="seo-shared-media-picker__hint" aria-live="polite">
                            {t('media_picker_uploading')}
                        </p>
                    ) : null}
                    {activeCustom?.keyword ? (
                        <p className="seo-shared-media-picker__hint">{activeCustom.keyword}</p>
                    ) : null}
                    {active.error ? <p className="seo-shared-media-picker__error">{active.error}</p> : null}
                    {readOnly ? <p className="seo-shared-media-picker__hint">{t('media_picker_readonly_hint')}</p> : null}
                    <div
                        className="seo-shared-media-picker__grid"
                        ref={gridRef}
                        onScroll={(event) => {
                            mediaPickerScrollState.set(scrollKey, event.currentTarget.scrollTop);
                        }}
                    >
                        {active.loading && active.images.length === 0 ? (
                            <p className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</p>
                        ) : active.images.length === 0 ? (
                            <p className="seo-shared-media-picker__empty">{t('media_picker_empty')}</p>
                        ) : (
                            <>
                                {active.loading ? (
                                    <p className="seo-shared-media-picker__hint seo-shared-media-picker__hint--overlay">
                                        {t('editor_module_loading')}
                                    </p>
                                ) : null}
                                {active.images.map((image) => {
                                    const key = imageKey(image);
                                    const selected = selectedKeys.includes(key);
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            className={`seo-shared-media-picker__item${selected ? ' is-selected' : ''}`}
                                            onClick={() => toggleSelect(image)}
                                        >
                                            <img src={String(image.url || '')} alt={String(image.alt || '')} loading="lazy" />
                                        </button>
                                    );
                                })}
                            </>
                        )}
                    </div>
                    <footer className="seo-shared-media-picker__footer">
                        <div className="seo-shared-media-picker__pager">
                            <button
                                type="button"
                                disabled={tab === 'article' || active.page <= 1 || active.loading}
                                onClick={() => loadTab(tab, active.page - 1, active.search || '')}
                            >
                                {t('media_picker_prev')}
                            </button>
                            <span>{active.page} / {active.totalPages}</span>
                            <button
                                type="button"
                                disabled={tab === 'article' || active.page >= active.totalPages || active.loading}
                                onClick={() => loadTab(tab, active.page + 1, active.search || '')}
                            >
                                {t('media_picker_next')}
                            </button>
                        </div>
                        <div className="seo-shared-media-picker__actions">
                            <button type="button" onClick={() => closeMediaPicker()}>{t('media_picker_cancel')}</button>
                            <button
                                type="button"
                                className="is-primary"
                                disabled={readOnly || selectedKeys.length === 0 || confirming}
                                onClick={() => void onConfirm()}
                            >
                                {confirming ? '…' : t('media_picker_confirm', { count: selectedKeys.length })}
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </EditorModuleErrorBoundary>
    );

    return createPortal(body, rootEl);
}
