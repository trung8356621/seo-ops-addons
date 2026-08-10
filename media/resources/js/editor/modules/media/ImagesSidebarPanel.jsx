import React, { lazy, Suspense } from 'react';
import { useEditorHostApi } from '@content-addon/editor/host/EditorHostApiContext.jsx';
import { t } from '@content-addon/utils/i18n.js';

const ImagesModule = lazy(() => import('../../../modules/ImagesModule'));

export function ImagesSidebarPanel() {
    const api = useEditorHostApi();
    const images = api.images || {};

    return (
        <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
            <ImagesModule
                key={images.reloadKey}
                blocks={images.blocks}
                extraImages={images.extraImages}
                featuredImage={images.featuredImage}
                galleryImages={images.galleryImages}
                useUnifiedInventory={images.useUnifiedInventory === true}
                siteId={images.siteId}
                articleId={images.articleId}
                jumpTarget={images.jumpTarget}
                focusKeyword={images.focusKeyword}
                articleTitle={images.articleTitle}
                onPatchImage={images.onPatchImage}
                onFocusBlock={images.onFocusBlock}
                onQuickFixSlugAll={images.onQuickFixSlugAll}
                quickFixSlugAllBusy={images.quickFixSlugAllBusy}
                onQuickFixSlugOne={images.onQuickFixSlugOne}
                onQuickFixAltTitleAll={images.onQuickFixAltTitleAll}
                onQuickFixAltTitleOne={images.onQuickFixAltTitleOne}
                onRemoveImage={images.onRemoveImage}
                onRemoveSupplementalImage={images.onRemoveSupplementalImage}
                onAltTitleChange={images.onAltTitleChange}
                onMakeFeatured={images.onMakeFeatured}
                onNotify={images.onNotify}
            />
        </Suspense>
    );
}
