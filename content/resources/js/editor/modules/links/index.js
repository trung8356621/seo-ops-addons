import { LinksSidebarPanel } from './LinksSidebarPanel';
import LinkEditBubble from '../../../components/LinkEditBubble';

export const linksModule = {
    id: 'article-editor.links',
    version: 1,
    order: 140,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.links',
            panelId: 'links',
            labelKey: 'links',
            label: 'Links',
            fullLabel: 'Link Assistant',
            order: 140,
            host: 'editor',
            portalRootKey: 'links',
            slot: 'sidebar.main',
            component: LinksSidebarPanel,
            keywords: ['link', 'links', 'internal', 'external', 'href'],
        },
    ],
    commands: [
        { id: 'create_link', name: 'create_link', order: 1 },
        { id: 'update_link', name: 'update_link', order: 2 },
        { id: 'remove_link_keep_text', name: 'remove_link_keep_text', order: 3 },
        { id: 'insert_link', name: 'insert_link', order: 4 },
        { id: 'exit_link_at_boundary', name: 'exit_link_at_boundary', order: 5 },
    ],
    healthProviders: [
        {
            id: 'health.links',
            order: 140,
            widgetId: 'links',
            builderKey: 'buildLinksWidgetHealth',
        },
    ],
    inspectors: [
        {
            id: 'bubble.link',
            slot: 'bubble.link',
            order: 10,
            component: LinkEditBubble,
        },
    ],
    shortcuts: [
        {
            id: 'links.shortcut.unlink',
            order: 50,
            keys: 'Mod-Shift-k',
            command: 'remove_link_keep_text',
        },
    ],
};
