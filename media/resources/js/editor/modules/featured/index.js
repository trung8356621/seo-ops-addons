import { FeaturedSidebarPanel } from './FeaturedSidebarPanel';

export const featuredModule = {
    id: 'article-editor.featured',
    version: 1,
    order: 120,
    dependsOn: ['article-editor.core'],
    optionalDependsOn: ['article-editor.media'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.featured',
            panelId: 'featured',
            labelKey: 'featured',
            label: 'Featured',
            fullLabel: 'Featured Image',
            order: 120,
            host: 'editor',
            portalRootKey: 'featured',
            slot: 'sidebar.main',
            component: FeaturedSidebarPanel,
            keywords: ['featured', 'thumbnail', 'cover', 'album', 'gallery', 'product', 'đại diện'],
        },
    ],
    healthProviders: [
        {
            id: 'health.featured',
            order: 120,
            widgetId: 'featured',
            builderKey: 'buildFeaturedWidgetHealth',
        },
    ],
};
