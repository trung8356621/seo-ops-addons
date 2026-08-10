/**
 * Gallery module — health + ownership metadata.
 * Product album UI renders inside Featured chip panel (parity); navChip stays false.
 */

export const galleryModule = {
    id: 'article-editor.gallery',
    version: 1,
    order: 130,
    dependsOn: ['article-editor.core'],
    optionalDependsOn: ['article-editor.media'],
    isEnabled: (context) => {
        const type = String(context?.article?.type ?? '').toLowerCase();
        if (type === 'product' || type === 'product_cat' || type === 'e-commerce') return true;
        return Boolean(context?.snapshots?.media?.gallery?.items?.length);
    },
    sidebar: [
        {
            id: 'sidebar.gallery',
            panelId: 'product-album',
            labelKey: 'gallery',
            label: 'Gallery',
            fullLabel: 'Product Album',
            order: 130,
            host: 'editor',
            portalRootKey: 'featured',
            slot: 'sidebar.main',
            // UI owned by FeaturedSidebarPanel for product posts — avoid duplicate portal.
            navChip: false,
            note: 'Gallery UI mounts via Featured chip when post type is product.',
        },
    ],
    healthProviders: [
        {
            id: 'health.gallery',
            order: 130,
            widgetId: 'gallery',
            builderKey: 'buildGalleryWidgetHealth',
        },
    ],
};
