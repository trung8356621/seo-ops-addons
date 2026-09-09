import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    findSuggestionPhraseOccurrences,
    resolveSuggestionInsertMatch,
    resolveSuggestionLocatePhrase,
} from '../utils/suggestedInternalLinkInsertMatch.js';
import { hasExplicitEditorTextSelection } from '../utils/editorExplicitSelection.js';

describe('resolveSuggestionLocatePhrase', () => {
    it('prefers matched_phrase over display text', () => {
        const phrase = resolveSuggestionLocatePhrase({
            text: 'May Túi Vải Không Dệt',
            matched_phrase: 'túi vải không dệt',
        });
        assert.equal(phrase, 'túi vải không dệt');
    });

    it('reads provenance.matched_phrase', () => {
        const phrase = resolveSuggestionLocatePhrase({
            text: 'Label',
            provenance: { matched_phrase: 'túi canvas' },
        });
        assert.equal(phrase, 'túi canvas');
    });
});

describe('resolveSuggestionInsertMatch', () => {
    const blocks = [
        { id: 'b1', content: '<p>túi vải không dệt lần 1</p>' },
        { id: 'b2', content: '<p>giữa bài túi vải không dệt lần 2 và túi vải không dệt lần 3</p>' },
    ];

    it('TEST 1 — default match uses locate phrase without selection', () => {
        const match = resolveSuggestionInsertMatch(
            { text: 'May Túi Vải Không Dệt', matched_phrase: 'túi vải không dệt' },
            null,
            blocks,
        );
        assert.ok(match);
        assert.equal(match.blockId, 'b1');
        assert.equal(match.matchIndex, 0);
        assert.equal(match.phrase, 'túi vải không dệt');
    });

    it('TEST 6 — stored occurrence #2 in block wins over first document hit', () => {
        const all = findSuggestionPhraseOccurrences(blocks, 'túi vải không dệt', 10);
        assert.ok(all.length >= 3);
        const match = resolveSuggestionInsertMatch(
            { text: 'May Túi Vải Không Dệt', matched_phrase: 'túi vải không dệt' },
            { blockId: 'b2', matchIndex: 1, phrase: 'túi vải không dệt' },
            blocks,
        );
        assert.ok(match);
        assert.equal(match.blockId, 'b2');
        assert.equal(match.matchIndex, 1);
    });

    it('TEST 7 — stale unique in-block re-resolves', () => {
        const match = resolveSuggestionInsertMatch(
            { matched_phrase: 'túi vải không dệt' },
            { blockId: 'b1', matchIndex: 9, phrase: 'túi vải không dệt' },
            blocks,
        );
        assert.ok(match);
        assert.equal(match.blockId, 'b1');
        assert.equal(match.matchIndex, 0);
    });

    it('TEST 7b — stale ambiguous in-block does not guess', () => {
        const match = resolveSuggestionInsertMatch(
            { matched_phrase: 'túi vải không dệt' },
            { blockId: 'b2', matchIndex: 9, phrase: 'túi vải không dệt' },
            blocks,
        );
        assert.equal(match, null);
    });

    it('TEST 8 — no match returns null', () => {
        const match = resolveSuggestionInsertMatch(
            { matched_phrase: 'cụm không tồn tại xyz' },
            null,
            blocks,
        );
        assert.equal(match, null);
    });
});

describe('hasExplicitEditorTextSelection', () => {
    it('TEST 3 — collapsed caret is not custom selection', () => {
        globalThis.window = {
            getSelection() {
                return {
                    isCollapsed: true,
                    rangeCount: 1,
                    toString: () => '',
                    getRangeAt: () => ({ commonAncestorContainer: null }),
                };
            },
        };
        assert.equal(hasExplicitEditorTextSelection(), false);
    });

    it('TEST 4 — selection outside editor is ignored', () => {
        const sidebarNode = {
            nodeType: 1,
            parentElement: {
                closest: () => null,
            },
        };
        globalThis.window = {
            getSelection() {
                return {
                    isCollapsed: false,
                    rangeCount: 1,
                    toString: () => 'túi canvas',
                    getRangeAt: () => ({ commonAncestorContainer: sidebarNode }),
                };
            },
        };
        assert.equal(hasExplicitEditorTextSelection(), false);
    });

    it('TEST 5 — browser Find style selection without ProseMirror ignored', () => {
        const bodyNode = {
            nodeType: 1,
            parentElement: null,
            closest: () => null,
        };
        globalThis.window = {
            getSelection() {
                return {
                    isCollapsed: false,
                    rangeCount: 1,
                    toString: () => 'túi vải không dệt',
                    getRangeAt: () => ({ commonAncestorContainer: bodyNode }),
                };
            },
        };
        assert.equal(hasExplicitEditorTextSelection(), false);
    });

    it('accepts non-collapsed selection inside ProseMirror', () => {
        const node = {
            nodeType: 1,
            parentElement: null,
            closest: (sel) => {
                if (String(sel).includes('ProseMirror')) {
                    return node;
                }
                if (String(sel).includes('data-seo-block-id')) {
                    return { getAttribute: () => 'block-9' };
                }
                return null;
            },
        };
        globalThis.window = {
            getSelection() {
                return {
                    isCollapsed: false,
                    rangeCount: 1,
                    toString: () => 'vải không dệt',
                    getRangeAt: () => ({ commonAncestorContainer: node }),
                };
            },
        };
        assert.equal(hasExplicitEditorTextSelection(), true);
    });
});
