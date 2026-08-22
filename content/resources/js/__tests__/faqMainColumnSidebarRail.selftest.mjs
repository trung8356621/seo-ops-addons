/**
 * Contract: opening FAQ (main-column) must not blank the sidebar SEO rail.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { isMainColumnOnlyPanel } from '../editor/runtime/mainColumnPanels.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const navigator = fs.readFileSync(
    path.resolve(root, '../../../seo/resources/js/utils/seoAssistantNavigator.js'),
    'utf8',
);
const portal = fs.readFileSync(path.join(root, 'editor/host/EditorSidebarPortalHost.jsx'), 'utf8');
const host = fs.readFileSync(path.join(root, 'components/SeoArticleEditor.jsx'), 'utf8');
const nav = fs.readFileSync(path.join(root, 'editor/host/EditorSidebarNavigation.jsx'), 'utf8');
const blade = fs.readFileSync(
    path.resolve(root, '../../../seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
    'utf8',
);

assert.equal(isMainColumnOnlyPanel('faq'), true);
assert.equal(isMainColumnOnlyPanel('seo'), false);

assert.match(navigator, /sidebarRailPanel/);
assert.match(navigator, /isMainColumnOnlyPanel/);
assert.match(navigator, /sidebarRailPanel \|\| 'seo'/);

assert.match(portal, /sidebarRailPanelId/);
assert.match(portal, /isMainColumnOnlyPanel\(active\)/);

assert.match(host, /sidebarRailPanelId/);
assert.match(host, /isMainColumnOnlyPanel\(normalized\)/);
assert.match(host, /sidebarRailPanelId=\{sidebarRailPanelId\}/);

assert.match(nav, /isMainColumnOnlyPanel\(panelId\)/);
assert.match(blade, /sidebarRailPanel === 'seo'/);
assert.doesNotMatch(blade, /runtimeActivePanel === 'seo'/);

console.log('faqMainColumnSidebarRail.selftest: ok');
