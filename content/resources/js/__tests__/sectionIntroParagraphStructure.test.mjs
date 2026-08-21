import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { reorderBlockWithinSection, withinSectionMoveAvailability } from '../utils/articleEditorBlockReorder.js';
import { insertAfterHeadingSectionInHtml } from '../utils/articleEditorOutlineMutations.js';
import { extractOutlineHeadingsFromHtml } from '../utils/articleEditorClientOutline.js';
import { canApplyParagraphStyle } from '../utils/paragraphStyleCompatibility.js';

/**
 * Lightweight peel mirror for node:test (no DOMParser required).
 */
function peelLeadingH2ForTest(html) {
    const source = String(html ?? '').trim();
    const match = source.match(/^<h2\b[^>]*>[\s\S]*?<\/h2>/i);
    if (!match) {
        return [source];
    }
    const heading = match[0];
    const rest = source.slice(heading.length).trim();
    if (!rest) {
        return [heading];
    }
    const parts = [heading];
    const chunkRe = /<(h[3-6]|p|table|ul|ol|blockquote|pre|figure)\b[\s\S]*?(?:<\/\1>|\/?>)/gi;
    let chunk;
    let cursor = 0;
    const body = rest;
    while ((chunk = chunkRe.exec(body)) !== null) {
        if (chunk.index > cursor) {
            const gap = body.slice(cursor, chunk.index).trim();
            if (gap) {
                parts.push(gap);
            }
        }
        parts.push(chunk[0]);
        cursor = chunkRe.lastIndex;
    }
    const tail = body.slice(cursor).trim();
    if (tail) {
        parts.push(tail);
    }

    return parts;
}

describe('section intro paragraph structure', () => {
    it('peels H3/table after H2 into sibling chunks', () => {
        const parts = peelLeadingH2ForTest(
            '<h2>Màu sắc thương hiệu</h2><h3>Bảng màu</h3><table><tr><td>Navy</td></tr></table>',
        );
        assert.ok(parts.length >= 2);
        assert.match(parts[0], /^<h2\b/i);
        assert.doesNotMatch(parts[0], /<h3\b/i);
        assert.doesNotMatch(parts[0], /<table\b/i);
        const trailing = parts.slice(1).join('');
        assert.match(trailing, /<h3\b/i);
        assert.match(trailing, /<table\b/i);
    });

    it('inserts outline intro paragraph directly under H2, before H3/table', () => {
        const html = '<h2>Section</h2><h3>Child</h3><table><tr><td>A</td></tr></table><p>tail</p>';
        const next = insertAfterHeadingSectionInHtml(html, { headingIndex: 0, paragraph: true });
        assert.match(next, /<h2[^>]*>Section<\/h2><p><\/p><h3/);
        assert.doesNotMatch(next, /<\/table><p><\/p>/);
    });

    it('moves paragraph up past H3 and table inside the same section', () => {
        const blocks = [
            { id: 'h2' },
            { id: 'h3' },
            { id: 'tbl' },
            { id: 'p' },
        ];
        const movable = ['h3', 'tbl', 'p'];
        assert.equal(withinSectionMoveAvailability(movable, 'p').canMoveUp, true);

        let working = blocks;
        working = reorderBlockWithinSection(working, {
            blockId: 'p',
            direction: 'up',
            sectionBlockIds: movable,
            sectionId: 'section-h2',
        }).blocks;
        working = reorderBlockWithinSection(working, {
            blockId: 'p',
            direction: 'up',
            sectionBlockIds: ['h3', 'p', 'tbl'],
            sectionId: 'section-h2',
        }).blocks;

        assert.deepEqual(working.map((row) => row.id), ['h2', 'p', 'h3', 'tbl']);
        assert.equal(withinSectionMoveAvailability(['p', 'h3', 'tbl'], 'p').canMoveUp, false);
    });

    it('does not promote table text into visible outline headings', () => {
        const html = '<h2>Ok</h2><table><tr><td><h3>Màu sắc Ý nghĩa Phù hợp Navy</h3></td></tr></table>';
        const headings = extractOutlineHeadingsFromHtml(html, 'b1');
        assert.equal(headings.length, 2);
        assert.equal(headings[0].outline_visible, true);
        assert.equal(headings[1].outline_visible, false);
    });

    it('blocks heading conversion while selection is inside a table', () => {
        const editor = {
            isDestroyed: false,
            state: {
                selection: {
                    $from: {
                        depth: 3,
                        parent: { type: { name: 'paragraph' } },
                        node(depth) {
                            const names = ['doc', 'table', 'tableRow', 'tableCell'];
                            return { type: { name: names[depth] } };
                        },
                    },
                },
            },
            isActive(name) {
                return name === 'table' || name === 'tableCell';
            },
        };

        assert.equal(canApplyParagraphStyle(editor, 'h3'), false);
        assert.equal(canApplyParagraphStyle(editor, 'p'), true);
    });
});
