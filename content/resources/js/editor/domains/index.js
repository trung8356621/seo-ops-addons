export { editorDomainBridge, getOwnerDirties } from './editorDomainBridge.js';
export { registerDomainSaveOwners, unregisterDomainSaveOwners } from './registerDomainSaveOwners.js';
export { contentActions, contentApi } from './content/actions.js';
export { getContentState, subscribe as subscribeContent } from './content/state.js';
export { useContentEditor } from './content/useContentEditor.js';
export { mediaActions, mediaApi } from '@media-addon/editor/domains/media/state.js';
export { getMediaDomainState, subscribe as subscribeMedia } from '@media-addon/editor/domains/media/state.js';
export { useMediaEditor } from '@media-addon/editor/domains/media/useMediaEditor.js';
export { seoActions, seoApi, getSeoState, subscribe as subscribeSeo } from '@seo-addon/editor/domains/seo/state.js';
export { useSeoEditor } from '@seo-addon/editor/domains/seo/useSeoEditor.js';
export { publishingActions, getPublishingState } from '@publishing-addon/editor/domains/publishing/state.js';
export { usePublishingEditor } from '@publishing-addon/editor/domains/publishing/usePublishingEditor.js';
export { wordpressActions, getWordpressSnapshot } from '@wordpress-addon/editor/domains/wordpress/snapshotStore.js';
export { useWordpressFacts } from '@wordpress-addon/editor/domains/wordpress/useWordpressFacts.js';
export {
    diag,
    diagMutationStart,
    diagMutationEnd,
    diagApi,
    diagDirty,
    diagPollStart,
    diagPollStop,
    getDiagnosticsSnapshot,
} from './editorDiagnostics.js';

import { getDiagnosticsSnapshot } from './editorDiagnostics.js';
import { getOwnerDirties } from './editorDomainBridge.js';

if (typeof window !== 'undefined') {
    window.__seoEditorDomainBridge = {
        version: 'diag-only',
        getDiagnostics: getDiagnosticsSnapshot,
        getDiagnosticsSnapshot,
        getOwnerDirties,
    };
}
