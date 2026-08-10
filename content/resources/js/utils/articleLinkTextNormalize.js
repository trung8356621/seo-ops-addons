/**
 * Shared link/plain-text normalizer — kept free of articleLinkScroll /
 * articlePlainTextRange imports to avoid circular TDZ in the Vite bundle.
 */

export function normalizeLinkText(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim();
}
