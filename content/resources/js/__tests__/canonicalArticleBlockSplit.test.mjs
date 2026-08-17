import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    planCanonicalArticleBlockSplit,
    stripLeadingTitleSeparator,
} from '../utils/editorCommands/canonicalArticleBlockSplit.js';

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
            attrs: { level: { default: 2 }, outlineVisible: { default: true } },
            toDOM: (node) => [`h${node.attrs.level}`, 0],
        },
        text: { group: 'inline' },
    },
    marks: {
        bold: { toDOM: () => ['strong', 0] },
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

function paragraph(content) {
    if (typeof content === 'string') {
        return schema.nodes.paragraph.create(null, content ? [textNode(content)] : undefined);
    }

    return schema.nodes.paragraph.create(null, content);
}

function stateFromDoc(doc, from, to = from) {
    const state = EditorState.create({ schema, doc });
    const selection = TextSelection.create(doc, from, to);

    return state.apply(state.tr.setSelection(selection));
}

function docOf(...blocks) {
    return schema.nodes.doc.create(null, blocks);
}

function selectIn(stateSource, needle) {
    const text = stateSource.doc.textBetween(0, stateSource.doc.content.size, '\n');
    const index = text.indexOf(needle);
    assert.ok(index >= 0, `missing ${needle}`);
    const from = index + 1;
    const to = from + needle.length;

    return stateFromDoc(stateSource.doc, from, to);
}

describe('canonical article block split', () => {
    it('strips only a leading title separator', () => {
        assert.equal(stripLeadingTitleSeparator(' – mô tả').text, 'mô tả');
        assert.equal(stripLeadingTitleSeparator('–Được mệnh').text, 'Được mệnh');
        assert.equal(stripLeadingTitleSeparator(': next').stripped, true);
        assert.equal(stripLeadingTitleSeparator('hello').stripped, false);
        assert.equal(stripLeadingTitleSeparator('. still here').stripped, false);
    });

    it('split + H3 at start of paragraph with en-dash separator', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('Herschel Supply Co. – mô tả')),
            }),
            'Herschel Supply Co.',
        );
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'heading', level: 3 });
        assert.equal(plan.ok, true);
        assert.deepEqual(plan.contents, [
            '<h3>Herschel Supply Co.</h3>',
            '<p>mô tả</p>',
        ]);
        assert.equal(plan.focusIndex, 1);
        assert.equal(plan.contents.some((html) => html.includes('<h3>') && html.includes('<p>')), false);
    });

    it('split + H4 middle selection creates three canonical blocks', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('AAA BBB CCC')),
            }),
            'BBB',
        );
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'heading', level: 4 });
        assert.deepEqual(plan.contents, [
            '<p>AAA </p>',
            '<h4>BBB</h4>',
            '<p> CCC</p>',
        ]);
    });

    it('split + H3 whole paragraph does not create empty body', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('Herschel Supply Co.')),
            }),
            'Herschel Supply Co.',
        );
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'heading', level: 3 });
        assert.deepEqual(plan.contents, ['<h3>Herschel Supply Co.</h3>']);
        assert.equal(plan.focusIndex, null);
    });

    it('split + H3 at end of paragraph', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('AAA Herschel')),
            }),
            'Herschel',
        );
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'heading', level: 3 });
        assert.deepEqual(plan.contents, [
            '<p>AAA </p>',
            '<h3>Herschel</h3>',
        ]);
        assert.equal(plan.focusIndex, null);
    });

    it('keeps Vietnamese, bold, and link in paragraph split slices', () => {
        const bold = schema.marks.bold.create();
        const link = schema.marks.link.create({ href: 'https://example.com' });
        const doc = docOf(paragraph([
            textNode('AAA '),
            textNode('Balo', [bold]),
            textNode(' '),
            textNode('Herschel', [link]),
            textNode(' CCC'),
        ]));
        const state = selectIn(EditorState.create({ schema, doc }), 'Balo');
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'paragraph' });
        assert.equal(plan.ok, true);
        assert.equal(plan.contents.length, 3);
        assert.match(plan.contents[1], /<strong>Balo<\/strong>/);
        assert.match(plan.contents[2], /<a href="https:\/\/example.com">Herschel<\/a>/);
    });

    it('cursor split AAA|BBB becomes two body blocks', () => {
        const base = EditorState.create({
            schema,
            doc: docOf(paragraph('AAABBB')),
        });
        const state = stateFromDoc(base.doc, 4);
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'cursor' });
        assert.deepEqual(plan.contents, ['<p>AAA</p>', '<p>BBB</p>']);
        assert.equal(plan.focusIndex, 1);
    });

    it('rejects cursor split at paragraph boundaries', () => {
        const base = EditorState.create({
            schema,
            doc: docOf(paragraph('ABC')),
        });
        assert.equal(planCanonicalArticleBlockSplit(stateFromDoc(base.doc, 1), { mode: 'cursor' }).ok, false);
        assert.equal(planCanonicalArticleBlockSplit(stateFromDoc(base.doc, 4), { mode: 'cursor' }).ok, false);
    });

    it('splits a selection that spans two paragraphs into canonical body blocks', () => {
        const doc = docOf(paragraph('AAA'), paragraph('BBB'));
        const firstSize = doc.firstChild.nodeSize;
        const state = stateFromDoc(doc, 1, firstSize + 2);
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'paragraph' });
        assert.equal(plan.ok, true, JSON.stringify(plan));
        assert.ok(plan.contents.length >= 2, JSON.stringify(plan.contents));
        const joined = plan.contents.join('\n');
        assert.match(joined, /AAA/);
        assert.match(joined, /B/);
    });

    it('cursor at the end of the first sibling paragraph splits into two body blocks', () => {
        const doc = docOf(paragraph('Herschel Supply Co'), paragraph('– Được mệnh danh'));
        const firstSize = doc.firstChild.nodeSize;
        const state = stateFromDoc(doc, firstSize - 1);
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'cursor' });
        assert.equal(plan.ok, true);
        assert.equal(plan.contents.length, 2);
        assert.match(plan.contents[0], /Herschel Supply Co/);
        assert.match(plan.contents[1], /Được mệnh danh/);
    });

    it('heading split plan is a structural replace payload, not an in-body heading node', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('Herschel Supply Co. – mô tả')),
            }),
            'Herschel Supply Co.',
        );
        const before = state.doc;
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'heading', level: 3 });
        const payload = {
            name: 'replace_blocks_at',
            blockId: 'block-a',
            contents: plan.contents,
            focusIndex: plan.focusIndex,
        };

        assert.equal(plan.ok, true);
        assert.equal(payload.name, 'replace_blocks_at');
        assert.deepEqual(payload.contents, [
            '<h3>Herschel Supply Co.</h3>',
            '<p>mô tả</p>',
        ]);
        assert.equal(payload.contents[0].includes('<p>'), false);
        assert.equal(state.doc, before);
    });

    it('paragraph split plan keeps focus on the remainder, not the extracted slice', () => {
        const state = selectIn(
            EditorState.create({
                schema,
                doc: docOf(paragraph('AAA BBB CCC')),
            }),
            'BBB',
        );
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'paragraph' });
        assert.equal(plan.ok, true);
        assert.deepEqual(plan.contents, ['<p>AAA </p>', '<p>BBB</p>', '<p> CCC</p>']);
        assert.equal(plan.focusIndex, 2);
    });

    it('extract p inside list yields a plain paragraph, not a nested list item', () => {
        const listSchema = new Schema({
            nodes: {
                doc: { content: 'block+' },
                paragraph: { group: 'block', content: 'inline*', toDOM: () => ['p', 0] },
                heading: {
                    group: 'block',
                    content: 'inline*',
                    attrs: { level: { default: 2 } },
                    toDOM: (node) => [`h${node.attrs.level}`, 0],
                },
                bulletList: { group: 'block', content: 'listItem+', toDOM: () => ['ul', 0] },
                listItem: { content: 'paragraph block*', defining: true, toDOM: () => ['li', 0] },
                text: { group: 'inline' },
            },
            marks: { bold: { toDOM: () => ['strong', 0] } },
        });
        const bold = listSchema.marks.bold.create();
        const doc = listSchema.nodes.doc.create(null, [
            listSchema.nodes.bulletList.create(null, [
                listSchema.nodes.listItem.create(null, [
                    listSchema.nodes.paragraph.create(null, [
                        listSchema.text('Herschel Supply Co', [bold]),
                        listSchema.text(' – mô tả'),
                    ]),
                ]),
            ]),
        ]);
        let from;
        let to;
        doc.descendants((node, pos) => {
            if (node.isText && node.text === 'Herschel Supply Co') {
                from = pos;
                to = pos + node.nodeSize;
            }
        });
        const base = EditorState.create({ schema: listSchema, doc });
        const state = base.apply(base.tr.setSelection(TextSelection.create(doc, from, to)));
        const plan = planCanonicalArticleBlockSplit(state, { mode: 'paragraph' });
        assert.equal(plan.ok, true, JSON.stringify(plan));
        assert.match(plan.contents[0], /^<p><strong>Herschel Supply Co<\/strong><\/p>$/);
        assert.equal(plan.contents[0].includes('<ul>'), false);
        assert.match(plan.contents.join('\n'), /<ul>/);
    });

    it('cursor plan is a no-op at the start of a paragraph', () => {
        const base = EditorState.create({ schema, doc: docOf(paragraph('ABC')) });
        const plan = planCanonicalArticleBlockSplit(stateFromDoc(base.doc, 1), { mode: 'cursor' });
        assert.equal(plan.ok, false);
        assert.equal(plan.reason, 'boundary');
    });
});
