import assert from 'node:assert/strict';
import test from 'node:test';
import {
    DEV_MODE_NORMAL,
    DEV_MODE_DEBUG,
    DEV_MODE_DIAG,
    getDeveloperModeStorageKey,
    getStoredDeveloperMode,
    setStoredDeveloperMode,
} from './agentDevMode.js';

test('getDeveloperModeStorageKey scopes to appKey', () => {
    assert.equal(getDeveloperModeStorageKey('seo-ops'), 'agent-runtime:developer-mode:seo-ops');
    assert.equal(getDeveloperModeStorageKey(''), 'agent-runtime:developer-mode:seo-ops');
});

test('getStoredDeveloperMode returns normal by default and handles invalid values', () => {
    const memory = new Map();
    globalThis.window = {
        localStorage: {
            getItem: (key) => memory.get(key) ?? null,
            setItem: (key, val) => memory.set(key, String(val)),
        },
    };

    assert.equal(getStoredDeveloperMode('seo-ops'), 'normal');

    memory.set('agent-runtime:developer-mode:seo-ops', 'invalid-mode');
    assert.equal(getStoredDeveloperMode('seo-ops'), 'normal');

    memory.set('agent-runtime:developer-mode:seo-ops', 'debug');
    assert.equal(getStoredDeveloperMode('seo-ops'), 'debug');

    memory.set('agent-runtime:developer-mode:seo-ops', 'diag');
    assert.equal(getStoredDeveloperMode('seo-ops'), 'diag');

    memory.set('agent-runtime:developer-mode:seo-ops', 'normal');
    assert.equal(getStoredDeveloperMode('seo-ops'), 'normal');
});

test('setStoredDeveloperMode persists valid values and falls back for invalid', () => {
    const memory = new Map();
    globalThis.window = {
        localStorage: {
            getItem: (key) => memory.get(key) ?? null,
            setItem: (key, val) => memory.set(key, String(val)),
        },
    };

    setStoredDeveloperMode('seo-ops', DEV_MODE_DEBUG);
    assert.equal(memory.get('agent-runtime:developer-mode:seo-ops'), 'debug');

    setStoredDeveloperMode('seo-ops', DEV_MODE_DIAG);
    assert.equal(memory.get('agent-runtime:developer-mode:seo-ops'), 'diag');

    setStoredDeveloperMode('seo-ops', 'unknown');
    assert.equal(memory.get('agent-runtime:developer-mode:seo-ops'), 'normal');
});
