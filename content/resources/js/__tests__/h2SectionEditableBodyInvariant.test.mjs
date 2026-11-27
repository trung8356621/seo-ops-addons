import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

/**
 * Mirrors ensureH2SectionsHaveEditableBody / createEmptySectionBlocks contracts
 * without loading Vite-aliased contentDocumentHelpers in node:test.
 */

function buildEditorSections(blocks) {
    const sections = [];
    let current = { id: 'section-intro', isIntro: true, blockIds: [] };

    for (const block of blocks) {
        const isH2 = typeof block.content === 'string' && /^<h2\b/i.test(block.content.trim());
        if (isH2) {
            if (current.blockIds.length > 0) {
                sections.push(current);
            }
            current = {
                id: `section-${block.id}`,
                isIntro: false,
                blockIds: [block.id],
            };
            continue;
        }
        current.blockIds.push(block.id);
    }

    if (current.blockIds.length > 0) {
        sections.push(current);
    }

    return sections;
}

function createEmptySectionBlocks() {
    const heading = { id: 'h2_new', type: 'text', content: '<h2>Section ab12</h2>' };
    const body = { id: 'p_new', type: 'text', content: '<p></p>' };

    return [heading, body];
}

function ensureH2SectionsHaveEditableBody(blocks) {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return Array.isArray(blocks) ? blocks : [];
    }

    const insertAfterIds = new Set();
    for (const section of buildEditorSections(blocks)) {
        if (section.isIntro || section.blockIds.length >= 2) {
            continue;
        }
        insertAfterIds.add(section.blockIds[0]);
    }

    if (insertAfterIds.size === 0) {
        return blocks;
    }

    const next = [];
    for (const block of blocks) {
        next.push(block);
        if (insertAfterIds.has(block.id)) {
            next.push({ id: `p_${block.id}`, type: 'text', content: '<p></p>' });
        }
    }

    return next;
}

describe('H2 section editable body invariant', () => {
    it('new H2 section is created as heading + empty paragraph siblings', () => {
        const [heading, body] = createEmptySectionBlocks();
        assert.match(heading.content, /^<h2\b/i);
        assert.doesNotMatch(heading.content, /<p\b/i);
        assert.equal(body.content, '<p></p>');
    });

    it('ensure adds editable empty paragraph for heading-only H2', () => {
        const blocks = [
            { id: 'intro', type: 'text', content: '<p>Intro</p>' },
            { id: 'h2', type: 'text', content: '<h2>Only heading</h2>' },
        ];
        const once = ensureH2SectionsHaveEditableBody(blocks);
        const section = buildEditorSections(once).find((item) => !item.isIntro);
        assert.equal(section.blockIds.length, 2);
        assert.equal(once[1].id, 'h2');
        assert.equal(once[2].content, '<p></p>');

        const twice = ensureH2SectionsHaveEditableBody(once);
        assert.equal(twice, once);
    });

    it('ensure does not add redundant placeholder when table body exists', () => {
        const blocks = [
            { id: 'h2', type: 'text', content: '<h2>Colors</h2>' },
            { id: 'tbl', type: 'text', content: '<table><tr><td>A</td></tr></table>' },
        ];
        const next = ensureH2SectionsHaveEditableBody(blocks);
        assert.equal(next, blocks);
        assert.deepEqual(buildEditorSections(next)[0].blockIds, ['h2', 'tbl']);
    });

    it('ensure does not invent H3 section placeholders', () => {
        const blocks = [
            { id: 'h2', type: 'text', content: '<h2>Parent</h2>' },
            { id: 'p', type: 'text', content: '<p>Body</p>' },
            { id: 'h3', type: 'text', content: '<h3>Child</h3>' },
        ];
        const next = ensureH2SectionsHaveEditableBody(blocks);
        assert.equal(next, blocks);
    });

    it('after last body removal, ensure restores one placeholder without touching neighbors', () => {
        const blocks = [
            { id: 'h2-a', type: 'text', content: '<h2>A</h2>' },
            { id: 'p-a', type: 'text', content: '<p>A body</p>' },
            { id: 'h2-b', type: 'text', content: '<h2>B</h2>' },
            { id: 'p-b', type: 'text', content: '<p>B body</p>' },
        ];
        const withoutBBody = blocks.filter((block) => block.id !== 'p-b');
        const restored = ensureH2SectionsHaveEditableBody(withoutBBody);
        const sections = buildEditorSections(restored);
        assert.deepEqual(sections[0].blockIds, ['h2-a', 'p-a']);
        assert.equal(sections[1].blockIds[0], 'h2-b');
        assert.equal(sections[1].blockIds.length, 2);
        assert.equal(restored.find((block) => block.id === 'p-a').content, '<p>A body</p>');
    });
});
