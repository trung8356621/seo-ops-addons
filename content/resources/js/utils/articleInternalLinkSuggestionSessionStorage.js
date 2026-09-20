/**
 * Persist Internal Link Assistant suggestion session across sidebar remounts.
 * Scoped by site + article. Pattern mirrors articleExcludedLinkSuggestionsStorage
 * (dedicated helper — not articleEditorStorage drafts).
 */

export const INTERNAL_LINK_SUGGESTION_SESSION_VERSION = 1;

const storageKey = (articleId, siteId) =>
    `seo_article_internal_link_suggestion_session_${Number(siteId ?? 0)}_${Number(articleId ?? 0)}`;

/**
 * @param {unknown} value
 * @returns {string[]}
 */
function normalizeStringList(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return [...new Set(
        value
            .map((item) => String(item ?? '').trim())
            .filter((item) => item !== ''),
    )];
}

/**
 * @param {unknown} value
 * @returns {Record<string, unknown>[]}
 */
function normalizeCatalog(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter((item) => item && typeof item === 'object');
}

/**
 * @param {unknown} cursor
 * @returns {{ stage: string, offset: number }|null}
 */
export function normalizeAdvancedCursor(cursor) {
    if (!cursor || typeof cursor !== 'object') {
        return null;
    }

    const stage = String(cursor.stage ?? '').trim();
    const offset = Math.max(0, Number(cursor.offset ?? 0) || 0);
    if (stage === '') {
        return null;
    }

    return { stage, offset };
}

/**
 * @returns {{
 *   version: number,
 *   siteId: number,
 *   articleId: number,
 *   contentFingerprint: string,
 *   catalog: Record<string, unknown>[],
 *   externalCatalog: Record<string, unknown>[],
 *   phase: string,
 *   hasResults: boolean,
 *   exhausted: boolean,
 *   failedKeys: string[],
 *   advancedCursor: { stage: string, offset: number }|null,
 *   advancedEnabled: boolean,
 *   updatedAt: number,
 * }|null}
 */
export function loadInternalLinkSuggestionSession(articleId, siteId) {
    const id = Number(articleId ?? 0);
    const site = Number(siteId ?? 0);
    if (id <= 0) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(storageKey(id, site));
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }

        const storedArticleId = Number(parsed.articleId ?? 0);
        const storedSiteId = Number(parsed.siteId ?? 0);
        if (storedArticleId !== id || storedSiteId !== site) {
            return null;
        }

        return {
            version: Number(parsed.version ?? 0) || INTERNAL_LINK_SUGGESTION_SESSION_VERSION,
            siteId: storedSiteId,
            articleId: storedArticleId,
            contentFingerprint: String(parsed.contentFingerprint ?? ''),
            catalog: normalizeCatalog(parsed.catalog),
            externalCatalog: normalizeCatalog(parsed.externalCatalog),
            phase: String(parsed.phase ?? 'idle'),
            hasResults: parsed.hasResults === true,
            exhausted: parsed.exhausted === true,
            failedKeys: normalizeStringList(parsed.failedKeys),
            advancedCursor: normalizeAdvancedCursor(parsed.advancedCursor),
            advancedEnabled: parsed.advancedEnabled === true,
            updatedAt: Number(parsed.updatedAt ?? 0) || 0,
        };
    } catch {
        return null;
    }
}

/**
 * @param {number} articleId
 * @param {number} siteId
 * @param {{
 *   contentFingerprint?: string,
 *   catalog?: unknown[],
 *   externalCatalog?: unknown[],
 *   phase?: string,
 *   hasResults?: boolean,
 *   exhausted?: boolean,
 *   failedKeys?: string[],
 *   advancedCursor?: { stage?: string, offset?: number }|null,
 *   advancedEnabled?: boolean,
 * }} session
 */
export function saveInternalLinkSuggestionSession(articleId, siteId, session) {
    const id = Number(articleId ?? 0);
    const site = Number(siteId ?? 0);
    if (id <= 0) {
        return;
    }

    const payload = {
        version: INTERNAL_LINK_SUGGESTION_SESSION_VERSION,
        siteId: site,
        articleId: id,
        contentFingerprint: String(session?.contentFingerprint ?? ''),
        catalog: normalizeCatalog(session?.catalog),
        externalCatalog: normalizeCatalog(session?.externalCatalog),
        phase: String(session?.phase ?? 'idle'),
        hasResults: session?.hasResults === true,
        exhausted: session?.exhausted === true,
        failedKeys: normalizeStringList(session?.failedKeys),
        advancedCursor: normalizeAdvancedCursor(session?.advancedCursor),
        advancedEnabled: session?.advancedEnabled === true,
        updatedAt: Date.now(),
    };

    try {
        window.localStorage.setItem(storageKey(id, site), JSON.stringify(payload));
    } catch (error) {
        console.warn('Không lưu được Internal Link suggestion session vào localStorage', error);
    }
}

export function clearInternalLinkSuggestionSession(articleId, siteId) {
    const id = Number(articleId ?? 0);
    const site = Number(siteId ?? 0);
    if (id <= 0) {
        return;
    }

    try {
        window.localStorage.removeItem(storageKey(id, site));
    } catch (error) {
        console.warn('Không xóa được Internal Link suggestion session trong localStorage', error);
    }
}

/**
 * @param {unknown} session
 * @param {{ siteId?: number, articleId?: number, contentFingerprint?: string }} expected
 */
export function isInternalLinkSuggestionSessionUsable(session, expected = {}) {
    if (!session || typeof session !== 'object') {
        return false;
    }

    const expectedArticleId = Number(expected.articleId ?? 0);
    const expectedSiteId = Number(expected.siteId ?? 0);
    const expectedFingerprint = String(expected.contentFingerprint ?? '');

    if (expectedArticleId > 0 && Number(session.articleId ?? 0) !== expectedArticleId) {
        return false;
    }
    if (Number(session.siteId ?? 0) !== expectedSiteId) {
        return false;
    }
    if (expectedFingerprint !== '' && String(session.contentFingerprint ?? '') !== expectedFingerprint) {
        return false;
    }

    const hasCatalog = Array.isArray(session.catalog) && session.catalog.length > 0;
    const hasPhase = session.hasResults === true || session.exhausted === true || String(session.phase ?? '') === 'exhausted';

    return hasCatalog || hasPhase;
}
