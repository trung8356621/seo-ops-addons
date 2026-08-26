/**
 * DomainLinkMatcher unit tests (node:test).
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    findDomainLinkOccurrencesInBlocks,
    findDomainLinkOccurrencesInPlainText,
} from '../utils/domainLinkMatcher.js';
import { nextDomainLinkOccurrenceIndex } from '../utils/domainLinkOccurrenceIndex.js';
import { resolveDomainLinkInventory } from '../utils/domainLinkSourceResolver.js';
import { filterDomainLinksInArticleContent, textContainsPhrase } from '../utils/articleLinkSuggestionFilter.js';

describe('DomainLinkMatcher', () => {
    it('Test 1 — exact phrase high score', () => {
        const rows = findDomainLinkOccurrencesInPlainText(
            'chúng tôi nhận may túi đựng mỹ phẩm số lượng lớn',
            'may túi đựng mỹ phẩm',
        );
        assert.ok(rows.length >= 1);
        assert.equal(rows[0].level, 'exact');
        assert.ok(rows[0].score >= 900);
    });

    it('Test 2 — soft match score < exact', () => {
        const exact = findDomainLinkOccurrencesInPlainText(
            'chúng tôi nhận may túi đựng mỹ phẩm số lượng lớn',
            'may túi đựng mỹ phẩm',
        );
        const soft = findDomainLinkOccurrencesInPlainText(
            'xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu',
            'may túi đựng mỹ phẩm',
        );
        assert.ok(soft.length >= 1);
        assert.ok(soft[0].score < exact[0].score);
    });

    it('Test 3 — proximity may túi canvas', () => {
        const rows = findDomainLinkOccurrencesInPlainText(
            'xưởng chuyên may các dòng túi bằng chất liệu canvas',
            'may túi canvas',
        );
        assert.ok(rows.length >= 1);
    });

    it('Test 4 — tokens too far → no match', () => {
        const far = [
            'xưởng chuyên may balo theo yêu cầu khách hàng.',
            'Chúng tôi có nhiều năm kinh nghiệm trong ngành.',
            'Chất liệu vải đa dạng và bền đẹp.',
            'Cuối cùng khách có thể chọn túi phù hợp nhu cầu.',
            'Một số đơn hàng yêu cầu canvas nhập khẩu cao cấp.',
        ].join(' ');
        const rows = findDomainLinkOccurrencesInPlainText(far, 'may túi canvas');
        assert.equal(rows.length, 0);
    });

    it('Test 5 — two-token học sinh tight vs loose', () => {
        const tight = findDomainLinkOccurrencesInPlainText(
            'sản phẩm dành cho học sinh tiểu học',
            'học sinh',
        );
        assert.ok(tight.length >= 1);

        const loose = findDomainLinkOccurrencesInPlainText(
            'học cách lựa chọn sản phẩm phù hợp cho sinh hoạt',
            'học sinh',
        );
        assert.equal(loose.length, 0);
    });

    it('Test 6 — Vietnamese accent preferred', () => {
        const accented = findDomainLinkOccurrencesInPlainText(
            'các mẫu túi giữ nhiệt cao cấp',
            'túi giữ nhiệt',
        );
        assert.ok(accented.length >= 1);
        assert.ok(accented[0].level === 'exact' || accented[0].score >= 800);

        const foldedOnly = findDomainLinkOccurrencesInPlainText(
            'cac mau tui giu nhiet cao cap',
            'túi giữ nhiệt',
        );
        if (foldedOnly.length > 0) {
            assert.ok(foldedOnly[0].score < accented[0].score);
        }
    });

    it('Test 7 — occurrence count across blocks', () => {
        const blocks = [
            { id: 'b1', content: '<p>xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu</p>' },
            { id: 'b2', content: '<p>dịch vụ may túi đựng mỹ phẩm số lượng lớn</p>' },
            { id: 'b3', content: '<p>chuyên may túi đựng mỹ phẩm xuất khẩu</p>' },
        ];
        const rows = findDomainLinkOccurrencesInBlocks(blocks, 'may túi đựng mỹ phẩm');
        assert.equal(rows.length, 3);
    });

    it('Test 8 — navigation cycling', () => {
        assert.equal(nextDomainLinkOccurrenceIndex(0, 3), 0);
        assert.equal(nextDomainLinkOccurrenceIndex(1, 3), 1);
        assert.equal(nextDomainLinkOccurrenceIndex(2, 3), 2);
        assert.equal(nextDomainLinkOccurrenceIndex(3, 3), 0);
    });

    it('custom links ordered before product_cat + dedupe', () => {
        const rows = resolveDomainLinkInventory([
            { text: 'túi canvas', href: '/cat/canvas', source: 'product_cat' },
            { text: 'Anchor A', href: '/custom-a', source: 'custom' },
            { text: 'túi canvas', href: '/custom-canvas', source: 'custom' },
            { text: 'Anchor B', href: '/custom-b', source: 'custom' },
        ]);
        assert.equal(rows[0].text, 'Anchor A');
        assert.equal(rows[0].source, 'custom');
        assert.ok(rows.some((row) => row.text === 'túi canvas' && row.source === 'custom'));
        assert.equal(rows.filter((row) => normalizeLabel(row.text) === 'túi canvas').length, 1);
    });

    it('Test 9 — document edit increases occurrence count', () => {
        const anchor = 'may túi đựng mỹ phẩm';
        const before = findDomainLinkOccurrencesInBlocks(
            [{ id: 'b1', content: '<p>xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu</p>' }],
            anchor,
        );
        assert.equal(before.length, 1);

        const after = findDomainLinkOccurrencesInBlocks(
            [
                { id: 'b1', content: '<p>xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu</p>' },
                { id: 'b2', content: '<p>thêm dịch vụ may túi đựng mỹ phẩm số lượng lớn</p>' },
                { id: 'b3', content: '<p>và may túi đựng mỹ phẩm xuất khẩu</p>' },
            ],
            anchor,
        );
        assert.equal(after.length, 3);
    });
});

describe('Internal Links regression — exact filter untouched', () => {
    it('filterDomainLinksInArticleContent still requires exact phrase', () => {
        const links = [{ text: 'may túi đựng mỹ phẩm', href: '/x' }];
        const softOnly = filterDomainLinksInArticleContent(
            links,
            'xưởng nhận may nhiều mẫu túi mỹ phẩm theo yêu cầu',
        );
        assert.equal(softOnly.length, 0);

        const exact = filterDomainLinksInArticleContent(
            links,
            'chúng tôi nhận may túi đựng mỹ phẩm số lượng lớn',
        );
        assert.equal(exact.length, 1);
        assert.equal(textContainsPhrase('abc may túi đựng mỹ phẩm xyz', 'may túi đựng mỹ phẩm'), true);
    });
});

function normalizeLabel(text) {
    return String(text ?? '').trim().toLowerCase();
}
