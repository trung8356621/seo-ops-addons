import React, { useCallback, useMemo, useState } from 'react';
import { LayoutGrid, Plus, Trash2 } from 'lucide-react';
import { EditorModuleErrorBoundary } from '@content-addon/editor/runtime/EditorModuleErrorBoundary.jsx';
import { useEditorMedia } from '../../host/hooks/useEditorMedia';
import { useEditorMediaPicker } from '../../host/hooks/useEditorMediaPicker';
import { useEditorNotifications } from '@content-addon/editor/host/hooks/useEditorNotifications.js';
import { t } from '@content-addon/utils/i18n.js';

function stableId(item, index) {
    return String(item?.asset_key || item?.id || item?.stable_id || `idx-${index}`);
}

/**
 * Product gallery panel — media snapshot SoT (Phase 6C.3).
 */
export function GallerySidebarPanel({ articleId = null, active = false }) {
    const media = useEditorMedia(articleId);
    const picker = useEditorMediaPicker();
    const { notify } = useEditorNotifications();
    const [dragId, setDragId] = useState(null);
    const items = useMemo(
        () => (Array.isArray(media.gallery?.items) ? media.gallery.items : []),
        [media.gallery],
    );
    const mutable = media.canMutate();

    const openGenerate = useCallback(() => {
        if (!mutable) {
            notify({
                title: t('editor_gallery_notify_title'),
                body: t('editor_gallery_read_only'),
                status: 'warning',
            });
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-open-generate-image-modal', {
            detail: { target: 'product-gallery' },
        }));
    }, [mutable, notify]);

    const distributeToSections = useCallback(() => {
        if (!mutable) {
            notify({
                title: t('editor_gallery_notify_title'),
                body: t('editor_gallery_read_only'),
                status: 'warning',
            });
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-distribute-product-gallery'));
    }, [mutable, notify]);

    const openPicker = useCallback(() => {
        if (!mutable) {
            notify({
                title: t('editor_gallery_notify_title'),
                body: t('editor_gallery_read_only'),
                status: 'warning',
            });
            return;
        }
        picker.open({
            mode: 'gallery',
            selection: 'multiple',
            onConfirm: async (selected) => {
                const mapped = selected
                    .filter((row) => row?.url)
                    .map((row) => ({
                        url: row.url,
                        wp_attachment_id: row.wp_attachment_id,
                        media_id: row.seo_media_id,
                        asset_key: row.asset_key,
                        source: row.source,
                        alt: row.alt,
                    }));
                if (mapped.length === 0) return;
                const merged = [
                    ...items.map((row) => ({
                        url: row.url,
                        wp_attachment_id: row.wp_attachment_id,
                        media_id: row.media_id,
                        asset_key: row.asset_key,
                        source: row.source,
                        alt: row.alt,
                        id: row.id,
                    })),
                    ...mapped,
                ];
                try {
                    await media.replaceGallery(merged);
                    notify({ title: t('editor_gallery_updated'), status: 'success' });
                } catch (error) {
                    notify({
                        title: t('editor_gallery_failed'),
                        body: String(error?.message || t('prompt_hook_try_again')),
                        status: 'warning',
                    });
                }
            },
        });
    }, [mutable, picker, items, media, notify]);

    const removeItem = useCallback(async (item) => {
        if (!mutable) return;
        const next = items.filter((row) => String(row.url) !== String(item.url) || String(row.id) !== String(item.id));
        try {
            await media.replaceGallery(next);
        } catch (error) {
            notify({
                title: t('editor_gallery_remove_failed'),
                body: String(error?.message || t('prompt_hook_try_again')),
                status: 'warning',
            });
        }
    }, [mutable, items, media, notify]);

    const onDrop = useCallback(async (targetId) => {
        if (!mutable || !dragId || dragId === targetId) {
            setDragId(null);
            return;
        }
        const ids = items.map((row, index) => stableId(row, index));
        const from = ids.indexOf(dragId);
        const to = ids.indexOf(targetId);
        if (from < 0 || to < 0) {
            setDragId(null);
            return;
        }
        const nextIds = [...ids];
        const [moved] = nextIds.splice(from, 1);
        nextIds.splice(to, 0, moved);
        setDragId(null);
        try {
            await media.reorderGallery(nextIds);
        } catch (error) {
            notify({
                title: t('editor_gallery_reorder_failed'),
                body: String(error?.message || t('prompt_hook_try_again')),
                status: 'warning',
            });
        }
    }, [mutable, dragId, items, media, notify]);

    if (!active) {
        return <div className="seo-assistant-widget__lazy-placeholder">{t('editor_panel_lazy_placeholder')}</div>;
    }

    return (
        <EditorModuleErrorBoundary moduleId="article-editor.gallery" slotName="sidebar.main">
            <section className="seo-assistant-widget seo-assistant-widget--product-album seo-assistant-widget--static">
                <header className="seo-assistant-widget__header seo-assistant-widget__header--static">
                    <div className="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                        <span className="seo-assistant-widget__title">{t('editor_product_album_title')}</span>
                    </div>
                </header>
                <div className="seo-assistant-widget__body">
                    {items.length > 0 ? (
                        <div className="wp-product-gallery-grid" role="list">
                            {items.map((image, index) => {
                                const sid = stableId(image, index);
                                return (
                                    <div
                                        key={sid}
                                        className="wp-product-gallery-thumb-wrap"
                                        role="listitem"
                                        draggable={mutable}
                                        onDragStart={() => setDragId(sid)}
                                        onDragOver={(event) => event.preventDefault()}
                                        onDrop={() => void onDrop(sid)}
                                        onDragEnd={() => setDragId(null)}
                                    >
                                        <img src={String(image.url || '')} alt="" className="wp-product-gallery-thumb" />
                                        {index === 0 ? (
                                            <span className="wp-product-gallery-badge">
                                                {t('editor_product_album_badge_featured')}
                                            </span>
                                        ) : null}
                                        {mutable ? (
                                            <button
                                                type="button"
                                                className="wp-product-gallery-remove"
                                                onClick={() => void removeItem(image)}
                                                title={t('editor_product_album_remove')}
                                            >
                                                <Trash2 size={12} />
                                            </button>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="text-xs text-gray-500 dark:text-gray-400">{t('editor_product_album_empty')}</p>
                    )}
                    <button
                        type="button"
                        className="wp-product-gallery-generate mt-2"
                        onClick={openGenerate}
                        disabled={!mutable}
                        title={t('generate_product_gallery_image')}
                    >
                        {t('generate_product_gallery_image')}
                    </button>
                    <button
                        type="button"
                        className="wp-product-gallery-distribute mt-2"
                        onClick={distributeToSections}
                        disabled={!mutable}
                        title={t('product_gallery_distribute')}
                    >
                        <LayoutGrid size={14} className="inline" /> {t('product_gallery_distribute')}
                    </button>
                    <button
                        type="button"
                        className="wp-product-gallery-add mt-2"
                        onClick={openPicker}
                        disabled={!mutable}
                    >
                        <Plus size={14} className="inline" /> {t('editor_product_album_add_library')}
                    </button>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {items.length === 0
                            ? t('editor_product_album_empty')
                            : t('editor_product_album_count_hint', { count: items.length })}
                    </p>
                </div>
            </section>
        </EditorModuleErrorBoundary>
    );
}
