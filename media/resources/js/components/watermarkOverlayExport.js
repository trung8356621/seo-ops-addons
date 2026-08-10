import { drawCtaButton } from './watermarkCtaDraw';
import {
    drawAestheticCorners,
    drawCircularBadge,
    drawClassicGrid,
    drawElegantSignature,
    drawMinimalFrame,
    drawSecurityRect,
    resolveStampCenter,
} from './watermarkDrawUtils';
import {
    dimensionsForPreset,
    OVERLAY_EXPORT_MAX,
    OVERLAY_RATIO_PRESETS,
} from './overlayRatioPresets';

export { OVERLAY_EXPORT_MAX, OVERLAY_RATIO_PRESETS };

/** @deprecated use OVERLAY_EXPORT_MAX + preset */
export const OVERLAY_REF_WIDTH = 2000;
export const OVERLAY_REF_HEIGHT = 1125;

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} w
 * @param {number} h
 * @param {string} activePattern
 * @param {number} opacity
 * @param {Record<string, unknown>} opts
 * @param {Record<string, unknown>} position
 */
export function drawWatermarkLayer(ctx, w, h, activePattern, opacity, opts, position) {
    const {
        positionType,
        customCoords,
        presetPos,
        margin,
    } = position;

    ctx.clearRect(0, 0, w, h);
    ctx.globalAlpha = Math.max(0.05, Math.min(1, Number(opacity) || 1));

    switch (activePattern) {
        case 'cta_button':
            drawCtaButton(ctx, w, h, opts);
            break;
        case 'classic_grid':
            drawClassicGrid(ctx, w, h, opts);
            break;
        case 'circular_badge': {
            const center = resolveStampCenter(w, h, positionType, customCoords, presetPos, margin, position);
            drawCircularBadge(ctx, center.x, center.y, opts);
            break;
        }
        case 'security_rect': {
            const center = resolveStampCenter(w, h, positionType, customCoords, presetPos, margin, position);
            drawSecurityRect(ctx, center.x, center.y, opts);
            break;
        }
        case 'elegant_sig': {
            const center = resolveStampCenter(w, h, positionType, customCoords, presetPos, margin, position);
            drawElegantSignature(ctx, center.x, center.y, opts);
            break;
        }
        case 'minimal_frame':
            drawMinimalFrame(ctx, w, h, opts);
            break;
        case 'full_cross':
            drawAestheticCorners(ctx, w, h, opts);
            break;
        default:
            break;
    }

    ctx.globalAlpha = 1;
}

/**
 * @param {number} width
 * @param {number} height
 * @param {string} activePattern
 * @param {number} opacity
 * @param {Record<string, unknown>} opts
 * @param {Record<string, unknown>} position
 * @returns {Promise<Blob>}
 */
export function exportWatermarkOverlayBlobAtSize(
    width,
    height,
    activePattern,
    opacity,
    opts,
    position,
) {
    return new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            reject(new Error('Canvas is unavailable.'));
            return;
        }

        drawWatermarkLayer(ctx, width, height, activePattern, opacity, opts, position);

        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('Failed to export overlay.'))),
            'image/png',
            0.92,
        );
    });
}

/**
 * Export overlay for all preset ratios (~2000px long edge).
 *
 * @param {string} activePattern
 * @param {number} opacity
 * @param {(w: number, h: number) => Record<string, unknown>} resolveOptsAt
 * @param {(w: number, h: number) => Record<string, unknown>} resolvePositionAt
 * @returns {Promise<Array<{ key: string, label: string, blob: Blob, width: number, height: number, ratio: number }>>}
 */
export async function exportAllWatermarkOverlayBlobs(
    activePattern,
    opacity,
    resolveOptsAt,
    resolvePositionAt,
) {
    const results = [];

    for (const preset of OVERLAY_RATIO_PRESETS) {
        const { width, height, ratio } = dimensionsForPreset(preset);
        const opts = resolveOptsAt(width, height);
        const position = resolvePositionAt(width, height);
        const blob = await exportWatermarkOverlayBlobAtSize(
            width,
            height,
            activePattern,
            opacity,
            opts,
            position,
        );
        results.push({
            key: preset.key,
            label: preset.label,
            blob,
            width,
            height,
            ratio,
        });
    }

    return results;
}

/**
 * @returns {Promise<Blob>}
 */
export function exportWatermarkOverlayBlob(activePattern, opacity, opts, position) {
    const preset = OVERLAY_RATIO_PRESETS.find((p) => p.key === '16x9') ?? OVERLAY_RATIO_PRESETS[0];
    const { width, height } = dimensionsForPreset(preset);

    return exportWatermarkOverlayBlobAtSize(width, height, activePattern, opacity, opts, position);
}
