/**
 * Static import-cycle gate for Article Editor.
 * Exit 1 when a cycle is found in editor runtime/modules/host/entry graph.
 *
 * Usage: node addons/content/scripts/check-editor-cycles.cjs
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'resources', 'js');
const files = [];

function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full);
            continue;
        }
        if (/\.(js|jsx)$/.test(entry.name)) {
            files.push(full);
        }
    }
}

walk(root);

function resolveImport(fromFile, spec) {
    if (!spec.startsWith('.')) {
        return null;
    }
    const resolved = path.normalize(path.join(path.dirname(fromFile), spec));
    for (const candidate of [
        resolved,
        `${resolved}.js`,
        `${resolved}.jsx`,
        path.join(resolved, 'index.js'),
        path.join(resolved, 'index.jsx'),
    ]) {
        if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
            return path.resolve(candidate);
        }
    }
    return null;
}

const adj = new Map();
for (const file of files) {
    const abs = path.resolve(file);
    const text = fs.readFileSync(file, 'utf8');
    const targets = new Set();
    const re = /(?:import\s+(?:[^'"\n]+from\s+)?|export\s+(?:[^'"\n]+from\s+))['"]([^'"]+)['"]/g;
    let match;
    while ((match = re.exec(text))) {
        const resolved = resolveImport(file, match[1]);
        if (resolved) {
            targets.add(resolved);
        }
    }
    adj.set(abs, [...targets]);
}

function findCycles(seedPred) {
    const cycles = [];
    const seenKeys = new Set();

    function dfs(node, stack, inStack) {
        if (inStack.has(node)) {
            const idx = stack.indexOf(node);
            const cycle = stack.slice(idx).concat(node);
            const key = cycle.map((p) => path.relative(root, p).replace(/\\/g, '/')).join(' -> ');
            if (!seenKeys.has(key)) {
                seenKeys.add(key);
                cycles.push(key);
            }
            return;
        }
        inStack.add(node);
        stack.push(node);
        for (const next of adj.get(node) || []) {
            dfs(next, stack, inStack);
        }
        stack.pop();
        inStack.delete(node);
    }

    for (const file of files) {
        const abs = path.resolve(file);
        if (!seedPred(abs.replace(/\\/g, '/'))) {
            continue;
        }
        dfs(abs, [], new Set());
    }
    return cycles;
}

const seedPred = (rel) => (
    rel.includes('/article-editor.jsx')
    || rel.includes('/SeoArticleEditor.jsx')
    || rel.includes('/editor/runtime/')
    || rel.includes('/editor/modules/')
    || rel.includes('/editor/host/')
    || rel.includes('/utils/articleLink')
    || rel.includes('/utils/articlePlainText')
    || rel.includes('/utils/articleEditorModules')
    || rel.includes('/BlockFormatToolbar.jsx')
    || rel.includes('/editorSessionClient.js')
);

const cycles = findCycles(seedPred);

// Forbidden architecture edges (even without a full cycle).
const forbidden = [];
const defaultRuntime = path.resolve(root, 'editor/runtime/defaultArticleEditorRuntime.js');
const modulesIndex = path.resolve(root, 'editor/modules/index.js');
const runtimeIndex = path.resolve(root, 'editor/runtime/index.js');
const defaultText = fs.readFileSync(defaultRuntime, 'utf8');
const runtimeIndexText = fs.readFileSync(runtimeIndex, 'utf8');

if (/from\s+['"]\.\.\/modules['"]/.test(defaultText)) {
    forbidden.push('defaultArticleEditorRuntime must not import ../modules');
}
if (/from\s+['"]\.\.\/modules['"]/.test(runtimeIndexText)) {
    forbidden.push('editor/runtime/index.js must not import ../modules');
}
if (!(adj.get(modulesIndex) || []).some((t) => t.includes(`${path.sep}builtinModulesRegistry.js`))) {
    forbidden.push('editor/modules/index.js must register builtinModulesRegistry');
}

if (cycles.length || forbidden.length) {
    if (cycles.length) {
        console.error(`Found ${cycles.length} import cycle(s):`);
        cycles.forEach((c) => console.error(` - ${c}`));
    }
    forbidden.forEach((f) => console.error(`FORBIDDEN: ${f}`));
    process.exit(1);
}

console.log('OK: no Article Editor import cycles; runtime does not import modules aggregate.');
process.exit(0);
