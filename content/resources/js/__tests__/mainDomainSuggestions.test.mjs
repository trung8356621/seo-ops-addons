import test from 'node:test';
import assert from 'node:assert/strict';

import { filterMainDomainSuggestionItems } from '../utils/articleLinkSuggestionFilter.js';
import { resolveFeaturedSnippetViolationFromTables } from '../../../../seo/resources/js/utils/seoContentBonus.js';

test('cross-domain main suggestions stay out of current external count', () => {
    const suggestions = filterMainDomainSuggestionItems(
        [
            { href: 'https://main.example/a', page_title: 'A' },
            { href: 'https://main.example/b', page_title: 'B' },
        ],
        [],
        [{ href: 'https://zalo.me/x' }],
    );

    assert.equal(suggestions.length, 2);
});

test('already linked and current URLs are not duplicated', () => {
    const suggestions = filterMainDomainSuggestionItems(
        [
            { href: 'https://main.example/current' },
            { href: 'https://main.example/current/' },
            { href: 'https://main.example/other' },
        ],
        [{ href: 'https://main.example/current' }],
        [],
    );

    assert.equal(suggestions.length, 1);
    assert.equal(suggestions[0].href, 'https://main.example/other');
});

test('featured snippet missing vs below_good stay distinct', () => {
    assert.equal(resolveFeaturedSnippetViolationFromTables([]), 'featured_snippet_missing');
    assert.equal(
        resolveFeaturedSnippetViolationFromTables([{ rows: 2, cols: 2, cells: 4 }]),
        'featured_snippet_below_good',
    );
});

// 6 — external main-domain suggestions still only dedupe by href; no content-matching
// is applied here (that stays scoped to the internal-relationship merge path).
test('external main-domain items are unaffected by content presence — dedupe-only behavior unchanged', () => {
    const suggestions = filterMainDomainSuggestionItems(
        [
            { href: 'https://main.example/absent-from-article', page_title: 'Not in article body at all' },
        ],
        [],
        [],
    );

    assert.equal(suggestions.length, 1);
    assert.equal(suggestions[0].page_title, 'Not in article body at all');
});

