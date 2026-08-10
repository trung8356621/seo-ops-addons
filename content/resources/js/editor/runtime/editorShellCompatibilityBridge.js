/**
 * Phase 6B–6C.4 — sole window CustomEvent adapter for Laravel/Alpine shell ↔ runtime.
 * Adapter does NOT own active panel, health, TipTap transactions, or snapshots.
 *
 * Flow: shell event → bridge → openPanel / host.actions (never Alpine SoT).
 */

import {
    closePanel,
    focusReason,
    getActivePanel,
    openPanel,
    subscribeEditorNavigation,
} from './editorRuntimeNavigation';
import {
    MODULE_EVENT_OPEN,
    MODULE_EVENT_SWITCH,
    normalizeHeavyModuleId,
} from '../../utils/articleEditorModules';
import { getEditorCommandHost } from '../../utils/editorCommands';
import { runFaqExtractFromToolbar } from '../modules/faq/faqExtractToolbarAction';

const INSTALLED_FLAG = '__SEO_EDITOR_SHELL_BRIDGE_INSTALLED__';

/**
 * Deprecated browser events still accepted at shell boundary.
 * replacement: preferred runtime API; consumer: who still fires; phase: planned removal.
 */
export const SHELL_COMPAT_DEPRECATED_EVENTS = Object.freeze([
    {
        event: 'seo-assistant-switch-panel',
        replacement: 'openPanel / useEditorNavigation',
        consumer: 'shell / help / widgets',
        phase: 'post-6c4',
    },
    {
        event: 'seo-editor-active-module',
        replacement: 'subscribeEditorNavigation',
        consumer: 'legacy mirrors',
        phase: 'post-6c4',
    },
    {
        event: 'article-editor:module-open',
        replacement: 'openPanel',
        consumer: 'FAQ shortcode / help',
        phase: 'post-6c4',
    },
    {
        event: 'seo-assistant-open-publishing',
        replacement: 'openPanel(publishing)',
        consumer: 'publish chrome',
        phase: 'keep-shell',
    },
    {
        event: 'seo-sidebar-open-publish-tab',
        replacement: 'openPanel(publishing)',
        consumer: 'publish chrome',
        phase: 'keep-shell',
    },
    {
        event: 'seo-editor-insert-suggested-link',
        replacement: 'host.actions.insertSuggestedLink',
        consumer: 'external mid-rollout',
        phase: 'post-6c4',
    },
    {
        event: 'seo-editor-insert-cta-link',
        replacement: 'host.actions.insertCtaLink',
        consumer: 'external mid-rollout',
        phase: 'post-6c4',
    },
    {
        event: 'extract-article-faqs-from-toolbar',
        replacement: 'runFaqExtractFromToolbar',
        consumer: 'legacy toolbar',
        phase: 'post-6c4',
    },
    {
        event: 'extract-article-faqs',
        replacement: 'runFaqExtractFromToolbar',
        consumer: 'legacy',
        phase: 'post-6c4',
    },
    {
        event: 'seo-article-ai-chat-open',
        replacement: 'openPanel(ai-chat) / useEditorAi.open',
        consumer: 'FAB / Alpine layout / BlockInsertMenu',
        phase: 'post-6c4',
    },
    {
        event: 'seo-article-ai-chat-close',
        replacement: 'closePanel / useEditorAi.close',
        consumer: 'Alpine layout',
        phase: 'post-6c4',
    },
    {
        event: 'seo-open-article-media-picker',
        replacement: 'openMediaPicker(content_image)',
        consumer: 'legacy media openers',
        phase: 'post-6c4',
    },
    {
        event: 'seo-open-featured-media-picker',
        replacement: 'openMediaPicker(featured)',
        consumer: 'legacy',
        phase: 'post-6c4',
    },
    {
        event: 'seo-open-gallery-media-picker',
        replacement: 'openMediaPicker(gallery)',
        consumer: 'legacy',
        phase: 'post-6c4',
    },
    {
        event: 'seo-article-editor-notify',
        replacement: 'useEditorNotifications',
        consumer: 'shell toast adapter',
        phase: 'keep-shell-adapter',
    },
    {
        event: 'generate-article-image',
        replacement: 'host.actions.generateArticleImage',
        consumer: 'legacy generate triggers',
        phase: 'post-6c4',
    },
    {
        event: 'generate-article-video',
        replacement: 'host.actions.generateArticleVideo',
        consumer: 'legacy generate triggers',
        phase: 'post-6c4',
    },
]);

/** @deprecated string list for older tests */
export const SHELL_COMPAT_DEPRECATED_EVENT_NAMES = Object.freeze(
    SHELL_COMPAT_DEPRECATED_EVENTS.map((row) => row.event),
);

/**
 * @returns {() => void} uninstall
 */
export function installEditorShellCompatibilityBridge() {
    if (typeof window === 'undefined') {
        return () => {};
    }
    if (window[INSTALLED_FLAG]) {
        return () => {};
    }
    window[INSTALLED_FLAG] = true;

    const onSwitch = (event) => {
        if (event?.detail?.closed === true || event?.detail?.panel == null || event?.detail?.panel === '') {
            closePanel({ source: 'shell_switch', closed: true });
            return;
        }
        const raw = event?.detail?.panel ?? event?.detail?.widgetId ?? event?.detail?.module;
        openPanel(raw, { source: 'shell_switch', detail: event?.detail || {} });
    };

    const onModuleOpen = (event) => {
        const raw = event?.detail?.module ?? event?.detail?.panel ?? event?.detail?.widgetId;
        const panel = normalizeHeavyModuleId(raw) || raw;
        if (!panel) return;
        openPanel(panel, { source: 'shell_module_open', detail: event?.detail || {} });
    };

    const onFocusReason = (event) => {
        // Runtime focusReason already emits this event — never call focusReason again.
        if (event?.detail?.fromRuntime === true) {
            return;
        }
        if (event?.detail?.reason === 'focus_reason' || event?.detail?.source === 'shell_focus_reason') {
            return;
        }
        const code = event?.detail?.code ?? event?.detail?.reasonCode;
        if (!code) {
            return;
        }
        // External/shell emitters only — mark fromRuntime so re-entry is impossible.
        focusReason(code, { source: 'shell_focus_reason', fromRuntime: true, detail: event?.detail || {} });
    };

    const onOpenPublishing = () => {
        openPanel('publishing', { source: 'shell_publishing' });
    };

    const onInsertSuggested = (event) => {
        getEditorCommandHost()?.actions?.insertSuggestedLink?.(event?.detail ?? {});
    };

    const onInsertCta = (event) => {
        getEditorCommandHost()?.actions?.insertCtaLink?.(event?.detail ?? {});
    };

    const onFaqExtractLegacy = () => {
        void runFaqExtractFromToolbar();
    };

    const onAiOpen = (event) => {
        if (event?.detail?.fromRuntime === true) return;
        openPanel('ai-chat', {
            source: 'shell_ai_open',
            detail: event?.detail && typeof event.detail === 'object' ? event.detail : {},
        });
    };

    const onAiClose = (event) => {
        if (event?.detail?.fromRuntime === true) return;
        if (getActivePanel() === 'ai-chat') {
            closePanel({ source: 'shell_ai_close' });
        }
    };

    // Alpine rail layout: mirror active panel without owning SoT.
    const unsubNavLayout = subscribeEditorNavigation((panelId, meta = {}) => {
        if (panelId === 'ai-chat') {
            if (meta?.source === 'shell_ai_open') return;
            window.dispatchEvent(new CustomEvent('seo-article-ai-chat-open', {
                detail: { ...(meta?.detail || {}), fromRuntime: true },
            }));
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-article-ai-chat-close', {
            detail: { fromRuntime: true },
        }));
    });

    window.addEventListener(MODULE_EVENT_SWITCH, onSwitch);
    window.addEventListener(MODULE_EVENT_OPEN, onModuleOpen);
    window.addEventListener('seo-assistant-focus-reason', onFocusReason);
    window.addEventListener('seo-assistant-open-publishing', onOpenPublishing);
    window.addEventListener('seo-sidebar-open-publish-tab', onOpenPublishing);
    window.addEventListener('seo-editor-insert-suggested-link', onInsertSuggested);
    window.addEventListener('seo-editor-insert-cta-link', onInsertCta);
    window.addEventListener('extract-article-faqs-from-toolbar', onFaqExtractLegacy);
    window.addEventListener('extract-article-faqs', onFaqExtractLegacy);
    window.addEventListener('seo-article-ai-chat-open', onAiOpen);
    window.addEventListener('seo-article-ai-chat-close', onAiClose);

    return () => {
        unsubNavLayout();
        window.removeEventListener(MODULE_EVENT_SWITCH, onSwitch);
        window.removeEventListener(MODULE_EVENT_OPEN, onModuleOpen);
        window.removeEventListener('seo-assistant-focus-reason', onFocusReason);
        window.removeEventListener('seo-assistant-open-publishing', onOpenPublishing);
        window.removeEventListener('seo-sidebar-open-publish-tab', onOpenPublishing);
        window.removeEventListener('seo-editor-insert-suggested-link', onInsertSuggested);
        window.removeEventListener('seo-editor-insert-cta-link', onInsertCta);
        window.removeEventListener('extract-article-faqs-from-toolbar', onFaqExtractLegacy);
        window.removeEventListener('extract-article-faqs', onFaqExtractLegacy);
        window.removeEventListener('seo-article-ai-chat-open', onAiOpen);
        window.removeEventListener('seo-article-ai-chat-close', onAiClose);
        window[INSTALLED_FLAG] = false;
    };
}
