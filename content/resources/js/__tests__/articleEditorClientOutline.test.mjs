import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    buildClientOutlineTree,
    buildOutlineTreeFromHeadings,
    extractOutlineHeadingsFromHtml,
    findLocalDuplicateHeadingKeys,
    flattenClientOutlineNodes,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline.js';

describe('extractOutlineHeadingsFromHtml', () => {
    it('extracts every H2/H3/H4 in document order', () => {
        const html = '<h2>Top 10</h2><p>intro</p><h3>Herschel Supply Co.</h3><p>body</p><h4>Chi tiết</h4>';
        const headings = extractOutlineHeadingsFromHtml(html, 'block-a');
        assert.equal(headings.length, 3);
        assert.deepEqual(headings.map((row) => `${row.level}:${row.heading_text}`), [
            '2:Top 10',
            '3:Herschel Supply Co.',
            '4:Chi tiết',
        ]);
        assert.equal(headings[1].id, 'client:block-a:1');
        assert.equal(headings[1].heading_index, 1);
        assert.equal(headings[1].block_id, 'block-a');
    });

    it('keeps hidden headings in the extract but marks outline_visible false', () => {
        const html = '<h3 data-outline-visible="false">Ẩn</h3><h3>Hiện</h3>';
        const headings = extractOutlineHeadingsFromHtml(html, 'b1');
        assert.equal(headings[0].outline_visible, false);
        assert.equal(headings[1].outline_visible, true);
    });
});

describe('buildOutlineTreeFromHeadings', () => {
    it('nests H3 under H2 and preserves order', () => {
        const tree = buildOutlineTreeFromHeadings([
            { id: 'a', level: 2, heading_text: 'Top 10 thương hiệu balo', heading_index: 0, block_id: '1', outline_visible: true },
            { id: 'b', level: 3, heading_text: 'Herschel Supply Co.', heading_index: 1, block_id: '1', outline_visible: true },
            { id: 'c', level: 2, heading_text: 'Kết luận', heading_index: 0, block_id: '2', outline_visible: true },
        ]);
        assert.equal(tree.length, 2);
        assert.equal(tree[0].heading_text, 'Top 10 thương hiệu balo');
        assert.equal(tree[0].children.length, 1);
        assert.equal(tree[0].children[0].heading_text, 'Herschel Supply Co.');
        assert.equal(tree[1].heading_text, 'Kết luận');
    });

    it('omits outline_visible=false headings', () => {
        const tree = buildOutlineTreeFromHeadings([
            { id: 'a', level: 2, heading_text: 'Visible', heading_index: 0, block_id: '1', outline_visible: true },
            { id: 'b', level: 3, heading_text: 'Hidden', heading_index: 1, block_id: '1', outline_visible: false },
        ]);
        assert.equal(tree[0].children.length, 0);
    });
});

describe('buildClientOutlineTree + duplicates', () => {
    const blocks = [
        { id: 's1', type: 'text', content: '<h2>Top 10 thương hiệu balo</h2><p>x</p><h3>Herschel Supply Co.</h3><p>y</p>' },
        { id: 's2', type: 'text', content: '<h2>Kết luận</h2><h3>Balo du lịch</h3><h3>Balo du lịch</h3>' },
        { id: 'img', type: 'image', content: '' },
    ];

    it('projects headings across blocks, not only the first heading', () => {
        const tree = buildClientOutlineTree(blocks);
        const flat = flattenClientOutlineNodes(tree);
        assert.deepEqual(flat.map((node) => `${node.level}:${node.heading_text}`), [
            '2:Top 10 thương hiệu balo',
            '3:Herschel Supply Co.',
            '2:Kết luận',
            '3:Balo du lịch',
            '3:Balo du lịch',
        ]);
        assert.equal(flat[1].block_id, 's1');
        assert.equal(flat[1].heading_index, 1);
    });

    it('detects simple local duplicates', () => {
        const tree = buildClientOutlineTree(blocks);
        const keys = findLocalDuplicateHeadingKeys(tree);
        assert.equal(keys.has('balo du lịch'), true);
        assert.equal(keys.has('herschel supply co.'), false);
    });

    it('fingerprint includes nested headings', () => {
        const fp = outlineHeadingFingerprint(blocks);
        assert.match(fp, /Herschel Supply Co/);
        assert.match(fp, /Balo du lịch/);
    });
});
