/**
 * Article-editor bridge to Client Core SaveCoordinator.
 * Prefer importing from '@client-core/saveCoordinator' when Vite alias available.
 */

export {
    registerSaveOwner,
    unregisterSaveOwner,
    clearSaveOwners,
    listSaveOwnerIds,
    flushAllSaveOwners,
} from '@client-core/saveCoordinator.js';
