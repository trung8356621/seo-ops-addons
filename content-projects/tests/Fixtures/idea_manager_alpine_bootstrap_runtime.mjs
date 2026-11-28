import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const bladePath = process.argv[2];
if (!bladePath) {
    throw new Error('Missing bladePath argument');
}

const blade = fs.readFileSync(bladePath, 'utf8');
const startIdx = blade.indexOf('function ideaManagerRoot(cfg)');
assert.notEqual(startIdx, -1, 'ideaManagerRoot(cfg) not found in Blade');

// Extract from function ideaManagerRoot(cfg) up to the closing </script>
const endScriptIdx = blade.indexOf('</script>', startIdx);
assert.notEqual(endScriptIdx, -1, '</script> not found after ideaManagerRoot');
const rootScript = blade.slice(startIdx, endScriptIdx);

// Minimal Alpine v3 reactive Proxy + Alpine.raw simulation:
// Any object/array assigned onto or read from the component is wrapped in a Proxy
// so calling structuredClone(proxy) directly throws DataCloneError just like in browsers.
const rawMap = new WeakMap();
function wrapReactive(val) {
    if (val === null || typeof val !== 'object') return val;
    if (rawMap.has(val)) return val; // already a proxy
    const proxy = new Proxy(val, {
        get(target, prop, receiver) {
            const v = Reflect.get(target, prop, receiver);
            if (typeof v === 'function') return v.bind(receiver);
            return wrapReactive(v);
        },
        set(target, prop, value, receiver) {
            const unwrapped = rawMap.get(value) || value;
            return Reflect.set(target, prop, unwrapped, receiver);
        },
    });
    rawMap.set(proxy, val);
    return proxy;
}

const store = new Map();
const localStorageMock = {
    getItem(k) { return store.has(k) ? store.get(k) : null; },
    setItem(k, v) { store.set(k, String(v)); },
    removeItem(k) { store.delete(k); },
};

const windowObj = {
    Alpine: {
        raw(v) {
            return rawMap.get(v) || v;
        },
    },
    addEventListener() {},
    dispatchEvent() {},
};

const context = vm.createContext({
    window: windowObj,
    document: { visibilityState: 'visible', addEventListener() {} },
    localStorage: localStorageMock,
    CustomEvent: class CustomEvent { constructor(name, init) { this.name = name; this.detail = init?.detail; } },
    structuredClone: globalThis.structuredClone,
    setTimeout,
    clearTimeout,
    queueMicrotask,
    Date,
    RegExp,
    WeakSet,
    Array,
    Object,
    String,
    Number,
    Boolean,
    JSON,
    console,
});

vm.runInContext(rootScript, context);

function makeIdeas(count = 13) {
    const out = [];
    for (let i = 1; i <= count; i++) {
        const isCapsule = i === 13;
        out.push({
            warehouse_key: isCapsule ? '4:cluster_capsule_13' : `1:cluster_${i}`,
            owner_site_id: isCapsule ? 4 : 1,
            source_site_id: isCapsule ? 4 : 1,
            source_domain: isCapsule ? 'thoitrang4.example' : 'site1.example',
            owner_domain: isCapsule ? 'thoitrang4.example' : 'site1.example',
            source_type: 'cluster',
            cluster_ref: isCapsule ? 'cluster_capsule_13' : `cluster_${i}`,
            cluster_name_snapshot: isCapsule ? 'phong cách capsule wardrobe' : `Idea ${i}`,
            seed_text: isCapsule ? 'phong cách capsule wardrobe' : `Idea ${i}`,
            target_dna_count: 3,
            target_mode: 'manual',
            dna: [{ text: isCapsule ? 'capsule basics' : `dna ${i}`, source: 'manual' }],
            mcp_share_snapshot: { score: i, nested: { tag: isCapsule ? 'capsule' : 'std' } },
        });
    }
    return out;
}

function makeComponent(serverSnap, wireImpl) {
    const rawComp = context.ideaManagerRoot({
        siteId: 1,
        siteDomain: 'site1.example',
        activeTab: 'ideas',
        debugEnabled: true,
        probeNeedle: 'phong cách capsule wardrobe',
        server: serverSnap,
    });
    rawComp.$wire = wireImpl || {
        loadGlobalIdeaWarehouse: async () => serverSnap,
        saveIdeaWorkspace: async () => ({ ok: true, revision: 2, item_count: 13 }),
    };
    return wrapReactive(rawComp);
}

// Confirm our Proxy actually triggers DataCloneError when structuredClone is called directly.
{
    const proxied = wrapReactive([{ a: 1 }]);
    assert.throws(() => structuredClone(proxied), /could not be cloned|DataCloneError/i);
}

// 1. Bootstrap with 13 Ideas under Alpine reactive proxy, clearing prior hydration error.
{
    store.clear();
    const serverSnap = {
        revision: 0,
        updated_at: '2026-11-27T08:00:00Z',
        item_count: 13,
        note_items: makeIdeas(13),
        sites: {
            '1': { revision: 1, domain: 'site1.example', item_count: 12, updated_at: '2026-11-27T08:00:00Z' },
            '4': { revision: 1, domain: 'thoitrang4.example', item_count: 1, updated_at: '2026-11-27T08:00:00Z' },
        },
    };
    const comp = makeComponent(serverSnap);
    comp.debugHydrationError = 'bootstrap:previous_error';
    comp.bootstrapFromServer(serverSnap);

    assert.equal(comp.debugHydrationError, '', 'hydration_error must clear on successful bootstrap');
    assert.equal(comp.items.length, 13, 'working set must have 13 Ideas');
    assert.ok(comp.debugSeq > 0, 'last_bootstrap_seq must advance');
    assert.equal(comp.probePresentIn(comp.items), true, 'probe must be in items');

    comp.search = 'phong cách capsule wardrobe';
    const visible = comp.filteredItems();
    assert.equal(visible.length, 1, 'exact search must return 1 Idea');
    assert.equal(visible[0].owner_site_id, 4);
    assert.equal(visible[0].dna[0].text, 'capsule basics');
    assert.equal(visible[0].mcp_share_snapshot.nested.tag, 'capsule');

    // partitionByOwner must not throw DataCloneError on reactive items
    const parts = comp.partitionByOwner();
    assert.equal(parts['1'].length, 12);
    assert.equal(parts['4'].length, 1);
}

// 2. Dirty local draft for owner 1 reconciles with clean owner 4 server Idea without DataCloneError.
{
    store.clear();
    const serverSnap = {
        revision: 0,
        updated_at: '2026-11-27T08:00:00Z',
        item_count: 13,
        note_items: makeIdeas(13),
        sites: {
            '1': { revision: 1, domain: 'site1.example', item_count: 12, updated_at: '2026-11-27T08:00:00Z' },
            '4': { revision: 1, domain: 'thoitrang4.example', item_count: 1, updated_at: '2026-11-27T08:00:00Z' },
        },
    };
    const localDraftItems = makeIdeas(12);
    localDraftItems[0].cluster_name_snapshot = 'Local edited Idea 1';
    store.set('seoOps:idea-manager:draft:v2:global', JSON.stringify({
        v: 2,
        dirty: true,
        dirty_owners: { '1': true },
        note_items: localDraftItems,
        site_revisions: { '1': 1, '4': 1 },
        edit_generation: 3,
    }));

    const comp = makeComponent(serverSnap);
    comp.bootstrapFromServer(serverSnap);

    assert.equal(comp.debugHydrationError, '');
    assert.equal(comp.dirty, true);
    assert.equal(comp.items.length, 13);
    assert.equal(comp.items.some((it) => it.cluster_name_snapshot === 'Local edited Idea 1'), true);
    comp.search = 'phong cách capsule wardrobe';
    assert.equal(comp.filteredItems().length, 1);

    // 3. Soft reload on dirty tab must not reintroduce DataCloneError
    await comp.softReloadGlobal();
    assert.equal(comp.debugHydrationError, '');
    assert.equal(comp.items.length, 13);
    assert.equal(comp.filteredItems().length, 1);
}

// 4. Malformed individual item does not wipe valid Ideas
{
    store.clear();
    const items = makeIdeas(13);
    const badItem = {};
    Object.defineProperty(badItem, 'broken', {
        enumerable: true,
        get() { throw new Error('unreadable getter'); },
    });
    items.splice(3, 0, null, 'bad-string', badItem);

    const serverSnap = {
        revision: 0,
        updated_at: '2026-11-27T08:00:00Z',
        item_count: items.length,
        note_items: items,
        sites: { '1': { revision: 1 }, '4': { revision: 1 } },
    };
    const comp = makeComponent(serverSnap);
    comp.bootstrapFromServer(serverSnap);

    assert.equal(comp.debugHydrationError, '');
    assert.equal(comp.items.length, 13);
    comp.search = 'phong cách capsule wardrobe';
    assert.equal(comp.filteredItems().length, 1);
}

console.log('ALPINE_BOOTSTRAP_RUNTIME_OK');
