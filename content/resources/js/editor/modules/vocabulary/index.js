import { BookText } from 'lucide-react';
import { VocabularySidebarPanel } from './VocabularySidebarPanel';

export const vocabularyModule = {
    id: 'article-editor.vocabulary',
    version: 1,
    order: 145,
    dependsOn: ['article-editor.core'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.vocabulary',
            panelId: 'vocabulary',
            labelKey: 'vocabulary',
            label: 'Vocabulary',
            fullLabel: 'Vocabulary',
            order: 145,
            host: 'editor',
            portalRootKey: 'vocabulary',
            slot: 'sidebar.main',
            component: VocabularySidebarPanel,
            keywords: ['vocabulary', 'keywords', 'semantic', 'terms'],
        },
    ],
};
