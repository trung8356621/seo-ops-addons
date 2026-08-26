/**
 * Domain Link insert — wrap selection URL, else wrap located matchedText.
 * Does not change Internal Link insert paths.
 */

import { executeEditorCommand, getEditorCommandHost } from './editorCommands';
import { getEditorInsertionContext } from './editorInsertionContext';
import { SEO_LINK_DEFAULT_ATTRS } from './inlineLinkNormalizer';

/**
 * @param {{
 *   item: { text?: string, href?: string, target_url?: string, keyword_id?: number|null },
 *   occurrence?: { blockId?: string, matchedText?: string, matchIndex?: number }|null,
 * }} args
 */
export function insertDomainLinkAction({ item, occurrence = null }) {
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    const anchor = String(item?.text ?? '').trim();
    if (!href) {
        return false;
    }

    const ctx = getEditorInsertionContext();
    const selection = ctx.selection;
    const hasSelection = Boolean(
        selection
        && Number.isFinite(selection.from)
        && Number.isFinite(selection.to)
        && selection.to > selection.from,
    );

    if (hasSelection) {
        const editorId = String(ctx.activeBlockId ?? '').trim() || undefined;
        const result = executeEditorCommand('create_link', {
            href,
            editorId,
            target: SEO_LINK_DEFAULT_ATTRS.target,
            rel: SEO_LINK_DEFAULT_ATTRS.rel,
            className: SEO_LINK_DEFAULT_ATTRS.class,
            extendMarkRange: false,
        }, { notifyOnFailure: true });

        if (result?.ok && result.transaction_applied) {
            window.dispatchEvent(
                new CustomEvent('seo-editor-suggested-link-inserted', {
                    detail: { text: anchor, href, blockId: editorId },
                }),
            );
            return true;
        }
    }

    const matchedText = String(occurrence?.matchedText ?? '').trim();
    const wrapText = matchedText || anchor;
    if (!wrapText) {
        return false;
    }

    const detail = matchedText
        ? {
            text: matchedText,
            href,
            keyword_id: item?.keyword_id ?? null,
            occurrence_index: Math.max(0, Number(occurrence?.matchIndex) || 0),
            blockId: occurrence?.blockId ?? null,
        }
        : {
            text: wrapText,
            href,
            keyword_id: item?.keyword_id ?? null,
            insert_mode: 'caret',
            target: {
                sectionId: ctx.activeSectionId,
                blockId: ctx.activeBlockId,
                selectionBookmark: ctx.selection,
            },
        };

    const actions = getEditorCommandHost()?.actions;
    if (typeof actions?.insertSuggestedLink === 'function') {
        actions.insertSuggestedLink(detail);
        return true;
    }
    window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
    return true;
}
