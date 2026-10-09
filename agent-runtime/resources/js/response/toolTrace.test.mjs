import assert from 'node:assert/strict';
import test from 'node:test';
import { toolTraceItems } from './toolTrace.js';

test('keywords execution is blue and a misrouted audit stays red', () => {
    const keywords = toolTraceItems({
        sources: [{ name: 'keywords', status: 'ok', request: 'GET /keywords' }],
        execution: { capabilities: ['keywords.landscape'], tools: ['keywords'], external_model_calls: 0 },
    });
    assert.deepEqual(keywords.map((item) => [item.key, item.color, item.icon]), [
        ['keywords.landscape', 'blue', 'Tags'],
    ]);

    const misroute = toolTraceItems({
        message: 'Website này đang có bao nhiêu từ khóa SEO?',
        sources: [{ name: 'articles', status: 'ok', request: 'SeoAuditAgentReadService::listArticles?low_score=true&limit=50' }],
        execution: { capabilities: ['seo_audit.worst_articles'], tools: ['articles'], external_model_calls: 0 },
    });
    assert.deepEqual(misroute.map((item) => item.color), ['red']);
    assert.equal(misroute[0].icon, 'ScanSearch');
});

test('multi-tool responses keep one color per executed capability', () => {
    const items = toolTraceItems({
        sources: [
            { name: 'keywords', status: 'ok', request: 'GET /keywords' },
            { name: 'topics', status: 'ok', request: 'GET /topics' },
            { name: 'gsc', status: 'ok', request: 'GET /gsc' },
            { name: 'keywords', status: 'ok', request: 'GET /keywords?again=1' },
        ],
        execution: {
            capabilities: ['keywords.landscape', 'keywords.relationship', 'gsc.performance'],
            tools: ['keywords', 'topics', 'gsc'],
            external_model_calls: 1,
            external_model: 'answer-model',
        },
    });
    assert.deepEqual(items.map((item) => item.key), [
        'keywords.landscape',
        'keywords.relationship',
        'gsc.performance',
        'external_answer_model',
    ]);
    assert.deepEqual(items.map((item) => item.color), ['blue', 'purple', 'green', 'pink']);
});

test('selected capability without a source and missing history produce no icons', () => {
    assert.deepEqual(toolTraceItems({
        execution: { capabilities: ['keywords.landscape'], tools: [], external_model_calls: 0 },
        sources: [],
    }), []);
    assert.deepEqual(toolTraceItems({ message: 'bao nhiêu từ khóa', sources: [] }), []);
    assert.deepEqual(toolTraceItems({ sources: [{ name: 'gsc_fallback_policy', status: 'ok' }] }), []);
});

test('audit topic intersection does not invent a keyword icon', () => {
    const items = toolTraceItems({
        sources: [
            { name: 'articles', status: 'ok', request: 'SeoAuditAgentReadService::listArticles?low_score=true&limit=50' },
            { name: 'topic_groups', status: 'ok', request: 'topic-group-retrieval' },
        ],
    });
    assert.deepEqual(items.map((item) => item.key), ['seo_audit.worst_articles']);
});

test('answer model awaiting or rejected stays distinct from a successful call', () => {
    const waiting = toolTraceItems({
        sources: [{ name: 'gsc', status: 'ok', request: 'GET /gsc' }],
        execution: { capabilities: ['gsc.performance'], tools: ['gsc'], external_model_calls: 0, answer_status: 'awaiting' },
    });
    assert.equal(waiting.find((item) => item.key === 'external_answer_model').status, 'awaiting');
    assert.equal(waiting.find((item) => item.key === 'gsc.performance').color, 'green');

    const rejected = toolTraceItems({
        sources: [{ name: 'articles', status: 'ok', request: 'SeoAuditAgentReadService::listArticles' }],
        execution: { capabilities: ['seo_audit.worst_articles'], tools: ['articles'], external_model_calls: 1, answer_status: 'rejected' },
    });
    assert.equal(rejected.find((item) => item.key === 'seo_audit.worst_articles').color, 'red');
    assert.equal(rejected.find((item) => item.key === 'external_answer_model').status, 'failed');
});

test('failed tools keep the same icon and external models require a real call', () => {
    const failed = toolTraceItems({
        sources: [{ name: 'gsc', status: 'unavailable', request: 'GET /gsc', reason: 'not_synced' }],
        execution: { capabilities: ['gsc.performance'], tools: ['gsc'], external_model_calls: 0 },
    });
    assert.equal(failed.length, 1);
    assert.equal(failed[0].status, 'failed');
    assert.equal(failed[0].color, 'green');

    assert.equal(toolTraceItems({
        sources: [{ name: 'keywords', status: 'ok' }],
        execution: { capabilities: ['keywords.landscape'], tools: ['keywords'], external_model_calls: 0 },
    }).some((item) => item.key === 'external_answer_model'), false);
});

test('debug tooltips add recorded evidence and normal tooltips stay short', () => {
    const response = {
        sources: [{ name: 'keywords', status: 'ok', request: 'GET /keywords?token=secret' }],
        execution: { capabilities: ['keywords.landscape'], tools: ['keywords'], router: 'local', outcome: 'confident' },
    };
    const normal = toolTraceItems(response)[0].title;
    const debug = toolTraceItems(response, { debug: true })[0].title;
    assert.equal(normal.includes('token'), false);
    assert.equal(debug.includes('token'), false);
    assert.equal(debug.includes('keywords.landscape'), true);
    assert.equal(debug.includes('local'), true);
});
