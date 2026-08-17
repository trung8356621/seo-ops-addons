import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    changeCurrentBlockType,
    changeHeadingLevelByIndex,
    deleteHeadingKeepContent,
    deleteHeadingWithContent,
    insertHeadingAfterSection,
    renameHeadingByIndex,
    setHeadingOutlineVisible,
    splitParagraphAtCursor,
    splitSelectionToBlockType,
} from '../utils/editorCommands/headingSplitEngine.js';

function requireFromClient() {
    let dir = fileURLToPath(new URL('.', import.meta.url));
    for (let i = 0; i < 8; i += 1) {
        const pkg = path.join(dir, 'package.json');
        if (fs.existsSync(path.join(dir, 'node_modules', '@tiptap', 'pm')) && fs.existsSync(pkg)) {
            return createRequire(pkg);
        }
        const siblingPkg = path.join(dir, 'omnichannel-client', 'package.json');
        if (fs.existsSync(path.join(dir, 'omnichannel-client', 'node_modules', '@tiptap', 'pm'))) {
            return createRequire(siblingPkg);
        }
        dir = path.dirname(dir);
    }
    throw new Error('Cannot resolve @tiptap/pm from omnichannel-client');
}

const require = requireFromClient();
const { Schema } = require('@tiptap/pm/model');
const { EditorState, TextSelection } = require('@tiptap/pm/state');

const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: {
            group: 'block',
            content: 'inline*',
            toDOM: () => ['p', 0],
        },
        heading: {
            group: 'block',
            content: 'inline*',
            attrs: {
                level: { default: 2 },
                outlineVisible: { default: true },
            },
            toDOM: (node) => [`h${node.attrs.level}`, 0],
        },
        text: { group: 'inline' },
    },
    marks: {
        bold: {
            toDOM: () => ['strong', 0],
        },
        link: {
            attrs: { href: { default: null } },
            inclusive: false,
            toDOM: (mark) => ['a', { href: mark.attrs.href }, 0],
        },
    },
});

function textNode(text, marks = []) {
    return marks.length > 0 ? schema.text(text, marks) : schema.text(text);
}

function paragraph(text, marks = []) {
    return schema.nodes.paragraph.create(null, text ? [textNode(text, marks)] : undefined);
}

function heading(level, text, extra = {}) {
    return schema.nodes.heading.create({ level, ...extra }, text ? [schema.text(text)] : undefined);
}

function makeState(doc, from, to = from) {
    const state = EditorState.create({ schema, doc });

    return state.apply(state.tr.setSelection(TextSelection.create(doc, from, to)));
}

function apply(state, command, payload) {
    let next = null;
    const ok = command(state, (tr) => {
        next = state.apply(tr);
    }, payload);
    assert.equal(ok, true);

    return next;
}

function blockTexts(state) {
    const out = [];
    state.doc.forEach((node) => {
        out.push({
            type: node.type.name,
            level: node.attrs?.level ?? null,
            text: node.textContent,
            outlineVisible: node.attrs?.outlineVisible,
        });
    });

    return out;
}

function paraDoc(text) {
    return schema.node('doc', null, [paragraph(text)]);
}

function posInFirstBlock(text, start, end = start) {
    // doc pos 0, paragraph pos 0, content starts at 1
    const doc = paraDoc(text);
    const state = makeState(doc, 1 + start, 1 + end);

    return { doc, state };
}

describe('splitSelectionToBlockType', () => {
    it('splits selection at the start of a paragraph', () => {
        const { state } = posInFirstBlock('AAA BBB CCC', 0, 3);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        assert.deepEqual(blockTexts(next), [
            { type: 'heading', level: 3, text: 'AAA', outlineVisible: true },
            { type: 'paragraph', level: null, text: ' BBB CCC', outlineVisible: undefined },
        ]);
    });

    it('splits selection in the middle of a paragraph', () => {
        const { state } = posInFirstBlock('AAA BBB CCC', 4, 7);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        assert.deepEqual(blockTexts(next).map((row) => `${row.type}:${row.level}:${row.text}`), [
            'paragraph:null:AAA ',
            'heading:3:BBB',
            'paragraph:null: CCC',
        ]);
    });

    it('splits selection at the end of a paragraph', () => {
        const { state } = posInFirstBlock('AAA BBB CCC', 8, 11);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        assert.deepEqual(blockTexts(next).map((row) => `${row.type}:${row.level}:${row.text}`), [
            'paragraph:null:AAA BBB ',
            'heading:3:CCC',
        ]);
    });

    it('converts the whole paragraph when fully selected', () => {
        const { state } = posInFirstBlock('AAA BBB CCC', 0, 11);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        assert.deepEqual(blockTexts(next).map((row) => `${row.type}:${row.level}:${row.text}`), [
            'heading:3:AAA BBB CCC',
        ]);
    });

    it('supports Vietnamese unicode text', () => {
        const text = 'Herschel Supply Co. – Được mệnh danh là biểu tượng';
        const selected = 'Herschel Supply Co.';
        const { state } = posInFirstBlock(text, 0, selected.length);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        assert.equal(blockTexts(next)[0].text, 'Herschel Supply Co.');
        assert.equal(blockTexts(next)[0].type, 'heading');
        assert.equal(blockTexts(next)[1].type, 'paragraph');
        assert.match(blockTexts(next)[1].text, /Được mệnh danh là biểu tượng/);
    });

    it('keeps bold marks on the selected slice', () => {
        const doc = schema.node('doc', null, [
            schema.nodes.paragraph.create(null, [
                schema.text('AAA '),
                schema.text('BBB', [schema.marks.bold.create()]),
                schema.text(' CCC'),
            ]),
        ]);
        const state = makeState(doc, 5, 8);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 3 });
        const headingNode = next.doc.child(1);
        assert.equal(headingNode.type.name, 'heading');
        assert.equal(headingNode.textContent, 'BBB');
        assert.equal(headingNode.firstChild.marks[0].type.name, 'bold');
    });

    it('keeps an existing link on the selected slice', () => {
        const doc = schema.node('doc', null, [
            schema.nodes.paragraph.create(null, [
                schema.text('AAA '),
                schema.text('BBB', [schema.marks.link.create({ href: 'https://example.com' })]),
                schema.text(' CCC'),
            ]),
        ]);
        const state = makeState(doc, 5, 8);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'heading', level: 4 });
        const headingNode = next.doc.child(1);
        assert.equal(headingNode.type.name, 'heading');
        assert.equal(headingNode.attrs.level, 4);
        assert.equal(headingNode.firstChild.marks[0].attrs.href, 'https://example.com');
    });

    it('does not create empty blocks when splitting at the start', () => {
        const { state } = posInFirstBlock('Hello', 0, 2);
        const next = apply(state, splitSelectionToBlockType, { nodeType: 'paragraph' });
        assert.equal(next.doc.childCount, 2);
        assert.equal(next.doc.child(0).textContent, 'He');
        assert.equal(next.doc.child(1).textContent, 'llo');
    });
});

describe('splitParagraphAtCursor / changeCurrentBlockType', () => {
    it('splits at the cursor without empty sides', () => {
        const { state } = posInFirstBlock('AAABBB', 3);
        const next = apply(state, splitParagraphAtCursor);
        assert.deepEqual(blockTexts(next).map((row) => row.text), ['AAA', 'BBB']);
    });

    it('rejects split at the start (would create empty block)', () => {
        const { state } = posInFirstBlock('AAA', 0);
        const ok = splitParagraphAtCursor(state, () => {
            throw new Error('should not dispatch');
        });
        assert.equal(ok, false);
    });

    it('converts the current block to H3', () => {
        const { state } = posInFirstBlock('Herschel Supply Co.', 3);
        const next = apply(state, changeCurrentBlockType, { nodeType: 'heading', level: 3 });
        assert.equal(blockTexts(next)[0].type, 'heading');
        assert.equal(blockTexts(next)[0].level, 3);
        assert.equal(blockTexts(next)[0].text, 'Herschel Supply Co.');
    });

    it('lifts heading out of listItem instead of nesting (schema paragraph block*)', () => {
        const listSchema = new Schema({
            nodes: {
                doc: { content: 'block+' },
                paragraph: { group: 'block', content: 'inline*' },
                heading: {
                    group: 'block',
                    content: 'inline*',
                    attrs: { level: { default: 2 }, outlineVisible: { default: true } },
                },
                bulletList: { group: 'block', content: 'listItem+' },
                listItem: { content: 'paragraph block*', defining: true },
                text: { group: 'inline' },
            },
            marks: { bold: {} },
        });
        const bold = listSchema.marks.bold.create();
        const paragraphNode = listSchema.nodes.paragraph.create(null, [
            listSchema.text('Herschel Supply Co', [bold]),
            listSchema.text(' – mô tả'),
        ]);
        const doc = listSchema.nodes.doc.create(null, [
            listSchema.nodes.bulletList.create(null, [
                listSchema.nodes.listItem.create(null, [paragraphNode]),
            ]),
        ]);
        let from = 3;
        doc.descendants((node, pos) => {
            if (node.isText && node.text === 'Herschel Supply Co') {
                from = pos;
            }
        });
        const base = EditorState.create({ schema: listSchema, doc });
        const state = base.apply(base.tr.setSelection(TextSelection.create(doc, from)));
        const next = apply(state, changeCurrentBlockType, { nodeType: 'heading', level: 3 });
        assert.equal(next.doc.childCount, 1);
        assert.equal(next.doc.firstChild.type.name, 'heading');
        assert.equal(next.doc.firstChild.attrs.level, 3);
        assert.match(next.doc.firstChild.textContent, /Herschel Supply Co/);
        next.doc.check();
    });
});

describe('outline heading mutations', () => {
    function articleState() {
        const doc = schema.node('doc', null, [
            heading(2, 'Top 10 thương hiệu balo'),
            paragraph('Intro'),
            heading(3, 'Herschel Supply Co.'),
            paragraph('Nội dung'),
            heading(3, 'Balo du lịch'),
            paragraph('Khác'),
        ]);

        return EditorState.create({ schema, doc });
    }

    it('renames a heading by index', () => {
        const next = apply(articleState(), renameHeadingByIndex, { headingIndex: 1, text: 'Herschel' });
        assert.equal(listTexts(next)[1], 'Herschel');
        assert.equal(listTexts(next)[0], 'Top 10 thương hiệu balo');
    });

    it('changes heading level without duplicating content', () => {
        const next = apply(articleState(), changeHeadingLevelByIndex, { headingIndex: 1, level: 2 });
        const headings = blockTexts(next).filter((row) => row.type === 'heading');
        assert.equal(headings[1].level, 2);
        assert.equal(headings[1].text, 'Herschel Supply Co.');
        assert.equal(next.doc.textContent.includes('Nội dung'), true);
    });

    it('deletes heading but keeps following content', () => {
        const next = apply(articleState(), deleteHeadingKeepContent, { headingIndex: 1 });
        const texts = blockTexts(next).map((row) => `${row.type}:${row.text}`);
        assert.equal(texts.includes('heading:Herschel Supply Co.'), false);
        assert.equal(texts.includes('paragraph:Nội dung'), true);
        assert.equal(texts.includes('heading:Balo du lịch'), true);
    });

    it('deletes heading and its section content', () => {
        const next = apply(articleState(), deleteHeadingWithContent, { headingIndex: 1 });
        const texts = blockTexts(next).map((row) => `${row.type}:${row.text}`);
        assert.equal(texts.includes('heading:Herschel Supply Co.'), false);
        assert.equal(texts.includes('paragraph:Nội dung'), false);
        assert.equal(texts.includes('heading:Balo du lịch'), true);
        assert.equal(texts.includes('heading:Top 10 thương hiệu balo'), true);
    });

    it('inserts an H3 child before the next H2, after existing H3s', () => {
        const doc = schema.node('doc', null, [
            heading(2, 'Top 10'),
            heading(3, 'Herschel'),
            paragraph('Nội dung'),
            heading(2, 'Kết luận'),
            paragraph('Done'),
        ]);
        const next = apply(EditorState.create({ schema, doc }), insertHeadingAfterSection, {
            headingIndex: 0,
            level: 3,
            text: 'Thương hiệu mới',
        });
        const headings = blockTexts(next).filter((row) => row.type === 'heading');
        assert.deepEqual(headings.map((row) => `${row.level}:${row.text}`), [
            '2:Top 10',
            '3:Herschel',
            '3:Thương hiệu mới',
            '2:Kết luận',
        ]);
    });

    it('inserts an H3 child after the H2 section slice', () => {
        const next = apply(articleState(), insertHeadingAfterSection, {
            headingIndex: 0,
            level: 3,
            text: 'Thương hiệu mới',
        });
        const headings = blockTexts(next).filter((row) => row.type === 'heading');
        assert.equal(headings.at(-1).text, 'Thương hiệu mới');
        assert.equal(headings.at(-1).level, 3);
        assert.equal(headings.length, 4);
    });

    it('toggles outlineVisible', () => {
        const next = apply(articleState(), setHeadingOutlineVisible, {
            headingIndex: 1,
            visible: false,
        });
        const headingNode = blockTexts(next).find((row) => row.text === 'Herschel Supply Co.');
        assert.equal(headingNode.outlineVisible, false);
    });
});

function listTexts(state) {
    return blockTexts(state).filter((row) => row.type === 'heading').map((row) => row.text);
}
