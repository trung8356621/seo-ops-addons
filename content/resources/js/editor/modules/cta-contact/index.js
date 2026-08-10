/**
 * CTA/Contact module — owns insert semantics + settings UI inside Links panel.
 * Nav chip aliases Links panel (panelId cta → show links portal). No duplicate portal root.
 */

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
            fullLabel: 'CTA Assistant',
            order: 145,
            // Alias: navigation opens cta; PortalHost maps cta → links panel body.
            host: 'editor',
            portalRootKey: 'links',
            aliasPanelId: 'links',
            slot: 'sidebar.main',
            linkSection: 'cta',
            keywords: ['cta', 'call', 'phone'],
            note: 'CTA chip aliases Links host; CtaContactInsertList owns insert via command layer.',
        },
    ],
    commands: [
        { id: 'insert_contact_cta', name: 'insert_contact_cta', order: 1 },
        { id: 'insert_contact_value', name: 'insert_contact_value', order: 2 },
    ],
};
