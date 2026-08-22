/**
 * Shared helpers for editor widget lock manifest + fingerprint enforcement.
 * Manifest is the single source of truth; this module never hard-codes widget IDs.
 */
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ADDONS_ROOT = path.resolve(__dirname, '..', '..');
const MANIFEST_PATH = path.join(ADDONS_ROOT, 'content', 'editor-widget-locks.json');

/**
 * @returns {string}
 */
function getManifestPath() {
    return MANIFEST_PATH;
}

/**
 * @returns {string}
 */
function getAddonsRoot() {
    return ADDONS_ROOT;
}

/**
 * @returns {object}
 */
function loadManifest() {
    if (!fs.existsSync(MANIFEST_PATH)) {
        throw new Error(`Editor widget lock manifest missing: ${MANIFEST_PATH}`);
    }
    const raw = fs.readFileSync(MANIFEST_PATH, 'utf8');
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object' || !data.widgets || typeof data.widgets !== 'object') {
        throw new Error('Invalid editor-widget-locks.json: expected top-level "widgets" object');
    }
    return data;
}

/**
 * @param {object} manifest
 */
function saveManifest(manifest) {
    const text = `${JSON.stringify(manifest, null, 2)}\n`;
    fs.writeFileSync(MANIFEST_PATH, text, 'utf8');
}

/**
 * @param {string} relativeFile
 * @returns {string}
 */
function resolveFile(relativeFile) {
    const full = path.resolve(ADDONS_ROOT, relativeFile.replace(/\//g, path.sep));
    const rootResolved = path.resolve(ADDONS_ROOT);
    if (!full.startsWith(rootResolved + path.sep) && full !== rootResolved) {
        throw new Error(`Path escapes addons root: ${relativeFile}`);
    }
    return full;
}

/**
 * Normalize newlines so manifest markers written with \n match CRLF sources.
 * @param {string} value
 * @returns {string}
 */
function normalizeNewlines(value) {
    return String(value).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

/**
 * @param {string} content
 * @param {{ start?: string, end?: string }} region
 * @returns {string}
 */
function extractRegion(content, region) {
    const normalized = normalizeNewlines(content);
    const start = region.start ? normalizeNewlines(region.start) : '';
    const end = region.end ? normalizeNewlines(region.end) : '';
    if (!start) {
        return normalized;
    }
    const startIdx = normalized.indexOf(start);
    if (startIdx < 0) {
        throw new Error(`Region start not found: ${JSON.stringify(start).slice(0, 120)}`);
    }
    if (!end) {
        return normalized.slice(startIdx);
    }
    const endIdx = normalized.indexOf(end, startIdx + start.length);
    if (endIdx < 0) {
        throw new Error(`Region end not found: ${JSON.stringify(end).slice(0, 120)}`);
    }
    return normalized.slice(startIdx, endIdx);
}

/**
 * @param {string} value
 * @returns {string}
 */
function sha256(value) {
    return crypto.createHash('sha256').update(value, 'utf8').digest('hex');
}

/**
 * @param {{ file: string, start?: string, end?: string }} pathEntry
 * @returns {{ absolute: string, relative: string, content: string, fingerprint: string }}
 */
function readProtectedContent(pathEntry) {
    const relative = String(pathEntry.file || '').replace(/\\/g, '/');
    if (!relative) {
        throw new Error('Path entry missing "file"');
    }
    const absolute = resolveFile(relative);
    if (!fs.existsSync(absolute)) {
        throw new Error(`Protected file missing: ${relative}`);
    }
    const fileText = fs.readFileSync(absolute, 'utf8');
    const content = extractRegion(fileText, pathEntry);
    return {
        absolute,
        relative,
        content,
        fingerprint: sha256(content),
    };
}

/**
 * @param {object} widget
 * @returns {object} widget with refreshed fingerprints
 */
function sealWidgetFingerprints(widget) {
    const paths = Array.isArray(widget.paths) ? widget.paths : [];
    const nextPaths = paths.map((entry) => {
        const { fingerprint } = readProtectedContent(entry);
        return {
            ...entry,
            fingerprint,
        };
    });
    return {
        ...widget,
        paths: nextPaths,
    };
}

/**
 * @param {object} manifest
 * @returns {{ ok: boolean, violations: Array<object>, unlocked: string[], locked: string[] }}
 */
function checkLocks(manifest) {
    const widgets = manifest.widgets || {};
    const violations = [];
    const unlocked = [];
    const locked = [];

    for (const [id, widget] of Object.entries(widgets)) {
        if (!widget || typeof widget !== 'object') {
            continue;
        }
        if (widget.locked !== true) {
            unlocked.push(id);
            continue;
        }
        locked.push(id);
        const paths = Array.isArray(widget.paths) ? widget.paths : [];
        for (const entry of paths) {
            let current;
            try {
                current = readProtectedContent(entry);
            } catch (error) {
                violations.push({
                    widgetId: id,
                    label: widget.label || id,
                    file: entry?.file || '(unknown)',
                    reason: error instanceof Error ? error.message : String(error),
                });
                continue;
            }
            const expected = String(entry.fingerprint || '');
            if (!expected) {
                violations.push({
                    widgetId: id,
                    label: widget.label || id,
                    file: current.relative,
                    reason: 'Missing fingerprint — run: node addons/content/scripts/widget-lock.cjs lock ' + id,
                });
                continue;
            }
            if (current.fingerprint !== expected) {
                violations.push({
                    widgetId: id,
                    label: widget.label || id,
                    file: current.relative,
                    reason: 'Protected content fingerprint mismatch',
                    expected,
                    actual: current.fingerprint,
                });
            }
        }
    }

    return {
        ok: violations.length === 0,
        violations,
        unlocked,
        locked,
    };
}

/**
 * @param {object} manifest
 * @returns {string}
 */
function formatStatus(manifest) {
    const lines = ['Editor widget locks', ''];
    const widgets = manifest.widgets || {};
    const ids = Object.keys(widgets).sort();
    for (const id of ids) {
        const widget = widgets[id] || {};
        const state = widget.locked === true ? '[LOCKED]  ' : '[UNLOCKED]';
        const label = widget.label || id;
        lines.push(`${state} ${id.padEnd(12)} ${label}`);
    }
    return lines.join('\n');
}

/**
 * @param {Array<object>} violations
 * @returns {string}
 */
function formatViolations(violations) {
    const byWidget = new Map();
    for (const item of violations) {
        if (!byWidget.has(item.widgetId)) {
            byWidget.set(item.widgetId, []);
        }
        byWidget.get(item.widgetId).push(item);
    }

    const chunks = [];
    for (const [widgetId, items] of byWidget.entries()) {
        const label = items[0]?.label || widgetId;
        chunks.push(`LOCKED EDITOR WIDGET MODIFIED: ${widgetId}`);
        chunks.push('');
        chunks.push(`Protected widget "${label}" was changed.`);
        chunks.push('Unlock it explicitly before modifying this widget.');
        chunks.push(`  node addons/content/scripts/widget-lock.cjs unlock ${widgetId}`);
        chunks.push('');
        for (const item of items) {
            chunks.push(`- ${item.file}`);
            chunks.push(`  ${item.reason}`);
        }
        chunks.push('');
    }
    return chunks.join('\n').trimEnd();
}

module.exports = {
    getManifestPath,
    getAddonsRoot,
    loadManifest,
    saveManifest,
    readProtectedContent,
    sealWidgetFingerprints,
    checkLocks,
    formatStatus,
    formatViolations,
};
