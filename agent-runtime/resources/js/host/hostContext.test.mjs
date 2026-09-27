import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeHostContext, buildScopePayload } from './hostContext.js';

test('normalizeHostContext defaults to standalone app with null scope', () => {
    const ctx = normalizeHostContext();
    assert.equal(ctx.appKey, 'standalone');
    assert.equal(ctx.scope, null);
    assert.deepEqual(ctx.capabilities, ['turn', 'model-input']);
});

test('normalizeHostContext normalizes seo-ops host with site scope and ref', () => {
    const ctx = normalizeHostContext({
        appKey: 'seo-ops',
        scope: {
            type: 'site',
            ref: 'site:3',
            label: 'congtybalo.com',
        },
        capabilities: ['turn', 'model-input'],
    });

    assert.equal(ctx.appKey, 'seo-ops');
    assert.equal(ctx.scope.type, 'site');
    assert.equal(ctx.scope.ref, 'site:3');
    assert.equal(ctx.scope.siteId, 3);
    assert.equal(ctx.scope.label, 'congtybalo.com');
    assert.deepEqual(ctx.capabilities, ['turn', 'model-input']);
});

test('normalizeHostContext normalizes wordpress host with siteId and auto-generated ref', () => {
    const ctx = normalizeHostContext({
        app_key: 'wordpress',
        scope: {
            type: 'site',
            site_id: 12,
            domain: 'my-wordpress-blog.com',
        },
    });

    assert.equal(ctx.appKey, 'wordpress');
    assert.equal(ctx.scope.type, 'site');
    assert.equal(ctx.scope.siteId, 12);
    assert.equal(ctx.scope.ref, 'site:12');
    assert.equal(ctx.scope.label, 'my-wordpress-blog.com');
});

test('normalizeHostContext normalizes global scope', () => {
    const ctx = normalizeHostContext({
        appKey: 'seo-ops',
        scope: {
            type: 'global',
        },
    });

    assert.equal(ctx.scope.type, 'global');
    assert.equal(ctx.scope.ref, 'global');
    assert.equal(ctx.scope.label, 'All Sites');
});

test('buildScopePayload produces correct backend payload for global and site scopes', () => {
    assert.deepEqual(buildScopePayload({ type: 'global' }), {
        type: 'global',
        ref: 'global',
    });

    assert.deepEqual(buildScopePayload({ type: 'site', ref: 'site:5', siteId: 5 }), {
        type: 'site',
        ref: 'site:5',
        siteId: 5,
        site_id: 5,
    });
});
