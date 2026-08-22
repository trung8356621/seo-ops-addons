/**
 * Contract: Reviews load attempt must resolve loading (no endless spinner on failure).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const core = fs.readFileSync(path.join(root, 'hooks/useArticleEditorCoreState.js'), 'utf8');
const tab = fs.readFileSync(path.join(root, 'components/ArticleReviewsTab.jsx'), 'utf8');
const nav = fs.readFileSync(path.join(root, 'editor/host/EditorSidebarNavigation.jsx'), 'utf8');
const editor = fs.readFileSync(path.join(root, 'components/SeoArticleEditor.jsx'), 'utf8');
const portalHost = fs.readFileSync(path.join(root, 'editor/host/EditorSidebarPortalHost.jsx'), 'utf8');
const portalRoots = fs.readFileSync(path.join(root, 'editor/host/editorSidebarPortalRoots.js'), 'utf8');
const css = fs.readFileSync(path.join(root, '../css/article-editor.css'), 'utf8');
const widget = fs.readFileSync(
    path.resolve(root, '../../../ai-prompt/resources/js/components/ArticleAssistantWidget.jsx'),
    'utf8',
);

assert.match(core, /setReviewsLoaded\(true\)/);
assert.match(core, /reviewsLoadedRef\.current = true/);
assert.doesNotMatch(tab, /countLoading \|\| !loaded/);
assert.match(tab, /!loading && !loaded/);
assert.doesNotMatch(nav, /Search assistants/);
assert.doesNotMatch(nav, /searchQuery/);
assert.doesNotMatch(nav, /seo-assistant-dock-search/);

// Reviews blank root cause: body must stay mounted; shell must open expanded.
assert.match(widget, /seo-assistant-widget__body/);
assert.doesNotMatch(widget, /\{!collapsed \? <div className="seo-assistant-widget__body"/);
assert.doesNotMatch(widget, /hidden=\{collapsed/);
assert.match(widget, /collapsible/);
assert.match(editor, /widgetId="reviews"[\s\S]*?collapsible=\{false\}/);
assert.match(portalHost, /ensureEditorSidebarPortalRoot/);
assert.match(portalHost, /active \|\| panelId === 'reviews'/);
assert.match(portalRoots, /seo-article-reviews-assistant-root/);
assert.match(portalHost, /active \|\| railFallback/);

// Scroll contract must target Blade attribute data-assistant-panel-root.
assert.match(css, /data-assistant-panel-root/);
assert.doesNotMatch(css, /is-active\[data-assistant-widget-id=/);
assert.match(css, /seo-assistant-panel-slot\.is-active[\s\S]*overflow-y:\s*auto/);
assert.match(css, /#article-editor-sidebar-panel-root[\s\S]*display:\s*none\s*!important/);
assert.match(css, /\.seo-assistant-dock-react-root\s*\{[^}]*display:\s*contents/s);
assert.doesNotMatch(css, /\.seo-assistant-dock\s*\{[^}]*min-height:\s*3\.5rem/s);
assert.match(
    css,
    /is-active\[data-assistant-panel-root="reviews"\][\s\S]*seo-reviews-tab[\s\S]*visibility:\s*visible/,
);

console.log('articleEditorUiFixes.selftest: ok');
