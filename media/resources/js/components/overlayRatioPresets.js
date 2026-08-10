/**
 * Common aspect ratios (screen + web images).
 * Export long edge = OVERLAY_EXPORT_MAX px.
 */
export const OVERLAY_EXPORT_MAX = 2000;

/** @typedef {{ key: string, label: string, rw: number, rh: number }} OverlayRatioPreset */

/** @type {OverlayRatioPreset[]} */
export const OVERLAY_RATIO_PRESETS = [
    { key: '16x9', label: '16:9 — Desktop / hero', rw: 16, rh: 9 },
    { key: '4x3', label: '4:3 — Image / landscape tablet', rw: 4, rh: 3 },
    { key: '3x2', label: '3:2 — Camera', rw: 3, rh: 2 },
    { key: '1x1', label: '1:1 — Square', rw: 1, rh: 1 },
    { key: '9x16', label: '9:16 — Mobile / story', rw: 9, rh: 16 },
    { key: '3x4', label: '3:4 — Small portrait', rw: 3, rh: 4 },
    { key: '2x3', label: '2:3 — Portrait', rw: 2, rh: 3 },
    { key: '21x9', label: '21:9 — Ultrawide', rw: 21, rh: 9 },
];

/**
 * @param {number} rw
 * @param {number} rh
 * @returns {{ width: number, height: number, ratio: number }}
 */
export function exportDimensionsForRatio(rw, rh) {
    const max = OVERLAY_EXPORT_MAX;
    let width;
    let height;

    if (rw >= rh) {
        width = max;
        height = Math.max(1, Math.round((max * rh) / rw));
    } else {
        height = max;
        width = Math.max(1, Math.round((max * rw) / rh));
    }

    return { width, height, ratio: rw / rh };
}

/**
 * @param {OverlayRatioPreset} preset
 */
export function dimensionsForPreset(preset) {
    return exportDimensionsForRatio(preset.rw, preset.rh);
}

/**
 * Pick overlay variant closest to target ratio.
 *
 * @param {number} targetWidth
 * @param {number} targetHeight
 * @param {Array<{ key: string, width: number, height: number }>} variants
 * @returns {string|null}
 */
export function resolveBestVariantKey(targetWidth, targetHeight, variants) {
    if (!variants?.length || targetWidth <= 0 || targetHeight <= 0) {
        return null;
    }

    const targetRatio = targetWidth / targetHeight;
    let bestKey = null;
    let bestDiff = Infinity;

    for (const v of variants) {
        const w = Math.max(1, Number(v.width) || 1);
        const h = Math.max(1, Number(v.height) || 1);
        const ratio = w / h;
        const diff = Math.abs(Math.log(targetRatio) - Math.log(Math.max(0.01, ratio)));

        if (diff < bestDiff) {
            bestDiff = diff;
            bestKey = v.key;
        }
    }

    return bestKey;
}
