import React, { Suspense, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { EditorModuleErrorBoundary } from '../runtime/EditorModuleErrorBoundary';
import { isMainColumnOnlyPanel } from '../runtime/mainColumnPanels';
import { getEditorCommandHost } from '../../utils/editorCommands';
import { t } from '../../utils/i18n';
import { ensureEditorSidebarPortalRoot } from './editorSidebarPortalRoots';

function resolvePanelActive(activePanelId, entry, sidebarRailPanelId = 'seo') {
    const panelId = String(entry.panelId || '');
    const aliasOf = String(entry.aliasPanelId || '');
    const active = String(activePanelId || '');
    const railFallback = String(sidebarRailPanelId || 'seo');

    // Main-column FAQ surface can be active while sidebar rail stays on SEO/etc.
    if (isMainColumnOnlyPanel(panelId)) {
        return active === panelId;
    }

    // When heavy module is cleared (close/shell) but Alpine rail still shows a panel,
    // keep mounting that rail body — otherwise Reviews/SEO render an empty bordered slot.
    const railId = isMainColumnOnlyPanel(active)
        ? railFallback
        : (active || railFallback);

    if (railId === panelId) {
        return true;
    }
    // CTA chip aliases Links panel body (no duplicate portal).
    if (aliasOf && railId === panelId && entry.component) {
        return true;
    }
    if (!entry.component && aliasOf && railId === panelId) {
        return false;
    }
    // When CTA active, Links entry (panelId links) must mount.
    if (panelId === 'links' && (railId === 'links' || railId === 'cta')) {
        return true;
    }
    return railId === panelId;
}

/**
 * Render editor-hosted sidebar panels from runtime registry into portal roots.
 */
export function EditorSidebarPortalHost({
    runtime,
    activePanelId,
    sidebarRailPanelId = 'seo',
    portalRoots = {},
    shells = {},
    isPanelAllowed = () => true,
    articleId = null,
    siteId = null,
}) {
    const entries = useMemo(() => {
        if (!runtime?.getSidebarEntries) return [];
        return runtime.getSidebarEntries().filter((entry) => entry.host === 'editor');
    }, [runtime, activePanelId, sidebarRailPanelId]);

    const hostArticleId = articleId ?? getEditorCommandHost()?.articleId ?? null;
    const hostSiteId = siteId ?? window.__SEO_EDITOR_SITE_ID__ ?? null;

    return (
        <>
            {entries.map((entry) => {
                const panelId = String(entry.panelId || '');
                const rootKey = String(entry.portalRootKey || panelId);
                // Alias-only entries (CTA) share Links portal — skip empty component mount.
                if (!entry.component) {
                    return null;
                }
                if (!isPanelAllowed(panelId, entry)) {
                    return null;
                }

                const preferred = portalRoots[rootKey] || portalRoots[panelId] || null;
                const root = ensureEditorSidebarPortalRoot(rootKey, panelId, preferred);
                if (!root) {
                    return null;
                }

                const Shell = shells[panelId] || shells.default || React.Fragment;
                const Comp = entry.component;
                const active = resolvePanelActive(activePanelId, entry, sidebarRailPanelId);
                // Avoid double-portal when CTA + Links share root — only Links has component.
                if (panelId === 'cta') {
                    return null;
                }
                // Reviews always mounts its body (not lazy placeholder). Alpine can show the
                // reviews slot as is-active while React heavy-id briefly lags — placeholder
                // then looks like a blank white card under the chips.
                const shouldMountBody = active || panelId === 'reviews';
                const body = shouldMountBody && Comp
                    ? (
                        <EditorModuleErrorBoundary moduleId={entry.moduleId} slotName="sidebar.main">
                            <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                                <Comp
                                    entry={entry}
                                    active={active || panelId === 'reviews'}
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
