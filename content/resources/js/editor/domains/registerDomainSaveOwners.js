import { registerSaveOwner, unregisterSaveOwner, clearSaveOwners } from '../runtime/saveCoordinator.js';
import { contentActions } from './content/actions.js';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { seoActions } from '@seo-addon/editor/domains/seo/state.js';
import { publishingActions } from '@publishing-addon/editor/domains/publishing/state.js';
import { diagListenerRegister, diagListenerUnregister } from './editorDiagnostics.js';

let registered = false;

/** @type {{ getArticleId: () => number, getContentBundle?: () => object }} */
let ownerCtx = {
    getArticleId: () => 0,
};

/**
 * Wire domain owners into Client Core SaveCoordinator.
 * Re-calling updates ctx (Pass 0 host may refine getContentBundle after mount).
 * @param {{ getArticleId: () => number, getContentBundle?: () => object }} ctx
 */
export function registerDomainSaveOwners(ctx) {
    ownerCtx = {
        getArticleId: typeof ctx?.getArticleId === 'function'
            ? ctx.getArticleId
            : ownerCtx.getArticleId,
        getContentBundle: typeof ctx?.getContentBundle === 'function'
            ? ctx.getContentBundle
            : ownerCtx.getContentBundle,
    };

    if (registered) {
        return;
    }
    registered = true;
    diagListenerRegister('domain-save-owners');

    registerSaveOwner({
        id: 'content',
        dirty: () => contentActions.isDirty(),
        flush: () => {
            if (typeof ownerCtx.getContentBundle === 'function') {
                const bundle = ownerCtx.getContentBundle();
                contentActions.patch({
                    html: String(bundle?.html ?? ''),
                    editorDocument: bundle?.editor_document ?? bundle?.editorDocument ?? null,
                    articleMeta: bundle?.articleMeta ?? null,
                    faqs: bundle?.faqs ?? null,
                });
            }
            return contentActions.flush();
        },
    });

    registerSaveOwner({
        id: 'media',
        dirty: () => mediaActions.isDirty(),
        flush: () => mediaActions.flush(Number(ownerCtx.getArticleId?.() || 0)),
    });

    registerSaveOwner({
        id: 'seo',
        dirty: () => seoActions.isDirty(),
        flush: () => seoActions.flush(),
    });

    registerSaveOwner({
        id: 'publishing',
        dirty: () => publishingActions.isDirty(),
        flush: () => {
            publishingActions.refreshFromShell();
            return publishingActions.flush();
        },
    });
}

export function unregisterDomainSaveOwners() {
    if (!registered) {
        return;
    }
    unregisterSaveOwner('content');
    unregisterSaveOwner('media');
    unregisterSaveOwner('seo');
    unregisterSaveOwner('publishing');
    clearSaveOwners();
    registered = false;
    ownerCtx = { getArticleId: () => 0 };
    diagListenerUnregister('domain-save-owners');
}
