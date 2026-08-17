import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { shouldClearLocalRecoveryAfterSave } from './articleEditorSaveGuard.js';
import {
    clearDraft,
    hashContent,
    setDraftPersistenceEnabled,
    writeSyncedLocalSnapshot,
} from './articleEditorStorage.js';
import { getMediaSnapshot } from './articleEditorMediaSnapshot.js';

/**
 * Token conflict hiện tại (expected_updated_at / expected_content_hash) — bootstrap từ
 * meta server (article-editor.jsx), cập nhật lại sau mỗi lần save thành công.
 * @returns {{ expected_updated_at: string|null, expected_content_hash: string|null }}
 */
export function getEditorConflictTokens() {
    const tokens = window.__SEO_EDITOR_CONFLICT__;

    return tokens && typeof tokens === 'object'
        ? tokens
        : { expected_updated_at: null, expected_content_hash: null };
}

/**
 * @param {{ expected_updated_at?: string|null, expected_content_hash?: string|null }} tokens
 */
export function setEditorConflictTokens(tokens) {
    window.__SEO_EDITOR_CONFLICT__ = {
        expected_updated_at: tokens?.expected_updated_at ?? null,
        expected_content_hash: tokens?.expected_content_hash ?? null,
    };
}

/**
 * Dev-only version/hash timeline (no body/token). Enable:
 * window.__SEO_EDITOR_VERSION_DEBUG__ = true
 *
 * @param {string} event
 * @param {Record<string, unknown>} [fields]
 */
export function logArticleEditorVersionDebug(event, fields = {}) {
    if (typeof window === 'undefined') {
        return;
    }
    if (!window.__SEO_EDITOR_VERSION_DEBUG__ && !window.__SEO_APP_DEBUG__) {
        return;
    }
    const sessionId = String(window.__seoEditorSessionClient?.sessionId ?? '').slice(0, 8);
    // eslint-disable-next-line no-console
    console.debug('[article-editor-version]', event, {
        session: sessionId || null,
        document_version: window.__SEO_EDITOR_DOCUMENT_VERSION__
            ?? window.__seoEditorSessionClient?.documentVersion
            ?? null,
        editor_document_hash: String(window.__SEO_EDITOR_DOCUMENT_HASH__ || '').slice(0, 12) || null,
        content_hash: String(getEditorConflictTokens().expected_content_hash || '').slice(0, 12) || null,
        ...fields,
    });
}

/**
 * Atomically ACK document version + hashes after session save/noop.
 * Must run before single-flight waiters rebuild the next payload.
 *
 * @param {Record<string, unknown>|null|undefined} ack
 */
export function applyEditorDocumentAck(ack) {
    if (!ack || typeof ack !== 'object') {
        return;
    }

    const version = Number(ack.document_version ?? ack.patch?.article?.document_version ?? 0);
    if (Number.isFinite(version) && version > 0) {
        window.__SEO_EDITOR_DOCUMENT_VERSION__ = version;
        window.__seoEditorSessionClient?.setDocumentVersion?.(version);
    }

    const editorHash = String(
        ack.editor_document_hash
        ?? ack.patch?.article?.editor_document_hash
        ?? '',
    ).trim();
    if (editorHash !== '') {
        window.__SEO_EDITOR_DOCUMENT_HASH__ = editorHash;
    }

    const contentHash = String(
        ack.content_hash
        ?? ack.patch?.article?.content_hash
        ?? '',
    ).trim();
    const updatedAt = ack.saved_at
        ?? ack.patch?.article?.updated_at
        ?? getEditorConflictTokens().expected_updated_at
        ?? null;
    if (contentHash !== '') {
        setEditorConflictTokens({
            expected_updated_at: updatedAt,
            expected_content_hash: contentHash,
        });
    } else if (updatedAt) {
        const tokens = getEditorConflictTokens();
        setEditorConflictTokens({
            expected_updated_at: updatedAt,
            expected_content_hash: tokens.expected_content_hash,
        });
    }

    logArticleEditorVersionDebug('ack', {
        noop: Boolean(ack.noop),
        reconciled: Boolean(ack.reconciled),
        document_version: version || null,
        editor_document_hash: editorHash.slice(0, 12) || null,
        content_hash: contentHash.slice(0, 12) || null,
    });
}

/**
 * @param {object|null|undefined} wire Livewire snapshot (read-only properties, không gọi method)
 * @return {{ title: string, slug: string, seo_meta_description: string, focus_keyword: string }}
 */
export function readArticleMetaFromWire(wire) {
    if (!wire) {
        return readArticleMetaFromDom();
    }

    return {
        title: String(wire.articleTitle ?? '').trim(),
        slug: String(wire.articleSlug ?? '').trim(),
        seo_meta_description: String(wire.seoMetaDescription ?? '').trim(),
        focus_keyword: String(wire.focusKeyword ?? '').trim(),
    };
}

/**
 * Đọc meta từ DOM/Livewire snapshot mà không gọi $wire method.
 */
export function readArticleMetaFromDom() {
    const titleInput = document.querySelector('.seo-article-edit-page input[wire\\:model\\.blur="articleTitle"]');
    const slugInput = document.querySelector('.seo-article-edit-page input[data-seo-article-slug-input]');

    let focusKeyword = '';
    let seoMetaDescription = '';

    try {
        const pageRoot = document.querySelector('.seo-article-edit-page[wire\\:id]');
        const wireId = pageRoot?.getAttribute('wire:id');
        const component = typeof Livewire !== 'undefined' && wireId ? Livewire.find(wireId) : null;
        if (component) {
            focusKeyword = String(component.get?.('focusKeyword') ?? component.focusKeyword ?? '').trim();
            seoMetaDescription = String(component.get?.('seoMetaDescription') ?? component.seoMetaDescription ?? '').trim();
        }
    } catch {
        /* ignore */
    }

    return {
        title: String(titleInput?.value ?? '').trim(),
        slug: String(slugInput?.value ?? '').trim(),
        seo_meta_description: seoMetaDescription,
        focus_keyword: focusKeyword,
    };
}

/**
 * @param {object} editorBundle from __seoCollectEditorHeavyBundle
 * @param {object|null|undefined} wire
 * @return {Record<string, unknown>}
 */
/**
 * Phase 2 lazy FAQ: core bootstrap không còn rows; panelFaqs mặc định [].
 * Nếu FAQ module chưa hydrate mà gửi faqs:[] → wipe seo_faqs + meta WP (shortcode trống).
 *
 * @param {object|null|undefined} editorBundle
 * @returns {{ faqs: unknown[]|null, faqs_source: 'editor'|'panel'|'none' }}
 */
export function resolveFaqsPersistPayload(editorBundle) {
    const collectorOpen = typeof window.__seoCollectArticleFaqs === 'function';
    const faqsFromEditor = collectorOpen ? window.__seoCollectArticleFaqs() : null;
    const faqsFromBundle = Array.isArray(editorBundle?.faqs) ? editorBundle.faqs : null;

    if (Array.isArray(faqsFromEditor)) {
        return { faqs: faqsFromEditor, faqs_source: 'editor' };
    }

    if (Array.isArray(faqsFromBundle) && faqsFromBundle.length > 0) {
        return { faqs: faqsFromBundle, faqs_source: 'panel' };
    }

    // Module chưa mở / unmount: [] không tin được — bỏ key để backend giữ DB.
    return { faqs: null, faqs_source: 'none' };
}

/**
 * Build article save payload with omit-vs-null semantics.
 *
 * @param {object} editorBundle from __seoCollectEditorHeavyBundle
 * @param {object|null|undefined} wire
 * @param {{
 *   flushContent?: boolean,
 *   flushMedia?: boolean,
 *   flushSeo?: boolean,
 *   flushPublishing?: boolean,
 *   flushFaqs?: boolean,
 * }} [owners] Which owners flush this round. Default: all true (compat).
 * @return {Record<string, unknown>}
 */
export function buildArticleEditorApiPayload(editorBundle, wire, owners = {}) {
    const flushContent = owners.flushContent !== false;
    const flushMedia = owners.flushMedia !== false;
    const flushSeo = owners.flushSeo !== false;
    const flushPublishing = owners.flushPublishing !== false;
    const flushFaqs = owners.flushFaqs !== false;

    const articleId = Number(editorBundle?.articleId ?? 0);
    const conflictTokens = getEditorConflictTokens();
    const sessionClient = window.__seoEditorSessionClient;

    /** @type {Record<string, unknown>} */
    const payload = {
        expected_updated_at: conflictTokens.expected_updated_at,
        expected_content_hash: conflictTokens.expected_content_hash,
        expected_document_version: sessionClient?.documentVersion
            ?? window.__SEO_EDITOR_DOCUMENT_VERSION__
            ?? null,
        editor_session_id: sessionClient?.sessionId || null,
        article_id: articleId || null,
    };

    if (flushContent) {
        payload.html = String(editorBundle?.html ?? '');
        payload.client_rendered_html = String(editorBundle?.html ?? '');
        payload.editor_document = editorBundle?.editor_document ?? editorBundle?.editorDocument ?? null;
        payload.expected_editor_document_hash = editorBundle?.expected_editor_document_hash
            ?? window.__SEO_EDITOR_DOCUMENT_HASH__
            ?? null;
        payload.article_meta = readArticleMetaFromWire(wire);
    }

    if (flushSeo) {
        // Only set when SEO owner has analysis; omit key if undefined (untouched).
        if (editorBundle?.seoAnalysis !== undefined) {
            payload.seo_analysis = editorBundle.seoAnalysis;
        }
    }

    if (flushPublishing) {
        if (typeof window.__seoPublishBoxSnapshot === 'function') {
            payload.publish_box = window.__seoPublishBoxSnapshot();
        }
        if (typeof window.__seoPublishCategoriesSnapshot === 'function') {
            payload.category_ids = window.__seoPublishCategoriesSnapshot();
        }
    }

    if (flushMedia && articleId > 0) {
        const mediaSnapshot = getMediaSnapshot(articleId);
        if (mediaSnapshot != null) {
            payload.media_snapshot = mediaSnapshot;
            // Featured: null only when snapshot explicitly cleared; omit if no snapshot owner data.
            if (Object.prototype.hasOwnProperty.call(mediaSnapshot, 'featured')) {
                payload.featured_image = normalizeMediaSnapshotFeatured(mediaSnapshot);
            }
            if (mediaSnapshot?.gallery?.required && Array.isArray(mediaSnapshot.gallery.items)) {
                payload.product_album = normalizeMediaSnapshotProductAlbum(mediaSnapshot);
            }
        }
    }

    if (flushFaqs) {
        const faqPersist = resolveFaqsPersistPayload(editorBundle);
        // faqs: null means "module not ready — keep DB" (intentional omit of wipe).
        // Callers that want clear must pass faqs: [] explicitly from FAQ owner flush.
        if (faqPersist.faqs_source !== 'none') {
            payload.faqs = faqPersist.faqs;
            payload.faqs_source = faqPersist.faqs_source;
        }
    }

    return payload;
}

/**
 * Phase 2 — save path using SaveCoordinator owner flushes.
 * Falls back to buildArticleEditorApiPayload when no owners registered.
 *
 * @param {object} editorBundle
 * @param {object|null|undefined} wire
 * @returns {Promise<Record<string, unknown>>}
 */
export async function buildCoordinatedArticleSavePayload(editorBundle, wire) {
    try {
        const { flushAllSaveOwners, listSaveOwnerIds } = await import('@client-core/saveCoordinator.js');
        if (listSaveOwnerIds().length === 0) {
            return buildArticleEditorApiPayload(editorBundle, wire);
        }
        const { payload: owned } = await flushAllSaveOwners({ onlyDirty: false });
        const base = buildArticleEditorApiPayload(editorBundle, wire, {
            flushContent: false,
            flushMedia: false,
            flushSeo: false,
            flushPublishing: false,
            flushFaqs: false,
        });
        return { ...base, ...owned };
    } catch {
        return buildArticleEditorApiPayload(editorBundle, wire);
    }
}

function normalizeMediaSnapshotFeatured(mediaSnapshot) {
    const featured = mediaSnapshot?.featured;
    if (!featured || typeof featured !== 'object') {
        return null;
    }

    const url = String(featured.url ?? featured.thumbnail_url ?? '').trim();
    if (url === '') {
        return null;
    }

    return {
        url,
        wp_attachment_id: Number(featured.wp_attachment_id ?? 0) || 0,
        seo_media_id: Number(featured.media_id ?? featured.seo_media_id ?? 0) || 0,
        id: featured.id ?? featured.asset_key ?? null,
        asset_key: String(featured.asset_key ?? featured.id ?? '').trim(),
        source: String(featured.source ?? '').trim(),
        alt: String(featured.alt ?? '').trim(),
        slug: String(featured.slug ?? featured.filename ?? '').trim(),
    };
}

function normalizeMediaSnapshotProductAlbum(mediaSnapshot) {
    const gallery = mediaSnapshot?.gallery;
    if (!gallery?.required || !Array.isArray(gallery.items)) {
        return null;
    }

    const items = [];
    if (mediaSnapshot?.featured) {
        items.push(mediaSnapshot.featured);
    }
    items.push(...gallery.items);

    return items
        .map((item) => normalizeMediaSnapshotFeatured({ featured: item }))
        .filter(Boolean);
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 */
export async function saveArticleViaApi(articleId, payload) {
    const sessionClient = window.__seoEditorSessionClient;
    if (sessionClient && !sessionClient.readOnly && sessionClient.sessionId) {
        if (window.__SEO_EDITOR_READ_ONLY__) {
            throw new Error('Editor đang ở chế độ chỉ đọc — không lưu được.');
        }

        const saveResult = await sessionClient.saveDocument(payload, payload?.save_mode === 'autosave' ? 'autosave' : 'explicit');
        if (!saveResult.ok) {
            const error = new Error(saveResult.error?.message ?? 'Không lưu được bài viết.');
            error.conflict = [
                'article_document_version_conflict',
                'article_content_hash_conflict',
                'conflict_document_version',
                'conflict_content_hash',
                'conflict_updated_at',
            ].includes(String(saveResult.error?.code ?? ''));
            error.data = saveResult.data;
            error.sessionError = saveResult.error;
            error.code = String(saveResult.error?.code ?? '');
            logArticleEditorVersionDebug('save_conflict', {
                code: error.code,
                expected: payload?.expected_document_version ?? null,
            });
            throw error;
        }

        // ACK before callers/waiters read tokens — closes autosave→explicit race.
        applyEditorDocumentAck(saveResult.data);

        return {
            success: true,
            patch: saveResult.data?.patch,
            content_project_handoff: saveResult.data?.content_project_handoff ?? null,
            document_version: saveResult.data?.document_version,
            notification: {
                title: 'Article saved',
                body: 'Article saved',
                status: 'success',
            },
            ...saveResult.data,
        };
    }

    const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    };
    if (payload?.editor_session_id) {
        headers['X-Editor-Session-Id'] = String(payload.editor_session_id);
    }

    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/save`, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
    });

    if (response.status === 409 || response.status === 423) {
        const error = new Error(data?.message ?? 'Nội dung đã bị thay đổi ở nơi khác — không lưu.');
        error.conflict = true;
        error.data = data;

        throw error;
    }

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Không lưu được bài viết.');
    }

    return data;
}

/**
 * Atomic Save & Close via editor session.
 *
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 * @param {string} [closeReason]
 */
export async function closeArticleViaSessionApi(articleId, payload, closeReason = 'save_and_close') {
    const sessionClient = window.__seoEditorSessionClient;
    if (!sessionClient || sessionClient.readOnly || !sessionClient.sessionId) {
        const saved = await saveArticleViaApi(articleId, payload);
        return { ...saved, released: false, used_legacy_save: true };
    }

    const closeResult = await sessionClient.close(payload, closeReason);
    if (!closeResult.ok) {
        const error = new Error(closeResult.error?.message ?? 'Không lưu và đóng được bài viết.');
        error.conflict = String(closeResult.error?.code ?? '').includes('conflict');
        error.data = closeResult.data;
        error.sessionError = closeResult.error;
        throw error;
    }

    if (closeResult.data?.document_version != null) {
        window.__SEO_EDITOR_DOCUMENT_VERSION__ = Number(closeResult.data.document_version);
    }

    return {
        success: true,
        released: true,
        document_version: closeResult.data?.document_version,
        ...closeResult.data,
    };
}

/**
 * @param {number} articleId
 * @param {{ focus_keyword?: string, meta_description?: string, slug?: string }} payload
 */
export async function saveSeoMetaViaApi(articleId, payload) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/seo-meta`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Không lưu được trường SEO.');
    }

    return data;
}

/**
 * PHP canonical SEO score preview — no DB write.
 *
 * @param {number} articleId
 * @param {{
 *   title?: string,
 *   slug?: string,
 *   meta_description?: string,
 *   focus_keyword?: string|null,
 *   content: string,
 * }} payload
 * @param {{ signal?: AbortSignal }} [options]
 */
export async function previewSeoScoreViaApi(articleId, payload, options = {}) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/seo-score/preview`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
        signal: options.signal,
    });

    if (!response.ok) {
        throw new Error(data?.message ?? 'SEO score preview failed');
    }

    return data;
}

/**
 * Chạy Prompt Hook (không lưu article / SEO / WP).
 *
 * @param {string} hookKey vd. article.title_suggestion
 * @param {number} articleId
 * @param {Record<string, unknown>} [input] runtime overrides
 * @returns {Promise<{ success: true, data: { hook: string, output: { format: string, raw: string, value: string } } }>}
 */
export async function executePromptHookViaApi(hookKey, articleId, input = {}) {
    const encodedKey = encodeURIComponent(String(hookKey ?? '').trim());
    const { response, data } = await seoArticleApiFetch(`/api/seo/prompt-hooks/${encodedKey}/execute`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            article_id: Number(articleId),
            input: input && typeof input === 'object' ? input : {},
        }),
    });

    if (!response.ok || data.success === false) {
        const err = new Error(data.message ?? 'Prompt Hook thất bại.');
        err.code = data.error ?? 'HOOK_EXECUTION_FAILED';
        err.status = response.status;
        throw err;
    }

    return data;
}

function resolveEditArticleLivewireComponent() {
    if (typeof Livewire === 'undefined') {
        return null;
    }

    const wireId =
        String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim()
        || document.querySelector('.seo-article-edit-page[wire\\:id]')?.getAttribute('wire:id')
        || document.querySelector('.seo-article-edit-page [wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (wireId === '') {
        return null;
    }

    const component = Livewire.find(wireId);

    return component?.set || component?.call ? component : null;
}

/**
 * Đồng bộ meta SEO lên Livewire snapshot (không gọi method server).
 *
 * @param {{ focus_keyword?: string, meta_description?: string, article_slug?: string }} patch
 */
export function patchLivewireSeoMeta(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    const component = resolveEditArticleLivewireComponent();
    if (!component) {
        return;
    }

    if (patch.focus_keyword != null) {
        component.set('focusKeyword', String(patch.focus_keyword).trim());
    }

    if (patch.meta_description != null) {
        component.set('seoMetaDescription', String(patch.meta_description).trim());
    }

    if (patch.article_slug != null) {
        component.set('articleSlug', String(patch.article_slug).trim());
    }
}

/**
 * Ghép URL hiển thị từ base + slug + suffix (vd. `.html`).
 *
 * @param {string} base
 * @param {string} slug
 * @param {string} suffix
 */
export function buildPermalinkDisplayUrl(base, slug, suffix = '') {
    const host = String(base ?? '').trim().replace(/\/+$/, '');
    const normalizedSlug = String(slug ?? '').trim().replace(/^\/+|\/+$/g, '');
    const suf = String(suffix ?? '').trim();

    if (host === '' || normalizedSlug === '') {
        return '';
    }

    if (suf !== '' && suf.startsWith('.')) {
        return `${host}/${normalizedSlug}${suf}`;
    }

    if (suf !== '' && suf !== '/') {
        const pathSuffix = suf.startsWith('/') ? suf : `/${suf}`;

        return `${host}/${normalizedSlug}${pathSuffix}`;
    }

    return `${host}/${normalizedSlug}/`;
}

/**
 * Cập nhật dòng «Đường dẫn» dưới tiêu đề (`.wp-permalink`) + slug input nếu có.
 *
 * @param {{
 *   permalink?: string,
 *   article_slug?: string,
 *   slug?: string,
 *   permalink_base?: string,
 *   permalink_suffix?: string,
 * }} patch
 */
export function patchPermalinkDisplay(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    const slug = String(patch.article_slug ?? patch.slug ?? '').trim();
    const root = document.querySelector('[data-seo-permalink-root], .wp-permalink');
    const baseFromDom = String(root?.getAttribute('data-permalink-base') ?? '').trim();
    const suffixFromDom = String(root?.getAttribute('data-permalink-suffix') ?? '').trim();

    const base = String(patch.permalink_base ?? baseFromDom).trim().replace(/\/+$/, '');
    const suffix = String(patch.permalink_suffix ?? suffixFromDom).trim();

    let permalink = String(patch.permalink ?? '').trim();
    if (permalink === '' && slug !== '') {
        permalink = buildPermalinkDisplayUrl(base, slug, suffix);
    }

    if (slug !== '') {
        const slugInput = document.querySelector(
            '.seo-article-edit-page input[data-seo-article-slug-input]',
        );
        if (slugInput instanceof HTMLInputElement) {
            slugInput.value = slug;
        }

        if (root) {
            root.setAttribute('data-article-slug', slug);
        }
    }

    if (base !== '' && root) {
        root.setAttribute('data-permalink-base', base);
    }

    if (root && patch.permalink_suffix != null) {
        root.setAttribute('data-permalink-suffix', suffix);
    }

    if (permalink === '') {
        return;
    }

    const target = root?.querySelector('[data-seo-permalink-url]')
        ?? root?.querySelector('a')
        ?? root?.querySelector('span.break-all');

    if (!target) {
        return;
    }

    target.textContent = permalink;
    if (target instanceof HTMLAnchorElement) {
        target.href = permalink;
    }
}

/**
 * @param {Record<string, unknown>} result
 */
export function applyArticleSeoMetaSaveResult(result) {
    if (!result || typeof result !== 'object') {
        return;
    }

    const preview = result.google_serp_preview ?? null;
    if (preview && typeof preview === 'object') {
        window.dispatchEvent(
            new CustomEvent('google-serp-preview-updated', {
                detail: { preview },
            }),
        );
    }

    if (result.focus_keyword != null) {
        window.dispatchEvent(
            new CustomEvent('seo-focus-keyword-updated', {
                detail: { focus_keyword: result.focus_keyword },
            }),
        );
    }

    const slug = String(result.article_slug ?? '').trim();
    const permalink = String(
        result.permalink
            ?? preview?.url
            ?? '',
    ).trim();

    patchPermalinkDisplay({
        permalink,
        article_slug: slug,
        permalink_base: result.permalink_base,
        permalink_suffix: result.permalink_suffix,
    });

    if (slug !== '') {
        window.dispatchEvent(
            new CustomEvent('seo-editor-slug-updated', {
                detail: {
                    slug,
                    article_slug: slug,
                    permalink,
                    permalink_base: result.permalink_base,
                    permalink_suffix: result.permalink_suffix,
                },
            }),
        );
    }

    patchLivewireSeoMeta({
        focus_keyword: result.focus_keyword,
        meta_description: result.meta_description ?? preview?.description ?? undefined,
        article_slug: result.article_slug,
    });

    if (result.seo_analysis_pending) {
        window.dispatchEvent(
            new CustomEvent('article-editor-save-patched', {
                detail: { seo_analysis_pending: true },
            }),
        );
    }
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 */
export async function syncArticleToWordPressViaApi(articleId, payload) {
    const { response, data } = await seoArticleApiFetch(`/api/seo/articles/${articleId}/sync-wp`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    const payloadData = data && typeof data === 'object' ? data : {};

    // Gate blocked / fail: server đã gắn notification — phải toast trước khi throw.
    if (payloadData.notification) {
        showArticleEditorFilamentToast(payloadData.notification);
        payloadData.notificationShown = true;
    }

    if (!response.ok || payloadData.success === false) {
        const message = String(
            payloadData.message
                ?? payloadData.notification?.body
                ?? 'Đồng bộ WordPress thất bại.',
        );
        const error = new Error(message);
        error.automationStatus = String(payloadData.status ?? 'blocked');
        error.notificationShown = Boolean(payloadData.notification);
        error.payload = payloadData;
        throw error;
    }

    return payloadData;
}

const TOAST_DEDUPE_MS = 2200;
/** @type {{ key: string, at: number } | null} */
let lastEditorToastFingerprint = null;

/**
 * Toast Filament thuần JS — không qua Livewire.
 * Bỏ toast rỗng (trắng) + dedupe ngắn để tránh lặp.
 *
 * @param {{ title?: string, body?: string, status?: string }|null|undefined} notification
 */
export function showArticleEditorFilamentToast(notification) {
    if (!notification || typeof notification !== 'object') {
        return;
    }

    if (typeof window.FilamentNotification === 'undefined') {
        return;
    }

    const title = String(notification.title ?? '').trim();
    const body = String(notification.body ?? '').trim();
    if (title === '' && body === '') {
        return;
    }

    const status = String(notification.status ?? 'success').trim() || 'success';
    const key = `${status}|${title}|${body}`;
    const now = Date.now();
    if (
        lastEditorToastFingerprint
        && lastEditorToastFingerprint.key === key
        && now - lastEditorToastFingerprint.at < TOAST_DEDUPE_MS
    ) {
        return;
    }
    lastEditorToastFingerprint = { key, at: now };

    const toast = new window.FilamentNotification();
    if (title !== '') {
        toast.title(title);
    }
    if (body !== '') {
        toast.body(body);
    }

    if (status === 'danger' || status === 'error') {
        toast.danger();
    } else if (status === 'warning') {
        toast.warning();
    } else if (status === 'info') {
        toast.info();
    } else {
        toast.success();
    }

    toast.send();
}

if (typeof window !== 'undefined') {
    window.__seoShowArticleEditorToast = showArticleEditorFilamentToast;
}

/**
 * @param {Record<string, unknown>} patch
 */
export function applyArticleEditorSavePatch(patch) {
    if (!patch || typeof patch !== 'object') {
        return;
    }

    window.dispatchEvent(new CustomEvent('article-editor-save-patched', { detail: patch }));

    const article = patch.article ?? {};
    if (article.updated_at_label) {
        document.querySelectorAll('[data-seo-article-updated-at]').forEach((el) => {
            el.textContent = String(article.updated_at_label);
        });
    }

    if (article.seo_score != null) {
        document.querySelectorAll('[data-seo-article-score]').forEach((el) => {
            el.textContent = String(article.seo_score);
        });
    }

    if (patch.seo_analysis && typeof patch.seo_analysis === 'object') {
        // Incomplete analysis (no violations) must not wipe client diagnostics mid-save.
        if (Object.prototype.hasOwnProperty.call(patch.seo_analysis, 'violations')) {
            window.dispatchEvent(
                new CustomEvent('seo-editor-analyze-result', {
                    detail: { result: patch.seo_analysis },
                }),
            );
        }
    }

    if (patch.revision_count != null) {
        window.dispatchEvent(
            new CustomEvent('article-revisions-changed', {
                detail: { count: Number(patch.revision_count) },
            }),
        );

        const revisionCountEl = document.querySelector('[data-seo-revision-count]');
        if (revisionCountEl) {
            revisionCountEl.textContent = String(Number(patch.revision_count));
        }
    }
}

function resetEditArticleHeavyActionBusyOnWire() {
    if (typeof Livewire === 'undefined') {
        return;
    }

    const wireId =
        String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim()
        || document.querySelector('.seo-article-edit-page[wire\\:id]')?.getAttribute('wire:id')
        || document.querySelector('.seo-article-edit-page [wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (wireId === '') {
        return;
    }

    const component = Livewire.find(wireId);
    if (!component?.call) {
        return;
    }

    const busy = Boolean(component.get?.('articleHeavyActionBusy') ?? component.articleHeavyActionBusy);
    if (!busy) {
        return;
    }

    void component.call('cancelHeavyArticleAction');
}

/**
 * Hoàn tất Save — không reload, không Livewire.
 *
 * Sau save thành công: hủy debounce autosave, cập nhật baseline/token,
 * xóa draft cũ rồi ghi snapshot synced (tránh race tạo lại draft bẩn).
 *
 * @param {{ patch?: Record<string, unknown>, notification?: Record<string, string> }} result
 * @param {{ articleId?: number, connectionHash?: string, savedHtml?: string, siteId?: number, keepOverlay?: boolean, silentNotification?: boolean }} [context]
 */
export function finishArticleSaveFromApi(result, context = {}) {
    if (result.patch) {
        applyArticleEditorSavePatch(result.patch);
    }

    if (result.notification && context.silentNotification !== true) {
        showArticleEditorFilamentToast(result.notification);
    }

    const { articleId, connectionHash, savedHtml } = context;
        if (typeof savedHtml === 'string') {
        // Prefer server ACK hash — client TipTap export can differ after table/whitespace guards.
        const serverContentHash = String(
            result?.content_hash
            ?? result?.patch?.article?.content_hash
            ?? '',
        ).trim();
        const savedContentHash = serverContentHash || hashContent(savedHtml);
        const nextUpdatedAt = result.patch?.article?.updated_at
            ?? result?.saved_at
            ?? getEditorConflictTokens().expected_updated_at;
        applyEditorDocumentAck({
            document_version: result.document_version ?? result.patch?.article?.document_version,
            content_hash: savedContentHash,
            editor_document_hash: result.editor_document_hash
                ?? result.patch?.article?.editor_document_hash,
            saved_at: nextUpdatedAt,
            noop: result.noop,
            reconciled: result.reconciled,
        });

        if (articleId) {
            window.__seoCancelArticleDraftAutosave?.();
            const siteId = Number(context.siteId ?? window.__SEO_ARTICLE_SITE_ID__ ?? 0) || 0;
            const currentHtml = typeof window.__seoExportEditorHtml === 'function'
                ? String(window.__seoExportEditorHtml() ?? '')
                : String(savedHtml);
            if (!shouldClearLocalRecoveryAfterSave({
                currentHtml,
                savedHtml,
                savedContentHash,
            })) {
                window.__seoFlushArticleRecoveryDraft?.();
            } else {
                clearDraft(articleId, connectionHash, { siteId });
                writeSyncedLocalSnapshot(articleId, connectionHash, {
                    content: savedHtml,
                    site_id: siteId,
                    base_updated_at: nextUpdatedAt || null,
                    base_content_hash: savedContentHash,
                    version: savedContentHash,
                    autosave_error: null,
                });
            }
        }
    }

    if (context.keepOverlay !== true) {
        window.__seoEndArticleHeavyActionClient?.();
        resetEditArticleHeavyActionBusyOnWire();
    }
    window.dispatchEvent(new CustomEvent('article-editor-save-finished'));
    const handoff = result?.content_project_handoff && typeof result.content_project_handoff === 'object'
        ? result.content_project_handoff
        : null;
    emitProjectItemUpdated({
        article_id: articleId || null,
        project_id: Number(
            handoff?.project_id
            ?? context.projectId
            ?? window.__SEO_ARTICLE_PROJECT_ID__
            ?? 0,
        ) || 0,
        content_project_handoff: handoff,
        counter_action: handoff?.handed_off ? 'content_manager_handoff' : null,
        task_id: handoff?.task_id ?? null,
    });
}

/**
 * Dirty flag for Content Project Needs Review / lazy refresh (no websocket).
 * @param {Record<string, unknown>} [detail]
 */
export function emitProjectItemUpdated(detail = {}) {
    const projectId = Number(detail.project_id ?? detail.projectId ?? window.__SEO_ARTICLE_PROJECT_ID__ ?? 0) || 0;
    try {
        sessionStorage.setItem('cp-ops-dirty-global', '1');
        if (projectId > 0) {
            sessionStorage.setItem(`cp-ops-dirty-${projectId}`, '1');
        }
    } catch {
        // ignore
    }
    window.dispatchEvent(new CustomEvent('project-item-updated', { detail: { ...detail, project_id: projectId } }));
}

/**
 * Xử lý khi save trả 409 (conflict) — KHÔNG reload, KHÔNG clearDraft. Dispatch event để
 * UI (SeoArticleEditor) hiển thị modal/alert cho người dùng quyết định.
 *
 * @param {Error & { conflict?: boolean, data?: Record<string, unknown> }} error
 */
export function handleArticleSaveConflict(error) {
    window.__seoEndArticleHeavyActionClient?.();
    resetEditArticleHeavyActionBusyOnWire();

    const notification = error?.data?.notification ?? {
        title: 'Xung đột khi lưu',
        body: error?.message ?? 'Nội dung đã bị thay đổi ở nơi khác.',
        status: 'warning',
    };
    showArticleEditorFilamentToast(notification);

    window.dispatchEvent(
        new CustomEvent('seo-article-save-conflict', {
            detail: { conflict: error?.data?.conflict ?? null, message: error?.message ?? '' },
        }),
    );
    window.dispatchEvent(new CustomEvent('article-editor-save-finished', { detail: { conflict: true } }));
}

/**
 * Optional helper — navigate to Sync Queue list when browser blocks window.close().
 *
 * @returns {string}
 */
export function resolveSyncQueueListUrl() {
    const configured = typeof window.__SEO_ARTICLES_SYNC_QUEUE_URL__ === 'string'
        ? window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim()
        : '';
    if (configured !== '') {
        return configured;
    }

    const indexUrl = typeof window.__SEO_ARTICLES_LIST_URL__ === 'string'
        ? window.__SEO_ARTICLES_LIST_URL__.trim()
        : '';
    if (indexUrl !== '') {
        const joiner = indexUrl.includes('?') ? '&' : '?';

        return `${indexUrl}${joiner}tab=queue`;
    }

    return '/seo/articles?tab=queue';
}

/**
 * Stop local draft persistence + clear scoped draft before leaving editor after enqueue.
 *
 * @param {number} articleId
 * @param {number} siteId
 */
export function prepareEditorExitAfterSyncEnqueue(articleId, siteId) {
    window.__SEO_EDITOR_EXITING__ = true;
    setDraftPersistenceEnabled(false);
    window.__seoDisableArticleDraftPersistence?.();
    window.__seoCancelArticleDraftAutosave?.();
    window.__seoArticleAutosaveLock?.set?.('editor-exiting', true);
    window.__seoClearArticleLocalState?.(articleId, siteId);

    const connectionHash = typeof window.__SEO_EDITOR_CONNECTION_HASH__ === 'string'
        ? window.__SEO_EDITOR_CONNECTION_HASH__
        : (typeof window.__SEO_CONNECTION_HASH__ === 'string' ? window.__SEO_CONNECTION_HASH__ : '');
    // Cancel trước clear — tránh debounce autosave ghi lại draft cũ sau khi sync.
    window.__seoCancelArticleDraftAutosave?.();
    clearDraft(articleId, connectionHash, { siteId });
}

/**
 * Close current tab after enqueue; fallback redirect to Articles Sync queue tab.
 * Không chờ window.close() (browser thường chặn) — navigate ngay nếu tab còn mở.
 */
export function closeEditorTabOrRedirectToSyncQueue() {
    const url = resolveSyncQueueListUrl();

    try {
        window.close();
    } catch {
        // Some browsers throw; fall through to redirect.
    }

    // Navigate ngay — đừng để user ngồi nhìn overlay "vui lòng chờ".
    try {
        if (!window.closed) {
            window.location.replace(url);
        }
    } catch {
        window.location.href = url;
    }

    // Safety net nếu close/replace bị browser trì hoãn.
    window.setTimeout(() => {
        try {
            if (!window.closed) {
                window.location.replace(url);
            }
        } catch {
            window.location.href = url;
        }
    }, 50);
}

/**
 * Close editor after proven Content Project local save (not Sync Queue).
 *
 * @param {string|null|undefined} projectUrl
 */
export function closeEditorAfterProjectLocalSave(projectUrl) {
    const fallback = typeof window.__SEO_ARTICLES_LIST_URL__ === 'string'
        && window.__SEO_ARTICLES_LIST_URL__.trim() !== ''
        ? window.__SEO_ARTICLES_LIST_URL__.trim()
        : '/seo/articles';
    const url = typeof projectUrl === 'string' && projectUrl.trim() !== ''
        ? projectUrl.trim()
        : fallback;

    try {
        window.close();
    } catch {
        // ignore
    }

    try {
        if (!window.closed) {
            window.location.replace(url);
        }
    } catch {
        window.location.href = url;
    }

    window.setTimeout(() => {
        try {
            if (!window.closed) {
                window.location.replace(url);
            }
        } catch {
            window.location.href = url;
        }
    }, 50);
}

/**
 * Hoàn tất Sync WP — enqueue thành công: clear draft, đóng tab ngay (không poll worker).
 * Content Project local save: chỉ đóng sau khi server trả save_mode=project_local_save + content_hash.
 *
 * @param {{ reload?: boolean, clear_local_state?: boolean, queued?: boolean, close_editor?: boolean, workspace_only?: boolean, save_mode?: string, notification?: Record<string, string>, operation?: object, notificationShown?: boolean, data?: Record<string, unknown>, success?: boolean }} result
 * @param {number} articleId
 * @param {number} siteId
 */
export function finishArticleSyncFromApi(result, articleId, siteId) {
    const saveMode = String(result?.save_mode ?? result?.data?.save_mode ?? '');
    const workspaceOnly = result?.workspace_only === true
        || saveMode === 'project_local_save'
        || String(result?.status ?? result?.data?.status ?? '') === 'workspace_saved';

    if (workspaceOnly) {
        const data = result?.data && typeof result.data === 'object' ? result.data : {};
        const savedArticleId = Number(data.article_id ?? articleId) || 0;
        const contentHash = String(data.content_hash ?? '').trim();
        const savedAt = String(data.saved_at ?? '').trim();
        const proven = result?.success !== false
            && savedArticleId > 0
            && contentHash !== ''
            && savedAt !== '';

        if (result.notification && result.notificationShown !== true) {
            showArticleEditorFilamentToast(result.notification);
        }

        if (!proven) {
            window.__seoEndArticleHeavyActionClient?.();
            if (!result.notification) {
                showArticleEditorFilamentToast({
                    title: 'Save failed',
                    body: String(result?.message ?? 'Workspace save was not confirmed.'),
                    status: 'danger',
                });
            }

            return;
        }

        if (result.close_editor === false) {
            window.__seoEndArticleHeavyActionClient?.();

            return;
        }

        window.__SEO_EDITOR_EXITING__ = true;
        window.__seoArticleOperationTracker?.stop?.();
        prepareEditorExitAfterSyncEnqueue(articleId, siteId);

        if (window.__seoArticleHeavyActionOverlay) {
            window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
            window.__seoArticleHeavyActionOverlay.locked = false;
        }
        window.__seoArticleHeavyActionOverlay?.hide?.();

        window.dispatchEvent(new CustomEvent('article-project-local-save-succeeded', { detail: result }));
        emitProjectItemUpdated({
            article_id: articleId || null,
            project_id: Number(data.project_id ?? window.__SEO_ARTICLE_PROJECT_ID__ ?? 0) || 0,
            save_mode: 'project_local_save',
        });
        closeEditorAfterProjectLocalSave(
            typeof data.project_url === 'string' ? data.project_url : null,
        );

        return;
    }

    if (result.queued) {
        // Quan trọng: đặt EXITING + navigate TRƯỚC Livewire cancel.
        // Nếu gọi Livewire trước, Alpine init lại → bootstrap thấy job queued → overlay Elapsed.
        window.__SEO_EDITOR_EXITING__ = true;
        window.__seoArticleOperationTracker?.stop?.();
        prepareEditorExitAfterSyncEnqueue(articleId, siteId);

        if (window.__seoArticleHeavyActionOverlay) {
            window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
            window.__seoArticleHeavyActionOverlay.locked = false;
        }
        window.__seoArticleHeavyActionOverlay?.hide?.();

        window.dispatchEvent(new CustomEvent('article-wordpress-sync-queued', { detail: result }));

        if (result.close_editor !== false) {
            if (typeof window.__seoArticleOperationTracker?.exitAfterQueued === 'function') {
                window.__seoArticleOperationTracker.exitAfterQueued();
            } else {
                closeEditorTabOrRedirectToSyncQueue();
            }
        }

        return;
    }

    if (result.notification && result.notificationShown !== true) {
        showArticleEditorFilamentToast(result.notification);
    }

    if (result.reload) {
        window.__seoArticleHeavyActionOverlay?.show('sync', { persistUntilUnload: true });
        if (result.clear_local_state) {
            window.__seoClearArticleLocalState?.(articleId, siteId);
        }
        window.location.reload();

        return;
    }

    window.__seoEndArticleHeavyActionClient?.();
}

/** @deprecated Sync-only — Save dùng finishArticleSaveFromApi */
export function finishArticleEditorApiAction(result, articleId, siteId, action = 'save') {
    if (action === 'sync') {
        finishArticleSyncFromApi(result, articleId, siteId);

        return;
    }

    finishArticleSaveFromApi(result);
}

/** @deprecated Save không gọi Livewire notify */
export function notifyEditorFromApi(_wire, notification) {
    showArticleEditorFilamentToast(notification);
}

/**
 * Load WordPress product reviews for Edit Article (source of truth).
 * @param {number} articleId
 */
export async function fetchWordPressProductReviews(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/wordpress-product-reviews`,
        {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
            },
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    if (!response.ok || payloadData.success === false) {
        return {
            success: false,
            message: String(payloadData.message ?? 'Không thể tải đánh giá từ WordPress.'),
            data: payloadData.data,
        };
    }

    return {
        success: true,
        data: payloadData.data && typeof payloadData.data === 'object' ? payloadData.data : payloadData,
    };
}

/**
 * Shared backend policy status for product reviews.
 * @param {number} articleId
 */
export async function fetchProductReviewStatus(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-review-status`,
        {
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    if (!response.ok || payloadData.success === false) {
        return {
            success: false,
            message: String(payloadData.message ?? 'Không thể tải trạng thái đánh giá.'),
            data: payloadData.data,
        };
    }

    return {
        success: true,
        data: payloadData.data && typeof payloadData.data === 'object' ? payloadData.data : payloadData,
    };
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} [body]
 */
export async function createProductReviewsForArticle(articleId, body = {}) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-reviews/create`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(body ?? {}),
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    return {
        success: response.ok && payloadData.success !== false,
        message: String(payloadData.message ?? ''),
        data: payloadData.data,
        status: payloadData.status,
    };
}

/**
 * @param {number} articleId
 */
export async function syncProductReviewsForArticle(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, message: 'Invalid article id' };
    }

    const { response, data } = await seoArticleApiFetch(
        `/api/seo/articles/${id}/product-reviews/sync`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: '{}',
        },
    );

    const payloadData = data && typeof data === 'object' ? data : {};
    return {
        success: response.ok && payloadData.success !== false,
        message: String(payloadData.message ?? ''),
        data: payloadData.data,
        status: payloadData.status,
    };
}

/**
 * @deprecated Prefer fetchWordPressProductReviews / fetchProductReviewStatus
 * @param {number} articleId
 */
export async function reconcileProductReviewsForArticle(articleId) {
    return fetchWordPressProductReviews(articleId);
}
