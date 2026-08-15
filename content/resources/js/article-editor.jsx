import React from 'react';
import { createRoot } from 'react-dom/client';
// Composition root: register built-in modules BEFORE SeoArticleEditor/runtime singleton.
import './editor/modules';
import { registerDomainSaveOwners, unregisterDomainSaveOwners } from './editor/domains';
import SeoArticleEditor from './components/SeoArticleEditor';
import WordPressMediaRenameModal from '@wordpress-addon/components/WordPressMediaRenameModal.jsx';
import '../css/article-editor.css';
import '../css/seo-select.css';
import '../../../media/resources/css/image-splitter.css';
import '@media-addon/utils/seoLocalMediaUpload.js';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from '@media-addon/utils/articleMediaPickerCache.js';
import { clearArticleLocalState } from './utils/articleLocalState';
import { loadFaqDraft, saveOutline, clearDraft } from './utils/articleEditorStorage';
import { isUsableEditorDocumentEnvelope } from './utils/articleEditorDocument';
import { registerFilamentHeaderActionsPersistence } from './utils/articleEditorHeaderActions';
import { installArticleEditorStickyHeaderBridge } from './utils/articleEditorStickyHeader';
import { normalizeArticleSlug } from './utils/articleSlugUtils';
import {
    buildArticleEditorApiPayload,
    buildCoordinatedArticleSavePayload,
    closeArticleViaSessionApi,
    closeEditorAfterProjectLocalSave,
    finishArticleEditorApiAction,
    finishArticleSaveFromApi,
    handleArticleSaveConflict,
    patchPermalinkDisplay,
    prepareEditorExitAfterSyncEnqueue,
    syncArticleToWordPressViaApi,
} from './utils/articleEditorApi';
import { loadArticleEditorSeoLazy } from './utils/articleEditorSeoLazy';
import {
    beginExplicitEditorSave,
    endExplicitEditorSave,
    isArticleSaveInFlight,
    saveArticleViaApiSingleFlight,
} from './utils/articleEditorSaveQueue';
import { flushMediaSnapshotMutations } from './utils/articleEditorMediaSnapshot';
import { EditorSessionClient } from './utils/editorSessionClient';
import {
    ARTICLE_EDITOR_SESSION_STATE_EVENT,
    EDITOR_SESSION_STATUS,
    emitArticleEditorSessionState,
    resolveSessionStatusFromClient,
} from './utils/editorSessionState';
import { assertWritableEditorSession } from './utils/editorSessionState';
import { t } from './utils/i18n';
import {
    loadFeaturedImage,
    persistFeaturedImageDraftToServer,
    saveFeaturedImage,
} from '@media-addon/utils/articleFeaturedImageStorage.js';
import {
    appendProductAlbumItems,
    loadProductAlbum,
    mergeProductAlbumBootstrap,
    normalizeProductAlbumList,
    persistProductAlbumDraftToServer,
    removeProductAlbumItem,
    reorderProductAlbum,
    saveProductAlbum,
    syncProductAlbumToServer,
} from '@media-addon/utils/articleProductAlbumStorage.js';
import {
    applyMediaSnapshot,
    discardLegacyMediaLocalStorage,
} from './utils/articleEditorMediaSnapshot';
import {
    setAnalysisPolicy,
    setExternalFacts,
} from '@seo-addon/utils/articleAnalysisOwnership.js';
import { installArticleAutosaveLock } from './utils/articleAutosaveLock';
import { installArticleOperationTracker } from './utils/articleOperationTracker';
import { mountArticleTitlePromptHook } from '@ai-prompt-addon/utils/articleTitlePromptHook.js';
import '@seo-addon/utils/seoAssistantNavigator.js';
import './utils/publishingTaxonomyResolver';
import {
    applyFetchedWpCategories,
    loadWpCategoryIds,
    saveWpCategoryIds,
} from '@wordpress-addon/utils/articleWpCategoriesStorage.js';
installArticleAutosaveLock();
installArticleOperationTracker();
window.__ARTICLE_EDITOR_UI_REVISION__ = 'sticky-help-v1';
window.__ARTICLE_EDITOR_HELP__ = { revision: 'sticky-help-v1' };

function installArticleEditorPageBodyClass() {
    const page = document.querySelector('.seo-article-edit-page, .article-editor-page, [data-article-editor-page]');
    if (!page) {
        return () => {};
    }

    document.body.classList.add('article-editor-page');
    document.documentElement.classList.add('article-editor-page');

    return () => {
        if (!document.querySelector('.seo-article-edit-page, .article-editor-page, [data-article-editor-page]')) {
            document.body.classList.remove('article-editor-page');
            document.documentElement.classList.remove('article-editor-page');
        }
    };
}

queueMicrotask(() => {
    if (window.__SEO_EDITOR_EXITING__) {
        return;
    }

    const activeOp = window.__SEO_ACTIVE_ARTICLE_OPERATION__;
    const articleId = Number(activeOp?.article_id ?? 0);
    if (activeOp && typeof activeOp === 'object' && articleId > 0) {
        // WP sync queued/processing → tracker redirect Sync Queue (không Elapsed).
        window.__seoArticleOperationTracker?.apply?.(articleId, activeOp);

        return;
    }
    if (articleId > 0) {
        window.__seoArticleOperationTracker?.bootstrap?.(articleId);
    }
});

window.addEventListener('seo-editor-slug-updated', (event) => {
    const detail = event?.detail ?? {};
    patchPermalinkDisplay({
        permalink: detail.permalink,
        article_slug: detail.article_slug ?? detail.slug,
        slug: detail.slug,
        permalink_base: detail.permalink_base,
        permalink_suffix: detail.permalink_suffix,
    });
});

window.addEventListener('seo-article-pipeline-rerun-completed', (event) => {
    const articleId = Number(event?.detail?.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        return;
    }

    let siteId = 0;
    try {
        const meta = document.getElementById('seo-article-meta')?.textContent;
        siteId = Number(meta ? JSON.parse(meta)?.site_id : 0) || 0;
    } catch (_error) {
        siteId = 0;
    }

    clearArticleLocalState(articleId, siteId);
    window.setTimeout(() => {
        window.location.reload();
    }, 150);
});

window.normalizeArticleSlug = normalizeArticleSlug;
window.__seoClearArticleLocalState = clearArticleLocalState;
window.__seoWpCategoryStorage = {
    load: loadWpCategoryIds,
    save: saveWpCategoryIds,
    applyFetched: applyFetchedWpCategories,
};
window.__seoFeaturedImageStorage = {
    load: loadFeaturedImage,
    save: saveFeaturedImage,
};
window.__seoPersistFeaturedImageDraft = persistFeaturedImageDraftToServer;
window.dispatchEvent(new CustomEvent('seo-featured-image-storage-ready'));

window.__seoArticleMediaPickerCache = {
    read: readArticleMediaPickerCache,
    write: writeArticleMediaPickerCache,
    isCacheableTab: isArticleMediaPickerCacheableTab,
};

window.__seoProductAlbumStorage = {
    load: loadProductAlbum,
    save: saveProductAlbum,
    append: appendProductAlbumItems,
    remove: removeProductAlbumItem,
    reorder: reorderProductAlbum,
};

window.__seoPersistProductAlbumDraft = persistProductAlbumDraftToServer;

window.__seoExecuteHeavyArticleAction = async function executeHeavyArticleAction(
    action,
    wire,
    { renameImagesBeforeWpSync = false } = {},
) {
    const normalizedAction = action === 'sync'
        ? 'sync'
        : (action === 'save-close' ? 'save-close' : 'save');
    const overlayAction = normalizedAction === 'sync' ? 'sync' : 'save';

    if (window.__SEO_EDITOR_NETWORK_STATUS__?.unavailable) {
        const message = normalizedAction === 'sync'
            ? 'Không thể Sync WP khi đang mất kết nối.'
            : 'Không thể lưu khi đang mất kết nối.';
        const error = new Error(message);
        error.notificationShown = false;
        throw error;
    }

    if (!window.__seoArticleHeavyActionOverlay?.locked) {
        window.__seoBeginArticleHeavyActionClient?.(overlayAction);
    }

    await window.__seoYieldForHeavyActionPaint?.();

    try {
        const collect = window.__seoCollectEditorHeavyBundle;
        if (typeof collect !== 'function') {
            throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
        }

        const editorBundle = await collect({
            validateLocalImageSlugsBeforeWpSync: normalizedAction === 'sync',
            renameImagesBeforeWpSync:
                normalizedAction === 'sync' && renameImagesBeforeWpSync === true,
        });
        const html = String(editorBundle?.html ?? '').trim();
        if (!html) {
            throw new Error('Không thu thập được nội dung bài viết.');
        }
        if (
            typeof window.__seoAssertEditorWhitespaceSafe === 'function'
            && window.__seoAssertEditorWhitespaceSafe(html) === false
        ) {
            throw new Error(
                'Khoảng trắng quanh in đậm/nghiêng/link bị hỏng — Save bị chặn. Reload hoặc khôi phục draft đúng.',
            );
        }

        const articleId = Number(editorBundle?.articleId ?? 0);
        if (!Number.isFinite(articleId) || articleId <= 0) {
            throw new Error('Không xác định được ID bài viết.');
        }

        const siteId = Number(
            document.getElementById('seo-article-meta')?.textContent
                ? JSON.parse(document.getElementById('seo-article-meta').textContent)?.site_id
                : 0,
        );

        if (normalizedAction === 'sync') {
            if (window.__SEO_EDITOR_READ_ONLY__ || window.__SEO_EDITOR_SESSION_STATE__?.writable === false) {
                throw new Error('Phiên chỉnh sửa đang chỉ đọc — không Sync được.');
            }
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                'Đang đưa vào hàng đợi…',
            );
            const apiPayload = await buildCoordinatedArticleSavePayload(editorBundle, wire);
            const result = await syncArticleToWordPressViaApi(articleId, apiPayload);
            finishArticleEditorApiAction(result, articleId, siteId, 'sync');
            return;
        }

        window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
            normalizedAction === 'save-close'
                ? 'Đang lưu rồi đóng…'
                : 'Đang lưu bài viết…',
        );
        try {
            if (window.__SEO_EDITOR_READ_ONLY__) {
                throw new Error('Bài viết đang ở chế độ chỉ đọc — không lưu được.');
            }

            const buildPayload = async () => {
                const freshCollect = window.__seoCollectEditorHeavyBundle;
                let bundle = editorBundle;
                if (typeof freshCollect === 'function') {
                    try {
                        bundle = await freshCollect({ renameImagesBeforeWpSync: false });
                    } catch {
                        bundle = editorBundle;
                    }
                }

                return buildCoordinatedArticleSavePayload(bundle, wire);
            };

            let result;
            if (normalizedAction === 'save-close') {
                beginExplicitEditorSave();
                window.__seoMarkIntentionalEditorClose?.();
                // Wait for any in-flight autosave/explicit save before close write.
                let waitGuard = 0;
                while (isArticleSaveInFlight() && waitGuard < 50) {
                    waitGuard += 1;
                    await new Promise((resolve) => setTimeout(resolve, 100));
                }
                try {
                    const payload = await buildPayload();
                    result = await closeArticleViaSessionApi(articleId, payload, 'save_and_close');
                } finally {
                    endExplicitEditorSave();
                }
                finishArticleSaveFromApi(result, {
                    articleId,
                    siteId,
                    connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
                    savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? result?.html ?? editorBundle.html ?? ''),
                    keepOverlay: true,
                });
                window.__seoResetPublishTabPrimed?.();
                const projectUrl = document
                    .querySelector('[data-seo-content-project-url]')
                    ?.getAttribute('data-seo-content-project-url')
                    || '';
                prepareEditorExitAfterSyncEnqueue(articleId, siteId);
                if (window.__seoArticleHeavyActionOverlay) {
                    window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
                    window.__seoArticleHeavyActionOverlay.locked = false;
                }
                window.__seoArticleHeavyActionOverlay?.hide?.();
                closeEditorAfterProjectLocalSave(projectUrl);
            } else {
                result = await saveArticleViaApiSingleFlight(articleId, buildPayload, { priority: 'explicit' });
                finishArticleSaveFromApi(result, {
                    articleId,
                    siteId,
                    connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
                    savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? editorBundle.html ?? ''),
                    keepOverlay: false,
                });
                window.__seoResetPublishTabPrimed?.();
            }
        } catch (error) {
            if (error?.conflict) {
                handleArticleSaveConflict(error);

                return;
            }

            throw error;
        }
    } catch (error) {
        window.__seoEndArticleHeavyActionClient?.();
        throw error;
    }
};

/**
 * Lưu / đồng bộ qua REST (dùng từ Alpine editor-html-collected).
 *
 * @param {'save'|'sync'} action
 * @param {object|null|undefined} wire
 * @param {{ html?: string, seoAnalysis?: object|null, articleId?: number }} [editorDetail]
 */
async function runArticleEditorApiAction(action, wire, editorDetail = {}) {
    const normalizedAction = action === 'sync' ? 'sync' : 'save';

    if (!window.__seoArticleHeavyActionOverlay?.locked) {
        window.__seoBeginArticleHeavyActionClient?.(normalizedAction);
    }

    await window.__seoYieldForHeavyActionPaint?.();

    const html = String(editorDetail.html ?? '').trim();
    if (!html) {
        window.__seoEndArticleHeavyActionClient?.();
        throw new Error('Không thu thập được nội dung bài viết.');
    }

    const articleId = Number(editorDetail.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        window.__seoEndArticleHeavyActionClient?.();
        throw new Error('Không xác định được ID bài viết.');
    }

    let siteId = 0;
    try {
        const metaEl = document.getElementById('seo-article-meta');
        const meta = metaEl?.textContent?.trim() ? JSON.parse(metaEl.textContent) : {};
        siteId = Number(meta?.site_id ?? 0);
    } catch {
        siteId = 0;
    }

    let editorBundle = {
        articleId,
        html,
        seoAnalysis: editorDetail.seoAnalysis ?? null,
    };

    if (normalizedAction === 'sync' && typeof window.__seoCollectEditorHeavyBundle === 'function') {
        editorBundle = await window.__seoCollectEditorHeavyBundle({
            validateLocalImageSlugsBeforeWpSync: true,
            renameImagesBeforeWpSync: false,
        });
    }

    if (normalizedAction === 'sync') {
        await flushMediaSnapshotMutations(articleId);
    }

    const apiPayload = await buildCoordinatedArticleSavePayload(editorBundle, wire);

    try {
        if (normalizedAction === 'sync') {
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                'Đang đưa bài vào hàng đợi đồng bộ WordPress…',
            );
            const result = await syncArticleToWordPressViaApi(articleId, apiPayload);
            finishArticleEditorApiAction(result, articleId, siteId, 'sync');
            return;
        } else {
            window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu bài viết…');
            try {
                const result = await saveArticleViaApiSingleFlight(articleId, async () => {
                    const freshHtml = String(
                        typeof window.__seoCollectEditorHeavyBundle === 'function'
                            ? (await window.__seoCollectEditorHeavyBundle({ renameImagesBeforeWpSync: false }))?.html
                                ?? apiPayload.html
                            : apiPayload.html,
                    );

                    return buildCoordinatedArticleSavePayload(
                        {
                            articleId,
                            html: freshHtml,
                            seoAnalysis: editorDetail.seoAnalysis ?? null,
                        },
                        wire,
                    );
                }, { priority: 'explicit' });
                finishArticleSaveFromApi(result, {
                    articleId,
                    siteId,
                    connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
                    savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? apiPayload.html ?? ''),
                });
            } catch (error) {
                if (error?.conflict) {
                    handleArticleSaveConflict(error);

                    return;
                }

                throw error;
            }
        }
    } catch (error) {
        window.__seoEndArticleHeavyActionClient?.();
        throw error;
    }
}

window.__seoRunArticleEditorApiAction = runArticleEditorApiAction;

/** @deprecated Phase 6C.3 — Gallery UI is React FeaturedSidebarPanel; Alpine album box is read-only stub. */
window.seoProductAlbumBoxData = function seoProductAlbumBoxData(articleId) {
    const id = Number(articleId ?? 0);

    return {
        articleId: id,
        albumItems: [],
        dragUrl: null,
        init() {
            // Phase 6C.3: no Alpine writable gallery shadow.
        },
        destroy() {},
        syncFromStorage() {
            this.albumItems = [];
        },
        removeItem() {},
        startDrag() {},
        allowDrop(event) {
            event.preventDefault();
        },
        finishDrag() {
            this.dragUrl = null;
        },
        onDrop(event) {
            event.preventDefault();
            this.dragUrl = null;
        },
        albumCountLabel() {
            const count = this.albumItems.length;
            if (count === 0) {
                return 'Chưa có ảnh trong album';
            }

            return `${count} ảnh · Ảnh đầu là đại diện · Kéo thả để đổi vị trí`;
        },
    };
};

window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) {
        return;
    }

    const data = event.data;
    if (!data || data.type !== 'seo-image-splitter-saved') {
        return;
    }

    const galleryItems = Array.isArray(data.product_gallery_items) ? data.product_gallery_items : [];
    const articleId = Number(data.article_id ?? 0);
    const storage = window.__seoProductAlbumStorage;

    if (galleryItems.length === 0 || !storage?.append || !Number.isFinite(articleId) || articleId <= 0) {
        return;
    }

    storage.append(articleId, galleryItems);
    syncProductAlbumToServer(articleId);
});

/** Livewire 3 có thể gửi params dạng object, mảng, hoặc nhiều argument — chuẩn hóa cho listener window. */
function normalizeLivewireEventDetail(payload) {
    if (payload == null) {
        return {};
    }
    if (Array.isArray(payload)) {
        if (payload.length === 1 && payload[0] != null && typeof payload[0] === 'object') {
            return payload[0];
        }

        const merged = {};
        for (const item of payload) {
            if (item != null && typeof item === 'object' && !Array.isArray(item)) {
                Object.assign(merged, item);
            }
        }

        return Object.keys(merged).length > 0 ? merged : { params: payload };
    }

    if (typeof payload === 'object') {
        // Livewire Event wrapper: { detail: {...} } hoặc { params: [...] }
        if (payload.detail != null && typeof payload.detail === 'object' && !Array.isArray(payload.detail)) {
            return payload.detail;
        }
        if (Array.isArray(payload.params)) {
            return normalizeLivewireEventDetail(payload.params);
        }

        return payload;
    }

    return {};
}

function mergeLivewireForwardArgs(args) {
    if (!Array.isArray(args) || args.length === 0) {
        return {};
    }

    if (args.length === 1) {
        return normalizeLivewireEventDetail(args[0]);
    }

    const merged = {};
    for (const arg of args) {
        Object.assign(merged, normalizeLivewireEventDetail(arg));
    }

    return merged;
}

function registerArticleEditorLivewireBridge() {
    if (window.__seoArticleLivewireBridgeRegistered) {
        return;
    }
    window.__seoArticleLivewireBridgeRegistered = true;

    /** Livewire 3 listens to window events with the same name — prevent echo loops. */
    const forwardingLivewireEvents = new Set();

    const forward = (name) => (...args) => {
        if (forwardingLivewireEvents.has(name)) {
            return;
        }

        forwardingLivewireEvents.add(name);
        try {
            window.dispatchEvent(
                new CustomEvent(name, {
                    detail: mergeLivewireForwardArgs(args),
                }),
            );
        } finally {
            queueMicrotask(() => {
                forwardingLivewireEvents.delete(name);
            });
        }
    };

    if (typeof Livewire !== 'undefined') {
        Livewire.on('collect-editor-html', forward('collect-editor-html'));
        Livewire.on('article-faqs-extracted', forward('article-faqs-extracted'));
        // Phase 2B: Livewire no longer owns analyze UI — do not bridge seo-analyze-result.
        Livewire.on('flush-article-faqs', forward('flush-article-faqs'));
        Livewire.on('article-faq-extract-debug', forward('article-faq-extract-debug'));
        Livewire.on('article-faq-extract-debug-cleared', forward('article-faq-extract-debug-cleared'));
        Livewire.on('editor-block-image-selected', forward('editor-block-image-selected'));
        Livewire.on('article-media-selected', forward('article-media-selected'));
        Livewire.on('article-media-removed', forward('article-media-removed'));
        Livewire.on('article-faqs-save-finished', forward('article-faqs-save-finished'));
        Livewire.on('seo-attachment-slugs-rename-finished', forward('seo-attachment-slugs-rename-finished'));
        Livewire.on('seo-product-gallery-updated', (payload) => {
            const name = 'seo-product-gallery-updated';
            if (forwardingLivewireEvents.has(name)) {
                return;
            }

            const detail = normalizeLivewireEventDetail(payload);
            const gallery = normalizeProductAlbumList(detail.gallery);
            // Livewire already persisted — forward UI event only. Do NOT replaceGalleryViaApi
            // (stale expected_snapshot_version → 409 Media snapshot version conflict).
            forwardingLivewireEvents.add(name);
            try {
                window.dispatchEvent(new CustomEvent(name, {
                    detail: {
                        ...detail,
                        gallery,
                        from_server: true,
                    },
                }));
            } finally {
                queueMicrotask(() => {
                    forwardingLivewireEvents.delete(name);
                });
            }
        });
        Livewire.on('article-ai-image-generated', forward('article-ai-image-generated'));
        Livewire.on('article-featured-snippet-generated', forward('article-featured-snippet-generated'));
        Livewire.on('article-ai-video-generated', forward('article-ai-video-generated'));
        Livewire.on('article-ai-media-failed', forward('article-ai-media-failed'));
        Livewire.on('article-post-images-synced', forward('article-post-images-synced'));
        Livewire.on('article-supplemental-images-synced', forward('article-supplemental-images-synced'));
        Livewire.on('virtual-reviews-updated', forward('virtual-reviews-updated'));
        Livewire.on('google-serp-preview-updated', forward('google-serp-preview-updated'));
        Livewire.on('pending-internal-link-ready', forward('pending-internal-link-ready'));
        Livewire.on('article-autosave-lock', forward('article-autosave-lock'));
    }
}

document.addEventListener('livewire:init', registerArticleEditorLivewireBridge);
if (typeof Livewire !== 'undefined') {
    registerArticleEditorLivewireBridge();
}

function getOrCreateReactRoot(element) {
    if (!element.__seoArticleReactRoot) {
        element.__seoArticleReactRoot = createRoot(element);
    }

    return element.__seoArticleReactRoot;
}

function ExclusiveLockScreen({
    lockInfo,
    onRetry,
    onBack = null,
    onReload = null,
    reasonCode = null,
    status = null,
}) {
    if (!lockInfo && !onRetry && !reasonCode && !onReload) {
        return null;
    }

    const code = String(reasonCode ?? '').trim();
    const archived = code === 'content_project_archived' || code === 'article_not_editable';
    const conflict = status === 'conflict' || code.includes('conflict');
    const unavailable = code === 'article_editor_session_unavailable'
        || code === 'unknown_error'
        || code === 'lost'
        || code === 'error'
        || status === 'network_degraded';
    const name = String(lockInfo?.editor_name ?? '').trim() || t('editor_locked_other_user');

    let title = t('editor_locked_title');
    let body = t('editor_locked_body', { name });
    if (archived) {
        title = t('editor_archived_title');
        body = t('editor_archived_body');
    } else if (conflict) {
        title = t('editor_conflict_title');
        body = t('editor_conflict_body');
    } else if (unavailable) {
        title = t('editor_session_unavailable_title');
        body = t('editor_session_unavailable_body');
    }

    const showRetry = typeof onRetry === 'function' && !archived && !conflict;
    const showReload = typeof onReload === 'function' && (unavailable || conflict);
    const showBack = typeof onBack === 'function';

    return (
        <div
            className={`seo-editor-exclusive-lock-screen${archived ? ' is-archived' : ''}${conflict ? ' is-conflict' : ''}${unavailable ? ' is-unavailable' : ''}`}
            role="alert"
            data-seo-editor-exclusive-lock="1"
            data-seo-editor-lock-banner="1"
            data-reason-code={code || undefined}
            data-session-status={status || undefined}
        >
            <div className="seo-editor-exclusive-lock-screen__notice">
                <h2 className="seo-editor-exclusive-lock-screen__title">{title}</h2>
                <p className="seo-editor-exclusive-lock-screen__body">{body}</p>
                {(showRetry || showReload || showBack) ? (
                    <div className="seo-editor-exclusive-lock-screen__actions">
                        {showReload ? (
                            <button
                                type="button"
                                className="seo-editor-exclusive-lock-screen__btn"
                                onClick={onReload}
                            >
                                {t('editor_session_reload')}
                            </button>
                        ) : null}
                        {showRetry ? (
                            <button
                                type="button"
                                className={`seo-editor-exclusive-lock-screen__btn${showReload ? ' seo-editor-exclusive-lock-screen__btn--muted' : ''}`}
                                onClick={onRetry}
                            >
                                {t('editor_locked_retry')}
                            </button>
                        ) : null}
                        {showBack ? (
                            <button
                                type="button"
                                className="seo-editor-exclusive-lock-screen__btn seo-editor-exclusive-lock-screen__btn--muted"
                                onClick={onBack}
                            >
                                {t('editor_locked_back')}
                            </button>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function ArticleEditorWithSession(props) {
    const {
        articleId,
        documentVersion = 1,
        currentUserId = null,
        editorSessionConfig = null,
        // Deprecated exclusive-lock presentation: takeover ignored (backend route retained).
        canTakeoverEditorSession: _canTakeoverIgnored = false,
        ...editorProps
    } = props;

    const [sessionReady, setSessionReady] = React.useState(false);
    const [sessionReadOnly, setSessionReadOnly] = React.useState(true);
    const [lockInfo, setLockInfo] = React.useState(null);
    const [acquireError, setAcquireError] = React.useState(null);
    const [sessionStatus, setSessionStatus] = React.useState(EDITOR_SESSION_STATUS.ACQUIRING);
    const [sessionReasonCode, setSessionReasonCode] = React.useState(null);
    const clientRef = React.useRef(null);
    const editorMountedRef = React.useRef(false);
    const acquireGenerationRef = React.useRef(0);
    const intentionalEditorCloseRef = React.useRef(false);
    const heartbeatSeconds = Math.max(
        10,
        Number(
            editorSessionConfig?.heartbeatSeconds
            ?? editorSessionConfig?.heartbeat_seconds
            ?? 30,
        ) || 30,
    );

    const applyClientState = React.useCallback((client, reasonCode = null) => {
        window.__seoEditorSessionClient = client;
        window.__SEO_EDITOR_SESSION_ID__ = client.sessionId || null;
        window.__SEO_EDITOR_DOCUMENT_VERSION__ = Math.max(1, Number(client.documentVersion) || 1);
        const writable = !Boolean(client.readOnly);
        const status = resolveSessionStatusFromClient(
            reasonCode || client.lockStatus,
            { readOnly: client.readOnly, sessionId: client.sessionId },
        );
        emitArticleEditorSessionState({
            article_id: Number(articleId) || 0,
            session_id: client.sessionId,
            status,
            writable,
            document_version: client.documentVersion,
            reason_code: reasonCode,
            lock: client.lockInfo,
        });
        setSessionReadOnly(Boolean(client.readOnly));
        setLockInfo(client.lockInfo);
        setSessionStatus(status);
        setSessionReasonCode(reasonCode || client.lockStatus || null);
        setSessionReady(true);
        editorMountedRef.current = writable;

        try {
            const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
            const component = livewireId && window.Livewire?.find?.(livewireId);
            component?.set?.('editorSessionId', client.sessionId || null);
            component?.set?.('expectedDocumentVersion', client.documentVersion || null);
        } catch {
            // ignore
        }
    }, [articleId]);

    const runAcquire = React.useCallback(async () => {
        const generation = ++acquireGenerationRef.current;
        const previous = clientRef.current;
        previous?.destroy?.();

        emitArticleEditorSessionState({
            article_id: Number(articleId) || 0,
            session_id: null,
            status: EDITOR_SESSION_STATUS.ACQUIRING,
            writable: false,
            document_version: documentVersion,
            reason_code: null,
            lock: null,
        });

        const client = new EditorSessionClient({
            articleId,
            documentVersion,
            heartbeatSeconds,
            onStateChange: (snap) => {
                if (acquireGenerationRef.current !== generation) {
                    return;
                }
                const status = resolveSessionStatusFromClient(snap.lockStatus, {
                    readOnly: snap.readOnly,
                    sessionId: snap.sessionId,
                });
                emitArticleEditorSessionState({
                    article_id: Number(articleId) || 0,
                    session_id: snap.sessionId,
                    status,
                    writable: !snap.readOnly,
                    document_version: snap.documentVersion,
                    reason_code: snap.lockStatus || null,
                    lock: snap.lockInfo,
                });
                setSessionReadOnly(Boolean(snap.readOnly));
                setLockInfo(snap.lockInfo);
                setSessionStatus(status);
                setSessionReasonCode(snap.lockStatus || null);
                try {
                    const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
                    const component = livewireId && window.Livewire?.find?.(livewireId);
                    component?.set?.('editorSessionId', snap.sessionId || null);
                    component?.set?.('expectedDocumentVersion', snap.documentVersion || null);
                } catch {
                    // ignore
                }
            },
        });
        clientRef.current = client;
        if (!editorMountedRef.current) {
            setSessionReady(false);
        }
        setAcquireError(null);

        const result = await client.acquire(documentVersion);
        if (acquireGenerationRef.current !== generation) {
            client.destroy?.();
            return;
        }
        if (!result.ok) {
            setAcquireError(result.error);
            applyClientState(client, result.error?.code || 'article_editor_locked');
            return;
        }

        setAcquireError(null);
        applyClientState(client, null);
    }, [articleId, applyClientState, documentVersion, heartbeatSeconds]);

    React.useEffect(() => {
        window.__SEO_EDITOR_CURRENT_USER_ID__ = currentUserId != null ? Number(currentUserId) || 0 : 0;
        void runAcquire();

        return () => {
            acquireGenerationRef.current += 1;
            const client = clientRef.current;
            clientRef.current = null;
            client?.destroy?.();
            if (window.__seoEditorSessionClient === client) {
                delete window.__seoEditorSessionClient;
            }
            delete window.__SEO_EDITOR_SESSION_ID__;
            window.__SEO_EDITOR_READ_ONLY__ = false;
        };
    }, [currentUserId, runAcquire]);

    React.useEffect(() => {
        const onBeforeUnload = (event) => {
            if (intentionalEditorCloseRef.current || window.__SEO_EDITOR_EXITING__) {
                return undefined;
            }
            if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {
                return undefined;
            }
            const client = clientRef.current;
            if (!client?.sessionId || client.readOnly) {
                return undefined;
            }
            event.preventDefault();
            event.returnValue = '';
            return '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        window.__seoMarkIntentionalEditorClose = () => {
            intentionalEditorCloseRef.current = true;
        };
        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            delete window.__seoMarkIntentionalEditorClose;
        };
    }, [sessionReadOnly]);

    React.useEffect(() => {
        const onPageHide = () => {
            if (intentionalEditorCloseRef.current || window.__SEO_EDITOR_EXITING__) {
                return;
            }
            const client = clientRef.current;
            if (!client?.sessionId || client.readOnly) {
                return;
            }
            if (window.__SEO_EDITOR_DIRTY__ || window.__seoEditorSaveInFlight) {
                return;
            }
            try {
                const url = `/api/seo/articles/${articleId}/editor-sessions/${encodeURIComponent(client.sessionId)}`;
                if (typeof navigator.sendBeacon === 'function') {
                    const blob = new Blob([JSON.stringify({ _method: 'DELETE' })], { type: 'application/json' });
                    navigator.sendBeacon(url, blob);
                } else {
                    void fetch(url, { method: 'DELETE', keepalive: true, credentials: 'same-origin' });
                }
            } catch {
                // best-effort
            }
        };
        window.addEventListener('pagehide', onPageHide);
        return () => window.removeEventListener('pagehide', onPageHide);
    }, [articleId]);

    const onBack = React.useCallback(() => {
        if (typeof window.history !== 'undefined' && window.history.length > 1) {
            window.history.back();
            return;
        }
        window.location.href = '/seo';
    }, []);

    const onReload = React.useCallback(() => {
        window.location.reload();
    }, []);

    if (!sessionReady) {
        return (
            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600 animate-pulse">
                Đang mở phiên chỉnh sửa…
            </div>
        );
    }

    const reason = String(sessionReasonCode || acquireError?.code || '');
    const sessionFault = (
        reason === 'article_editor_session_unavailable'
        || reason === 'unknown_error'
        || reason === 'lost'
        || reason === 'error'
        || reason === 'article_editor_session_revoked'
        || reason === 'article_editor_session_taken_over'
        || sessionStatus === EDITOR_SESSION_STATUS.NETWORK_DEGRADED
    );
    const blocked = (
        reason === 'article_editor_locked'
        || reason === 'content_project_archived'
        || reason === 'article_not_editable'
        || sessionFault
    ) && Boolean(sessionReadOnly);

    if (blocked) {
        return (
            <div
                className="seo-editor-session-shell seo-editor-session-shell--exclusive-lock"
                data-seo-editor-session-shell="1"
                data-seo-editor-exclusive-lock="1"
                data-session-status={sessionStatus || undefined}
                data-session-writable="0"
            >
                <ExclusiveLockScreen
                    lockInfo={lockInfo || acquireError?.lock}
                    reasonCode={sessionReasonCode || acquireError?.code || null}
                    status={sessionStatus}
                    onRetry={() => { void runAcquire(); }}
                    onReload={onReload}
                    onBack={onBack}
                />
            </div>
        );
    }

    return (
        <div
            className="seo-editor-session-shell"
            data-seo-editor-session-shell="1"
            data-session-status={sessionStatus || undefined}
            data-session-writable={sessionReadOnly ? '0' : '1'}
        >
            <SeoArticleEditor
                {...editorProps}
                articleId={articleId}
                sessionReadOnly={Boolean(sessionReadOnly)}
                documentVersion={documentVersion}
            />
        </div>
    );
}

function readArticleEditorBootstrap() {
    let initialHtml = '';
    let initialEditorDocument = null;
    let initialEditorDocumentHash = null;
    let initialSeo = null;
    let editorSettings = { history_step: 20, autosave_interval_seconds: 2 };
    let initialPostImages = [];
    let initialSupplementalImages = [];
    let articleId = null;
    let siteId = null;
    let articleTitle = '';
    let articlePostType = '';
    let contentRevision = '';
    let connectionHash = '';
    let expectedUpdatedAt = '';
    let expectedContentHash = '';
    let documentVersion = 1;
    let currentUserId = null;
    let editorSessionConfig = null;
    let supportsProductGallery = false;
    let isCanaryProduct = false;
    let parentChildAllowed = false;
    let parentChildReason = '';
    let productCategoryOptions = [];
    let initialProductGallery = [];
    let aiDebug = { enabled: false };
    let initialVirtualReviews = [];
    let mediaPickerUrl = '';
    let initialFaqs = [];
    let initialLoaiSanPham = '';
    let initialGalleryDescription = '';
    let lazyEndpoints = {};
    let aiHistoryPendingApply = null;

    // Phase 2 primary: single core bootstrap.
    try {
        const coreEl = document.getElementById('seo-article-core-bootstrap');
        const rawCore = coreEl?.textContent?.trim();
        if (rawCore) {
            const core = JSON.parse(rawCore);
            articleId = core?.articleId ?? core?.id ?? null;
            siteId = core?.siteId ?? core?.site_id ?? null;
            articleTitle = String(core?.title ?? '');
            articlePostType = String(core?.postType ?? core?.post_type ?? '').trim();
            contentRevision = String(core?.contentRevision ?? core?.content_revision ?? '').trim();
            connectionHash = String(core?.connectionHash ?? core?.seo_connection_hash ?? '').trim();
            expectedUpdatedAt = String(core?.expectedUpdatedAt ?? core?.expected_updated_at ?? '').trim();
            expectedContentHash = String(core?.expectedContentHash ?? core?.expected_content_hash ?? '').trim();
            documentVersion = Math.max(1, Number(core?.documentVersion ?? core?.document_version ?? 1) || 1);
            currentUserId = core?.currentUserId ?? core?.current_user_id ?? null;
            editorSessionConfig = core?.editorSession && typeof core.editorSession === 'object'
                ? core.editorSession
                : null;
            supportsProductGallery = Boolean(core?.supportsProductGallery ?? core?.supports_product_gallery);
            isCanaryProduct = Boolean(core?.isCanaryProduct ?? core?.is_canary_product);
            parentChildAllowed = Boolean(core?.parentChildAllowed ?? core?.parent_child_allowed);
            parentChildReason = String(core?.parentChildReason ?? core?.parent_child_reason ?? '').trim();
            initialHtml = typeof core?.content === 'string' ? core.content : '';
            if (core?.editorDocument && typeof core.editorDocument === 'object') {
                initialEditorDocument = core.editorDocument;
            } else if (core?.editor_document && typeof core.editor_document === 'object') {
                initialEditorDocument = core.editor_document;
            }
            // Hollow JSON (images + empty text) must not suppress HTML hydrate.
            if (!isUsableEditorDocumentEnvelope(initialEditorDocument, initialHtml)) {
                initialEditorDocument = null;
            }
            // Server already repaired glued mark spaces — do not hydrate corrupted JSON.
            if (core?.inlineWhitespaceRepaired === true || core?.inline_whitespace_repaired === true) {
                initialEditorDocument = null;
            }
            initialEditorDocumentHash = core?.editorDocumentHash
                ?? core?.editor_document_hash
                ?? null;
            if (initialEditorDocumentHash) {
                window.__SEO_EDITOR_DOCUMENT_HASH__ = String(initialEditorDocumentHash);
            }
            if (core?.aiHistoryPendingApply && typeof core.aiHistoryPendingApply === 'object') {
                aiHistoryPendingApply = core.aiHistoryPendingApply;
            }
            if (core?.settings && typeof core.settings === 'object') {
                editorSettings = { ...editorSettings, ...core.settings };
            }
            if (core?.endpoints && typeof core.endpoints === 'object') {
                lazyEndpoints = core.endpoints;
            }
            if (typeof core?.featuredImageUrl === 'string' && core.featuredImageUrl.trim() !== '') {
                // Featured URL available; images catalog still lazy.
            }
            if (core?.mediaSnapshot && typeof core.mediaSnapshot === 'object') {
                window.__SEO_EDITOR_MEDIA_SNAPSHOT_BOOTSTRAP__ = core.mediaSnapshot;
            } else if (core?.media_snapshot && typeof core.media_snapshot === 'object') {
                window.__SEO_EDITOR_MEDIA_SNAPSHOT_BOOTSTRAP__ = core.media_snapshot;
            }
            if (core?.analysisPolicy && typeof core.analysisPolicy === 'object') {
                window.__SEO_ANALYSIS_POLICY_BOOTSTRAP__ = core.analysisPolicy;
            } else if (core?.analysis_policy && typeof core.analysis_policy === 'object') {
                window.__SEO_ANALYSIS_POLICY_BOOTSTRAP__ = core.analysis_policy;
            }
            if (core?.externalFacts && typeof core.externalFacts === 'object') {
                window.__SEO_EXTERNAL_FACTS_BOOTSTRAP__ = core.externalFacts;
            } else if (core?.external_facts && typeof core.external_facts === 'object') {
                window.__SEO_EXTERNAL_FACTS_BOOTSTRAP__ = core.external_facts;
            }
            // Light SERP / SEO identity from core — never wait for seo-summary to paint preview.
            if (!initialSeo) {
                const title = String(core?.title ?? '').trim();
                const slug = String(core?.slug ?? '').trim();
                const metaDescription = String(core?.metaDescription ?? core?.meta_description ?? '').trim();
                const permalinkBase = String(core?.permalinkBase ?? core?.permalink_base ?? '').trim();
                const permalinkSuffix = String(core?.permalinkSuffix ?? core?.permalink_suffix ?? '').trim();
                const siteDomain = String(core?.siteDomain ?? core?.site_domain ?? '').trim();
                const path = [slug, permalinkSuffix.replace(/^\//, '')].filter(Boolean).join('/');
                const url = permalinkBase !== ''
                    ? `${permalinkBase.replace(/\/$/, '')}/${path}`
                    : (siteDomain !== '' ? `https://${siteDomain.replace(/^https?:\/\//i, '')}/${path}` : '#');
                let displayHost = siteDomain;
                try {
                    if (permalinkBase) {
                        displayHost = new URL(
                            permalinkBase.includes('://') ? permalinkBase : `https://${permalinkBase}`,
                        ).hostname;
                    }
                } catch {
                    displayHost = siteDomain || permalinkBase;
                }
                initialSeo = {
                    google_serp_preview: {
                        title,
                        description: metaDescription,
                        url,
                        display_url: displayHost
                            ? (path ? `${displayHost} › ${path.replace(/\//g, ' › ')}` : displayHost)
                            : '#',
                    },
                    article_slug: slug,
                    site_domain: siteDomain,
                    permalink_base: permalinkBase,
                    permalink_suffix: permalinkSuffix,
                    focus_keyword: core?.focusKeyword ?? core?.focus_keyword ?? null,
                    meta_description: metaDescription,
                    skip_seo_score: false,
                };
            }
        }
    } catch (e) {
        console.warn('Invalid seo-article-core-bootstrap JSON', e);
    }

    // Legacy fallbacks (older cached HTML) — only fill gaps, do not prefer over core.
    try {
        if (!initialHtml) {
            const htmlEl = document.getElementById('seo-article-initial-html');
            const raw = htmlEl?.textContent?.trim();
            if (raw) {
                initialHtml = JSON.parse(raw);
            }
        }
    } catch (e) {
        console.warn('Invalid article HTML JSON', e);
    }

    try {
        const seoEl = document.getElementById('seo-article-initial-seo');
        const rawSeo = seoEl?.textContent?.trim();
        if (rawSeo) {
            initialSeo = JSON.parse(rawSeo);
        }
    } catch (e) {
        console.warn('Invalid article SEO JSON', e);
    }

    try {
        const settingsEl = document.getElementById('seo-article-editor-settings');
        const rawSettings = settingsEl?.textContent?.trim();
        if (rawSettings) {
            editorSettings = { ...editorSettings, ...JSON.parse(rawSettings) };
        }
    } catch (e) {
        console.warn('Invalid editor settings JSON', e);
    }

    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (rawMeta) {
            const meta = JSON.parse(rawMeta);
            articleId = articleId ?? meta?.id ?? null;
            siteId = siteId ?? meta?.site_id ?? meta?.siteId ?? null;
            if (!articleTitle) articleTitle = meta?.title ?? '';
            if (!articlePostType) articlePostType = String(meta?.post_type ?? '').trim();
            if (!contentRevision) contentRevision = String(meta?.content_revision ?? '').trim();
            if (!connectionHash) connectionHash = String(meta?.seo_connection_hash ?? '').trim();
            if (!expectedUpdatedAt) expectedUpdatedAt = String(meta?.expected_updated_at ?? '').trim();
            if (!expectedContentHash) expectedContentHash = String(meta?.expected_content_hash ?? '').trim();
            supportsProductGallery = supportsProductGallery || Boolean(meta?.supports_product_gallery);
            isCanaryProduct = isCanaryProduct || Boolean(meta?.is_canary_product);
            if (meta?.parent_child_allowed !== undefined || meta?.parentChildAllowed !== undefined) {
                parentChildAllowed = Boolean(meta?.parent_child_allowed ?? meta?.parentChildAllowed);
            }
            if (meta?.parent_child_reason !== undefined || meta?.parentChildReason !== undefined) {
                parentChildReason = String(meta?.parent_child_reason ?? meta?.parentChildReason ?? '').trim();
            }
            productCategoryOptions = Array.isArray(meta?.product_category_options)
                ? meta.product_category_options
                : [];
            initialProductGallery = Array.isArray(meta?.product_gallery) ? meta.product_gallery : [];
            aiDebug = meta?.ai_debug ?? { enabled: false };
            initialSupplementalImages = Array.isArray(meta?.supplemental_images)
                ? meta.supplemental_images
                : [];
            initialVirtualReviews = Array.isArray(meta?.virtual_reviews)
                ? meta.virtual_reviews
                : [];
            mediaPickerUrl = String(meta?.media_picker_url ?? '').trim();
            initialLoaiSanPham = String(meta?.loai_san_pham ?? '').trim();
            initialGalleryDescription = String(meta?.gallery_description ?? '').trim();
        }
    } catch (e) {
        console.warn('Invalid article meta JSON', e);
    }

    window.__SEO_EDITOR_LAZY_ENDPOINTS__ = lazyEndpoints;
    if (connectionHash) {
        window.__SEO_CONNECTION_HASH__ = connectionHash;
    }

    return {
        initialHtml,
        initialEditorDocument,
        initialEditorDocumentHash,
        initialSeo,
        editorSettings,
        initialPostImages,
        initialSupplementalImages,
        articleId,
        siteId,
        articleTitle,
        articlePostType,
        contentRevision,
        connectionHash,
        expectedUpdatedAt,
        expectedContentHash,
        documentVersion,
        currentUserId,
        editorSessionConfig,
        supportsProductGallery,
        isCanaryProduct,
        parentChildAllowed,
        parentChildReason,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
        lazyEndpoints,
        aiHistoryPendingApply,
    };
}

function mountArticleEditorPage() {
    const rootElement = document.getElementById('seo-article-editor-root');
    if (!rootElement) {
        return;
    }

    const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
    // One React root per DOM node. Empty Livewire id still counts as "already mounted"
    // so Filament livewire:navigated on first paint cannot abort in-flight lazy GETs.
    if (rootElement.__seoArticleReactRoot) {
        if (livewireId === '' || rootElement.__seoMountedLivewireId === livewireId) {
            return;
        }
    }

    // Phase 3: cleanup idle timers/fetches from previous navigate before remount.
    const previousCleanups = window.__seoArticleEditorPageCleanups;
    if (Array.isArray(previousCleanups)) {
        while (previousCleanups.length > 0) {
            const fn = previousCleanups.pop();
            try {
                fn?.();
            } catch {
                // ignore cleanup errors
            }
        }
    }
    const pageCleanups = [];
    window.__seoArticleEditorPageCleanups = pageCleanups;
    rootElement.__seoMountedLivewireId = livewireId;

    registerDomainSaveOwners({
        getArticleId: () => Number(window.__SEO_ARTICLE_ID__ || 0),
        getContentBundle: () => {
            if (typeof window.__seoCollectEditorHeavyBundle === 'function') {
                return window.__seoCollectEditorHeavyBundle() || {};
            }
            return {};
        },
    });
    pageCleanups.push(() => unregisterDomainSaveOwners());

    const bootstrap = readArticleEditorBootstrap();
    const {
        initialHtml,
        initialEditorDocument,
        initialEditorDocumentHash,
        initialSeo,
        editorSettings,
        initialPostImages,
        initialSupplementalImages,
        articleId,
        siteId,
        articleTitle,
        articlePostType,
        contentRevision,
        connectionHash,
        expectedUpdatedAt,
        expectedContentHash,
        documentVersion,
        currentUserId,
        editorSessionConfig,
        supportsProductGallery,
        isCanaryProduct,
        parentChildAllowed,
        parentChildReason,
        productCategoryOptions,
        initialProductGallery,
        aiDebug,
        initialVirtualReviews,
        mediaPickerUrl,
        initialFaqs,
        initialLoaiSanPham,
        initialGalleryDescription,
        lazyEndpoints,
        aiHistoryPendingApply,
    } = bootstrap;

    // Manual AI History apply: nạp outline/content vào draft session, không gọi AI.
    if (articleId && aiHistoryPendingApply && typeof aiHistoryPendingApply === 'object') {
        const pendingTarget = String(aiHistoryPendingApply.target ?? '').trim();
        const pendingPayload = String(aiHistoryPendingApply.payload ?? '');
        if (pendingTarget === 'outline' && pendingPayload !== '') {
            saveOutline(articleId, pendingPayload);
        }
        if (pendingTarget === 'content' && pendingPayload !== '') {
            clearDraft(articleId, connectionHash, { siteId: Number(siteId ?? 0) || 0 });
        }
    }

    window.__SEO_EDITOR_CONFLICT__ = {
        expected_updated_at: expectedUpdatedAt || null,
        expected_content_hash: expectedContentHash || null,
    };
    window.__SEO_EDITOR_DOCUMENT_VERSION__ = Math.max(1, Number(documentVersion) || 1);
    window.__SEO_EDITOR_CURRENT_USER_ID__ = currentUserId != null ? Number(currentUserId) || 0 : 0;
    window.__SEO_EDITOR_CONNECTION_HASH__ = connectionHash || '';
    window.__SEO_ARTICLE_SITE_ID__ = Number(siteId ?? 0) || 0;
    window.__SEO_EDITOR_LAZY_ENDPOINTS__ = lazyEndpoints || window.__SEO_EDITOR_LAZY_ENDPOINTS__ || {};

    const perfDebugEnabled = Boolean(window.__SEO_ARTICLE_EDITOR_PERF_DEBUG__ || editorSettings?.perf_debug);
    if (perfDebugEnabled && typeof performance !== 'undefined' && typeof performance.mark === 'function') {
        performance.mark('seo-article-editor-mount-start');
    }

    if (articleId) {
        discardLegacyMediaLocalStorage(articleId);
        const bootSnap = window.__SEO_EDITOR_MEDIA_SNAPSHOT_BOOTSTRAP__;
        if (bootSnap && typeof bootSnap === 'object') {
            applyMediaSnapshot(articleId, bootSnap, { force: true });
        } else if (initialProductGallery.length > 0) {
            // Legacy meta-only bootstrap: hydrate gallery via API replace (no LS).
            saveProductAlbum(articleId, mergeProductAlbumBootstrap(initialProductGallery, articleId));
        }
    }

    const bootPolicy = window.__SEO_ANALYSIS_POLICY_BOOTSTRAP__
        || editorSettings?.analysis_policy
        || null;
    if (bootPolicy && typeof bootPolicy === 'object') {
        setAnalysisPolicy(bootPolicy);
        if (Array.isArray(bootPolicy.seo_scoring_rules) && bootPolicy.seo_scoring_rules.length > 0) {
            window.__SEO_SCORING_RULES__ = bootPolicy.seo_scoring_rules;
        }
    }
    if (window.__SEO_EXTERNAL_FACTS_BOOTSTRAP__ && typeof window.__SEO_EXTERNAL_FACTS_BOOTSTRAP__ === 'object') {
        setExternalFacts(window.__SEO_EXTERNAL_FACTS_BOOTSTRAP__);
    }

    const scoringRules = Array.isArray(editorSettings?.seo_scoring_rules) && editorSettings.seo_scoring_rules.length > 0
        ? editorSettings.seo_scoring_rules
        : (Array.isArray(initialSeo?.seo_scoring_rules) ? initialSeo.seo_scoring_rules : []);
    if (scoringRules.length > 0) {
        window.__SEO_SCORING_RULES__ = scoringRules;
    }
    const scoringMessages = editorSettings?.seo_rule_messages && typeof editorSettings.seo_rule_messages === 'object'
        ? editorSettings.seo_rule_messages
        : (initialSeo?.seo_rule_messages && typeof initialSeo.seo_rule_messages === 'object'
            ? initialSeo.seo_rule_messages
            : {});
    if (Object.keys(scoringMessages).length > 0) {
        window.__SEO_RULE_MESSAGES__ = scoringMessages;
    }

    getOrCreateReactRoot(rootElement).render(
        <>
            <ArticleEditorWithSession
                articleId={articleId}
                siteId={siteId}
                documentVersion={documentVersion}
                currentUserId={currentUserId}
                editorSessionConfig={editorSessionConfig}
                initialHtml={initialHtml}
                initialEditorDocument={initialEditorDocument}
                initialEditorDocumentHash={initialEditorDocumentHash}
                initialSeo={initialSeo}
                initialPostImages={initialPostImages}
                initialSupplementalImages={initialSupplementalImages}
                initialPostType={articlePostType}
                contentRevision={contentRevision}
                connectionHash={connectionHash}
                expectedUpdatedAt={expectedUpdatedAt}
                expectedContentHash={expectedContentHash}
                supportsProductGallery={supportsProductGallery}
                isCanaryProduct={isCanaryProduct}
                parentChildAllowed={parentChildAllowed}
                parentChildReason={parentChildReason}
                productCategoryOptions={productCategoryOptions}
                initialProductGallery={initialProductGallery}
                initialFaqs={[]}
                initialVirtualReviews={[]}
                articleTitle={articleTitle}
                editorSettings={{
                    ...(editorSettings && typeof editorSettings === 'object' ? editorSettings : {}),
                    ai_debug: aiDebug,
                }}
                mediaPickerUrl={mediaPickerUrl}
                initialLoaiSanPham={initialLoaiSanPham}
                initialGalleryDescription={initialGalleryDescription}
                perfDebug={perfDebugEnabled}
            />
            <WordPressMediaRenameModal />
        </>,
    );

    pageCleanups.push(installArticleEditorStickyHeaderBridge());
    pageCleanups.push(installArticleEditorPageBodyClass());

    if (perfDebugEnabled && typeof performance !== 'undefined' && typeof performance.mark === 'function') {
        performance.mark('seo-article-editor-mount-end');
        try {
            performance.measure(
                'seo-article-editor-mount',
                'seo-article-editor-mount-start',
                'seo-article-editor-mount-end',
            );
        } catch {
            // measure API có thể ném lỗi nếu mark thiếu — bỏ qua, không chặn mount.
        }
    }

    // Phase 2/3: light SEO summary + settings idle — abortable; no heavy module fetch.
    if (articleId) {
        const seoSummaryUrl =
            bootstrap.lazyEndpoints?.seoSummary
            || `/api/seo/articles/${articleId}/editor/seo-summary`;
        const settingsUrl =
            bootstrap.lazyEndpoints?.settings
            || `/api/seo/articles/${articleId}/editor/settings`;

        let idleCancelled = false;
        pageCleanups.push(() => {
            idleCancelled = true;
        });

        const schedule = typeof requestIdleCallback === 'function'
            ? (cb) => {
                const id = requestIdleCallback(cb, { timeout: 2500 });
                pageCleanups.push(() => {
                    if (typeof cancelIdleCallback === 'function') {
                        cancelIdleCallback(id);
                    }
                });
            }
            : (cb) => {
                const id = setTimeout(cb, 400);
                pageCleanups.push(() => clearTimeout(id));
            };

        schedule(() => {
            if (idleCancelled) {
                return;
            }
            void (async () => {
                try {
                    const [seoRes, settingsRes] = await loadArticleEditorSeoLazy({
                        articleId,
                        seoSummaryUrl,
                        settingsUrl,
                    });
                    if (idleCancelled) {
                        return;
                    }
                    if (settingsRes.response.ok && settingsRes.data?.success !== false) {
                        const settingsData = settingsRes.data?.data ?? {};
                        if (Array.isArray(settingsData.seo_scoring_rules)) {
                            window.__SEO_SCORING_RULES__ = settingsData.seo_scoring_rules;
                        }
                        if (settingsData.seo_rule_messages && typeof settingsData.seo_rule_messages === 'object') {
                            window.__SEO_RULE_MESSAGES__ = settingsData.seo_rule_messages;
                        }
                        if (settingsData.analysis_policy && typeof settingsData.analysis_policy === 'object') {
                            setAnalysisPolicy(settingsData.analysis_policy);
                        }
                        if (settingsData.external_facts && typeof settingsData.external_facts === 'object') {
                            setExternalFacts(settingsData.external_facts);
                        }
                    }
                    if (seoRes.response.ok && seoRes.data?.success !== false) {
                        window.dispatchEvent(
                            new CustomEvent('seo-editor-seo-summary-loaded', {
                                detail: seoRes.data?.data ?? {},
                            }),
                        );
                    }
                } catch (e) {
                    if (e?.name === 'AbortError') {
                        return;
                    }
                    console.warn('Failed to load SEO summary/settings', e);
                }
            })();
        });
    }
}

mountArticleEditorPage();
mountArticleTitlePromptHook();
registerFilamentHeaderActionsPersistence();

if (!window.__seoArticleEditorNavigatedBound) {
    window.__seoArticleEditorNavigatedBound = true;
    document.addEventListener('livewire:navigated', () => {
        const rootElement = document.getElementById('seo-article-editor-root');
        const livewireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '');
        // Same Filament edit page often fires navigated after first paint — do not remount.
        if (
            rootElement?.__seoArticleReactRoot
            && (livewireId === '' || rootElement.__seoMountedLivewireId === livewireId)
        ) {
            return;
        }
        if (rootElement) {
            rootElement.__seoMountedLivewireId = null;
        }
        if (!document.querySelector('.seo-article-edit-page, [data-article-editor-page]')) {
            document.body.classList.remove('article-editor-page');
            document.documentElement.classList.remove('article-editor-page');
        }
        mountArticleEditorPage();
        mountArticleTitlePromptHook();
    });
}

if (typeof window !== 'undefined') {
    if (!window.__seoArticleEditorMorphBound) {
        window.__seoArticleEditorMorphBound = true;
        const bindMorph = () => {
            if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
                return;
            }
            Livewire.hook('morph.updated', () => {
                mountArticleTitlePromptHook();
            });
        };
        document.addEventListener('livewire:init', bindMorph);
        bindMorph();
    }
}
