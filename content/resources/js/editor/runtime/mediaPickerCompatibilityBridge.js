/**
 * Phase 6C.3 — shell/Alpine → Shared Media Picker (no Alpine modal SoT).
 * Adapter only: opens React picker + wires Featured/Gallery/content_image confirms.
 */

import { openMediaPicker } from './editorMediaPickerStore';
import {
    replaceGalleryViaApi,
    setFeaturedViaApi,
    galleryFromSnapshot,
} from '../../utils/articleEditorMediaSnapshot';
import { executeEditorCommand, getEditorCommandHost } from '../../utils/editorCommands';
import { canMutateEditor } from '../../utils/editorSessionState';

const INSTALLED_FLAG = '__SEO_MEDIA_PICKER_BRIDGE_INSTALLED__';

function mapMode(raw) {
    const mode = String(raw || '').toLowerCase();
    if (mode === 'gallery') return 'gallery';
    if (mode === 'featured') return 'featured';
    if (mode === 'editor-block' || mode === 'content_image' || mode === 'content') {
        return 'content_image';
    }
    return 'content_image';
}

/**
 * @param {object} options
 * @param {string} [options.mode]
 * @param {string|null} [options.blockId]
 * @param {number|null} [options.articleId]
 */
export function openSharedMediaPickerFromShell(options = {}) {
    const mode = mapMode(options.mode);
    const blockId = String(options.blockId ?? options.target?.blockId ?? '').trim() || null;
    const host = getEditorCommandHost();
    const articleId = Number(options.articleId ?? host?.articleId ?? 0) || 0;
    const selection = mode === 'gallery' ? 'multiple' : 'single';

    return openMediaPicker({
        mode,
        selection,
        target: blockId ? { blockId } : (options.target || null),
        onConfirm: async (items) => {
            if (!canMutateEditor() || host?.isArchived?.()) {
                return;
            }
            if (!Array.isArray(items) || items.length === 0) {
                return;
            }

            if (mode === 'featured') {
                const item = items[0];
                if (!item?.url || !articleId) return;
                await setFeaturedViaApi(articleId, {
                    url: item.url,
                    wp_attachment_id: item.wp_attachment_id,
                    seo_media_id: item.seo_media_id,
                    alt: item.alt,
                    slug: item.slug,
                });
                return;
            }

            if (mode === 'gallery') {
                if (!articleId) return;
                const existing = galleryFromSnapshot(articleId).map((row) => ({
                    url: row.url,
                    wp_attachment_id: row.id > 0 ? row.id : 0,
                    media_id: 0,
                    id: row.stable_id || undefined,
                }));
                const mapped = items.filter((row) => row?.url).map((row) => ({
                    url: row.url,
                    wp_attachment_id: row.wp_attachment_id,
                    media_id: row.seo_media_id,
                    alt: row.alt,
                }));
                await replaceGalleryViaApi(articleId, [...existing, ...mapped]);
                return;
            }

            // content_image
            const item = items[0];
            if (!item?.url) return;

            if (blockId && typeof host?.actions?.applyEditorBlockImage === 'function') {
                host.actions.applyEditorBlockImage({
                    blockId,
                    url: item.url,
                    alt: item.alt || '',
                    slug: item.slug || '',
                    attachmentId: Number(item.wp_attachment_id || 0) || 0,
                    seoMediaId: Number(item.seo_media_id || 0) || 0,
                    mediaType: item.media_type || 'image',
                });
                return;
            }

            const result = executeEditorCommand('insert_image', {
                src: item.url,
                url: item.url,
                alt: item.alt || '',
                attrs: {
                    slug: item.slug || '',
                    wpAttachmentId: Number(item.wp_attachment_id || 0) || undefined,
                    seoMediaId: Number(item.seo_media_id || 0) || undefined,
                },
            });
            if (result && result.ok === false) {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Insert image',
                        body: String(result.message || result.code || 'insert_failed'),
                        status: 'warning',
                    },
                }));
            }
        },
    });
}

/**
 * Expose for Alpine openArticleMediaModal bridge.
 * @returns {() => void} uninstall
 */
export function installMediaPickerCompatibilityBridge() {
    if (typeof window === 'undefined') {
        return () => {};
    }
    if (window[INSTALLED_FLAG]) {
        return () => {};
    }
    window[INSTALLED_FLAG] = true;

    window.__seoOpenSharedMediaPicker = openSharedMediaPickerFromShell;

    const onOpenArticle = (event) => {
        openSharedMediaPickerFromShell({
            mode: 'content_image',
            blockId: event?.detail?.blockId ?? null,
        });
    };
    const onOpenFeatured = () => {
        openSharedMediaPickerFromShell({ mode: 'featured' });
    };
    const onOpenGallery = () => {
        openSharedMediaPickerFromShell({ mode: 'gallery' });
    };
    const onOpenModal = (event) => {
        openSharedMediaPickerFromShell({
            mode: event?.detail?.mode || 'featured',
            blockId: event?.detail?.blockId ?? null,
        });
    };

    window.addEventListener('seo-open-article-media-picker', onOpenArticle);
    window.addEventListener('seo-open-featured-media-picker', onOpenFeatured);
    window.addEventListener('seo-open-gallery-media-picker', onOpenGallery);
    window.addEventListener('open-article-media-modal', onOpenModal);

    return () => {
        window.removeEventListener('seo-open-article-media-picker', onOpenArticle);
        window.removeEventListener('seo-open-featured-media-picker', onOpenFeatured);
        window.removeEventListener('seo-open-gallery-media-picker', onOpenGallery);
        window.removeEventListener('open-article-media-modal', onOpenModal);
        delete window.__seoOpenSharedMediaPicker;
        window[INSTALLED_FLAG] = false;
    };
}
