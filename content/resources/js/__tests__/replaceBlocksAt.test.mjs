import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { applyReplaceBlocksAt } from '../utils/editorCommands/replaceBlocksAt.js';

function block(id, content) {
    return {
        id,
        type: 'text',
        isWp: false,
        prefix: '',
        content,
        suffix: '',
    };
}

describe('applyReplaceBlocksAt host mutation', () => {
    it('replaces the source block immutably and changes the array reference', () => {
        const source = [block('a', '<p>AAA BBB</p>'), block('b', '<p>keep</p>')];
        const frozen = source;
        const result = applyReplaceBlocksAt(source, {
            sourceBlockId: 'a',
            replacements: ['<p>AAA</p>', '<p>BBB</p>'],
            createBlock: () => block('n1', ''),
        });

        assert.equal(result.ok, true);
        assert.notEqual(result.blocks, frozen);
        assert.equal(source.length, 2);
        assert.equal(source[0].content, '<p>AAA BBB</p>');
        assert.equal(result.blocks.length, 3);
        assert.equal(result.blocks[0].id, 'a');
        assert.equal(result.blocks[0].content, '<p>AAA</p>');
        assert.equal(result.blocks[1].content, '<p>BBB</p>');
        assert.equal(result.blocks[2].id, 'b');
        assert.equal(result.createdIds.filter((id) => id === 'a').length, 1);
    });

    it('creates unique ids for a 1→3 extraction split', () => {
        let n = 0;
        const result = applyReplaceBlocksAt([block('src', '<p>AAA BBB CCC</p>')], {
            sourceBlockId: 'src',
            replacements: ['<p>AAA</p>', '<p>BBB</p>', '<p>CCC</p>'],
            createBlock: () => block(`new_${n++}`, ''),
        });
        assert.equal(result.ok, true);
        assert.deepEqual(result.createdIds, ['src', 'new_0', 'new_1']);
        assert.equal(new Set(result.createdIds).size, 3);
        assert.equal(result.blocks.filter((item) => item.id === 'src').length, 1);
    });

    it('cursor-middle two-body replacement keeps one preserved id', () => {
        let n = 0;
        const result = applyReplaceBlocksAt([block('src', '<p>ABCDEF</p>')], {
            sourceBlockId: 'src',
            replacements: ['<p>ABC</p>', '<p>DEF</p>'],
            createBlock: () => block(`x${n++}`, ''),
        });
        assert.equal(result.ok, true);
        assert.equal(result.afterCount, 2);
        assert.equal(result.blocks[0].id, 'src');
        assert.equal(result.blocks[1].id, 'x0');
        assert.ok(result.blocks[0].editorEpoch);
        assert.equal(result.blocks[0].editorDocument, undefined);
    });

    it('fails safely when the source block is gone', () => {
        const result = applyReplaceBlocksAt([block('a', '<p>x</p>')], {
            sourceBlockId: 'missing',
            replacements: ['<p>a</p>', '<p>b</p>'],
        });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'source_missing');
        assert.equal(result.unchanged, true);
    });

    it('does not report success when content is unchanged', () => {
        const source = [block('a', '<p>same</p>')];
        const result = applyReplaceBlocksAt(source, {
            sourceBlockId: 'a',
            replacements: ['<p>same</p>'],
        });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'no_change');
    });

    it('keeps rich marks in replacement HTML', () => {
        const html = '<p>AAA <strong>Balo</strong> <a href="https://example.com">Herschel</a></p>';
        const result = applyReplaceBlocksAt([block('src', html)], {
            sourceBlockId: 'src',
            replacements: [
                '<p>AAA </p>',
                '<p><strong>Balo</strong></p>',
                '<p> <a href="https://example.com">Herschel</a></p>',
            ],
            createBlock: () => block(`n_${Math.random()}`, ''),
        });
        assert.equal(result.ok, true);
        assert.match(result.blocks[1].content, /<strong>Balo<\/strong>/);
        assert.match(result.blocks[2].content, /example.com/);
    });
});
