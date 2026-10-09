/**
 * CTA Automation — independent right-sidebar widget, mounted after Link Assistant.
 */

import { CtaAutomationSidebarPanel } from './CtaAutomationSidebarPanel';

export const ctaContactModule = {
    id: 'article-editor.cta-contact',
    version: 1,
    order: 160,
    dependsOn: ['article-editor.core'],
    optionalDependsOn: ['article-editor.links'],
    isEnabled: () => true,
    sidebar: [
        {
            id: 'sidebar.cta',
            panelId: 'cta',
            labelKey: 'cta',
            label: 'CTA',
            fullLabel: 'CTA Automation',
            order: 145,
            host: 'editor',
            portalRootKey: 'cta',
            slot: 'sidebar.main',
            component: CtaAutomationSidebarPanel,
            keywords: ['cta', 'call', 'phone'],
            note: 'Independent CTA Automation widget. Contact configuration stays in Site settings.',
        },
    ],
    commands: [
        { id: 'insert_contact_cta', name: 'insert_contact_cta', order: 1 },
        { id: 'insert_contact_value', name: 'insert_contact_value', order: 2 },
    ],
};
