/**
 * Internal Link Assistant insert — custom selection vs suggestion matched occurrence.
 * Does not change candidate ranking / discovery.
 */

import { t } from './i18n';
import { executeEditorCommand, getEditorCommandHost } from './editorCommands';
import { SEO_LINK_DEFAULT_ATTRS } from './inlineLinkNormalizer';
import { hasExplicitEditorTextSelection, readLiveEditorTextSelection } from './editorExplicitSelection';
import { getEditorInsertionContext } from './editorInsertionContext';
import {
    resolveSuggestionInsertMatch,
    resolveSuggestionLocatePhrase,
} from './suggestedInternalLinkInsertMatch';

export {
    resolveSuggestionInsertMatch,
    resolveSuggestionLocatePhrase,
} from './suggestedInternalLinkInsertMatch';

/**
 * @param {{
 *   item: Record<string, unknown>,
 *   occurrence?: { blockId?: string, matchIndex?: number, phrase?: string, matchedText?: string }|null,
 * }} args
 * @returns {'custom'|'match'|'need_selection'|false}
 */
export function insertSuggestedInternalLinkAction({ item, occurrence = null }) {
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    if (!href) {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('links_insert_failed_title'),
                    body: t('links_insert_failed_body'),
                    status: 'warning',
                },
            }),
        );
        return false;
    }

    const dispatchInsert = (detail) => {
        const actions = getEditorCommandHost()?.actions;
        if (typeof actions?.insertSuggestedLink === 'function') {
            actions.insertSuggestedLink(detail);
            return;
        }
        window.dispatchEvent(new CustomEvent('seo-editor-insert-suggested-link', { detail }));
    };

    // CASE A — explicit user text selection inside editor.
    if (hasExplicitEditorTextSelection()) {
        const live = readLiveEditorTextSelection();
        const ctx = getEditorInsertionContext();
        const preferredBlockId = String(live?.blockId ?? ctx.activeBlockId ?? '').trim();
        const result = executeEditorCommand('create_link', {
            href,
            editorId: preferredBlockId || undefined,
            target: SEO_LINK_DEFAULT_ATTRS.target,
            rel: SEO_LINK_DEFAULT_ATTRS.rel,
            className: SEO_LINK_DEFAULT_ATTRS.class,
            extendMarkRange: false,
        }, { notifyOnFailure: true });

        if (result?.ok && result.transaction_applied) {
            window.dispatchEvent(
                new CustomEvent('seo-editor-suggested-link-inserted', {
                    detail: {
                        text: String(live?.text ?? item?.text ?? '').trim(),
                        href,
                        blockId: preferredBlockId,
                    },
                }),
            );
            return 'custom';
        }

        // Fall through to match insert if create_link failed without hard block.
        if (result && result.ok === false && (
            result.code === 'editor_read_only'
            || result.code === 'editor_session_not_owned'
            || result.code === 'content_replace_conflict'
            || result.code === 'permission_denied'
        )) {
            return false;
        }
    }

    // CASE B — suggestion matched occurrence (no custom selection required).
    const match = resolveSuggestionInsertMatch(item, occurrence);
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
        return 'need_selection';
    }

    dispatchInsert({
        text: match.phrase,
        href,
        keyword_id: item?.keyword_id ?? null,
        occurrence_index: match.matchIndex,
        blockId: match.blockId,
        block_id: match.blockId,
        insert_mode: 'wrap',
        matched_phrase: match.phrase,
    });

    return 'match';
}
