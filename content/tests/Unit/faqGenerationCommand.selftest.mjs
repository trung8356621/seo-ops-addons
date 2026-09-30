import assert from 'node:assert/strict';
import {
    createFaqGenerationCommandStore,
    isFaqGenerationCreateDisabled,
} from '../../resources/js/utils/faqGenerationCommandStore.js';

const makeStore = () => createFaqGenerationCommandStore();

{
    const store = makeStore();
    const requestId = store.request('shortcode');
    assert.equal(store.getState().phase, 'opening');
    let deliveries = 0;
    store.registerExecutor(({ requestId: deliveredId }) => {
        deliveries += 1;
        assert.equal(deliveredId, requestId);
    });
    assert.equal(deliveries, 1);
}

assert.equal(isFaqGenerationCreateDisabled({
    phase: 'idle',
    canGenerateFaq: false,
    onCreateFaq: () => {},
}), true);
assert.equal(isFaqGenerationCreateDisabled({
    phase: 'idle',
    canGenerateFaq: true,
    onCreateFaq: () => {},
}), false);

{
    const store = makeStore();
    let deliveries = 0;
    store.registerExecutor(() => { deliveries += 1; });
    store.request('panel');
    assert.equal(deliveries, 1);
}

{
    const store = makeStore();
    let deliveries = 0;
    store.registerExecutor(() => { deliveries += 1; });
    const first = store.request('shortcode');
    assert.equal(store.request('seo-violation'), null);
    assert.equal(first, 1);
    assert.equal(deliveries, 1);
}

{
    const store = makeStore();
    const executor = () => {};
    store.request('shortcode');
    store.registerExecutor(executor);
    store.unregisterExecutor(executor);
    let remountDeliveries = 0;
    store.registerExecutor(() => { remountDeliveries += 1; });
    assert.equal(remountDeliveries, 0);
}

{
    const store = makeStore();
    const requestId = store.request('shortcode');
    assert.equal(store.claim(requestId), requestId);
    assert.equal(store.finish(requestId, 'preview failed'), true);
    assert.equal(store.getState().phase, 'idle');
    assert.equal(store.request('retry'), 2);
}

{
    const store = makeStore();
    const requestId = store.request('shortcode');
    store.claim(requestId);
    assert.equal(store.markPreviewReady(requestId), true);
    assert.equal(store.getState().phase, 'preview');
    assert.equal(store.request('blocked-while-preview'), null);
    assert.equal(store.beginApply(), requestId);
    assert.equal(store.getState().phase, 'applying');
    assert.equal(store.finish(requestId), true);
    assert.equal(store.getState().phase, 'idle');
}

console.log('faqGenerationCommand.selftest: ok');
