/**
 * Persist Internal Link Assistant suggestion session across sidebar remounts.
 * Scoped by site + article. Pattern mirrors articleExcludedLinkSuggestionsStorage
 * (dedicated helper — not articleEditorStorage drafts).
 */

/** v4 binds each catalog to its engine. Internal suggestions also require the V2 algorithm stamp. */
export const INTERNAL_LINK_SUGGESTION_SESSION_VERSION = 4;

/** Bump when Internal V2 ranking, anchor selection, or the paged pool changes. */
export const INTERNAL_LINK_ALGORITHM = 'semantic-v2-pool-1';

export const INTERNAL_SUGGESTION_PAGE = 5;

export function initialVisibleSuggestionCount(poolSize, page = INTERNAL_SUGGESTION_PAGE) {
    const total = Math.max(0, Number(poolSize) || 0);

    return Math.min(total, Math.max(1, Number(page) || INTERNAL_SUGGESTION_PAGE));
}

export function nextVisibleSuggestionCount(visible, poolSize, page = INTERNAL_SUGGESTION_PAGE) {
    const shown = Math.max(0, Number(visible) || 0);
    const total = Math.max(0, Number(poolSize) || 0);
    if (shown >= total) {
        return shown;
    }

    return Math.min(total, shown + Math.max(1, Number(page) || INTERNAL_SUGGESTION_PAGE));
}

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
 * @param {unknown} value
 * @returns {{ internal: 'legacy'|'semantic_v2', external: 'legacy'|'wiki_v2' }}
 */
function normalizeSuggestionEngines(value) {
    const engines = value && typeof value === 'object' ? value : {};

    return {
        internal: engines.internal === 'semantic_v2' ? 'semantic_v2' : 'legacy',
        external: engines.external === 'wiki_v2' ? 'wiki_v2' : 'legacy',
    };
}

/**
 * @param {unknown} value
 * @returns {{ match_limit: number, scope_open: boolean, exhausted: boolean, excluded_urls: string[] }|null}
 */
function normalizeDiscoveryCursor(value) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    return {
        match_limit: Math.max(0, Number(value.match_limit ?? value.matchLimit ?? 0) || 0),
        scope_open: value.scope_open === true || value.scopeOpen === true,
        exhausted: value.exhausted === true,
        excluded_urls: normalizeStringList(value.excluded_urls ?? value.excludedUrls),
    };
}

/**
 * @param {unknown} value
 * @param {Record<string, unknown>} fallback
 * @returns {{ internal: boolean, external: boolean }}
 */
function normalizeGenerated(value, fallback = {}) {
    if (value && typeof value === 'object') {
        return {
            internal: value.internal === true,
            external: value.external === true,
        };
    }

    const catalog = Array.isArray(fallback.catalog) ? fallback.catalog : [];
    const externalCatalog = Array.isArray(fallback.externalCatalog) ? fallback.externalCatalog : [];

    return {
        internal: catalog.length > 0 || fallback.hasResults === true || fallback.exhausted === true,
        external: externalCatalog.length > 0,
    };
}

/**
 * @param {unknown} cursor
 * @returns {{ stage: string, offset: number }|null}
 */
export function normalizeAdvancedCursor(cursor) {
    if (!cursor || typeof cursor !== 'object') {
        return null;
    }

    let stage = String(cursor.stage ?? '').trim();
    if (stage === 'content_fallback') {
        stage = 'content_deep';
    }
    const offset = Math.max(
        0,
        Number(cursor.offset ?? cursor.phrase_offset ?? 0) || 0,
    );
    if (stage === '') {
        return null;
    }

    return { stage, offset, phrase_offset: offset };
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
 *   suggestionEngines: { internal: string, external: string },
 *   generated: { internal: boolean, external: boolean },
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
            advancedEnabled: false,
            suggestionEngines: normalizeSuggestionEngines(parsed.suggestionEngines),
            internalAlgorithm: String(parsed.internalAlgorithm ?? ''),
            visibleCount: Math.max(0, Number(parsed.visibleCount ?? INTERNAL_SUGGESTION_PAGE) || 0),
            discoveryCursor: normalizeDiscoveryCursor(parsed.discoveryCursor),
            generated: normalizeGenerated(parsed.generated, parsed),
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

    const catalog = normalizeCatalog(session?.catalog);
    const externalCatalog = normalizeCatalog(session?.externalCatalog);
    const payload = {
        version: INTERNAL_LINK_SUGGESTION_SESSION_VERSION,
        siteId: site,
        articleId: id,
        contentFingerprint: String(session?.contentFingerprint ?? ''),
        catalog,
        externalCatalog,
        phase: String(session?.phase ?? 'idle'),
        hasResults: session?.hasResults === true,
        exhausted: session?.exhausted === true,
        failedKeys: normalizeStringList(session?.failedKeys),
        advancedCursor: normalizeAdvancedCursor(session?.advancedCursor),
        advancedEnabled: false,
        suggestionEngines: {
            internal: 'semantic_v2',
            external: normalizeSuggestionEngines(session?.suggestionEngines).external,
        },
        internalAlgorithm: INTERNAL_LINK_ALGORITHM,
        visibleCount: Math.max(0, Number(session?.visibleCount ?? INTERNAL_SUGGESTION_PAGE) || 0),
        discoveryCursor: normalizeDiscoveryCursor(session?.discoveryCursor),
        generated: normalizeGenerated(session?.generated, {
            catalog,
            externalCatalog,
            hasResults: session?.hasResults === true,
            exhausted: session?.exhausted === true,
        }),
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
    const version = Number(session.version ?? 0) || 0;
    if (version < INTERNAL_LINK_SUGGESTION_SESSION_VERSION) {
        return false;
    }

    if (expected.suggestionEngines && typeof expected.suggestionEngines === 'object') {
        const resolved = resolveSuggestionSessionCache(session, expected);

        return resolved.internal !== null || resolved.external !== null;
    }

    const hasCatalog = Array.isArray(session.catalog) && session.catalog.length > 0;
    const hasPhase = session.hasResults === true || session.exhausted === true || String(session.phase ?? '') === 'exhausted';

    return hasCatalog || hasPhase;
}

/**
 * Per-category cache hit. null = do not serve that category.
 * A hit may be an empty list when that engine already ran.
 *
 * @param {unknown} session
 * @param {{ siteId?: number, articleId?: number, contentFingerprint?: string, suggestionEngines?: { internal?: string, external?: string } }} expected
 * @returns {{ internal: Record<string, unknown>[]|null, external: Record<string, unknown>[]|null }}
 */
export function resolveSuggestionSessionCache(session, expected = {}) {
    const miss = { internal: null, external: null };
    if (!session || typeof session !== 'object') {
        return miss;
    }

    const expectedArticleId = Number(expected.articleId ?? 0);
    const expectedSiteId = Number(expected.siteId ?? 0);
    const expectedFingerprint = String(expected.contentFingerprint ?? '');
    if (expectedArticleId > 0 && Number(session.articleId ?? 0) !== expectedArticleId) {
        return miss;
    }
    if (Number(session.siteId ?? 0) !== expectedSiteId) {
        return miss;
    }
    if (expectedFingerprint !== '' && String(session.contentFingerprint ?? '') !== expectedFingerprint) {
        return miss;
    }
    if ((Number(session.version ?? 0) || 0) < INTERNAL_LINK_SUGGESTION_SESSION_VERSION) {
        return miss;
    }
    if (!expected.suggestionEngines || typeof expected.suggestionEngines !== 'object') {
        return miss;
    }

    const stored = normalizeSuggestionEngines(session.suggestionEngines);
    const wanted = normalizeSuggestionEngines(expected.suggestionEngines);
    const generated = session.generated && typeof session.generated === 'object'
        ? session.generated
        : {};

    const internalReady = stored.internal === 'semantic_v2'
        && session.internalAlgorithm === INTERNAL_LINK_ALGORITHM
        && generated.internal === true;

    return {
        internal: internalReady
            ? (Array.isArray(session.catalog) ? session.catalog : [])
            : null,
        external: stored.external === wanted.external && generated.external === true
            ? (Array.isArray(session.externalCatalog) ? session.externalCatalog : [])
            : null,
    };
}
