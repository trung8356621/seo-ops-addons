/**
 * Phase 6B/6C.1 — compose assistant widget health from runtime healthProviders.
 * Builders stay pure in assistantWidgetHealth.js; React health store owns chip badges.
 */

import {
    buildFeaturedWidgetHealth,
    buildGalleryWidgetHealth,
    buildImagesWidgetHealth,
    buildLinksWidgetHealth,
    buildSeoWidgetHealth,
} from '@ai-prompt-addon/utils/assistantWidgetHealth.js';
import {
    getRuntimeWidgetHealth,
    publishEditorShellHealthSummary,
    patchRuntimeWidgetHealth,
    setRuntimeWidgetHealth,
} from './editorRuntimeHealthStore';

const BUILDERS = Object.freeze({
    buildSeoWidgetHealth,
    buildImagesWidgetHealth,
    buildLinksWidgetHealth,
    buildFeaturedWidgetHealth,
    buildGalleryWidgetHealth,
});

/**
 * @param {object} runtime
 * @param {Record<string, object>} inputByWidgetId
 * @returns {Record<string, object>}
 */
export function composeRuntimeWidgetHealth(runtime, inputByWidgetId = {}) {
    const providers = typeof runtime?.getHealthProviders === 'function'
        ? runtime.getHealthProviders()
        : [];
    const health = {};

    for (const provider of providers) {
        const widgetId = String(provider.widgetId || provider.id || '').trim();
        if (!widgetId) continue;

        if (typeof provider.select === 'function') {
            try {
                health[widgetId] = provider.select({
                    runtime,
                    context: runtime.getContext?.(),
                    input: inputByWidgetId[widgetId] || {},
                });
            } catch (error) {
                // eslint-disable-next-line no-console
                console.warn('[editor-runtime-health] provider failed', widgetId, error);
            }
            continue;
        }

        const builderKey = String(provider.builderKey || '').trim();
        const builder = BUILDERS[builderKey];
        if (typeof builder !== 'function') continue;
        try {
            health[widgetId] = builder(inputByWidgetId[widgetId] || {});
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-health] builder failed', builderKey, error);
        }
    }

    return health;
}

/**
 * Publish health to React store (primary chips). Shell gets summary only — no Alpine mirror.
 *
 * @param {object} runtime
 * @param {Record<string, object>} inputByWidgetId
 * @param {{ reviewsBadge?: number|null, dirty?: boolean }} [extras]
 */
export function publishRuntimeWidgetHealth(runtime, inputByWidgetId, extras = {}) {
    const health = composeRuntimeWidgetHealth(runtime, inputByWidgetId);
    setRuntimeWidgetHealth(health, extras);
    publishEditorShellHealthSummary(health, { dirty: Boolean(extras.dirty) });
    return health;
}

/**
 * Recompute + merge only widgets whose keys are present in `inputByWidgetId`.
 * Typing must not rebuild featured/gallery when those inputs are omitted.
 *
 * @param {object} runtime
 * @param {Record<string, object>} inputByWidgetId
 * @param {{ reviewsBadge?: number|null, dirty?: boolean }} [extras]
 */
export function publishPartialRuntimeWidgetHealth(runtime, inputByWidgetId, extras = {}) {
    const wanted = new Set(Object.keys(inputByWidgetId || {}));
    if (wanted.size === 0) {
        return { ...getRuntimeWidgetHealth() };
    }

    const providers = typeof runtime?.getHealthProviders === 'function'
        ? runtime.getHealthProviders()
        : [];
    const partial = {};

    for (const provider of providers) {
        const widgetId = String(provider.widgetId || provider.id || '').trim();
        if (!widgetId || !wanted.has(widgetId)) {
            continue;
        }

        if (typeof provider.select === 'function') {
            try {
                partial[widgetId] = provider.select({
                    runtime,
                    context: runtime.getContext?.(),
                    input: inputByWidgetId[widgetId] || {},
                });
            } catch (error) {
                // eslint-disable-next-line no-console
                console.warn('[editor-runtime-health] provider failed', widgetId, error);
            }
            continue;
        }

        const builderKey = String(provider.builderKey || '').trim();
        const builder = BUILDERS[builderKey];
        if (typeof builder !== 'function') {
            continue;
        }
        try {
            partial[widgetId] = builder(inputByWidgetId[widgetId] || {});
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-health] builder failed', builderKey, error);
        }
    }

    patchRuntimeWidgetHealth(partial, extras);
    const merged = { ...getRuntimeWidgetHealth() };
    publishEditorShellHealthSummary(merged, { dirty: Boolean(extras.dirty) });
    return merged;
}

export function listRuntimeHealthBuilderKeys() {
    return Object.keys(BUILDERS);
}
