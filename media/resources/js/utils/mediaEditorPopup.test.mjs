import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import { openMediaEditorPopup, POPUP_BLOCKED_MESSAGE } from './mediaEditorPopup.js';

function popupHarness() {
    const navigated = [];
    const popup = {
        closed: false,
        close() { this.closed = true; },
        location: { replace(url) { navigated.push(url); } },
    };
    const calls = [];
    globalThis.window = {
        open(...args) {
            calls.push(args);
            return popup;
        },
    };

    return { popup, calls, navigated };
}

test('direct media URL uses one named opener-preserving popup', async () => {
    const harness = popupHarness();
    let prepared = false;
    const result = await openMediaEditorPopup({
        directUrl: '/seo/media-image-editor?media=12',
        prepareUrl: async () => { prepared = true; },
    });

    assert.deepEqual(harness.calls, [['about:blank', 'seo-media-image-editor']]);
    assert.deepEqual(harness.navigated, ['/seo/media-image-editor?media=12']);
    assert.equal(prepared, false);
    assert.equal(result.editorUrl, '/seo/media-image-editor?media=12');
});

test('prepare branch navigates the same reserved popup after await', async () => {
    const harness = popupHarness();
    let resolvePrepare;
    const preparing = new Promise((resolvePromise) => { resolvePrepare = resolvePromise; });
    const opening = openMediaEditorPopup({ prepareUrl: () => preparing });

    assert.equal(harness.calls.length, 1);
    assert.deepEqual(harness.navigated, []);
    resolvePrepare({ editor_url: '/seo/media-image-editor?media=44' });
    await opening;

    assert.equal(harness.calls.length, 1);
    assert.deepEqual(harness.navigated, ['/seo/media-image-editor?media=44']);
});

test('prepare failure closes the reserved popup and reports the error', async () => {
    const harness = popupHarness();
    const messages = [];
    const result = await openMediaEditorPopup({
        prepareUrl: async () => { throw new Error('prepare failed'); },
        notifyDanger: async (message) => messages.push(message),
    });

    assert.equal(result, null);
    assert.equal(harness.popup.closed, true);
    assert.deepEqual(messages, ['prepare failed']);
    assert.equal(harness.calls.length, 1);
});

test('blocked popup reports a user-visible message', async () => {
    const messages = [];
    globalThis.window = { open: () => null };

    const result = await openMediaEditorPopup({
        prepareUrl: async () => ({ editor_url: '/unused' }),
        notifyDanger: async (message) => messages.push(message),
    });

    assert.equal(result, null);
    assert.deepEqual(messages, [POPUP_BLOCKED_MESSAGE]);
});

test('save callbacks retain opener messaging and parent origin validation', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const editor = readFileSync(resolve(here, '../media-image-editor-page.jsx'), 'utf8');
    const library = readFileSync(resolve(here, '../media-library-actions.js'), 'utf8');
    const splitter = readFileSync(resolve(here, '../components/ImageSplitterApp.jsx'), 'utf8');

    assert.match(editor, /window\.opener\.postMessage/);
    assert.match(editor, /window\.close\(\)/);
    assert.match(splitter, /seo-image-splitter-saved/);
    assert.match(library, /event\.origin !== window\.location\.origin/);
    assert.doesNotMatch(library, /window\.open\('about:blank',[\s\S]*noopener,noreferrer/);
});
