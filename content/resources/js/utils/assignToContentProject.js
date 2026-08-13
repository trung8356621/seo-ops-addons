import { AssignToContentProjectContract } from './assignToContentProjectContract';

/**
 * Canonical opener for Assign-to-Content-Project drawer.
 * React/Alpine adapters MUST use this — no parallel event names.
 *
 * @param {Record<string, unknown>} payload
 */
export function openAssignToContentProject(payload) {
    const detail = payload && typeof payload === 'object' ? payload : {};
    window.dispatchEvent(new CustomEvent(AssignToContentProjectContract.OPEN_EVENT, { detail }));
}

/** @deprecated Use openAssignToContentProject — compatibility alias only. */
export function openAssignToContentProjectDrawer(payload) {
    openAssignToContentProject(payload);
}

export { AssignToContentProjectContract };
