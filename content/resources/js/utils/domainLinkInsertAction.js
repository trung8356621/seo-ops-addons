/**
 * Domain Link insert — wrap selection URL, else wrap located matchedText.
 * Click-time occurrence identity uses the same actionable matcher as suggestions.
 */

import { t } from './i18n';
import { executeEditorCommand, getEditorCommandHost } from './editorCommands';
import { getEditorInsertionContext } from './editorInsertionContext';
import { SEO_LINK_DEFAULT_ATTRS } from './inlineLinkNormalizer';
import { resolveSuggestionInsertMatch } from './suggestedInternalLinkInsertMatch';

/**
 * @param {{
 *   item: { text?: string, href?: string, target_url?: string, keyword_id?: number|null, matched_phrase?: string },
 *   occurrence?: { blockId?: string, matchedText?: string, matchIndex?: number, phrase?: string }|null,
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

    const locateItem = {
        ...item,
        text: anchor,
        matched_phrase: String(item?.matched_phrase ?? occurrence?.matchedText ?? occurrence?.phrase ?? anchor).trim(),
    };
    const match = resolveSuggestionInsertMatch(locateItem, occurrence);
    if (!match) {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('links_insert_link'),
                    body: t('links_insert_no_match_need_selection'),
                    status: 'warning',
                },
            }),
        );
        window.dispatchEvent(new CustomEvent('seo-editor-links-updated', {
            detail: { text: anchor, href, refresh: true },
        }));
        return false;
    }

    const detail = {
        text: match.phrase,
        href,
        keyword_id: item?.keyword_id ?? null,
        occurrence_index: Math.max(0, Number(match.matchIndex) || 0),
        blockId: match.blockId,
        block_id: match.blockId,
        insert_mode: 'wrap',
        matched_phrase: match.phrase,
        link_occurrence_mode: 'unlinked',
    };

    const actions = getEditorCommandHost()?.actions;
    if (typeof actions?.insertSuggestedLink === 'function') {
        actions.insertSuggestedLink(detail);
        return true;
    }
    window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
    return true;
}
