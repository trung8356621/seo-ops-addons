import React, { useCallback } from 'react';
import { ImagePlus, Trash2 } from 'lucide-react';
import { EditorModuleErrorBoundary } from '@content-addon/editor/runtime/EditorModuleErrorBoundary.jsx';
import { useEditorHostApiOptional } from '@content-addon/editor/host/EditorHostApiContext.jsx';
import { useEditorMedia } from '../../host/hooks/useEditorMedia';
import { useEditorMediaPicker } from '../../host/hooks/useEditorMediaPicker';
import { useEditorNotifications } from '@content-addon/editor/host/hooks/useEditorNotifications.js';
import { GallerySidebarPanel } from '../gallery/GallerySidebarPanel';
import { normalizeFeaturedMediaItem } from '@content-addon/utils/articleEditorMediaSnapshot.js';
import { t } from '@content-addon/utils/i18n.js';

function isProductType(type, supportsProductGallery = false) {
    if (supportsProductGallery) {
        return true;
    }
    const normalized = String(type ?? '').toLowerCase();
    return normalized === 'product' || normalized === 'e-commerce' || normalized === 'product_cat';
}

/**
 * Featured chip panel — Featured image OR product album (parity with prior Alpine slots).
 * Host API only — never import defaultArticleEditorRuntime (circular with modules registry).
 */
export function FeaturedSidebarPanel({ articleId = null, active = false }) {
    const host = useEditorHostApiOptional();
    const runtimeType = host?.article?.type ?? null;
    const supportsProductGallery = Boolean(host?.article?.supportsProductGallery);

    if (isProductType(runtimeType, supportsProductGallery)) {
        return <GallerySidebarPanel articleId={articleId} active={active} />;
    }

    return <FeaturedImagePanel articleId={articleId} active={active} />;
}

function FeaturedImagePanel({ articleId = null, active = false }) {
    const media = useEditorMedia(articleId);
    const picker = useEditorMediaPicker();
    const { notify } = useEditorNotifications();
    const featured = media.featured;
    const mutable = media.canMutate();

    const openPicker = useCallback(() => {
        if (!mutable) {
            notify({ title: 'Featured', body: 'Read-only', status: 'warning' });
            return;
        }
        picker.open({
            mode: 'featured',
            selection: 'single',
            onConfirm: async (items) => {
                const item = normalizeFeaturedMediaItem(items[0]);
                if (!item?.url) return;
                try {
                    await media.setFeatured(item);
                    notify({ title: t('make_featured_image_success') || 'Featured updated', status: 'success' });
                } catch (error) {
                    notify({
                        title: t('make_featured_image_failed') || 'Featured failed',
                        body: String(error?.message || 'error'),
                        status: 'warning',
                    });
                }
            },
        });
    }, [mutable, picker, media, notify]);

    const clear = useCallback(async () => {
        if (!mutable) return;
        try {
            await media.clearFeatured();
            notify({ title: 'Featured cleared', status: 'success' });
        } catch (error) {
            notify({ title: 'Clear failed', body: String(error?.message || 'error'), status: 'warning' });
        }
    }, [mutable, media, notify]);

    if (!active) {
        return <div className="seo-assistant-widget__lazy-placeholder">{t('editor_panel_lazy_placeholder')}</div>;
    }

    return (
        <EditorModuleErrorBoundary moduleId="article-editor.featured" slotName="sidebar.main">
            <section className="seo-assistant-widget seo-assistant-widget--featured-image seo-assistant-widget--static">
                <header className="seo-assistant-widget__header seo-assistant-widget__header--static">
                    <div className="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                        <span className="seo-assistant-widget__title">Ảnh đại diện</span>
                    </div>
                </header>
                <div className="seo-assistant-widget__body text-center">
                    <div
                        className={featured?.url ? 'wp-featured-image-picker' : 'hidden'}
                        title="Chọn ảnh từ thư viện"
                    >
                        {featured?.url ? (
                            <img src={String(featured.url)} alt={String(featured.alt || 'Featured')} className="wp-featured-image-picker__img" />
                        ) : (
                            <span className="wp-featured-image-picker__empty">
                                <ImagePlus className="mx-auto h-12 w-12 opacity-40" />
                                <span className="wp-featured-image-picker__label">Đặt ảnh đại diện</span>
                            </span>
                        )}
                    </div>
                    <p className={featured?.url ? 'mt-2 text-xs text-gray-500 dark:text-gray-400' : 'hidden'}>
                        {featured?.url
                            ? `${featured.filename || featured.alt || 'Featured'} · snapshot v${media.snapshotVersion}`
                            : 'Bấm để chọn từ Shared Media Picker'}
                    </p>
                    {mutable ? (
                        <button
                            type="button"
                            className="mt-2 inline-flex items-center gap-1 text-sm text-primary-600"
                            onClick={openPicker}
                            title="Chon anh tu thu vien"
                        >
                            <ImagePlus size={14} />
                            {featured?.url ? 'Thay anh dai dien' : 'Chon anh dai dien'}
                        </button>
                    ) : null}
                    {featured?.url && mutable ? (
                        <button type="button" className="mt-2 inline-flex items-center gap-1 text-sm text-danger-600" onClick={() => void clear()}>
                            <Trash2 size={14} /> Clear
                        </button>
                    ) : null}
                </div>
            </section>
        </EditorModuleErrorBoundary>
    );
}
