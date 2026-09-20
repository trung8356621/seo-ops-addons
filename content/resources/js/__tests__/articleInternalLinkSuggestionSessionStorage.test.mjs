import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clearInternalLinkSuggestionSession,
    isInternalLinkSuggestionSessionUsable,
    loadInternalLinkSuggestionSession,
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
