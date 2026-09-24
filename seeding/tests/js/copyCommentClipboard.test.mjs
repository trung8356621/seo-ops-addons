/**
 * Node unit tests for clipboardWrite (no Seeding-domain deps).
 * Run from seeding/: node --test tests/js/copyCommentClipboard.test.mjs
 */

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { writeClipboard } from '../../resources/js/seeding/services/clipboardWrite.js';

function mockDocument({ execOk = true } = {}) {
    const removed = [];
    const body = {
        children: [],
        appendChild(node) {
            this.children.push(node);
            return node;
        },
        removeChild(node) {
            removed.push(node);
            this.children = this.children.filter((n) => n !== node);
            return node;
        },
    };
    return {
        body,
        removed,
        createElement(tag) {
            assert.equal(tag, 'textarea');
            return {
                value: '',
                style: {},
                setAttribute() {},
                focus() {},
                select() {},
                setSelectionRange() {},
            };
        },
        execCommand(cmd) {
            assert.equal(cmd, 'copy');
            return execOk;
        },
    };
}

describe('writeClipboard', () => {
    it('uses Clipboard API when available', async () => {
        const calls = [];
        const result = await writeClipboard('hello', {
            clipboard: {
                async writeText(text) {
                    calls.push(text);
                },
            },
            document: null,
        });
        assert.deepEqual(result, { ok: true, method: 'clipboard' });
        assert.deepEqual(calls, ['hello']);
    });

    it('falls back when Clipboard API is unavailable', async () => {
        const doc = mockDocument({ execOk: true });
        const result = await writeClipboard('fallback-text', {
            clipboard: null,
            document: doc,
        });
        assert.deepEqual(result, { ok: true, method: 'execCommand' });
        assert.equal(doc.removed.length, 1);
    });

    it('falls back when Clipboard API rejects', async () => {
        const doc = mockDocument({ execOk: true });
        const result = await writeClipboard('after-reject', {
            clipboard: {
                async writeText() {
                    throw new Error('NotAllowedError');
                },
            },
            document: doc,
        });
        assert.deepEqual(result, { ok: true, method: 'execCommand' });
    });

    it('returns ok:false when both fail', async () => {
        const doc = mockDocument({ execOk: false });
        const result = await writeClipboard('nope', {
            clipboard: {
                async writeText() {
                    throw new Error('denied');
                },
            },
            document: doc,
        });
        assert.equal(result.ok, false);
        assert.equal(result.method, null);
        assert.ok(result.error);
    });
});
