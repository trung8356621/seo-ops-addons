/**
 * Personal Link Pool helpers (localStorage SoT).
 * Ownership = document user scope — no manager assignment.
 */

import { makeId, normalizeUrlKey } from './storage';

export const DEFAULT_DAILY_LIMIT = 5;
export const MIN_DAILY_LIMIT = 1;

/**
 * Start of local calendar day (ms).
 */
export function startOfLocalDay(now = Date.now()) {
    const d = new Date(now);
    d.setHours(0, 0, 0, 0);
    return d.getTime();
}

/**
 * One-pass aggregate: seed_link_id → usage count for local day.
 * @param {Array<Record<string, unknown>>} outputs
 * @param {number} [dayStartMs]
 * @returns {Map<string, number>}
 */
export function usedTodayByLinkId(outputs, dayStartMs = startOfLocalDay()) {
    /** @type {Map<string, number>} */
    const map = new Map();
    for (const out of outputs || []) {
        const linkId = out?.seed_link_id;
        if (linkId == null || linkId === '') continue;
        const ts = Date.parse(String(out.created_at || ''));
        if (!Number.isFinite(ts) || ts < dayStartMs) continue;
        const key = String(linkId);
        map.set(key, (map.get(key) || 0) + 1);
    }
    return map;
}

/**
 * @param {unknown} raw
 * @returns {Record<string, unknown>|null}
 */
export function normalizeSeedLink(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const url = String(raw.url || '').trim();
    if (!url) return null;
    const dailyLimit = Math.max(MIN_DAILY_LIMIT, Number(raw.daily_limit) || DEFAULT_DAILY_LIMIT);
    const now = new Date().toISOString();
    return {
        id: String(raw.id || makeId('slink')),
        url,
        normalized_url: String(raw.normalized_url || normalizeUrlKey(url)),
        daily_limit: dailyLimit,
        is_active: raw.is_active !== false,
        label: typeof raw.label === 'string' ? raw.label.trim() : '',
        created_at: raw.created_at || now,
        updated_at: raw.updated_at || now,
    };
}

/**
 * @param {unknown} list
 * @returns {Array<Record<string, unknown>>}
 */
export function normalizeSeedLinks(list) {
    if (!Array.isArray(list)) return [];
    /** @type {Map<string, Record<string, unknown>>} */
    const byId = new Map();
    for (const raw of list) {
        const link = normalizeSeedLink(raw);
        if (!link) continue;
        byId.set(String(link.id), link);
    }
    return [...byId.values()];
}

/**
 * @param {{ url: string, daily_limit?: number, label?: string, is_active?: boolean }} input
 */
export function createSeedLink(input) {
    const now = new Date().toISOString();
    return normalizeSeedLink({
        id: makeId('slink'),
        url: input.url,
        daily_limit: input.daily_limit ?? DEFAULT_DAILY_LIMIT,
        label: input.label || '',
        is_active: input.is_active !== false,
        created_at: now,
        updated_at: now,
    });
}

/**
 * Capacity summary for Share panel (1 pass over links + used map).
 * @param {Array<Record<string, unknown>>} links
 * @param {Map<string, number>|Record<string, number>} usedMap
 */
export function linkPoolCapacity(links, usedMap) {
    const getUsed = (id) => {
        if (usedMap instanceof Map) return usedMap.get(String(id)) || 0;
        return Number(usedMap?.[String(id)] || 0);
    };
    let available = 0;
    let remainingHint = 0;
    let active = 0;
    let atLimit = 0;
    for (const link of links || []) {
        if (!link?.is_active) continue;
        active += 1;
        const used = getUsed(link.id);
        const limit = Math.max(MIN_DAILY_LIMIT, Number(link.daily_limit) || DEFAULT_DAILY_LIMIT);
        const rem = Math.max(0, limit - used);
        if (rem > 0) {
            available += 1;
            remainingHint += rem;
        } else {
            atLimit += 1;
        }
    }
    return { available, remainingHint, active, atLimit };
}

/**
 * UI row helpers — soft wording only.
 * @param {Record<string, unknown>} link
 * @param {number} used
 */
export function linkUsageLabel(link, used) {
    const limit = Math.max(MIN_DAILY_LIMIT, Number(link.daily_limit) || DEFAULT_DAILY_LIMIT);
    const u = Math.max(0, Number(used) || 0);
    const atLimit = u >= limit;
    return {
        used: u,
        limit,
        atLimit,
        text: `${u} / ${limit}`,
        statusText: atLimit ? 'Đủ hôm nay' : null,
    };
}
