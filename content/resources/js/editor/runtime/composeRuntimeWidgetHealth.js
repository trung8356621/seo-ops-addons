/**
 * Phase 6B/6C.1 — compose assistant widget health from runtime healthProviders.
 * Builders stay pure in assistantWidgetHealth.js; React health store owns chip badges.
 */

import {
    buildFeaturedWidgetHealth,
    buildGalleryWidgetHealth,
    buildImagesWidgetHealth,
    buildLinksWidgetHealth,
    buildPublishingWidgetHealth,
    buildSeoWidgetHealth,
} from '@ai-prompt-addon/utils/assistantWidgetHealth.js';
import {
    getDiagnosticsGeneration,
    getRuntimeWidgetHealth,
    markDiagnosticsRefreshing,
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
    buildPublishingWidgetHealth,
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

    // Shell boundary: Publishing is not a registry healthProvider — compose when input present.
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'publishing')) {
        try {
            health.publishing = buildPublishingWidgetHealth(inputByWidgetId.publishing || {});
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-health] publishing builder failed', error);
        }
    }

    return health;
}

/**
 * @param {Record<string, object>} inputByWidgetId
 * @returns {Record<string, boolean>}
 */
function detectIncompleteWidgets(inputByWidgetId = {}) {
    const incomplete = {};
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'seo')) {
        const seo = inputByWidgetId.seo || {};
        if (seo.incomplete === true || seo.analysisReady === false) {
            incomplete.seo = true;
        }
    }
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'links')) {
        const links = inputByWidgetId.links || {};
        if (links.incomplete === true || links.extractedLinks == null) {
            incomplete.links = true;
        }
    }
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'images')) {
        const images = inputByWidgetId.images || {};
        if (images.incomplete === true) {
            incomplete.images = true;
        }
    }
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'featured')) {
        const featured = inputByWidgetId.featured || {};
        if (featured.incomplete === true) {
            incomplete.featured = true;
        }
    }
    if (Object.prototype.hasOwnProperty.call(inputByWidgetId, 'publishing')) {
        const publishing = inputByWidgetId.publishing || {};
        if (publishing.incomplete === true) {
            incomplete.publishing = true;
        }
    }

    return incomplete;
}

/**
 * Publish health to React store (primary chips). Shell gets summary only — no Alpine mirror.
 *
 * @param {object} runtime
 * @param {Record<string, object>} inputByWidgetId
 * @param {{ reviewsBadge?: number|null, dirty?: boolean }} [extras]
 * @param {{ articleId?: number|null, generation?: number|null }} [meta]
 */
export function publishRuntimeWidgetHealth(runtime, inputByWidgetId, extras = {}, meta = {}) {
    const incompleteWidgets = detectIncompleteWidgets(inputByWidgetId);
    const incompleteIds = Object.keys(incompleteWidgets).filter((id) => incompleteWidgets[id]);
    if (incompleteIds.length > 0) {
        markDiagnosticsRefreshing(incompleteIds);
    }

    const completeInputs = { ...inputByWidgetId };
    incompleteIds.forEach((id) => {
        delete completeInputs[id];
    });

    if (Object.keys(completeInputs).length > 0) {
        const health = composeRuntimeWidgetHealth(runtime, completeInputs);
        setRuntimeWidgetHealth(health, extras, {
            articleId: meta.articleId ?? null,
            generation: meta.generation ?? getDiagnosticsGeneration(),
            incompleteWidgets: {},
        });
    } else {
        // Still allow reviews badge extras while diagnostics refresh.
        setRuntimeWidgetHealth({}, extras, {
            articleId: meta.articleId ?? null,
            generation: meta.generation ?? getDiagnosticsGeneration(),
        });
    }

    publishEditorShellHealthSummary(getRuntimeWidgetHealth(), { dirty: Boolean(extras.dirty) });
    return getRuntimeWidgetHealth();
}

/**
 * Recompute + merge only widgets whose keys are present in `inputByWidgetId`.
 * Typing must not rebuild featured/gallery when those inputs are omitted.
 *
 * @param {object} runtime
 * @param {Record<string, object>} inputByWidgetId
 * @param {{ reviewsBadge?: number|null, dirty?: boolean }} [extras]
 * @param {{ articleId?: number|null, generation?: number|null }} [meta]
 */
export function publishPartialRuntimeWidgetHealth(runtime, inputByWidgetId, extras = {}, meta = {}) {
    const wanted = new Set(Object.keys(inputByWidgetId || {}));
    if (wanted.size === 0) {
        return { ...getRuntimeWidgetHealth() };
    }

    const incompleteWidgets = detectIncompleteWidgets(inputByWidgetId);
    const incompleteIds = Object.keys(incompleteWidgets).filter((id) => incompleteWidgets[id]);
    if (incompleteIds.length > 0) {
        // Refresh flag only — never publish fake issue_count / error severity.
        markDiagnosticsRefreshing(incompleteIds);
    }

    const providers = typeof runtime?.getHealthProviders === 'function'
        ? runtime.getHealthProviders()
        : [];
    const partial = {};

    for (const provider of providers) {
        const widgetId = String(provider.widgetId || provider.id || '').trim();
        if (!widgetId || !wanted.has(widgetId) || incompleteWidgets[widgetId]) {
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

    if (wanted.has('publishing') && !incompleteWidgets.publishing) {
        try {
            partial.publishing = buildPublishingWidgetHealth(inputByWidgetId.publishing || {});
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-health] publishing builder failed', error);
        }
    }

    if (Object.keys(partial).length > 0 || Object.prototype.hasOwnProperty.call(extras, 'reviewsBadge')) {
        patchRuntimeWidgetHealth(partial, extras, {
            articleId: meta.articleId ?? null,
            generation: meta.generation ?? getDiagnosticsGeneration(),
            incompleteWidgets: {},
        });
    }

    const merged = { ...getRuntimeWidgetHealth() };
    publishEditorShellHealthSummary(merged, { dirty: Boolean(extras.dirty) });
    return merged;
}

export function listRuntimeHealthBuilderKeys() {
    return Object.keys(BUILDERS);
}
