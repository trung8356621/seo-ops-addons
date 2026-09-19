import assert from 'node:assert/strict';
import { describe, it, before } from 'node:test';

before(() => {
    globalThis.window = { location: { origin: 'https://mayhophat.com' } };
});

const {
    normalizeHrefForCompare,
    isUnresolvedSuggestionHref,
    isInternalHrefForSite,
    mergeSuggestionCatalog,
    compareSuggestionCandidateRank,
    partitionSuggestionCatalogBySite,
    filterSuggestedInternalLinks,
    buildVisibleInternalSuggestions,
} = await import('../utils/articleLinkSuggestionFilter.js');

function unresolved(text) {
    return {
        text,
        href: '#',
        target_url: null,
        destination_resolved: false,
        can_insert: true,
    };
}

describe('placeholder href semantics', () => {
    it('A — normalizeHrefForCompare(#) is empty', () => {
        assert.equal(normalizeHrefForCompare('#'), '');
        assert.equal(isUnresolvedSuggestionHref('#'), true);
    });

    it('B — normalizeHrefForCompare(#section) is empty', () => {
        assert.equal(normalizeHrefForCompare('#section'), '');
        assert.equal(isUnresolvedSuggestionHref('#section'), true);
    });

    it('C — three unresolved survive filterSuggestedInternalLinks', () => {
        const suggested = [
            unresolved('anchor A'),
            unresolved('anchor B'),
            unresolved('anchor C'),
        ];
        const filtered = filterSuggestedInternalLinks(suggested, [], []);
        assert.equal(filtered.length, 3);
    });

    it('D — partition puts unresolved into internal, not external', () => {
        const catalog = [
            unresolved('anchor A'),
            unresolved('anchor B'),
            unresolved('anchor C'),
        ];
        const { internal, external } = partitionSuggestionCatalogBySite(catalog, 'mayhophat.com');
        assert.equal(internal.length, 3);
        assert.equal(external.length, 0);
    });

    it('E — mergeSuggestionCatalog preserves null target_url and destination_resolved', () => {
        const merged = mergeSuggestionCatalog([unresolved('anchor A')]);
        assert.equal(merged.length, 1);
        assert.equal(merged[0].href, '#');
        assert.equal(merged[0].target_url, null);
        assert.equal(merged[0].destination_resolved, false);
        assert.equal(merged[0].can_insert, true);
    });

    it('F — two distinct unresolved survive merge + partition + visible', () => {
        const catalog = mergeSuggestionCatalog([
            unresolved('phrase one'),
            unresolved('phrase two'),
        ]);
        const { internal, external } = partitionSuggestionCatalogBySite(catalog, 'mayhophat.com');
        assert.equal(external.length, 0);
        const visible = buildVisibleInternalSuggestions({
            catalog: internal,
            internal: [],
            external: [],
            skipContentFilter: true,
        });
        assert.equal(visible.length, 2);
    });

    it('G — same unresolved anchor still dedupes by label', () => {
        const merged = mergeSuggestionCatalog([
            unresolved('túi vải bố'),
            unresolved('Túi Vải Bố'),
        ]);
        assert.equal(merged.length, 1);
    });

    it('H — two real same URLs still dedupe by URL in filter', () => {
        const filtered = filterSuggestedInternalLinks(
            [
                {
                    text: 'anchor A',
                    href: 'https://mayhophat.com/foo',
                    target_url: 'https://mayhophat.com/foo',
                    destination_resolved: true,
                },
                {
                    text: 'anchor B',
                    href: 'https://mayhophat.com/foo/',
                    target_url: 'https://mayhophat.com/foo/',
                    destination_resolved: true,
                },
            ],
            [],
            [],
        );
        assert.equal(filtered.length, 1);
        assert.equal(filtered[0].text, 'anchor A');
    });

    it('I — resolved external HTTP partitions external', () => {
        const { internal, external } = partitionSuggestionCatalogBySite(
            [
                {
                    text: 'ext',
                    href: 'https://other.example/page',
                    target_url: 'https://other.example/page',
                    destination_resolved: true,
                },
            ],
            'mayhophat.com',
        );
        assert.equal(internal.length, 0);
        assert.equal(external.length, 1);
    });

    it('J — resolved same-domain HTTP partitions internal', () => {
        const { internal, external } = partitionSuggestionCatalogBySite(
            [
                {
                    text: 'int',
                    href: 'https://mayhophat.com/page',
                    target_url: 'https://mayhophat.com/page',
                    destination_resolved: true,
                },
            ],
            'mayhophat.com',
        );
        assert.equal(internal.length, 1);
        assert.equal(external.length, 0);
    });

    it('K — mixed real + unresolved + external partition counts', () => {
        const catalog = mergeSuggestionCatalog([
            {
                text: 'real one',
                href: 'https://mayhophat.com/a',
                target_url: 'https://mayhophat.com/a',
                destination_resolved: true,
            },
            {
                text: 'real two',
                href: 'https://mayhophat.com/b',
                target_url: 'https://mayhophat.com/b',
                destination_resolved: true,
            },
            unresolved('unresolved one'),
            unresolved('unresolved two'),
            unresolved('unresolved three'),
            {
                text: 'external one',
                href: 'https://other.example/x',
                target_url: 'https://other.example/x',
                destination_resolved: true,
            },
        ]);
        const { internal, external } = partitionSuggestionCatalogBySite(catalog, 'mayhophat.com');
        assert.equal(internal.length, 5);
        assert.equal(external.length, 1);
        assert.equal(
            internal.filter((row) => row.destination_resolved === false).length,
            3,
        );
    });
});

describe('critical ArticleLinksSidebar chain', () => {
    it('server payload → merge → partition → visible = 3 unresolved', () => {
        const serverPayload = [
            unresolved('anchor A'),
            unresolved('anchor B'),
            unresolved('anchor C'),
        ];

        const merged = mergeSuggestionCatalog(serverPayload);
        assert.equal(merged.length, 3);
        assert.ok(merged.every((row) => row.target_url === null && row.destination_resolved === false));

        const { internal, external } = partitionSuggestionCatalogBySite(merged, 'mayhophat.com');
        assert.equal(internal.length, 3);
        assert.equal(external.length, 0);

        const visible = buildVisibleInternalSuggestions({
            catalog: internal,
            internal: [],
            external: [],
            skipContentFilter: true,
        });
        assert.equal(visible.length, 3);
        assert.ok(visible.every((row) => row.href === '#' && row.destination_resolved === false));
    });
});

describe('mergeSuggestionCatalog — relevance metadata + deterministic duplicate resolution', () => {
    it('A — preserves backend relevance metadata untouched', () => {
        const merged = mergeSuggestionCatalog([
            {
                text: 'túi vải bố',
                href: '/tui-vai-bo',
                target_url: '/tui-vai-bo',
                destination_resolved: true,
                score: 95,
                match_reason: 'title_exact',
                source_priority: 1,
                source: 'product_cat',
                candidate_source: 'product_cat',
                provenance: { source_stage: 'product_cat', source_priority: 1 },
                target_article_id: 42,
            },
        ]);

        assert.equal(merged.length, 1);
        const [row] = merged;
        assert.equal(row.score, 95);
        assert.equal(row.match_reason, 'title_exact');
        assert.equal(row.source_priority, 1);
        assert.equal(row.candidate_source, 'product_cat');
        assert.deepEqual(row.provenance, { source_stage: 'product_cat', source_priority: 1 });
        assert.equal(row.target_article_id, 42);
    });

    it('B — same label duplicate: stronger candidate survives regardless of input order', () => {
        const weakFirst = mergeSuggestionCatalog([
            {
                text: 'túi vải bố',
                href: '/generic',
                destination_resolved: true,
                score: 38,
                source_priority: 4,
                candidate_source: 'generic',
            },
            {
                text: 'túi vải bố',
                href: '/product-cat',
                destination_resolved: true,
                score: 60,
                source_priority: 1,
                candidate_source: 'product_cat',
            },
        ]);
        assert.equal(weakFirst.length, 1);
        assert.equal(weakFirst[0].href, '/product-cat');

        const strongFirst = mergeSuggestionCatalog([
            {
                text: 'túi vải bố',
                href: '/product-cat',
                destination_resolved: true,
                score: 60,
                source_priority: 1,
                candidate_source: 'product_cat',
            },
            {
                text: 'túi vải bố',
                href: '/generic',
                destination_resolved: true,
                score: 38,
                source_priority: 4,
                candidate_source: 'generic',
            },
        ]);
        assert.equal(strongFirst.length, 1);
        assert.equal(strongFirst[0].href, '/product-cat');
    });

    it('C — unresolved candidate cannot beat resolved candidate for same phrase', () => {
        const merged = mergeSuggestionCatalog([
            {
                text: 'túi vải bố',
                href: '#',
                destination_resolved: false,
                score: 90,
                source_priority: 1,
            },
            {
                text: 'túi vải bố',
                href: '/tui-vai-bo',
                destination_resolved: true,
                score: 40,
                source_priority: 4,
            },
        ]);
        assert.equal(merged.length, 1);
        assert.equal(merged[0].destination_resolved, true);
        assert.equal(merged[0].href, '/tui-vai-bo');
    });

    it('F — source_priority breaks score ties/order correctly', () => {
        const merged = mergeSuggestionCatalog([
            { text: 'balo laptop', href: '/a', destination_resolved: true, score: 80, source_priority: 3 },
            { text: 'balo laptop', href: '/b', destination_resolved: true, score: 80, source_priority: 1 },
        ]);
        assert.equal(merged.length, 1);
        assert.equal(merged[0].href, '/b');
    });

    it('G — score breaks ties within same source priority', () => {
        const merged = mergeSuggestionCatalog([
            { text: 'balo laptop', href: '/a', destination_resolved: true, score: 55, source_priority: 2 },
            { text: 'balo laptop', href: '/b', destination_resolved: true, score: 90, source_priority: 2 },
        ]);
        assert.equal(merged.length, 1);
        assert.equal(merged[0].href, '/b');
    });

    it('compareSuggestionCandidateRank places rows without source_priority/score after ranked rows', () => {
        const ranked = { destination_resolved: true, source_priority: 2, score: 10 };
        const unranked = { destination_resolved: true };
        assert.ok(compareSuggestionCandidateRank(ranked, unranked) < 0);
        assert.ok(compareSuggestionCandidateRank(unranked, ranked) > 0);
    });
});

