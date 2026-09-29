export async function copyPlainText(text) {
    const value = String(text ?? '');

    if (globalThis.navigator?.clipboard?.writeText) {
        try {
            await globalThis.navigator.clipboard.writeText(value);
            return;
        } catch {
            // Insecure origins commonly reject the Clipboard API. Fall through.
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.inset = '0 auto auto -9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);

    try {
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, value.length);
        if (! document.execCommand('copy')) {
            throw new Error('Copy command was rejected.');
        }
    } finally {
        textarea.remove();
    }
}
