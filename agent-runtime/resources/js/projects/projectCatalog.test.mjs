import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { buildProjectItems, PROJECT_SIDEBAR_ACTIONS, scopePayload, switchProject } from './projectCatalog.js';

test('sidebar lists All Sites then each site and has no add-website action', () => {
    const sites = [
        { id: 7, domain: 'congtybalo.com' },
        { id: 8, domain: 'xuongmaytuikhongdet.com' },
    ];
    const items = buildProjectItems(sites);
    assert.equal(items[0].type, 'global');
    assert.equal(items[0].label, 'All Sites');
    assert.equal(items[0].siteId, undefined);
    assert.deepEqual(items.slice(1).map((item) => item.label), [
        'congtybalo.com',
        'xuongmaytuikhongdet.com',
    ]);
    assert.equal(items[1].siteRef, 'site:7');
    assert.deepEqual(PROJECT_SIDEBAR_ACTIONS, []);
    assert.equal(JSON.stringify(items).includes('Add website'), false);
    assert.equal(JSON.stringify(items).includes('Add Website'), false);
});

test('All Sites scope is not a fake site id', () => {
    const items = buildProjectItems([{ id: 7, domain: 'congtybalo.com' }]);
    assert.deepEqual(scopePayload(items[0]), { type: 'global' });
    assert.equal(scopePayload(items[0]).siteId, undefined);
    assert.deepEqual(scopePayload(items[1]), {
        type: 'site',
        siteId: 7,
        siteRef: 'site:7',
    });
});

test('switching projects does not mutate website rows', () => {
    const sites = [{ id: 7, domain: 'congtybalo.com' }];
    const before = structuredClone(sites);
    const items = buildProjectItems(sites);
    const selected = switchProject(items, 'site:7');
    selected.label = 'changed.example';
    assert.deepEqual(sites, before);
    assert.equal(items[1].label, 'congtybalo.com');
});

test('global retrieval stays unsupported', () => {
    const items = buildProjectItems([]);
    assert.equal(items[0].retrieval, 'unsupported');
});

test('react shell does not offer add website', () => {
    const source = readFileSync(new URL('../app/main.jsx', import.meta.url), 'utf8');
    assert.equal(/add website/i.test(source), false);
    assert.equal(source.includes('buildProjectItems'), true);
    assert.equal(source.includes('scopePayload'), true);
});
