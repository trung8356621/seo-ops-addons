/**
 * @vitest-environment node
 */
import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';
import {
    acknowledgeDocumentVersion,
    bindEditorDocumentRevision,
    getConfirmedDocumentVersion,
    observeServerDocumentVersion,
    resetEditorDocumentRevisionForTests,
    stampExpectedDocumentVersion,
} from '../utils/editorDocumentRevision.js';

describe('editorDocumentRevision', () => {
    beforeEach(() => {
        resetEditorDocumentRevisionForTests();
    });

    it('A/B — observe (heartbeat/meta read) does not bump confirmed revision', () => {
        bindEditorDocumentRevision(42, 10);
        assert.equal(getConfirmedDocumentVersion(), 10);
        assert.equal(observeServerDocumentVersion(10, { source: 'lease_renew' }), 10);
        assert.equal(observeServerDocumentVersion(11, { source: 'lease_renew' }), 10);
        assert.equal(getConfirmedDocumentVersion(), 10);
    });

    it('C — sequential acks consume returned latest revision', () => {
        bindEditorDocumentRevision(42, 20);
        assert.equal(acknowledgeDocumentVersion(21, { source: 'save_ack' }).version, 21);
        assert.equal(acknowledgeDocumentVersion(22, { source: 'save_ack' }).version, 22);
        assert.equal(getConfirmedDocumentVersion(), 22);
        const payload = { expected_document_version: 20 };
        stampExpectedDocumentVersion(payload);
        assert.equal(payload.expected_document_version, 22);
    });

    it('D — overlapping writes cannot keep a stale base revision', () => {
        bindEditorDocumentRevision(42, 20);
        acknowledgeDocumentVersion(21, { source: 'autosave' });
        const staleFactory = { expected_document_version: 20, html: '<p>next</p>' };
        stampExpectedDocumentVersion(staleFactory);
        assert.equal(staleFactory.expected_document_version, 21);
    });

    it('E — stale/out-of-order ack cannot regress confirmed revision', () => {
        bindEditorDocumentRevision(42, 20);
        acknowledgeDocumentVersion(22, { source: 'save_ack' });
        const stale = acknowledgeDocumentVersion(21, { source: 'late_autosave' });
        assert.equal(stale.stale, true);
        assert.equal(stale.applied, false);
        assert.equal(stale.version, 22);
        assert.equal(getConfirmedDocumentVersion(), 22);
        observeServerDocumentVersion(20, { source: 'lease_renew' });
        assert.equal(getConfirmedDocumentVersion(), 22);
    });

    it('F — autosave then explicit save stamps confirmed base', () => {
        bindEditorDocumentRevision(42, 30);
        acknowledgeDocumentVersion(31, { source: 'autosave' });
        const explicit = { expected_document_version: 30, save_mode: 'explicit' };
        stampExpectedDocumentVersion(explicit);
        assert.equal(explicit.expected_document_version, 31);
    });

    it('G — own background document mutation updates confirmed revision', () => {
        bindEditorDocumentRevision(42, 40);
        const faq = acknowledgeDocumentVersion(41, { source: 'faq_apply' });
        assert.equal(faq.applied, true);
        assert.equal(getConfirmedDocumentVersion(), 41);
        const nextSave = { expected_document_version: 40 };
        stampExpectedDocumentVersion(nextSave);
        assert.equal(nextSave.expected_document_version, 41);
    });

    it('H — unexplained higher observe is not adopted as save base', () => {
        bindEditorDocumentRevision(42, 50);
        observeServerDocumentVersion(51, { source: 'lease_renew' });
        assert.equal(getConfirmedDocumentVersion(), 50);
        const payload = { expected_document_version: 50 };
        stampExpectedDocumentVersion(payload);
        assert.equal(payload.expected_document_version, 50);
    });

    it('I — bind across articles does not keep the previous article revision', () => {
        bindEditorDocumentRevision(1, 99);
        bindEditorDocumentRevision(2, 3);
        assert.equal(getConfirmedDocumentVersion(), 3);
    });
});
