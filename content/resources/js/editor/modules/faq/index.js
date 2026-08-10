import { FaqSidebarPanel } from './FaqSidebarPanel';

export const faqModule = {
    id: 'article-editor.faq',
    version: 1,
    order: 150,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.faq',
            panelId: 'faq',
            labelKey: 'faq',
            label: 'FAQ',
            fullLabel: 'FAQ Assistant',
            order: 150,
            host: 'editor',
            portalRootKey: 'faq',
            slot: 'sidebar.main',
            component: FaqSidebarPanel,
            // FAQ opens from shortcode / toolbar — not assistant dock chip (parity).
            navChip: false,
        },
    ],
    toolbar: [
        {
            id: 'faq.toolbar.extract',
            group: 'insert',
            order: 80,
            labelKey: 'faq_extract',
            command: 'faq_extract_selection',
            note: 'Custom toolbar contribution — BlockFormatToolbar reads registry id faq.toolbar.extract',
        },
    ],
    commands: [
        { id: 'insert_faq_placeholder', name: 'insert_faq_placeholder', order: 1 },
        { id: 'remove_faq_placeholder', name: 'remove_faq_placeholder', order: 2 },
        { id: 'apply_faq_fragment', name: 'apply_faq_fragment', order: 3 },
    ],
};
