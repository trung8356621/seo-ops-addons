/**
 * Phase 6C.1 — Alpine shell panel-visibility adapter only.
 * React runtime owns chips, active panel SoT, and health badges.
 * This Alpine data must NOT own writable chips/activePanel/widgetHealth/badges.
 */

import { subscribeEditorNavigation } from '@content-addon/editor/runtime/editorRuntimeNavigation.js';
import { isMainColumnOnlyPanel } from '@content-addon/editor/runtime/mainColumnPanels.js';

function isProductPostType(postType) {
    const normalized = String(postType ?? '').trim().toLowerCase();

    return normalized === 'product' || normalized === 'e-commerce';
}

function resolveSidebarRailPanel(panelId, previous = 'seo') {
    const id = String(panelId ?? '').trim();
    if (id === '' || isMainColumnOnlyPanel(id)) {
        return previous || 'seo';
    }
    if (id === 'cta') {
        return 'links';
    }

    return id;
}

/**
 * @param {{ postType?: string, supportsProductGallery?: boolean }} [initial]
 */
export function createSeoAssistantNavigator(initial = {}) {
    const initialPostType = String(initial?.postType ?? 'article').trim() || 'article';
    const initialSupportsGallery = initial?.supportsProductGallery !== undefined
        ? Boolean(initial.supportsProductGallery)
        : isProductPostType(initialPostType);

    return {
        /** Read-only mirror for x-show reactivity — SoT is runtime navigation. */
        runtimeActivePanel: 'seo',
        /**
         * Sidebar rail visibility SoT. FAQ (main-column) must not clear this,
         * or SEO / FAQ-schema UI vanishes when Edit FAQ opens.
         */
        sidebarRailPanel: 'seo',
        panelFilterActive: true,
        editorPostType: initialPostType,
        supportsProductGalleryUi: initialSupportsGallery,
        _unsubNav: null,

        initWorkspace() {
            const onDestroy = () => {
                this.destroyWorkspace();
            };
            this.$el.addEventListener('alpine:destroying', onDestroy, { once: true });

            this._unsubNav = subscribeEditorNavigation((panelId, meta = {}) => {
                this.runtimeActivePanel = panelId || '';
                this.panelFilterActive = true;
                this.sidebarRailPanel = resolveSidebarRailPanel(panelId, this.sidebarRailPanel);

                // Keep Links section sync for ModuleHost until 6C.2.
                if (typeof window !== 'undefined' && meta?.source !== 'react_nav') {
                    let section = 'all';
                    if (panelId === 'cta') {
                        section = 'cta';
                    } else if (panelId === 'links') {
                        section = 'links';
                    }
                    window.dispatchEvent(new CustomEvent('seo-assistant-link-section', {
                        detail: { section },
                    }));
                }
            });

            this._onPostTypeChanged = (event) => {
                const nextType = String(event?.detail?.postType ?? event?.detail?.post_type ?? '').trim();
                if (nextType === '') {
                    return;
                }
                this.applyEditorPostType(nextType);
            };

            window.addEventListener('seo-publish-post-type-changed', this._onPostTypeChanged);
        },

        applyEditorPostType(postType) {
            const nextType = String(postType ?? '').trim() || 'article';
            const nextSupports = isProductPostType(nextType);
            if (this.editorPostType === nextType && this.supportsProductGalleryUi === nextSupports) {
                return;
            }

            this.editorPostType = nextType;
            this.supportsProductGalleryUi = nextSupports;
        },

        destroyWorkspace() {
            if (typeof this._unsubNav === 'function') {
                this._unsubNav();
                this._unsubNav = null;
            }

            if (this._onPostTypeChanged) {
                window.removeEventListener('seo-publish-post-type-changed', this._onPostTypeChanged);
            }
        },

        isWidgetVisible(widgetId) {
            if (!this.panelFilterActive) {
                return true;
            }

            const active = this.sidebarRailPanel || 'seo';
            if (!active) {
                return false;
            }

            if (active === widgetId) {
                return true;
            }

            // CTA chip shows Links host panel.
            if (widgetId === 'links' && active === 'cta') {
                return true;
            }

            return false;
        },
    };
}

function registerSeoAssistantNavigator() {
    if (window.__seoAssistantNavigatorRegistered) {
        return;
    }

    window.__seoAssistantNavigatorRegistered = true;

    if (!window.Alpine?.data) {
        return;
    }

    window.Alpine.data('seoAssistantNavigator', createSeoAssistantNavigator);
}

document.addEventListener('alpine:init', registerSeoAssistantNavigator);
registerSeoAssistantNavigator();
