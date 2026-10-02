import assert from 'node:assert/strict';
import {
    createFaqGenerationCommandStore,
    isFaqGenerationCreateDisabled,
    getFaqGenerationCreateDisabledReason,
} from '../../resources/js/utils/faqGenerationCommandStore.js';
import { normalizeFaqPayload } from '../../resources/js/utils/articleEditorPayloadAdapters.js';

const makeStore = () => createFaqGenerationCommandStore();

function resolveFaqCardState({
    faqs = [],
    faqCount = null,
    canGenerateFaq = false,
    phase = 'idle',
    onCreateFaq = () => {},
}) {
    const rows = (faqs ?? []).filter((row) => String(row?.answer ?? '').trim() !== '');
    const countFromRows = rows.length;
    const resolvedCount = Number.isFinite(Number(faqCount)) && Number(faqCount) > 0
        ? Number(faqCount)
        : countFromRows;
    const hasFaq = resolvedCount > 0 || countFromRows > 0;
    const isPreviewPhase = phase === 'preview';
    const generationBusy = phase === 'opening' || phase === 'generating' || phase === 'applying';
    const createDisabled = isFaqGenerationCreateDisabled({
        phase,
        canGenerateFaq,
        onCreateFaq,
    });
    const disabledReason = createDisabled
        ? getFaqGenerationCreateDisabledReason({
            phase,
            canGenerateFaq,
            onCreateFaq,
            t: (k) => k,
        })
        : '';

    let cardBody = 'empty';
    let action = 'create';
    if (hasFaq) {
        cardBody = `${resolvedCount} FAQs`;
        action = 'edit';
    } else if (isPreviewPhase) {
        cardBody = 'preview_pending';
        action = 'view_preview';
    } else if (generationBusy) {
        cardBody = 'generating';
        action = 'busy';
    }

    return {
        hasFaq,
        resolvedCount,
        cardBody,
        action,
        createDisabled,
        disabledReason,
    };
}

// ----------------------------------------------------
// Case A: can_generate_faq=true, canonical FAQ=0 → shortcode Tạo FAQ enabled
// ----------------------------------------------------
{
    const state = resolveFaqCardState({
        faqs: [],
        faqCount: 0,
        canGenerateFaq: true,
        phase: 'idle',
        onCreateFaq: () => {},
    });
    assert.equal(state.hasFaq, false);
    assert.equal(state.action, 'create');
    assert.equal(state.createDisabled, false);
    assert.equal(state.cardBody, 'empty');
    assert.equal(state.disabledReason, '');
}

// ----------------------------------------------------
// Case B: canonical faqCount > 0 nhưng rows chưa lazy hydrate → shortcode hiển thị có FAQ / Xem FAQ
// ----------------------------------------------------
{
    const state = resolveFaqCardState({
        faqs: [],
        faqCount: 4,
        canGenerateFaq: true,
        phase: 'idle',
    });
    assert.equal(state.hasFaq, true);
    assert.equal(state.resolvedCount, 4);
    assert.equal(state.action, 'edit');
    assert.equal(state.cardBody, '4 FAQs');
}

// ----------------------------------------------------
// Case C: click generate trước khi FAQ module mount → request không mất, executor sau mount claim đúng một lần
// ----------------------------------------------------
{
    const store = makeStore();
    const requestId = store.request('shortcode');
    assert.equal(store.getState().phase, 'opening');
    assert.equal(requestId, 1);

    let deliveries = 0;
    let claimedId = null;
    store.registerExecutor(({ requestId: reqId }) => {
        deliveries += 1;
        claimedId = store.claim(reqId);
    });
    assert.equal(deliveries, 1);
    assert.equal(claimedId, 1);
    assert.equal(store.getState().phase, 'generating');

    let remountDeliveries = 0;
    store.registerExecutor(() => { remountDeliveries += 1; });
    assert.equal(remountDeliveries, 0);
}

// ----------------------------------------------------
// Case D: generation API lỗi → store về idle, nút generate usable lại
// ----------------------------------------------------
{
    const store = makeStore();
    const requestId = store.request('shortcode');
    store.registerExecutor(({ requestId: reqId }) => {
        store.claim(reqId);
    });
    assert.equal(store.getState().phase, 'generating');

    assert.equal(store.finish(requestId, 'preview failed'), true);
    assert.equal(store.getState().phase, 'idle');

    const state = resolveFaqCardState({
        faqs: [],
        faqCount: 0,
        canGenerateFaq: true,
        phase: store.getState().phase,
    });
    assert.equal(state.createDisabled, false);
    assert.equal(store.request('retry'), 2);
}

// ----------------------------------------------------
// Case E: generate + apply thành công → faqCount/card sync không reload
// ----------------------------------------------------
{
    const store = makeStore();
    let canonicalFaqCount = 0;
    let canonicalFaqs = [];

    assert.equal(resolveFaqCardState({ faqs: canonicalFaqs, faqCount: canonicalFaqCount, canGenerateFaq: true, phase: store.getState().phase }).action, 'create');

    const requestId = store.request('shortcode');
    store.registerExecutor(({ requestId: reqId }) => { store.claim(reqId); });
    assert.equal(store.getState().phase, 'generating');

    const previewRows = [
        { question: 'Q1', answer: 'A1' },
        { question: 'Q2', answer: 'A2' },
        { question: 'Q3', answer: 'A3' },
    ];
    store.markPreviewReady(requestId);
    assert.equal(store.getState().phase, 'preview');

    // Unapplied preview event must NOT overwrite canonical state
    const previewEvent = { detail: { faqs: previewRows, isPreview: true } };
    if (!previewEvent.detail.isPreview) {
        canonicalFaqs = previewRows;
        canonicalFaqCount = previewRows.length;
    }
    assert.equal(canonicalFaqCount, 0);

    const previewCardState = resolveFaqCardState({
        faqs: canonicalFaqs,
        faqCount: canonicalFaqCount,
        canGenerateFaq: true,
        phase: store.getState().phase,
    });
    assert.equal(previewCardState.cardBody, 'preview_pending');
    assert.equal(previewCardState.action, 'view_preview');

    // Apply preview
    const applyId = store.beginApply();
    assert.equal(store.getState().phase, 'applying');

    // Apply success -> canonical rows sync
    const appliedEvent = { detail: { faqs: previewRows, isPreview: false } };
    if (!appliedEvent.detail.isPreview) {
        canonicalFaqs = appliedEvent.detail.faqs;
        canonicalFaqCount = appliedEvent.detail.faqs.length;
    }
    store.finish(applyId);
    assert.equal(store.getState().phase, 'idle');
    assert.equal(canonicalFaqCount, 3);

    const appliedCardState = resolveFaqCardState({
        faqs: canonicalFaqs,
        faqCount: canonicalFaqCount,
        canGenerateFaq: true,
        phase: store.getState().phase,
    });
    assert.equal(appliedCardState.hasFaq, true);
    assert.equal(appliedCardState.resolvedCount, 3);
    assert.equal(appliedCardState.cardBody, '3 FAQs');
    assert.equal(appliedCardState.action, 'edit');
}

// ----------------------------------------------------
// Case F: can_generate_faq=false → không generate nhưng UI có reason rõ ràng
// ----------------------------------------------------
{
    const state = resolveFaqCardState({
        faqs: [],
        faqCount: 0,
        canGenerateFaq: false,
        phase: 'idle',
    });
    assert.equal(state.createDisabled, true);
    assert.equal(state.disabledReason, 'faq_generate_disabled_reason');
}

// ----------------------------------------------------
// Unregister during generating resets store to idle
// ----------------------------------------------------
{
    const store = makeStore();
    const executor = ({ requestId: reqId }) => { store.claim(reqId); };
    store.request('shortcode');
    store.registerExecutor(executor);
    assert.equal(store.getState().phase, 'generating');
    store.unregisterExecutor(executor);
    assert.equal(store.getState().phase, 'idle');
}

// ----------------------------------------------------
// Payload normalization contract
// ----------------------------------------------------
{
    const res1 = normalizeFaqPayload({ data: { count: 3, can_generate_faq: true, items: [] } });
    assert.equal(res1.canGenerateFaq, true);
    assert.equal(res1.count, 3);

    const res2 = normalizeFaqPayload({ data: { faq_count: 2, can_generate: true, items: [] } });
    assert.equal(res2.canGenerateFaq, true);
    assert.equal(res2.count, 2);

    const res3 = normalizeFaqPayload(null);
    assert.equal(res3.canGenerateFaq, false);
    assert.equal(res3.count, 0);

    const res4 = normalizeFaqPayload({ data: { can_generate_faq: false } });
    assert.equal(res4.canGenerateFaq, false);
}

console.log('faqGenerationCommand.selftest: ok');

