/**
 * Enforce Article Editor widget locks from content/editor-widget-locks.json.
 *
 * Usage (from omnichannel-client):
 *   npm run check:editor-widget-locks
 *   node addons/content/scripts/check-editor-widget-locks.cjs
 *
 * The guard never hard-codes widget IDs — it reads the manifest dynamically.
 */
'use strict';

const {
    loadManifest,
    checkLocks,
    formatStatus,
    formatViolations,
    getManifestPath,
} = require('./editor-widget-lock-lib.cjs');

function main() {
    let manifest;
    try {
        manifest = loadManifest();
    } catch (error) {
        console.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
    }

    console.log(formatStatus(manifest));
    console.log('');
    console.log(`Manifest: ${getManifestPath()}`);
    console.log('');

    const result = checkLocks(manifest);
    if (result.ok) {
        console.log('PASS: no locked editor widget content changed.');
        process.exit(0);
    }

    console.error(formatViolations(result.violations));
    console.error('');
    console.error('FAIL: locked editor widget(s) modified without explicit unlock.');
    process.exit(1);
}

main();
