import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const hook = readFileSync(join(root, 'hooks/useArticleEditorOutline.js'), 'utf8');
const outline = readFileSync(join(root, 'components/ArticleOutlineTab.jsx'), 'utf8');
const editor = readFileSync(join(root, 'components/SeoArticleEditor.jsx'), 'utf8');
const i18n = readFileSync(join(root, 'utils/i18n.js'), 'utf8');
const pageCss = readFileSync(join(root, '../css/article-edit-page.css'), 'utf8');
const editorCss = readFileSync(join(root, '../css/article-editor.css'), 'utf8');

/** Mirrors readExplicitlyExpandedIds — missing keys are NOT expanded. */
function explicitlyExpandedIds(sections, collapsedMap) {
    return sections.filter((section) => collapsedMap[section.id] === false).map((section) => section.id);
}

function allCollapsedMap(sections) {
    const next = {};
    sections.forEach((section) => {
        next[section.id] = true;
    });
    return next;
}

const sections = [
    { id: 'section-intro' },
    { id: 'section-1' },
    { id: 'section-2' },
];

// Fresh load: empty map must not be treated as "all expanded" for restore.
assert.deepEqual(explicitlyExpandedIds(sections, {}), []);
assert.deepEqual(explicitlyExpandedIds(sections, allCollapsedMap(sections)), []);
assert.deepEqual(
    explicitlyExpandedIds(sections, { 'section-intro': true, 'section-1': false, 'section-2': true }),
    ['section-1'],
);

assert.match(hook, /markInitialDone:\s*true/);
assert.match(hook, /seedActiveBlockIdRef/);
assert.match(hook, /useLayoutEffect/);
assert.match(hook, /collapsedSectionIdsRef\.current\[section\.id\] === false/);
assert.match(hook, /waitAndScrollToHeading/);
assert.match(hook, /scrollEditorTargetIntoViewAsync/);
assert.match(hook, /jumpGenerationRef/);
assert.doesNotMatch(hook, /scrollToCollapsedSectionBaseline\(/);
assert.doesNotMatch(hook, /collapsedSectionIdsRef\.current\[section\.id\] !== true/);
assert.match(editor, /collapsedSectionIds\[section\.id\] !== false/);
assert.match(pageCss, /\[data-article-editor-page\] section\.py-8/);
assert.match(pageCss, /padding-top:\s*0\s*!important/);

assert.match(outline, /seo-outline-collapse-all/);
assert.match(outline, /seo-outline-toolbar__title-actions/);
assert.match(editor, /onCollapseAllSections=\{collapseAllSections\}/);
assert.doesNotMatch(editor, /onClick=\{collapseAllSections\}/);
assert.match(i18n, /editor_collapse_all_sections:\s*'Thu gọn tất cả'/);
assert.match(editorCss, /seo-outline-collapse-all/);
assert.match(pageCss, /data-status='pending_review'/);
assert.match(pageCss, /#fff7ed/);
assert.match(pageCss, /data-status='approved'/);
assert.match(pageCss, /#f0fdf4/);

console.log('articleEditorUxRegression.selftest: ok');
