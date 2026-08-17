import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { isEditorChunkLoadError } from '../editor/runtime/staleEditorAssets.js';

describe('isEditorChunkLoadError', () => {
    it('detects Vite dynamic import 404 failures', () => {
        assert.equal(
            isEditorChunkLoadError(
                new TypeError('Failed to fetch dynamically imported module: http://localhost:8000/build/assets/ImagesModule-old.js'),
            ),
            true,
        );
        assert.equal(isEditorChunkLoadError({ name: 'ChunkLoadError', message: 'Loading chunk 5 failed' }), true);
        assert.equal(isEditorChunkLoadError(new Error('Network down')), false);
    });
});
