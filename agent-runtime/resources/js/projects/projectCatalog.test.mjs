import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { buildProjectItems, PROJECT_SIDEBAR_ACTIONS, scopePayload, switchProject } from './projectCatalog.js';

test('sidebar lists All Sites, Test utility, then each site and has no add-website action', () => {
    const sites = [
        { id: 7, domain: 'congtybalo.com' },
        { id: 8, domain: 'xuongmaytuikhongdet.com' },
    ];
    const items = buildProjectItems(sites);
    assert.equal(items[0].type, 'global');
    assert.equal(items[0].label, 'All Sites');
    assert.equal(items[0].siteId, undefined);
    assert.equal(items[1].type, 'utility');
    assert.equal(items[1].key, 'test');
    assert.equal(items[1].label, '/** Test');
    assert.deepEqual(items.slice(2).map((item) => item.label), [
        'congtybalo.com',
        'xuongmaytuikhongdet.com',
    ]);
    assert.equal(items[2].siteRef, 'site:7');
    assert.deepEqual(PROJECT_SIDEBAR_ACTIONS, []);
    assert.equal(JSON.stringify(items).includes('Add website'), false);
    assert.equal(JSON.stringify(items).includes('Add Website'), false);
});

test('All Sites scope is not a fake site id', () => {
    const items = buildProjectItems([{ id: 7, domain: 'congtybalo.com' }]);
    assert.deepEqual(scopePayload(items[0]), { type: 'global' });
    assert.equal(scopePayload(items[0]).siteId, undefined);
    assert.throws(() => scopePayload(items[1]), /not an AgentProjectScope/);
    assert.deepEqual(scopePayload(items[2]), {
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
    assert.equal(items[2].label, 'congtybalo.com');
});

test('global retrieval stays unsupported', () => {
    const items = buildProjectItems([]);
    assert.equal(items[0].retrieval, 'unsupported');
});

test('canonical agent widget does not offer add website', () => {
    const source = readFileSync(new URL('../widget/AgentWidget.jsx', import.meta.url), 'utf8');
    assert.equal(/add website/i.test(source), false);
    assert.equal(source.includes('buildProjectItems'), true);
    assert.equal(source.includes('scopePayload'), true);
});

test('canonical agent widget blocks global turn execution and displays clear unsupported feedback', () => {
    const source = readFileSync(new URL('../widget/AgentWidget.jsx', import.meta.url), 'utf8');
    assert.equal(source.includes('globalUnsupported'), true);
    assert.equal(source.includes('All Sites retrieval is unsupported'), true);
    assert.equal(source.includes('setError'), true);
    assert.equal(source.includes('normalizeHostContext'), true);
});

test('Test utility renders one shared shell and never enters normal Agent scope flow', () => {
    const source = readFileSync(new URL('../widget/AgentWidget.jsx', import.meta.url), 'utf8');
    assert.equal(source.includes("selected?.type === 'utility' && selected?.key === 'test'"), true);
    assert.equal(source.includes('TestTargetPicker'), true);
    assert.equal(source.includes('ImageIcon'), true);
    assert.equal(source.includes('<Video'), true);
    assert.equal(source.includes('Select a concrete site'), true);
    assert.equal(source.includes('<option value="article">Article</option>'), true);
    assert.equal(source.includes('<option value="raw">Raw input</option>'), true);
    assert.equal(source.includes('Article picker/search'), true);
    assert.equal(source.includes('Test results will be stored in Agent conversation'), true);
    assert.equal(source.includes('className="agent-test-run" disabled'), true);
    assert.equal(source.includes('if (isTestMode || !currentScopeRef)'), true);
});

test('main entry point exports canonical widget, mount adapter, and sets window.AgentRuntime', () => {
    const mainSource = readFileSync(new URL('../app/main.jsx', import.meta.url), 'utf8');
    assert.equal(mainSource.includes('mountAgentWidget'), true);
    assert.equal(mainSource.includes('AgentWidget'), true);
    assert.equal(mainSource.includes('window.AgentRuntime'), true);

    const mountSource = readFileSync(new URL('../widget/mountAgentWidget.js', import.meta.url), 'utf8');
    assert.equal(mountSource.includes('createRoot'), true);
    assert.equal(mountSource.includes('AgentWidget'), true);
    assert.equal(mountSource.includes('unmount'), true);
});

test('response blocks component preserves structured blocks, actions, and sources', () => {
    const source = readFileSync(new URL('../response/ResponseBlocks.jsx', import.meta.url), 'utf8');
    assert.equal(source.includes('ActionsBlock'), true);
    assert.equal(source.includes('SourcesBlock'), true);
    assert.equal(source.includes('ChartBlock'), true);
    assert.equal(source.includes('TableBlock'), true);
    assert.equal(source.includes('MarkdownBlock'), true);
    assert.equal(source.includes('block.type === \'chart\''), true);
    assert.equal(source.includes('block.type === \'table\''), true);
    assert.equal(source.includes('block.data'), true);
    assert.equal(source.includes('source.status === \'unavailable\''), true);
});

test('canonical agent widget supports drawer mode without a visible header close button', () => {
    const source = readFileSync(new URL('../widget/AgentWidget.jsx', import.meta.url), 'utf8');
    assert.equal(source.includes("mode === 'drawer'"), true);
    assert.equal(source.includes('agent-shell--drawer'), true);
    assert.equal(source.includes('agent-drawer-header'), true);
    assert.equal(source.includes('agent-project-select'), true);
    assert.equal(source.includes('agent-drawer-close-btn'), false);
    assert.equal(source.includes('onClose'), true);
    // When isDrawer, showSidebar is false
    assert.equal(source.includes('!isDrawer'), true);
});

test('main entry point exports mountDrawer and hooks up onClose to agent-drawer:close event', () => {
    const source = readFileSync(new URL('../app/main.jsx', import.meta.url), 'utf8');
    assert.equal(source.includes('export function mountDrawer'), true);
    assert.equal(source.includes('agent-drawer:close'), true);
    assert.equal(source.includes('mountDrawer,'), true);
    assert.equal(source.includes('mode: \'drawer\''), true);
});

test('blade views define global launcher and right-side drawer hook', () => {
    const launcherSource = readFileSync(new URL('../../views/filament/hooks/agent-launcher.blade.php', import.meta.url), 'utf8');
    assert.equal(launcherSource.includes('agent-drawer:toggle'), true);
    assert.equal(launcherSource.includes('global-agent-launcher-trigger'), true);
    assert.equal(launcherSource.includes('aria-haspopup="dialog"'), true);

    const drawerSource = readFileSync(new URL('../../views/filament/hooks/agent-drawer.blade.php', import.meta.url), 'utf8');
    assert.equal(drawerSource.includes('agent-drawer-root'), true);
    assert.equal(drawerSource.includes('agent-drawer-container'), true);
    assert.equal(drawerSource.includes('agent-drawer-scrim'), true);
    assert.equal(drawerSource.includes('agent-drawer:toggle'), true);
    assert.equal(drawerSource.includes('agent-drawer:close'), true);
    assert.equal(drawerSource.includes('x-on:click="close()"'), true);
    assert.equal(drawerSource.includes('x-on:keydown.escape.window="close()"'), true);
    assert.equal(drawerSource.includes('DomainContextResolver'), true);
});

