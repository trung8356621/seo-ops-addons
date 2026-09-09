/**
 * Soft daily-limit link selection with provisional usage inside a batch.
 *
 * Algorithm:
 *   virtualUsed = usedTodayByLinkId(existingOutputs)
 *   for each slot:
 *     eligible = active && virtualUsed < daily_limit
 *     if eligible: weighted random by remaining
 *     else: soft fallback all active (or null)
 *     if picked: virtualUsed[id]++
 */

import { DEFAULT_DAILY_LIMIT, MIN_DAILY_LIMIT, usedTodayByLinkId } from './linkPool';

/**
 * @param {Record<string, unknown>} link
 * @param {Map<string, number>} virtualUsed
 */
function remainingOf(link, virtualUsed) {
    const limit = Math.max(MIN_DAILY_LIMIT, Number(link.daily_limit) || DEFAULT_DAILY_LIMIT);
    const used = virtualUsed.get(String(link.id)) || 0;
    return Math.max(0, limit - used);
}

/**
 * @param {Array<Record<string, unknown>>} links
 * @param {Map<string, number>} virtualUsed
 */
export function eligibleLinks(links, virtualUsed) {
    return (links || []).filter((link) => {
        if (!link || link.is_active === false) return false;
        return remainingOf(link, virtualUsed) > 0;
    });
}

/**
 * @param {Array<Record<string, unknown>>} links
 */
export function activeLinks(links) {
    return (links || []).filter((link) => link && link.is_active !== false);
}

/**
 * Weighted pick by remaining capacity. Inject random ∈ [0,1).
 * @param {Array<Record<string, unknown>>} pool
 * @param {Map<string, number>} virtualUsed
 * @param {() => number} random
 */
export function weightedPickByRemaining(pool, virtualUsed, random = Math.random) {
    if (!pool.length) return null;
    let total = 0;
    const weights = pool.map((link) => {
        const w = remainingOf(link, virtualUsed);
        total += w;
        return w;
    });
    if (total <= 0) {
        const idx = Math.floor(random() * pool.length) % pool.length;
        return pool[idx] || null;
    }
    let cursor = random() * total;
    for (let i = 0; i < pool.length; i += 1) {
        cursor -= weights[i];
        if (cursor < 0) return pool[i];
    }
    return pool[pool.length - 1] || null;
}

/**
 * Uniform pick among pool.
 * @param {Array<Record<string, unknown>>} pool
 * @param {() => number} random
 */
export function uniformPick(pool, random = Math.random) {
    if (!pool.length) return null;
    const idx = Math.floor(random() * pool.length) % pool.length;
    return pool[idx] || null;
}

/**
 * Select up to `quantity` links with provisional usage.
 *
 * @param {{
 *   links: Array<Record<string, unknown>>,
 *   existingOutputs?: Array<Record<string, unknown>>,
 *   quantity: number,
 *   random?: () => number,
 *   dayStartMs?: number,
 * }} opts
 * @returns {Array<Record<string, unknown>|null>}
 */
export function selectLinksForBatch(opts) {
    const quantity = Math.max(0, Math.min(50, Number(opts.quantity) || 0));
    const random = typeof opts.random === 'function' ? opts.random : Math.random;
    const links = Array.isArray(opts.links) ? opts.links : [];
    /** @type {Map<string, number>} */
    const virtualUsed = usedTodayByLinkId(opts.existingOutputs || [], opts.dayStartMs);

    /** @type {Array<Record<string, unknown>|null>} */
    const results = [];
    for (let i = 0; i < quantity; i += 1) {
        const eligible = eligibleLinks(links, virtualUsed);
        let pick = null;
        if (eligible.length > 0) {
            pick = weightedPickByRemaining(eligible, virtualUsed, random);
        } else {
            const fallback = activeLinks(links);
            pick = fallback.length > 0 ? uniformPick(fallback, random) : null;
        }
        results.push(pick);
        if (pick?.id != null) {
            const key = String(pick.id);
            virtualUsed.set(key, (virtualUsed.get(key) || 0) + 1);
        }
    }
    return results;
}

/**
 * Pick one link for regenerate-with-rerandom (provisional: treat current output's
 * link as released for this single pick if keep-same is false).
 *
 * @param {{
 *   links: Array<Record<string, unknown>>,
 *   existingOutputs: Array<Record<string, unknown>>,
 *   excludeOutputId?: string,
 *   random?: () => number,
 * }} opts
 */
export function selectLinkForSingle(opts) {
    const outputs = (opts.existingOutputs || []).filter(
        (o) => String(o.id) !== String(opts.excludeOutputId || ''),
    );
    const [pick] = selectLinksForBatch({
        links: opts.links,
        existingOutputs: outputs,
        quantity: 1,
        random: opts.random,
    });
    return pick ?? null;
}
