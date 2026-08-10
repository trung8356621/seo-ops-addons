import { ImagesSidebarPanel } from './ImagesSidebarPanel';

export const mediaModule = {
    id: 'article-editor.media',
    version: 1,
    order: 110,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.images',
            panelId: 'images',
            labelKey: 'images',
            label: 'Images',
            fullLabel: 'Image Assistant',
            order: 110,
            host: 'editor',
            portalRootKey: 'image',
            slot: 'sidebar.main',
            component: ImagesSidebarPanel,
            keywords: ['image', 'images', 'alt', 'photo', 'picture', 'generate', 'fix'],
        },
    ],
    toolbar: [
        {
            id: 'media.toolbar.insert_image',
            group: 'media',
            order: 10,
            command: 'insert_image',
            labelKey: 'insert_image',
            iconKey: 'image',
        },
    ],
    commands: [
        { id: 'insert_image', name: 'insert_image', order: 1 },
        { id: 'replace_image', name: 'replace_image', order: 2 },
        { id: 'delete_image', name: 'delete_image', order: 3 },
        { id: 'update_image_attributes', name: 'update_image_attributes', order: 4 },
    ],
    healthProviders: [
        {
            id: 'health.images',
            order: 110,
            widgetId: 'images',
            builderKey: 'buildImagesWidgetHealth',
        },
    ],
    inspectors: [
        {
            id: 'bubble.image',
            slot: 'bubble.image',
            order: 10,
        },
    ],
};
