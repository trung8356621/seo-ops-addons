import {
    MODULE_EVENT_SWITCH,
    dispatchActiveModule,
    normalizeHeavyModuleId,
} from '../utils/articleEditorModules';

/**
 * @param {{ type?: string, id?: string }|null|undefined} target
 */
export function navigateHelpTarget(target) {
    if (!target || typeof target !== 'object') {
        return;
    }

    const id = String(target.id ?? '').trim();
    if (id === '') {
        return;
    }

    if (target.type === 'module') {
        const moduleId = normalizeHeavyModuleId(id);
        if (!moduleId) {
            return;
        }

        if (moduleId === 'publishing') {
            window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
            window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
            return;
        }

        window.dispatchEvent(
            new CustomEvent(MODULE_EVENT_SWITCH, {
                detail: { panel: moduleId, module: moduleId },
            }),
        );
        dispatchActiveModule(moduleId);

        window.setTimeout(() => {
            const panel = document.querySelector(
                `[data-seo-assistant-panel="${moduleId}"], [data-seo-module="${moduleId}"], .seo-assistant-dock`,
            );
            panel?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
            if (panel instanceof HTMLElement) {
                panel.classList.add('is-help-target-flash');
                window.setTimeout(() => panel.classList.remove('is-help-target-flash'), 1200);
            }
        }, 80);

        return;
    }

    if (target.type === 'widget' && id === 'outline') {
        window.dispatchEvent(new CustomEvent('seo-outline-rail-opened'));
        const rail = document.querySelector('.seo-article-editor-outline-rail, .seo-outline-panel');
        rail?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        if (rail instanceof HTMLElement) {
            rail.classList.add('is-help-target-flash');
            window.setTimeout(() => rail.classList.remove('is-help-target-flash'), 1200);
        }
        return;
    }

    if (target.type === 'scroll' && id === 'google-preview') {
        const preview = document.querySelector(
            '.seo-article-editor-google-preview-rail, .seo-google-serp-preview, [data-seo-google-serp-preview]',
        );
        preview?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        if (preview instanceof HTMLElement) {
            preview.classList.add('is-help-target-flash');
            window.setTimeout(() => preview.classList.remove('is-help-target-flash'), 1200);
        }
    }
}
