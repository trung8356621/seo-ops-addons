/**
 * CLI for Article Editor widget locks (manifest-only policy changes).
 *
 * Usage:
 *   node addons/content/scripts/widget-lock.cjs status
 *   node addons/content/scripts/widget-lock.cjs list
 *   node addons/content/scripts/widget-lock.cjs unlock <widgetId>
 *   node addons/content/scripts/widget-lock.cjs lock <widgetId>
 *   node addons/content/scripts/widget-lock.cjs seal [widgetId]
 *
 * No unlock-all. Each widget lock is independent.
 * Guard still reads the manifest — this CLI only edits locked flags / fingerprints.
 */
'use strict';

const {
    loadManifest,
    saveManifest,
    sealWidgetFingerprints,
    formatStatus,
    getManifestPath,
} = require('./editor-widget-lock-lib.cjs');

function usage() {
    return [
        'Usage:',
        '  widget-lock status',
        '  widget-lock list',
        '  widget-lock unlock <widgetId>',
        '  widget-lock lock <widgetId>',
        '  widget-lock seal [widgetId]',
        '',
        'Notes:',
        '  - lock seals fingerprints for that widget and sets locked=true',
        '  - unlock sets locked=false for that widget only',
        '  - seal refreshes fingerprints without changing lock flags',
        '  - there is no unlock-all',
    ].join('\n');
}

function requireWidget(manifest, id) {
    if (!id) {
        throw new Error('Missing widget id.\n\n' + usage());
    }
    if (!manifest.widgets[id]) {
        const known = Object.keys(manifest.widgets).sort().join(', ');
        throw new Error(`Unknown widget id: ${id}\nKnown: ${known}`);
    }
    return manifest.widgets[id];
}

function main() {
    const [, , command, widgetId] = process.argv;
    if (!command || command === 'help' || command === '--help' || command === '-h') {
        console.log(usage());
        process.exit(command ? 0 : 1);
    }

    const manifest = loadManifest();

    if (command === 'status' || command === 'list') {
        console.log(formatStatus(manifest));
        console.log('');
        console.log(`Manifest: ${getManifestPath()}`);
        return;
    }

    if (command === 'unlock') {
        const widget = requireWidget(manifest, widgetId);
        widget.locked = false;
        saveManifest(manifest);
        console.log(`Unlocked: ${widgetId}`);
        console.log('');
        console.log(formatStatus(manifest));
        return;
    }

    if (command === 'lock') {
        const widget = requireWidget(manifest, widgetId);
        manifest.widgets[widgetId] = sealWidgetFingerprints({
            ...widget,
            locked: true,
        });
        saveManifest(manifest);
        console.log(`Locked + sealed: ${widgetId}`);
        console.log('');
        console.log(formatStatus(manifest));
        return;
    }

    if (command === 'seal') {
        if (widgetId) {
            const widget = requireWidget(manifest, widgetId);
            manifest.widgets[widgetId] = sealWidgetFingerprints(widget);
            saveManifest(manifest);
            console.log(`Sealed fingerprints: ${widgetId}`);
        } else {
            for (const id of Object.keys(manifest.widgets)) {
                manifest.widgets[id] = sealWidgetFingerprints(manifest.widgets[id]);
            }
            saveManifest(manifest);
            console.log('Sealed fingerprints for all widgets in the manifest.');
        }
        console.log('');
        console.log(formatStatus(manifest));
        return;
    }

    console.error(`Unknown command: ${command}\n`);
    console.error(usage());
    process.exit(1);
}

try {
    main();
} catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
}
