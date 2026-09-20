export function dispatchWordPressAttachmentMetaUpdate(items, options = {}) {
    if (!items?.length) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-update-attachment-meta', {
            detail: {
                items,
                silent: options.silent === true,
            },
        }),
    );
}

/**
 * Stage featured/gallery WP ALT until article WordPress sync succeeds.
 * Never mutates WordPress immediately.
 */
export function dispatchWordPressAttachmentAltStage(items, options = {}) {
    if (!items?.length) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-stage-attachment-alt', {
            detail: {
                items,
                silent: options.silent === true,
            },
        }),
    );
}
