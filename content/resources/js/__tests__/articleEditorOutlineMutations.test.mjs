import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    changeHeadingLevelInHtml,
    convertHeadingInHtml,
    deleteHeadingWithContentInHtml,
    setOutlineVisibleInHtml,
} from '../utils/articleEditorOutlineMutations.js';
import { extractOutlineHeadingsFromHtml } from '../utils/articleEditorClientOutline.js';

describe('convertHeadingInHtml', () => {
    const html = '<h2>Chất liệu túi</h2><p>body</p>';

    it('converts H2 to H3 and keeps the heading in outline extract', () => {
        const next = convertHeadingInHtml(html, 0, 'h3');
        assert.match(next, /<h3[^>]*>Chất liệu túi<\/h3>/);
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1').length, 1);
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1')[0].level, 3);
    });

    it('converts H2 to H4 and keeps the heading in outline extract', () => {
        const next = convertHeadingInHtml(html, 0, 'h4');
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1')[0].level, 4);
    });

    it('converts heading to paragraph and keeps text', () => {
        const next = convertHeadingInHtml(html, 0, 'paragraph');
        assert.equal(next, '<p>Chất liệu túi</p><p>body</p>');
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1').length, 0);
    });

    it('converts heading to bold paragraph', () => {
        const next = convertHeadingInHtml(html, 0, 'bold');
        assert.equal(next, '<p><strong>Chất liệu túi</strong></p><p>body</p>');
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1').length, 0);
    });

    it('converts heading to italic paragraph', () => {
        const next = convertHeadingInHtml(html, 0, 'italic');
        assert.equal(next, '<p><em>Chất liệu túi</em></p><p>body</p>');
        assert.equal(extractOutlineHeadingsFromHtml(next, 'b1').length, 0);
    });
});

describe('hide and delete still work', () => {
    it('hides heading from outline without deleting content', () => {
        const next = setOutlineVisibleInHtml('<h2>Visible</h2><p>x</p>', 0, false);
        assert.match(next, /data-outline-visible="false"/);
        assert.match(next, /<h2[^>]*>Visible<\/h2>/);
    });

    it('deletes heading and following section content', () => {
        const next = deleteHeadingWithContentInHtml('<h2>A</h2><p>gone</p><h2>B</h2>', 0);
        assert.equal(next.includes('gone'), false);
        assert.match(next, /<h2[^>]*>B<\/h2>/);
    });

    it('changeHeadingLevelInHtml still converts H2 to H3', () => {
        const next = changeHeadingLevelInHtml('<h2>A</h2>', 0, 3);
        assert.match(next, /<h3[^>]*>A<\/h3>/);
    });
});
