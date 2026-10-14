/**
 * Decides whether the open Agent drawer can reuse its React root.
 * A replaced or emptied mount node must be mounted again, exactly once.
 */
export function shouldRemountDrawer(root, handle) {
    if (!root) {
        return false;
    }
    if (!handle || root.isConnected === false) {
        return true;
    }
    if (root.dataset?.agentMounted !== '1' || root.childElementCount === 0) {
        return true;
    }

    return false;
}
