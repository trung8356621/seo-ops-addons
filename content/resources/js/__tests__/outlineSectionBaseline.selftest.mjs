import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = readFileSync(join(root, 'utils/outlineSectionBaseline.js'), 'utf8');
const nav = readFileSync(join(root, 'utils/ctaSectionNavigator.js'), 'utf8');
const hook = readFileSync(join(root, 'hooks/useArticleEditorOutline.js'), 'utf8');

assert.match(source, /export function buildCollapsedBaselineMap/);
assert.match(source, /export function scrollToCollapsedSectionBaseline/);
assert.match(source, /export async function waitForStableElement/);
assert.match(source, /waitForNextPaint/);
assert.match(source, /requestAnimationFrame/);
assert.match(source, /scrollEditorTargetIntoViewAsync/);
assert.doesNotMatch(source, /setTimeout\(resolve/);

assert.match(nav, /export function measureEditorStickyHeaderHeight/);
assert.match(nav, /export function editorScrollOffset/);
assert.match(nav, /export function computeEditorScrollDestination/);
assert.match(nav, /export async function scrollEditorTargetIntoViewAsync/);
assert.doesNotMatch(nav, /SCROLL_OFFSET\s*=\s*88/);

assert.match(hook, /rebuildCollapsedBaseline/);
assert.match(hook, /scrollEditorTargetIntoViewAsync/);
assert.match(hook, /jumpGenerationRef/);
assert.match(hook, /outlineNavLockedRef/);
assert.match(hook, /flushSync/);
assert.doesNotMatch(hook, /scrollToCollapsedSectionBaseline\(/);

console.log('outlineSectionBaseline.selftest: ok');
