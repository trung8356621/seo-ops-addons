import test from 'node:test';
import assert from 'node:assert/strict';

import { createCurrentDraftAnalysisSnapshot } from '../utils/currentDraftAnalysisSnapshot.js';
import { resolveFeaturedSnippetViolationFromTables } from '../../../../seo/resources/js/utils/seoContentBonus.js';

const draftWithTable = {
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: [{ type: 'text', text: 'Current unsaved editor draft' }],
        },
        {
            type: 'table',
            content: [
                {
                    type: 'tableRow',
                    content: [
                        { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'A' }] }] },
                        { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'B' }] }] },
                    ],
                },
                {
                    type: 'tableRow',
                    content: [
                        { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: '1' }] }] },
                        { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: '2' }] }] },
                    ],
                },
            ],
        },
    ],
};

test('current draft table is detected without waiting for save', () => {
    const snapshot = createCurrentDraftAnalysisSnapshot({ document: draftWithTable });
    const reason = resolveFeaturedSnippetViolationFromTables(snapshot.tables);

    assert.equal(snapshot.source, 'current_editor_draft');
    assert.equal(snapshot.tables.length, 1);
    assert.notEqual(reason, 'featured_snippet_missing');
});

test('persisted content cannot override the current draft snapshot', () => {
    const persistedContent = '<p>Persisted body without a table.</p>';
    const snapshot = createCurrentDraftAnalysisSnapshot({
        document: draftWithTable,
        html: '<p>Current exported draft</p>',
    });

    assert.doesNotMatch(persistedContent, /<table\b/i);
    assert.equal(snapshot.tables.length, 1);
    assert.notEqual(
        resolveFeaturedSnippetViolationFromTables(snapshot.tables),
        'featured_snippet_missing',
    );
});

test('shared snapshot exposes draft words, headings, images, lists, and links', () => {
    const snapshot = createCurrentDraftAnalysisSnapshot({
        document: {
            type: 'doc',
            content: [
                {
                    type: 'heading',
                    attrs: { level: 2 },
                    content: [{ type: 'text', text: 'Draft heading' }],
                },
                {
                    type: 'paragraph',
                    content: [{
                        type: 'text',
                        text: 'linked words',
                        marks: [{ type: 'link', attrs: { href: '/draft-link' } }],
                    }],
                },
                {
                    type: 'bulletList',
                    content: [{
                        type: 'listItem',
                        content: [{ type: 'paragraph', content: [{ type: 'text', text: 'List item' }] }],
                    }],
                },
                {
                    type: 'articleImage',
                    attrs: { src: '/draft.jpg', alt: 'Draft image' },
                },
            ],
        },
    });

    assert.equal(snapshot.h2Count, 1);
    assert.equal(snapshot.imageCount, 1);
    assert.equal(snapshot.lists.length, 1);
    assert.equal(snapshot.links.length, 1);
    assert.ok(snapshot.wordCount >= 5);
});

test('featured snippet missing is only used when the current draft has no table', () => {
    const snapshot = createCurrentDraftAnalysisSnapshot({
        html: '<p>No table in this draft</p>',
    });

    assert.equal(snapshot.tables.length, 0);
    assert.equal(
        resolveFeaturedSnippetViolationFromTables(snapshot.tables),
        'featured_snippet_missing',
    );
});

test('live TipTap HTML wins over stale blocksRef content for analyze', async () => {
    const { htmlFromEditorsOrBlocks } = await import('../utils/editorDocumentBridge.js');
    const editors = new Map([
        ['b1', {
            isDestroyed: false,
            getHTML: () => '<p>Fresh unsaved keyword text</p>',
        }],
    ]);
    const html = htmlFromEditorsOrBlocks(editors, [
        { id: 'b1', type: 'text', content: '<p>Stale flushed content</p>' },
    ]);

    assert.match(html, /Fresh unsaved keyword text/);
    assert.doesNotMatch(html, /Stale flushed content/);
});
