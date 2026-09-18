/**
 * Actionable Domain Link suggestions — exact body match only.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildDomainLinkListForEditor } from '../utils/domainLinkOccurrenceIndex.js';
import {
    buildActionableDomainLinkSuggestions,
    findExactAnchorOccurrences,
    plainTextExcludingAnchors,
    selectLongestNonOverlappingMatches,
    tokenizeExactAnchorPlain,
} from '../utils/editorAnchorOccurrenceMatcher.js';

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

    it('CASE C — longest match wins for overlapping range', () => {
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

    it('CASE D — shorter phrase survives separate occurrence', () => {
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

    it('CASE E — already-linked anchor excluded', () => {
        const rows = buildDomainLinkListForEditor(
            [{ text: 'balo laptop', href: '/may-balo-laptop' }],
            [{ id: 'b1', content: '<p>Xem <a href="/old">balo laptop</a> tại đây.</p>' }],
        );
        assert.equal(rows.length, 0);
        assert.equal(plainTextExcludingAnchors('<p>Xem <a href="/old">balo laptop</a> tại đây.</p>').includes('balo'), false);
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
            hrefKey: '/x',
            href: '/x',
            item: {},
            matchIndex: 0,
            phrase: row.matchedText,
        }));
        const shortHits = findExactAnchorOccurrences(tokens, 'balo laptop').map((row) => ({
            ...row,
            blockId: 'b1',
            hrefKey: '/x',
            href: '/x',
            item: {},
            matchIndex: 0,
            phrase: row.matchedText,
        }));
        const selected = selectLongestNonOverlappingMatches([...longHits, ...shortHits]);
        const texts = selected.map((row) => row.matchedText).sort();
        assert.deepEqual(texts, ['balo laptop', 'may balo laptop']);
    });
});
