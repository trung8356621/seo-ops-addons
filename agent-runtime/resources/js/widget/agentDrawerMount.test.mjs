import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { shouldRemountDrawer } from './agentDrawerMount.js';

function node({ connected = true, mounted = true, children = 1 } = {}) {
    return {
        isConnected: connected,
        childElementCount: children,
        dataset: { agentMounted: mounted ? '1' : '' },
    };
}

test('a live mounted drawer is reused and a replaced node is mounted again', () => {
    const live = node();
    assert.equal(shouldRemountDrawer(live, { unmount() {} }), false);
    assert.equal(shouldRemountDrawer(node({ connected: false }), { unmount() {} }), true);
    assert.equal(shouldRemountDrawer(node({ children: 0 }), { unmount() {} }), true);
    assert.equal(shouldRemountDrawer(node({ mounted: false }), { unmount() {} }), true);
    assert.equal(shouldRemountDrawer(null, null), false);
});

test('drawer bootstrap remounts once and initialization failure exposes retry', () => {
    const main = readFileSync(new URL('../app/main.jsx', import.meta.url), 'utf8');
    const mount = readFileSync(new URL('./mountAgentWidget.js', import.meta.url), 'utf8');
    assert.equal(main.includes('if (!shouldRemountDrawer(drawerRoot, drawerHandle))'), true);
    assert.equal(main.includes('drawerHandle.unmount()'), true);
    assert.equal(mount.includes('Không thể khởi tạo Agent.'), true);
    assert.equal(mount.includes('Thử lại'), true);
    assert.equal(mount.includes('dataset.agentMounted'), true);
});
