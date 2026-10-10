import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../app/agent-runtime.css', import.meta.url), 'utf8');

test('routing review replaces answer rating and sits above the composer', () => {
    assert.equal(widget.includes('ThumbsUp'), false);
    assert.equal(widget.includes('ThumbsDown'), false);
    assert.match(widget, /Theo bạn, câu hỏi nào gần với ý bạn muốn hỏi nhất\?/);
    assert.ok(widget.indexOf('className="agent-routing-review"') < widget.indexOf('className="agent-composer"'));
    assert.match(css, /\.agent-routing-review\s*\{[\s\S]*position:\s*sticky/);
});

test('routing review renders only evidence questions and stable candidate ids', () => {
    assert.match(widget, /reviewCandidates\.map/);
    assert.match(widget, /candidate\.question/);
    assert.match(widget, /candidate\.selected/);
    assert.match(widget, /Không câu nào đúng ý tôi/);
    assert.equal(widget.includes('semantic_score'), false);
});
