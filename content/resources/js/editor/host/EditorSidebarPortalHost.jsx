import React, { Suspense, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { EditorModuleErrorBoundary } from '../runtime/EditorModuleErrorBoundary';
import { getEditorCommandHost } from '../../utils/editorCommands';
import { t } from '../../utils/i18n';

function resolvePanelActive(activePanelId, entry) {
    const panelId = String(entry.panelId || '');
    const aliasOf = String(entry.aliasPanelId || '');
    if (activePanelId === panelId) {
        return true;
    }
    // CTA chip aliases Links panel body (no duplicate portal).
    if (aliasOf && activePanelId === panelId && entry.component) {
        return true;
    }
    if (!entry.component && aliasOf && activePanelId === panelId) {
        return false;
    }
    // When CTA active, Links entry (panelId links) must mount.
    if (panelId === 'links' && (activePanelId === 'links' || activePanelId === 'cta')) {
        return true;
    }
    return activePanelId === panelId;
}

/**
 * Render editor-hosted sidebar panels from runtime registry into portal roots.
 */
export function EditorSidebarPortalHost({
    runtime,
    activePanelId,
    portalRoots = {},
    shells = {},
    isPanelAllowed = () => true,
    articleId = null,
    siteId = null,
}) {
    const entries = useMemo(() => {
        if (!runtime?.getSidebarEntries) return [];
        return runtime.getSidebarEntries().filter((entry) => entry.host === 'editor');
    }, [runtime]);

    const hostArticleId = articleId ?? getEditorCommandHost()?.articleId ?? null;
    const hostSiteId = siteId ?? window.__SEO_EDITOR_SITE_ID__ ?? null;

    return (
        <>
            {entries.map((entry) => {
                const panelId = String(entry.panelId || '');
                const rootKey = String(entry.portalRootKey || panelId);
                const root = portalRoots[rootKey] || null;
                // Alias-only entries (CTA) share Links portal — skip empty component mount.
                if (!entry.component) {
                    return null;
                }
                if (!root || !isPanelAllowed(panelId, entry)) {
                    return null;
                }

                const Shell = shells[panelId] || shells.default || React.Fragment;
                const Comp = entry.component;
                const active = resolvePanelActive(activePanelId, entry);
                // Avoid double-portal when CTA + Links share root — only Links has component.
                if (panelId === 'cta') {
                    return null;
                }
                const body = active && Comp
                    ? (
                        <EditorModuleErrorBoundary moduleId={entry.moduleId} slotName="sidebar.main">
                            <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                                <Comp
                                    entry={entry}
                                    active={active}
                                    articleId={hostArticleId}
                                    siteId={hostSiteId}
                                />
                            </Suspense>
                        </EditorModuleErrorBoundary>
                    )
                    : (
                        <div className="seo-assistant-widget__lazy-placeholder">
                            {t('editor_panel_lazy_placeholder')}
                        </div>
                    );

                return createPortal(
                    <Shell panelId={panelId} entry={entry} active={active}>
                        {body}
                    </Shell>,
                    root,
                    `runtime-panel-${panelId}`,
                );
            })}
        </>
    );
}
