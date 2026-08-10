import { contentActions } from './state.js';
import { diagApi } from '../editorDiagnostics.js';

/**
 * Content API surface — explicit mutations only (no effect pipelines).
 */
export const contentApi = {
    /**
     * Sync local editable state from host export (call from explicit save/flush paths).
     * @param {{ html?: string, editorDocument?: unknown, articleMeta?: object|null, faqs?: unknown[]|null }} bundle
     */
    adoptFromHost(bundle) {
        diagApi('content', 'adoptFromHost', {});
        contentActions.patch({
            html: String(bundle?.html ?? ''),
            editorDocument: bundle?.editorDocument ?? bundle?.editor_document ?? null,
            articleMeta: bundle?.articleMeta ?? null,
            faqs: bundle?.faqs ?? null,
        });
    },
};

export { contentActions } from './state.js';
