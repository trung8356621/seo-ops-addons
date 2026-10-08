import assert from 'node:assert/strict';
import test from 'node:test';
import {
    INTERNAL_LINK_ALGORITHM,
    INTERNAL_LINK_SUGGESTION_SESSION_VERSION,
    clearInternalLinkSuggestionSession,
    isInternalLinkSuggestionSessionUsable,
    loadInternalLinkSuggestionSession,
    resolveSuggestionSessionCache,
    saveInternalLinkSuggestionSession,
} from '../utils/articleInternalLinkSuggestionSessionStorage.js';

function installLocalStorage() {
    /** @type {Map<string, string>} */
    const map = new Map();
    globalThis.window = {
        localStorage: {
            getItem: (key) => (map.has(key) ? map.get(key) : null),
            setItem: (key, value) => {
                map.set(String(key), String(value));
            },
            removeItem: (key) => {
                map.delete(String(key));
            },
        },
    };
    return map;
}

test('session storage isolates by site_id + article_id', () => {
    installLocalStorage();
    saveInternalLinkSuggestionSession(11, 2, {
        contentFingerprint: 'fp-a',
        catalog: [{ text: 'alpha', href: 'https://example.com/a' }],
        phase: 'source1_done',
        hasResults: true,
        exhausted: false,
        failedKeys: ['product_cat|0|alpha|example.com/a'],
        advancedCursor: { stage: 'topic', offset: 3 },
        advancedEnabled: true,
    });
    saveInternalLinkSuggestionSession(12, 2, {
        contentFingerprint: 'fp-b',
        catalog: [{ text: 'beta', href: 'https://example.com/b' }],
        phase: 'exhausted',
        hasResults: true,
        exhausted: true,
    });

    const a = loadInternalLinkSuggestionSession(11, 2);
    const b = loadInternalLinkSuggestionSession(12, 2);
    const otherSite = loadInternalLinkSuggestionSession(11, 9);

    assert.equal(a?.catalog?.[0]?.text, 'alpha');
    assert.equal(a?.advancedCursor?.stage, 'topic');
    assert.equal(a?.advancedCursor?.offset, 3);
    assert.equal(a?.failedKeys?.length, 1);
    assert.equal(b?.exhausted, true);
    assert.equal(otherSite, null);
});

test('usable session requires matching fingerprint', () => {
    installLocalStorage();
    saveInternalLinkSuggestionSession(5, 1, {
        contentFingerprint: 'fp-1',
        catalog: [{ text: 'x', href: '/x' }],
        hasResults: true,
        phase: 'source1_done',
    });
    const session = loadInternalLinkSuggestionSession(5, 1);
    assert.equal(isInternalLinkSuggestionSessionUsable(session, {
        articleId: 5,
        siteId: 1,
        contentFingerprint: 'fp-1',
    }), true);
    assert.equal(isInternalLinkSuggestionSessionUsable(session, {
        articleId: 5,
        siteId: 1,
        contentFingerprint: 'fp-stale',
    }), false);
});

test('clear removes session for article', () => {
    installLocalStorage();
    saveInternalLinkSuggestionSession(7, 3, {
        contentFingerprint: 'fp',
        catalog: [{ text: 'z' }],
        hasResults: true,
    });
    clearInternalLinkSuggestionSession(7, 3);
    assert.equal(loadInternalLinkSuggestionSession(7, 3), null);
});

function saveBoth(articleId, siteId, engines, fingerprint = 'fp') {
    saveInternalLinkSuggestionSession(articleId, siteId, {
        contentFingerprint: fingerprint,
        catalog: [{ text: 'internal-row', href: '/in', suggestion_engine: engines.internal }],
        externalCatalog: [{ text: 'external-row', href: 'https://en.wikipedia.org/wiki/USB', suggestion_engine: engines.external }],
        hasResults: true,
        phase: 'source1_done',
        suggestionEngines: engines,
        generated: { internal: true, external: true },
    });
}

test('legacy internal cache is not served and wiki cache survives', () => {
    const map = installLocalStorage();
    map.set('seo_article_internal_link_suggestion_session_4_13614', JSON.stringify({
        version: INTERNAL_LINK_SUGGESTION_SESSION_VERSION,
        siteId: 4,
        articleId: 13614,
        contentFingerprint: 'fp',
        catalog: [{ text: 'internal-row', href: '/in', suggestion_engine: 'legacy' }],
        externalCatalog: [{ text: 'external-row', href: 'https://en.wikipedia.org/wiki/USB', suggestion_engine: 'wiki_v2' }],
        hasResults: true,
        suggestionEngines: { internal: 'legacy', external: 'wiki_v2' },
        generated: { internal: true, external: true },
    }));
    const session = loadInternalLinkSuggestionSession(13614, 4);
    const resolved = resolveSuggestionSessionCache(session, {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'fp',
        suggestionEngines: { internal: 'semantic_v2', external: 'wiki_v2' },
    });

    assert.equal(resolved.internal, null);
    assert.equal(resolved.external?.[0]?.text, 'external-row');
    assert.equal(resolved.external?.[0]?.suggestion_engine, 'wiki_v2');
});

test('current v2 suggestions stay reusable', () => {
    installLocalStorage();
    saveBoth(13614, 4, { internal: 'semantic_v2', external: 'wiki_v2' });
    const session = loadInternalLinkSuggestionSession(13614, 4);
    assert.equal(session?.internalAlgorithm, INTERNAL_LINK_ALGORITHM);
    const resolved = resolveSuggestionSessionCache(session, {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'fp',
        suggestionEngines: { internal: 'semantic_v2', external: 'wiki_v2' },
    });

    assert.equal(resolved.internal?.[0]?.text, 'internal-row');
    assert.equal(resolved.external?.[0]?.suggestion_engine, 'wiki_v2');
});

test('internal and external invalidation are independent', () => {
    installLocalStorage();
    saveBoth(13614, 4, { internal: 'semantic_v2', external: 'wiki_v2' });
    const session = loadInternalLinkSuggestionSession(13614, 4);
    const onlyExternalChanged = resolveSuggestionSessionCache(session, {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'fp',
        suggestionEngines: { internal: 'legacy', external: 'legacy' },
    });

    assert.equal(onlyExternalChanged.internal?.[0]?.text, 'internal-row');
    assert.equal(onlyExternalChanged.external, null);
});

test('content change invalidates both categories', () => {
    installLocalStorage();
    saveBoth(13614, 4, { internal: 'semantic_v2', external: 'wiki_v2' }, 'before');
    const session = loadInternalLinkSuggestionSession(13614, 4);
    const resolved = resolveSuggestionSessionCache(session, {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'after',
        suggestionEngines: { internal: 'semantic_v2', external: 'wiki_v2' },
    });

    assert.equal(resolved.internal, null);
    assert.equal(resolved.external, null);
});

test('unchanged engine and content reuse the same catalogs', () => {
    installLocalStorage();
    saveBoth(13614, 4, { internal: 'semantic_v2', external: 'legacy' });
    const session = loadInternalLinkSuggestionSession(13614, 4);
    const expected = {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'fp',
        suggestionEngines: { internal: 'semantic_v2', external: 'legacy' },
    };
    const first = resolveSuggestionSessionCache(session, expected);
    const second = resolveSuggestionSessionCache(session, expected);

    assert.equal(first.internal?.[0]?.text, 'internal-row');
    assert.equal(second.internal?.[0]?.href, first.internal?.[0]?.href);
    assert.equal(second.external?.[0]?.text, 'external-row');
    assert.equal(isInternalLinkSuggestionSessionUsable(session, expected), true);
});

test('older session version is not reused', () => {
    const map = installLocalStorage();
    map.set('seo_article_internal_link_suggestion_session_4_13614', JSON.stringify({
        version: INTERNAL_LINK_SUGGESTION_SESSION_VERSION - 1,
        siteId: 4,
        articleId: 13614,
        contentFingerprint: 'fp',
        catalog: [{ text: 'stale', href: '/stale' }],
        externalCatalog: [{ text: 'stale-ext', href: 'https://example.com' }],
        hasResults: true,
        suggestionEngines: { internal: 'legacy', external: 'legacy' },
        generated: { internal: true, external: true },
    }));
    const session = loadInternalLinkSuggestionSession(13614, 4);
    const resolved = resolveSuggestionSessionCache(session, {
        articleId: 13614,
        siteId: 4,
        contentFingerprint: 'fp',
        suggestionEngines: { internal: 'legacy', external: 'legacy' },
    });

    assert.equal(resolved.internal, null);
    assert.equal(resolved.external, null);
});

test('exhausted-only session without catalog is still usable', () => {
    installLocalStorage();
    saveInternalLinkSuggestionSession(8, 1, {
        contentFingerprint: 'fp',
        catalog: [],
        hasResults: true,
        exhausted: true,
        phase: 'exhausted',
    });
    const session = loadInternalLinkSuggestionSession(8, 1);
    assert.equal(isInternalLinkSuggestionSessionUsable(session, {
        articleId: 8,
        siteId: 1,
        contentFingerprint: 'fp',
    }), true);
});
