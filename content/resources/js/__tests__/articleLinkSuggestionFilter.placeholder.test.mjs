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
