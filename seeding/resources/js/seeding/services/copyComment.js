/**
 * Copy-time link selection + clipboard helpers.
 * Gen comment does NOT pick links — only Copy with append_link does.
 */

import { eligibleLinks, weightedPickByRemaining } from './linkSelector';
import { usedTodayByLinkId } from './linkPool';

/**
 * Build clipboard text for a generated comment.
 *
 * @param {{
 *   content: string,
 *   appendLink?: boolean,
 *   selectedSeedLinkId?: string|null,
 *   selectedSeedUrl?: string|null,
 *   seedLinks?: Array<Record<string, unknown>>,
 *   linkUsageToday?: Map<string, number>|Record<string, number>|Array<Record<string, unknown>>,
 *   random?: () => number,
 * }} opts
 * @returns {{
 *   text: string,
 *   selected_seed_link_id: string|null,
 *   selected_seed_url: string|null,
 *   appended: boolean,
 *   softLimitReached: boolean,
 * }}
 */
export function buildCopyPayload(opts) {
    const content = String(opts.content || '').trim();
    const appendLink = Boolean(opts.appendLink);
    let selectedId = opts.selectedSeedLinkId != null && opts.selectedSeedLinkId !== ''
        ? String(opts.selectedSeedLinkId)
        : null;
    let selectedUrl = opts.selectedSeedUrl != null && opts.selectedSeedUrl !== ''
        ? String(opts.selectedSeedUrl)
        : null;
    let softLimitReached = false;
    let appended = false;

    if (appendLink) {
        if (!selectedId || !selectedUrl) {
            const usage = normalizeUsageMap(opts.linkUsageToday);
            const eligible = eligibleLinks(opts.seedLinks || [], usage);
            if (eligible.length === 0) {
                softLimitReached = true;
            } else {
                const pick = weightedPickByRemaining(eligible, usage, opts.random || Math.random);
                if (pick) {
                    selectedId = String(pick.id);
                    selectedUrl = String(pick.url || '');
                } else {
                    softLimitReached = true;
                }
            }
        }

        if (selectedUrl) {
            appended = true;
            return {
                text: `${content}\n\n${selectedUrl}`.trim(),
                selected_seed_link_id: selectedId,
                selected_seed_url: selectedUrl,
                appended: true,
                softLimitReached: false,
            };
        }
    }

    return {
        text: content,
        selected_seed_link_id: selectedId,
        selected_seed_url: selectedUrl,
        appended,
        softLimitReached,
    };
}

/**
 * @param {Map<string, number>|Record<string, number>|Array<Record<string, unknown>>|undefined} raw
 * @returns {Map<string, number>}
 */
function normalizeUsageMap(raw) {
    if (raw instanceof Map) return raw;
    if (Array.isArray(raw)) return usedTodayByLinkId(raw);
    /** @type {Map<string, number>} */
    const map = new Map();
    if (raw && typeof raw === 'object') {
        for (const [key, value] of Object.entries(raw)) {
            map.set(String(key), Number(value) || 0);
        }
    }
    return map;
}

/**
 * Instant client-side clipboard write — no network.
 * @param {string} text
 */
export async function writeClipboard(text) {
    await navigator.clipboard.writeText(String(text || ''));
}
