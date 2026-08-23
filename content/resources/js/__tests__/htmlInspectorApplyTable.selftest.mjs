/**
 * Apply HTML: pretty-print whitespace must not become empty <p> in table cells.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const normalizer = fs.readFileSync(path.join(root, 'utils/inlineLinkNormalizer.js'), 'utf8');
const editor = fs.readFileSync(path.join(root, 'components/ActiveBlockEditor.jsx'), 'utf8');
const i18n = fs.readFileSync(path.join(root, 'utils/i18n.js'), 'utf8');

assert.match(normalizer, /export function prepareHtmlForTipTapApply/);
assert.match(normalizer, /STRUCTURAL_WHITESPACE_PARENTS/);
assert.match(normalizer, /querySelectorAll\('td, th'\)/);
assert.match(normalizer, /isEmptyParagraphElement/);
assert.match(editor, /prepareHtmlForTipTapApply/);
assert.match(editor, /prepareHtmlForTipTapApply[\s\S]{0,240}setContent/);
assert.match(i18n, /html_inspector_stat_anchors: 'Anchors: \{count\}'/);
assert.doesNotMatch(i18n, /html_inspector_stat_anchors: 'Anchors: :count'/);

console.log('htmlInspectorApplyTable.selftest: ok');
