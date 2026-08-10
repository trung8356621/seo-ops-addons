import { SeoSidebarPanel } from './SeoSidebarPanel';

export const seoModule = {
    id: 'article-editor.seo',
    version: 1,
    order: 100,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.seo',
            panelId: 'seo',
            labelKey: 'seo',
            label: 'SEO',
            fullLabel: 'SEO Assistant',
            order: 100,
            host: 'editor',
            portalRootKey: 'seo',
            slot: 'sidebar.main',
            component: SeoSidebarPanel,
            keywords: ['seo', 'score', 'keyword', 'violation', 'check', 'focus'],
            isVisible: () => true,
        },
    ],
    healthProviders: [
        {
            id: 'health.seo',
            order: 100,
            widgetId: 'seo',
            builderKey: 'buildSeoWidgetHealth',
        },
    ],
    inspectors: [
        {
            id: 'inspector.seo-details',
            slot: 'inspector.document',
            order: 100,
        },
    ],
};
