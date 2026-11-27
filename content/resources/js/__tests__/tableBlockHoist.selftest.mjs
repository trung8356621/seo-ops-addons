/**
 * Contract self-test for Table Block refactor (source-level).
 * Run: node content/resources/js/__tests__/tableBlockHoist.selftest.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const helpers = fs.readFileSync(path.join(root, 'utils/contentDocumentHelpers.js'), 'utf8');
const tableMgr = fs.readFileSync(path.join(root, 'utils/articleTableManager.js'), 'utf8');
const blockEditor = fs.readFileSync(path.join(root, 'components/BlockEditor.jsx'), 'utf8');
const tableBlock = fs.readFileSync(path.join(root, 'components/TableBlockEditor.jsx'), 'utf8');
const tableModal = fs.readFileSync(path.join(root, 'components/TableEditorModal.jsx'), 'utf8');
const seoSidebar = fs.readFileSync(
    path.resolve(root, '../../../seo/resources/js/editor/modules/seo/SeoSidebarPanel.jsx'),
    'utf8',
);
const optimizeSvc = fs.readFileSync(
    path.resolve(root, '../../src/Services/ArticleTableOptimizeService.php'),
    'utf8',
);
const normalizeBlocks = fs.readFileSync(
    path.resolve(root, '../../../media/resources/js/utils/blockImageUtils.js'),
    'utf8',
);
const insertCmd = fs.readFileSync(path.join(root, 'utils/editorCommands/formattingCommands.js'), 'utf8');

assert.match(helpers, /export const createEmptyTableBlock/);
assert.match(helpers, /export const hoistInlineTablesFromTextBlocks/);
assert.match(helpers, /hoistInlineTablesFromTextBlocks\(/);
assert.match(helpers, /isWpTableBlock/);
assert.match(tableMgr, /setPrimaryTableByBlockId/);
assert.match(tableMgr, /replaceTableBlockContent/);
assert.match(blockEditor, /TableBlockEditor/);
assert.match(blockEditor, /block\.type === 'table'/);
assert.match(tableBlock, /TableEditorModal/);
assert.match(tableBlock, /optimizeFeaturedSnippetTableFromEditor/);
assert.match(tableBlock, /setOptimizeOpen/);
assert.match(tableModal, /tableEditorExtensions/);
assert.match(tableModal, /addRowAfter/);
assert.doesNotMatch(seoSidebar, /ArticleTableManagerPanel/);
assert.match(optimizeSvc, /PromptRunnerService/);
assert.doesNotMatch(optimizeSvc, /PromptHookExecutionService/);
assert.match(normalizeBlocks, /block\.type === 'table'/);
assert.match(insertCmd, /type: 'table'/);
assert.match(insertCmd, /runHostStructure/);

console.log('tableBlockHoist.selftest: PASS');
