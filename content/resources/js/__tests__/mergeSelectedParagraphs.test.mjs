import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    applyMergeParagraphBlocks,
    evaluateMergeSelection,
    joinParagraphInners,
    needsParagraphJoinSpace,
} from '../utils/editorCommands/mergeSelectedParagraphs.js';

function block(id, content, type = 'text') {
    return {
        id,
        type,
        isWp: false,
        prefix: '',
        content,
        suffix: '',
    };
}

describe('mergeSelectedParagraphs', () => {
    it('joins inners with a space when words would stick', () => {
        assert.equal(needsParagraphJoinSpace('Hello', 'world'), true);
        assert.equal(needsParagraphJoinSpace('Hello ', 'world'), false);
        assert.equal(
            joinParagraphInners(['Hello <strong>world</strong>', '<em>again</em>']),
            '<p>Hello <strong>world</strong> <em>again</em></p>',
        );
    });

    it('preserves links and bold when merging two paragraphs', () => {
        const source = [
            block('a', '<p>Hello <strong>bold</strong></p>'),
            block('b', '<p>and <a href="/x">link</a></p>'),
            block('c', '<p>keep</p>'),
        ];
        const result = applyMergeParagraphBlocks(source, ['a', 'b']);
        assert.equal(result.ok, true);
        assert.equal(result.keepBlockId, 'a');
        assert.deepEqual(result.removedIds, ['b']);
        assert.equal(result.blocks.length, 2);
        assert.equal(result.blocks[0].id, 'a');
        assert.match(result.blocks[0].content, /<strong>bold<\/strong>/);
        assert.match(result.blocks[0].content, /<a href="\/x">link<\/a>/);
        assert.match(result.blocks[0].content, /bold<\/strong> and <a href=/);
        assert.equal(result.blocks[1].id, 'c');
    });

    it('merges three consecutive paragraphs in document order', () => {
        const source = [
            block('a', '<p>One</p>'),
            block('b', '<p>Two</p>'),
            block('c', '<p>Three</p>'),
        ];
        const result = applyMergeParagraphBlocks(source, ['c', 'a', 'b']);
        assert.equal(result.ok, true);
        assert.equal(result.blocks.length, 1);
        assert.equal(result.blocks[0].content, '<p>One Two Three</p>');
    });

    it('disables when selection spans two sections', () => {
        const source = [block('a', '<p>One</p>'), block('b', '<p>Two</p>')];
        const evaluation = evaluateMergeSelection({
            blocks: source,
            rangeIds: ['a', 'b'],
            sectionByBlockId: new Map([
                ['a', 's1'],
                ['b', 's2'],
            ]),
        });
        assert.equal(evaluation.ok, false);
        assert.equal(evaluation.reasonKey, 'editor_merge_cross_section');
    });

    it('disables when range is not contiguous', () => {
        const source = [
            block('a', '<p>One</p>'),
            block('b', '<p>Two</p>'),
            block('c', '<p>Three</p>'),
        ];
        const evaluation = evaluateMergeSelection({
            blocks: source,
            rangeIds: ['a', 'c'],
            sectionByBlockId: new Map([
                ['a', 's1'],
                ['b', 's1'],
                ['c', 's1'],
            ]),
        });
        assert.equal(evaluation.ok, false);
        assert.equal(evaluation.reasonKey, 'editor_merge_not_contiguous');
    });

    it('disables for CTA, FAQ, heading, list, table, and image', () => {
        const cases = [
            block('cta', '<div class="seo-managed-cta">[seo_ops_cta]x[/seo_ops_cta]</div>'),
            block('faq', '<p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq]</p>'),
            block('h', '<h2>Title</h2>'),
            block('list', '<ul><li>One</li></ul>'),
            block('table', '<table><tr><td>1</td></tr></table>'),
            block('img', '<figure><img src="/a.jpg" /></figure>', 'image'),
        ];

        for (const special of cases) {
            const evaluation = evaluateMergeSelection({
                blocks: [block('a', '<p>One</p>'), special],
                rangeIds: ['a', special.id],
                sectionByBlockId: new Map([
                    ['a', 's1'],
                    [special.id, 's1'],
                ]),
            });
            assert.equal(evaluation.ok, false, special.id);
            assert.equal(evaluation.reasonKey, 'editor_merge_special_blocks', special.id);
        }
    });
});
