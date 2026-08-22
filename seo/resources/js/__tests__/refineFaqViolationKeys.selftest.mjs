/**
 * FAQ content present must upgrade faq_missing → faq_schema_missing for display.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { presentSeoReason } from '../utils/seoReasonMetrics.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const calculator = fs.readFileSync(path.join(root, 'utils/seoScoreCalculator.js'), 'utf8');

assert.match(calculator, /export function refineFaqViolationKeys/);
assert.match(calculator, /key === 'faq_missing' \? 'faq_schema_missing'/);
assert.match(calculator, /sanitizeViolations\(violations = \[\], rules = \[\], metrics = \{\}\)/);

{
    const presented = presentSeoReason('faq_missing', {
        metrics: { faq_question_count: 5 },
        locale: 'vi',
    });
    assert.match(presented.summary, /Đã có 5 câu hỏi FAQ nhưng chưa có FAQ schema/);
    assert.doesNotMatch(presented.summary, /Thiếu dữ liệu FAQ/);
}

{
    const presented = presentSeoReason('faq_missing', {
        metrics: { faq_question_count: 0 },
        locale: 'vi',
    });
    assert.match(presented.summary, /Thiếu dữ liệu FAQ/);
}

{
    const presented = presentSeoReason('faq_schema_missing', {
        metrics: { faq_question_count: 5 },
        locale: 'vi',
    });
    assert.match(presented.summary, /Đã có 5 câu hỏi FAQ nhưng chưa có FAQ schema/);
}

console.log('refineFaqViolationKeys.selftest: ok');
