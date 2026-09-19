/**
 * Actionable Domain Link suggestions — exact body match only.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildDomainLinkListForEditor, buildActionableInternalLinkSuggestions } from '../utils/domainLinkOccurrenceIndex.js';
import {
    buildActionableDomainLinkSuggestions,
    findExactAnchorOccurrences,
    findExactAnchorOccurrencesInBlocks,
    plainTextExcludingAnchors,
    selectLongestNonOverlappingMatches,
    tokenizeExactAnchorPlain,
} from '../utils/editorAnchorOccurrenceMatcher.js';
import {
    findSuggestionPhraseOccurrences,
    resolveSuggestionInsertMatch,
} from '../utils/suggestedInternalLinkInsertMatch.js';

describe('EditorAnchorOccurrenceMatcher', () => {
    it('CASE A — no body occurrence => empty', () => {
        const rows = buildDomainLinkListForEditor(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Chúng tôi nhận may balo quà tặng cho doanh nghiệp.</p>' }],
        );
        assert.equal(rows.length, 0);
    });

    it('CASE B — alias occurrence becomes suggestion text', () => {
        const rows = buildDomainLinkListForEditor(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Các mẫu balo laptop phù hợp cho nhân viên văn phòng.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'balo laptop');
        assert.equal(rows[0].href, '/may-balo-laptop');
    });

    it('CASE C / CASE 8 — longest match wins for overlapping range', () => {
        const rows = buildDomainLinkListForEditor(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'Balo Laptop', href: '/may-balo-laptop' },
                { text: 'Laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Dịch vụ May Balo Laptop được thực hiện theo yêu cầu.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'May Balo Laptop');
    });

    it('CASE D / CASE 9 — shorter phrase survives separate occurrence', () => {
        const rows = buildDomainLinkListForEditor(
            [
                { text: 'may balo laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{
                id: 'b1',
                content: '<p>Dịch vụ may balo laptop dành cho doanh nghiệp. Các mẫu balo laptop có nhiều kích thước.</p>',
            }],
        );
        const texts = rows.map((row) => row.text).sort();
        assert.deepEqual(texts, ['balo laptop', 'may balo laptop']);
        assert.ok(rows.every((row) => row.href === '/may-balo-laptop'));
    });

    it('CASE E / CASE 1 — already-linked anchor excluded', () => {
        const blocks = [{ id: 'b1', content: '<p>Xem <a href="/old">balo laptop</a> tại đây.</p>' }];
        const rows = buildDomainLinkListForEditor(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            blocks,
        );
        assert.equal(rows.length, 0);
        assert.equal(findSuggestionPhraseOccurrences(blocks, 'balo laptop').length, 0);
        assert.equal(resolveSuggestionInsertMatch({ text: 'balo laptop' }, null, blocks), null);
        assert.equal(plainTextExcludingAnchors(blocks[0].content).includes('balo'), false);
    });

    it('CASE F — case-insensitive match preserves article casing', () => {
        const rows = buildDomainLinkListForEditor(
            [{ text: 'May Balo Laptop', href: '/may-balo-laptop' }],
            [{ id: 'b1', content: '<p>Dịch vụ MAY BALO LAPTOP theo yêu cầu.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'MAY BALO LAPTOP');
        assert.equal(rows[0].href, '/may-balo-laptop');
    });

    it('CASE 2 — linked first, unlinked second: actionable index 0 is second block', () => {
        const blocks = [
            { id: 'b1', content: '<p><a href="/old">balo laptop</a></p>' },
            { id: 'b2', content: '<p>balo laptop mới</p>' },
        ];
        const rows = buildDomainLinkListForEditor(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            blocks,
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0]._domain_occurrences[0].blockId, 'b2');
        assert.equal(rows[0]._domain_occurrences[0].matchIndex, 0);
        assert.equal(blocks[0].content.includes('href="/old"'), true);

        const match = resolveSuggestionInsertMatch(
            { text: 'balo laptop', matched_phrase: 'balo laptop' },
            rows[0]._domain_occurrences[0],
            blocks,
        );
        assert.ok(match);
        assert.equal(match.blockId, 'b2');
        assert.equal(match.matchIndex, 0);
    });

    it('CASE 3 — unlinked first, linked second: first gets destination', () => {
        const blocks = [
            { id: 'b1', content: '<p>balo laptop mới</p>' },
            { id: 'b2', content: '<p><a href="/old">balo laptop</a></p>' },
        ];
        const match = resolveSuggestionInsertMatch({ text: 'balo laptop' }, null, blocks);
        assert.ok(match);
        assert.equal(match.blockId, 'b1');
        assert.equal(match.matchIndex, 0);
        assert.equal(blocks[1].content.includes('href="/old"'), true);
    });

    it('CASE 4 — actionable indexes skip linked occurrence', () => {
        const blocks = [
            { id: 'b1', content: '<p><a href="/old">balo laptop</a></p>' },
            { id: 'b2', content: '<p>balo laptop A</p>' },
            { id: 'b3', content: '<p>balo laptop B</p>' },
        ];
        const hits = findSuggestionPhraseOccurrences(blocks, 'balo laptop');
        assert.equal(hits.length, 2);
        assert.equal(hits[0].blockId, 'b2');
        assert.equal(hits[0].matchIndex, 0);
        assert.equal(hits[1].blockId, 'b3');
        assert.equal(hits[1].matchIndex, 0);

        const rows = buildActionableDomainLinkSuggestions(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            blocks,
        );
        assert.equal(rows[0]._domain_occurrences.length, 2);
        assert.equal(rows[0]._domain_occurrences[0].blockId, 'b2');
        assert.equal(rows[0]._domain_occurrences[1].blockId, 'b3');
    });

    it('CASE 5 — stored occurrence became linked → relocate other actionable', () => {
        const before = [
            { id: 'b1', content: '<p>balo laptop A</p>' },
            { id: 'b2', content: '<p>balo laptop B</p>' },
        ];
        const stored = resolveSuggestionInsertMatch({ text: 'balo laptop' }, null, before);
        assert.ok(stored);
        assert.equal(stored.blockId, 'b1');

        const after = [
            { id: 'b1', content: '<p><a href="/old">balo laptop A</a></p>' },
            { id: 'b2', content: '<p>balo laptop B</p>' },
        ];
        const relocated = resolveSuggestionInsertMatch(
            { text: 'balo laptop' },
            { blockId: 'b1', matchIndex: 0, phrase: 'balo laptop' },
            after,
        );
        assert.ok(relocated);
        assert.equal(relocated.blockId, 'b2');
    });

    it('CASE 6 — stored occurrence removed → relocate or null', () => {
        const afterGone = [
            { id: 'b1', content: '<p>không còn cụm nữa</p>' },
            { id: 'b2', content: '<p>balo laptop còn lại</p>' },
        ];
        const relocated = resolveSuggestionInsertMatch(
            { text: 'balo laptop' },
            { blockId: 'b1', matchIndex: 0, phrase: 'balo laptop' },
            afterGone,
        );
        assert.ok(relocated);
        assert.equal(relocated.blockId, 'b2');

        const none = resolveSuggestionInsertMatch(
            { text: 'balo laptop' },
            { blockId: 'b1', matchIndex: 0, phrase: 'balo laptop' },
            [{ id: 'b1', content: '<p>trống</p>' }],
        );
        assert.equal(none, null);
    });

    it('CASE 7 — document order follows blocks[] not blockId lexical order', () => {
        const blocks = [
            { id: 'z-block', content: '<p>balo laptop first</p>' },
            { id: 'a-block', content: '<p>balo laptop second</p>' },
        ];
        const hits = findExactAnchorOccurrencesInBlocks(blocks, 'balo laptop');
        assert.equal(hits[0].blockId, 'z-block');
        assert.equal(hits[0].blockIndex, 0);
        assert.equal(hits[1].blockId, 'a-block');
        assert.equal(hits[1].blockIndex, 1);

        const rows = buildActionableDomainLinkSuggestions(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            blocks,
        );
        assert.equal(rows[0]._domain_occurrences[0].blockId, 'z-block');
    });

    it('CASE 10 — destination preserved when matched phrase differs', () => {
        const rows = buildDomainLinkListForEditor(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Các mẫu balo laptop cho nhân viên.</p>' }],
        );
        assert.equal(rows[0].text, 'balo laptop');
        assert.equal(rows[0].href, '/may-balo-laptop');
        assert.equal(rows[0].target_url, '/may-balo-laptop');
    });

    it('soft proximity alone does not create a suggestion', () => {
        const rows = buildDomainLinkListForEditor(
            [{ text: 'may túi đựng mỹ phẩm', href: '/tui' }],
            [{ id: 'b1', content: '<p>xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu</p>' }],
        );
        assert.equal(rows.length, 0);
    });

    it('duplicate phrase occurrences do not create duplicate rows', () => {
        const rows = buildActionableDomainLinkSuggestions(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            [{ id: 'b1', content: '<p>Một balo laptop. Hai balo laptop. Ba balo laptop.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].occurrence_count, 3);
    });

    it('longest-match selection suppresses substring overlap only', () => {
        const tokens = tokenizeExactAnchorPlain('Dịch vụ may balo laptop và balo laptop khác');
        const longHits = findExactAnchorOccurrences(tokens, 'may balo laptop').map((row) => ({
            ...row,
            blockId: 'b1',
            blockIndex: 0,
            hrefKey: '/x',
            href: '/x',
            item: {},
            matchIndex: 0,
            phrase: row.matchedText,
        }));
        const shortHits = findExactAnchorOccurrences(tokens, 'balo laptop').map((row, idx) => ({
            ...row,
            blockId: 'b1',
            blockIndex: 0,
            hrefKey: '/x',
            href: '/x',
            item: {},
            matchIndex: idx,
            phrase: row.matchedText,
        }));
        const selected = selectLongestNonOverlappingMatches([...longHits, ...shortHits]);
        const texts = selected.map((row) => row.matchedText).sort();
        assert.deepEqual(texts, ['balo laptop', 'may balo laptop']);
    });
});

describe('Internal Link suggestions — same actionable occurrence semantics as Domain Link List', () => {
    it('1 — phrase absent from article => hidden', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Chúng tôi nhận may balo quà tặng cho doanh nghiệp.</p>' }],
        );
        assert.equal(rows.length, 0);
    });

    it('2 — alias present => alias/article phrase shown, not the catalog label', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Các mẫu balo laptop cho nhân viên.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'balo laptop');
        assert.equal(rows[0].href, '/may-balo-laptop');
    });

    it('3 — phrase only inside existing <a> => hidden', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            [{ id: 'b1', content: '<p>Xem <a href="/old">balo laptop</a> tại đây.</p>' }],
        );
        assert.equal(rows.length, 0);
    });

    it('4 — linked occurrence first, unlinked occurrence later => visible', () => {
        const blocks = [
            { id: 'b1', content: '<p><a href="/old">balo laptop</a></p>' },
            { id: 'b2', content: '<p>balo laptop mới</p>' },
        ];
        const rows = buildActionableInternalLinkSuggestions([{ text: 'balo laptop', href: '/may-balo-laptop' }], blocks);
        assert.equal(rows.length, 1);
        assert.equal(rows[0]._domain_occurrences[0].blockId, 'b2');
    });

    it('5 — longest overlap wins', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [
                { text: 'May Balo Laptop', href: '/may-balo-laptop' },
                { text: 'Balo Laptop', href: '/may-balo-laptop' },
                { text: 'Laptop', href: '/may-balo-laptop' },
            ],
            [{ id: 'b1', content: '<p>Dịch vụ May Balo Laptop được thực hiện theo yêu cầu.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'May Balo Laptop');
    });

    it('6 — shorter phrase survives at a separate occurrence', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [
                { text: 'may balo laptop', href: '/may-balo-laptop' },
                { text: 'balo laptop', href: '/may-balo-laptop' },
            ],
            [{
                id: 'b1',
                content: '<p>Dịch vụ may balo laptop dành cho doanh nghiệp. Các mẫu balo laptop có nhiều kích thước.</p>',
            }],
        );
        const texts = rows.map((row) => row.text).sort();
        assert.deepEqual(texts, ['balo laptop', 'may balo laptop']);
    });

    it('7 — actual article casing retained', () => {
        const rows = buildActionableInternalLinkSuggestions(
            [{ text: 'May Balo Laptop', href: '/may-balo-laptop' }],
            [{ id: 'b1', content: '<p>Dịch vụ MAY BALO LAPTOP theo yêu cầu.</p>' }],
        );
        assert.equal(rows.length, 1);
        assert.equal(rows[0].text, 'MAY BALO LAPTOP');
    });

    it('8 — current editor blocks are SSOT; edit rebuilds visible suggestions', () => {
        const catalog = [{ text: 'balo laptop', href: '/may-balo-laptop' }];
        const before = buildActionableInternalLinkSuggestions(
            catalog,
            [{ id: 'b1', content: '<p>Chưa nhắc tới cụm này.</p>' }],
        );
        assert.equal(before.length, 0);

        const afterEdit = buildActionableInternalLinkSuggestions(
            catalog,
            [{ id: 'b1', content: '<p>Bài viết vừa được chỉnh sửa nhắc tới balo laptop.</p>' }],
        );
        assert.equal(afterEdit.length, 1);
        assert.equal(afterEdit[0].text, 'balo laptop');
    });

    it('9 — excludedLabels and MAX_INTERNAL_LINK_SLOTS gate still apply', () => {
        const catalog = [{ text: 'balo laptop', href: '/may-balo-laptop' }];
        const blocks = [{ id: 'b1', content: '<p>Các mẫu balo laptop cho nhân viên.</p>' }];

        const excluded = buildActionableInternalLinkSuggestions(catalog, blocks, [], [], ['balo laptop']);
        assert.equal(excluded.length, 0);

        const tenExistingInternalLinks = Array.from({ length: 10 }, (_, i) => ({
            text: `existing ${i}`,
            href: `/existing-${i}`,
        }));
        const slotsFull = buildActionableInternalLinkSuggestions(catalog, blocks, tenExistingInternalLinks, []);
        assert.equal(slotsFull.length, 0);
    });
});

