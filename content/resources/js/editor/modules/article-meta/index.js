import { ReviewsSidebarPanel } from './ReviewsSidebarPanel';

export const articleMetaModule = {
    id: 'article-editor.article-meta',
    version: 1,
    order: 90,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.reviews',
            panelId: 'reviews',
            labelKey: 'reviews',
            label: 'Reviews',
            fullLabel: 'Reviews Assistant',
            order: 115,
            host: 'editor',
            portalRootKey: 'reviews',
            slot: 'sidebar.main',
            component: ReviewsSidebarPanel,
            keywords: ['reviews', 'rating', 'comment'],
            isVisible: (context) => {
                const type = String(context?.article?.type ?? '').toLowerCase();
                return type === 'product' || type === 'e-commerce' || type === 'product_cat';
            },
        },
    ],
    inspectors: [
        {
            id: 'inspector.article-meta',
            slot: 'inspector.document',
            order: 90,
        },
    ],
};
