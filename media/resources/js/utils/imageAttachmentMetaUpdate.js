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
