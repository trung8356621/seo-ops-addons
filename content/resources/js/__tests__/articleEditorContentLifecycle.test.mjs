/**
 * @vitest-environment node
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    CONTENT_LIFECYCLE,
    resolveContentLifecycleFromFacts,
    normalizeContentLifecyclePayload,
    isContentLifecycleEditable,
    isContentSyncRequired,
} from '../utils/articleEditorContentLifecycle.js';

describe('articleEditorContentLifecycle', () => {
    it('A — WP linked + local missing → CONTENT_LOADING (auto-fetch)', () => {
        const state = resolveContentLifecycleFromFacts({
            loadCompleted: true,
            wordpressLinked: true,
            localContentPresent: false,
        });
        assert.equal(state, CONTENT_LIFECYCLE.CONTENT_LOADING);
        assert.equal(isContentSyncRequired(state), false);
        assert.equal(isContentLifecycleEditable(state), false);
    });

    it('B — loading does not jump to SYNC_REQUIRED', () => {
        const state = resolveContentLifecycleFromFacts({
            loadCompleted: false,
            wordpressLinked: true,
            localContentPresent: false,
        });
        assert.equal(state, CONTENT_LIFECYCLE.CONTENT_LOADING);
        assert.equal(isContentSyncRequired(state), false);
    });

    it('C — new article empty → NEW_EMPTY_ARTICLE editable', () => {
        const state = resolveContentLifecycleFromFacts({
            loadCompleted: true,
            wordpressLinked: false,
            localContentPresent: false,
        });
        assert.equal(state, CONTENT_LIFECYCLE.NEW_EMPTY_ARTICLE);
        assert.equal(isContentLifecycleEditable(state), true);
        assert.equal(isContentSyncRequired(state), false);
    });

    it('D — sync success facts → EDITABLE', () => {
        const state = resolveContentLifecycleFromFacts({
            loadCompleted: true,
            wordpressLinked: true,
            localContentPresent: true,
        });
        assert.equal(state, CONTENT_LIFECYCLE.EDITABLE);
        assert.equal(isContentLifecycleEditable(state), true);
    });

    it('E — error keeps blocker semantics', () => {
        const state = resolveContentLifecycleFromFacts({
            loadCompleted: true,
            error: true,
            wordpressLinked: true,
            localContentPresent: false,
        });
        assert.equal(state, CONTENT_LIFECYCLE.ERROR);
        assert.equal(isContentLifecycleEditable(state), false);
    });

    it('normalizes bootstrap camelCase / snake_case and maps SYNC_REQUIRED to CONTENT_LOADING', () => {
        const payload = normalizeContentLifecyclePayload({
            state: 'SYNC_REQUIRED',
            wordpressLinked: true,
            localContentPresent: false,
            wpPostId: 99,
            observedPermalink: 'https://example.com/post',
            allowFetchFromWordPress: true,
        });
        assert.equal(payload.state, 'CONTENT_LOADING');
        assert.equal(payload.wordpress_linked, true);
        assert.equal(payload.wp_post_id, 99);
        assert.equal(payload.observed_permalink, 'https://example.com/post');
        assert.equal(payload.allow_fetch_from_wordpress, true);
    });
});
