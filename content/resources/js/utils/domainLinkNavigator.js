/**
 * Domain Link locate/scroll — goes through editor scrollToLink so collapsed
 * sections expand (DOM-only phrase scroll cannot see unmounted blocks).
 */

import { getEditorCommandHost } from './editorCommands';

/**
 * @param {{
 *   blockId?: string,
 *   matchedText?: string,
 *   phrase?: string,
 *   matchIndex?: number,
 *   level?: string,
 * }|null|undefined} occurrence
 * @param {{ fallbackText?: string }} [options]
 */
export function scrollToDomainLinkOccurrence(occurrence, options = {}) {
    const blockId = String(occurrence?.blockId ?? '').trim();
    const matchedText = String(occurrence?.matchedText ?? '').trim();
    const fallbackText = String(options.fallbackText ?? occurrence?.phrase ?? '').trim();
    const level = String(occurrence?.level ?? '');
    // Exact/contiguous: prefer original anchor (stable for TipTap plain-text find).
    const text = (level === 'exact' || level === 'contiguous')
        ? (fallbackText || matchedText)
        : (matchedText || fallbackText);
    if (text === '') {
        return;
    }

    const detail = {
        text,
        href: '',
        type: 'internal',
        index: 0,
        searchPlainText: true,
        blockId: blockId || undefined,
        localIndex: Math.max(0, Number(occurrence?.matchIndex) || 0),
    };

    const actions = getEditorCommandHost()?.actions;
    if (typeof actions?.scrollToLink === 'function') {
        actions.scrollToLink(detail);
        return;
    }

    window.dispatchEvent(new CustomEvent('seo-editor-scroll-to-link', { detail }));
}
