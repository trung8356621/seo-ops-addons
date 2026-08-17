import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    applyContextMenuSelection,
    captureEditorContextMenuSnapshot,
    clampMenuPosition,
    CONTEXT_MENU_COMMANDS,
    eventPathContainsMenu,
    headingIndexAtPos,
    resolveClickPos,
    shouldKeepExistingSelection,
} from '../utils/editorContextMenuController.js';
import { splitSelectionToBlockType } from '../utils/editorCommands/headingSplitEngine.js';

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
        paragraph: { group: 'block', content: 'inline*', toDOM: () => ['p', 0] },
        heading: {
            group: 'block',
            content: 'inline*',
            attrs: { level: { default: 2 }, outlineVisible: { default: true } },
            toDOM: (node) => [`h${node.attrs.level}`, 0],
        },
        text: { group: 'inline' },
    },
    marks: {
        link: { attrs: { href: { default: null } }, inclusive: false },
    },
});

function paragraph(text) {
    return schema.nodes.paragraph.create(null, text ? [schema.text(text)] : undefined);
}

function heading(level, text) {
    return schema.nodes.heading.create({ level }, text ? [schema.text(text)] : undefined);
}

function makeEditor(doc, from, to = from) {
    const state = EditorState.create({
        schema,
        doc,
        selection: TextSelection.create(doc, from, to),
    });
    const dispatched = [];
    const view = {
        posAtCoords: ({ left, top }) => {
            if (left === 10 && top === 20) {
                return { pos: 4, inside: 1 };
            }
            return null;
        },
        focus() {
            this.focused = true;
        },
        dispatch(tr) {
            dispatched.push(tr);
            this.state = this.state.apply(tr);
        },
        state,
    };
    view.state = state;
    const editor = {
        view,
        get state() {
            return view.state;
        },
        isDestroyed: false,
    };

    return { editor, dispatched };
}

describe('shouldKeepExistingSelection', () => {
    it('keeps a range that contains the click pos', () => {
        assert.equal(shouldKeepExistingSelection({ from: 2, to: 8, empty: false }, 5), true);
    });

    it('does not keep an empty caret', () => {
        assert.equal(shouldKeepExistingSelection({ from: 5, to: 5, empty: true }, 5), false);
    });

    it('does not keep a range that misses the click', () => {
        assert.equal(shouldKeepExistingSelection({ from: 2, to: 4, empty: false }, 9), false);
    });
});

describe('resolveClickPos', () => {
    it('uses ProseMirror posAtCoords', () => {
        const view = { posAtCoords: () => ({ pos: 12 }) };
        assert.equal(resolveClickPos(view, 1, 1, 3), 12);
    });

    it('falls back when coords miss', () => {
        const view = { posAtCoords: () => null };
        assert.equal(resolveClickPos(view, 1, 1, 7), 7);
    });
});

describe('clampMenuPosition', () => {
    it('keeps the menu inside the viewport near the bottom-right', () => {
        const next = clampMenuPosition(900, 700, 240, 280, { vw: 1000, vh: 800, pad: 8 });
        assert.equal(next.left <= 1000 - 240 - 8, true);
        assert.equal(next.top <= 800 - 280 - 8, true);
        assert.equal(next.flipSubmenuLeft, true);
    });
});

describe('eventPathContainsMenu', () => {
    it('treats composedPath hits as inside', () => {
        const root = { contains: () => false };
        const event = { composedPath: () => [root], target: null };
        assert.equal(eventPathContainsMenu(event, root), true);
    });

    it('does not treat outside targets as inside', () => {
        const root = { contains: () => false };
        const event = { composedPath: () => [{}], target: { closest: () => null } };
        assert.equal(eventPathContainsMenu(event, root), false);
    });
});

describe('capture + restore + split', () => {
    it('opening a menu snapshot does not change document content', () => {
        const doc = schema.node('doc', null, [paragraph('Herschel Supply Co.')]);
        const { editor, dispatched } = makeEditor(doc, 1, 9);
        const before = editor.state.doc;
        const snapshot = captureEditorContextMenuSnapshot(editor, {
            clientX: 10,
            clientY: 20,
            blockId: 'block-a',
        });
        assert.equal(snapshot.blockId, 'block-a');
        assert.equal(snapshot.keptRange, true);
        assert.equal(editor.state.doc, before);
        assert.equal(dispatched.length, 0);
    });

    it('right-click outside the range moves caret without changing text', () => {
        const doc = schema.node('doc', null, [paragraph('AAA BBB')]);
        const { editor } = makeEditor(doc, 1, 3);
        const snapshot = captureEditorContextMenuSnapshot(editor, {
            clientX: 10,
            clientY: 20,
            blockId: 'block-a',
        });
        assert.equal(snapshot.keptRange, false);
        assert.equal(editor.state.doc.textContent, 'AAA BBB');
        assert.equal(snapshot.clickPos, 4);
    });

    it('restores the captured range then Split + H3 mutates that editor only', () => {
        const doc = schema.node('doc', null, [paragraph('Herschel Supply Co. vintage')]);
        const { editor } = makeEditor(doc, 1, 9);
        const snapshot = captureEditorContextMenuSnapshot(editor, {
            clientX: 0,
            clientY: 0,
            blockId: 'block-a',
        });
        editor.view.dispatch(editor.state.tr.setSelection(TextSelection.create(editor.state.doc, 12)));
        const restored = applyContextMenuSelection(editor, snapshot);
        assert.equal(restored, true);
        assert.equal(editor.state.selection.from, snapshot.from);
        assert.equal(editor.state.selection.to, snapshot.to);

        const ok = splitSelectionToBlockType(editor.state, (tr) => editor.view.dispatch(tr), {
            nodeType: 'heading',
            level: 3,
        });
        assert.equal(ok, true);
        const headings = [];
        editor.state.doc.forEach((node) => {
            if (node.type.name === 'heading') {
                headings.push(node.textContent);
            }
        });
        assert.equal(headings[0], 'Herschel');
    });

    it('headingIndexAtPos finds the clicked heading', () => {
        const doc = schema.node('doc', null, [
            heading(2, 'Top 10'),
            heading(3, 'Herschel'),
        ]);
        const h3Pos = doc.firstChild.nodeSize;
        assert.equal(headingIndexAtPos(doc, h3Pos + 1), 1);
    });

    it('maps split H3 to the registered command name', () => {
        assert.equal(CONTEXT_MENU_COMMANDS.splitH3.name, 'split_selection_to_heading');
        assert.deepEqual(CONTEXT_MENU_COMMANDS.splitH3.args(), { level: 3 });
        assert.equal(CONTEXT_MENU_COMMANDS.renameHeading.name, 'rename_heading');
    });
});
