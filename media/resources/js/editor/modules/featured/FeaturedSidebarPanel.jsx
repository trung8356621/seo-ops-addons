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
            notify({
                title: t('editor_featured_notify_title'),
                body: t('editor_featured_read_only'),
                status: 'warning',
            });
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
                    notify({ title: t('make_featured_image_success'), status: 'success' });
                } catch (error) {
                    notify({
                        title: t('make_featured_image_failed'),
                        body: String(error?.message || t('prompt_hook_try_again')),
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
            notify({ title: t('editor_featured_cleared'), status: 'success' });
        } catch (error) {
            notify({
                title: t('editor_featured_clear_failed'),
                body: String(error?.message || t('prompt_hook_try_again')),
                status: 'warning',
            });
        }
    }, [mutable, media, notify]);

    if (!active) {
        return <div className="seo-assistant-widget__lazy-placeholder">{t('editor_panel_lazy_placeholder')}</div>;
    }

    const caption = featured?.url
        ? `${featured.filename || featured.alt || t('editor_featured_title')} · snapshot v${media.snapshotVersion}`
        : t('editor_featured_empty_hint');

    return (
        <EditorModuleErrorBoundary moduleId="article-editor.featured" slotName="sidebar.main">
            <section className="seo-assistant-widget seo-assistant-widget--featured-image seo-assistant-widget--static">
                <header className="seo-assistant-widget__header seo-assistant-widget__header--static">
                    <div className="seo-assistant-widget__toggle seo-assistant-widget__toggle--static">
                        <span className="seo-assistant-widget__title">{t('editor_featured_title')}</span>
                    </div>
                </header>
                <div className="seo-assistant-widget__body text-center">
                    <div
                        className={featured?.url ? 'wp-featured-image-picker' : 'hidden'}
                        title={t('editor_featured_pick_title')}
                    >
                        {featured?.url ? (
                            <img
                                src={String(featured.url)}
                                alt={String(featured.alt || t('editor_featured_title'))}
                                className="wp-featured-image-picker__img"
                            />
                        ) : (
                            <span className="wp-featured-image-picker__empty">
                                <ImagePlus className="mx-auto h-12 w-12 opacity-40" />
                                <span className="wp-featured-image-picker__label">{t('editor_featured_pick')}</span>
                            </span>
                        )}
                    </div>
                    <p className={featured?.url ? 'mt-2 text-xs text-gray-500 dark:text-gray-400' : 'hidden'}>
                        {caption}
                    </p>
                    {mutable ? (
                        <button
                            type="button"
                            className="mt-2 inline-flex items-center gap-1 text-sm text-primary-600"
                            onClick={openPicker}
                            title={t('editor_featured_pick_title')}
                        >
                            <ImagePlus size={14} />
                            {featured?.url ? t('editor_featured_change') : t('editor_featured_pick')}
                        </button>
                    ) : null}
                    {featured?.url && mutable ? (
                        <button
                            type="button"
                            className="mt-2 inline-flex items-center gap-1 text-sm text-danger-600"
                            onClick={() => void clear()}
                        >
                            <Trash2 size={14} /> {t('editor_featured_clear')}
                        </button>
                    ) : null}
                </div>
            </section>
        </EditorModuleErrorBoundary>
    );
}
