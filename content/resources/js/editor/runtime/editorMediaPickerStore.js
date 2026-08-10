/**
 * Phase 6C.3 — shared media picker UI state (React runtime).
 * Does not own Featured/Gallery canonical data — only selection/modal.
 */

/** @type {object|null} */
let pickerState = null;
/** @type {Set<(state: object|null) => void>} */
const listeners = new Set();

function emit() {
    const snapshot = pickerState;
    listeners.forEach((listener) => {
        try {
            listener(snapshot);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[media-picker] listener failed', error);
        }
    });
}

/**
 * @param {object} config
 * @param {'content_image'|'featured'|'gallery'} config.mode
 * @param {'single'|'multiple'} [config.selection]
 * @param {object} [config.target]
 * @param {(items: object[]) => void|Promise<void>} [config.onConfirm]
 * @param {() => void} [config.onCancel]
 */
export function openMediaPicker(config) {
    const mode = String(config?.mode || 'content_image');
    const selection = config?.selection
        || (mode === 'gallery' ? 'multiple' : 'single');

    pickerState = {
        open: true,
        mode,
        selection,
        target: config?.target || null,
        onConfirm: typeof config?.onConfirm === 'function' ? config.onConfirm : null,
        onCancel: typeof config?.onCancel === 'function' ? config.onCancel : null,
        selectedKeys: [],
        selectedItems: {},
        openedAt: Date.now(),
    };
    emit();
    return pickerState;
}

export function closeMediaPicker() {
    const prev = pickerState;
    pickerState = null;
    emit();
    if (typeof prev?.onCancel === 'function') {
        try {
            prev.onCancel();
        } catch {
            // ignore
        }
    }
}

export function getMediaPickerState() {
    return pickerState;
}

/**
 * @param {(state: object|null) => void} listener
 * @returns {() => void}
 */
export function subscribeMediaPicker(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    listeners.add(listener);
    try {
        listener(pickerState);
    } catch {
        // ignore
    }
    return () => listeners.delete(listener);
}

export function patchMediaPickerSelection(selectedKeys, selectedItems) {
    if (!pickerState) {
        return;
    }
    pickerState = {
        ...pickerState,
        selectedKeys: Array.isArray(selectedKeys) ? selectedKeys : [],
        selectedItems: selectedItems && typeof selectedItems === 'object' ? selectedItems : {},
    };
    emit();
}

/**
 * @returns {Promise<boolean>}
 */
export async function confirmMediaPicker() {
    const current = pickerState;
    if (!current?.open) {
        return false;
    }
    const keys = current.selectedKeys || [];
    const items = keys.map((key) => current.selectedItems[key]).filter(Boolean);
    const onConfirm = current.onConfirm;
    pickerState = null;
    emit();
    if (typeof onConfirm === 'function') {
        await onConfirm(items);
    }
    return true;
}
