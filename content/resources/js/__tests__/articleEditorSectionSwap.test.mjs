import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    outlineH2MoveAvailability,
    swapAdjacentEditorSections,
} from '../utils/articleEditorBlockReorder.js';
import {
    buildClientOutlineTree,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline.js';

function sampleDoc() {
    return [
        { id: 'intro', type: 'text', content: '<p>Intro stays first</p>' },
        { id: 'h2-a', type: 'text', content: '<h2>Alpha</h2>' },
        { id: 'p-a', type: 'text', content: '<p>Alpha body</p>' },
        { id: 'h3-a', type: 'text', content: '<h3>Alpha child</h3>' },
        { id: 'table-a', type: 'text', content: '<table><tr><td>A</td></tr></table>' },
        { id: 'h2-b', type: 'text', content: '<h2>Beta</h2>' },
        { id: 'p-b', type: 'text', content: '<p>Beta body</p>' },
        { id: 'h4-b', type: 'text', content: '<h4>Beta nested</h4>' },
        { id: 'h2-c', type: 'text', content: '<h2>Alpha</h2>' },
        { id: 'p-c', type: 'text', content: '<p>Gamma body with duplicate H2 title</p>' },
    ];
}

function sampleSections() {
    return [
        { id: 'section-intro', isIntro: true, blockIds: ['intro'] },
        { id: 'section-h2-a', isIntro: false, blockIds: ['h2-a', 'p-a', 'h3-a', 'table-a'] },
        { id: 'section-h2-b', isIntro: false, blockIds: ['h2-b', 'p-b', 'h4-b'] },
        { id: 'section-h2-c', isIntro: false, blockIds: ['h2-c', 'p-c'] },
    ];
}

describe('swapAdjacentEditorSections', () => {
    it('moves middle H2 section up with H3/table children intact', () => {
        const blocks = sampleDoc();
        const beforeIds = blocks.map((block) => block.id);
        const result = swapAdjacentEditorSections(blocks, {
            sections: sampleSections(),
            sectionId: 'section-h2-b',
            direction: 'prev',
        });

        assert.equal(result.ok, true);
        assert.deepEqual(
            result.blocks.map((block) => block.id),
            ['intro', 'h2-b', 'p-b', 'h4-b', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c'],
        );
        assert.equal(result.blocks.find((block) => block.id === 'table-a').content, '<table><tr><td>A</td></tr></table>');
        assert.equal(result.blocks.find((block) => block.id === 'h4-b').content, '<h4>Beta nested</h4>');
        assert.deepEqual(
            beforeIds.sort(),
            result.blocks.map((block) => block.id).sort(),
        );
        assert.equal(result.blocks[0].id, 'intro');
    });

    it('moves middle H2 section down', () => {
        const result = swapAdjacentEditorSections(sampleDoc(), {
            sections: sampleSections(),
            sectionId: 'section-h2-b',
            direction: 'next',
        });

        assert.equal(result.ok, true);
        assert.deepEqual(
            result.blocks.map((block) => block.id),
            ['intro', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c', 'h2-b', 'p-b', 'h4-b'],
        );
    });

    it('refuses moving first H2 above intro', () => {
        const result = swapAdjacentEditorSections(sampleDoc(), {
            sections: sampleSections(),
            sectionId: 'section-h2-a',
            direction: 'prev',
        });

        assert.equal(result.ok, false);
        assert.equal(result.code, 'intro_protected');
        assert.deepEqual(
            result.blocks.map((block) => block.id),
            sampleDoc().map((block) => block.id),
        );
    });

    it('refuses moving last H2 down', () => {
        const result = swapAdjacentEditorSections(sampleDoc(), {
            sections: sampleSections(),
            sectionId: 'section-h2-c',
            direction: 'next',
        });

        assert.equal(result.ok, false);
        assert.equal(result.code, 'target_section_missing');
    });

    it('preserves block identity and content for duplicate H2 titles', () => {
        const blocks = sampleDoc();
        const alphaHeading = blocks.find((block) => block.id === 'h2-a');
        const gammaHeading = blocks.find((block) => block.id === 'h2-c');
        const result = swapAdjacentEditorSections(blocks, {
            sections: sampleSections(),
            sectionId: 'section-h2-c',
            direction: 'prev',
        });

        assert.equal(result.ok, true);
        assert.equal(result.blocks.find((block) => block.id === 'h2-a'), alphaHeading);
        assert.equal(result.blocks.find((block) => block.id === 'h2-c'), gammaHeading);
        assert.equal(result.blocks.find((block) => block.id === 'h2-a').content, '<h2>Alpha</h2>');
        assert.equal(result.blocks.find((block) => block.id === 'h2-c').content, '<h2>Alpha</h2>');
        assert.deepEqual(
            result.blocks.map((block) => block.id),
            ['intro', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c', 'h2-b', 'p-b', 'h4-b'],
        );
    });

    it('updates client outline / fingerprint order without changing heading content', () => {
        const before = sampleDoc();
        const after = swapAdjacentEditorSections(before, {
            sections: sampleSections(),
            sectionId: 'section-h2-b',
            direction: 'prev',
        }).blocks;

        const beforeTree = buildClientOutlineTree(before);
        const afterTree = buildClientOutlineTree(after);

        assert.deepEqual(
            beforeTree.map((node) => `${node.block_id}:${node.heading_text}`),
            ['h2-a:Alpha', 'h2-b:Beta', 'h2-c:Alpha'],
        );
        assert.deepEqual(
            afterTree.map((node) => `${node.block_id}:${node.heading_text}`),
            ['h2-b:Beta', 'h2-a:Alpha', 'h2-c:Alpha'],
        );
        assert.notEqual(outlineHeadingFingerprint(before), outlineHeadingFingerprint(after));
        assert.equal(afterTree[0].children[0]?.heading_text, 'Beta nested');
        assert.equal(afterTree[1].children[0]?.heading_text, 'Alpha child');
    });
});

describe('outlineH2MoveAvailability', () => {
    it('hides up on first and down on last H2', () => {
        assert.deepEqual(outlineH2MoveAvailability(0, 3), { canMoveUp: false, canMoveDown: true });
        assert.deepEqual(outlineH2MoveAvailability(1, 3), { canMoveUp: true, canMoveDown: true });
        assert.deepEqual(outlineH2MoveAvailability(2, 3), { canMoveUp: true, canMoveDown: false });
    });
});
