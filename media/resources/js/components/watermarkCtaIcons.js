import { drawCustomIconImage } from './watermarkCustomIcon';

/**
 * Icon CTA watermark — path SVG 24×24 (Lucide-style), vẽ bằng Path2D.
 *
 * @typedef {{ value: string, label: string, path: string, mode?: 'stroke'|'fill' }} WatermarkCtaIconDef
 * @typedef {{ customIconImage?: HTMLImageElement|null }} DrawIconExtra
 */

/** @type {WatermarkCtaIconDef[]} */
export const WATERMARK_CTA_ICONS = [
    { value: 'none', label: 'None', path: '' },
    { value: 'phone', label: 'Phone', path: 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z' },
    { value: 'arrow', label: 'Right arrow', path: 'M5 12h14M12 5l7 7-7 7' },
    { value: 'cart', label: 'Cart', path: 'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0' },
    { value: 'chat', label: 'Message / chat', path: 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z' },
    { value: 'mail', label: 'Email', path: 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6' },
    { value: 'map-pin', label: 'Location / map', path: 'M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0zM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z' },
    { value: 'clock', label: 'Clock', path: 'M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zM12 6v6l4 2' },
    { value: 'check', label: 'Confirmed', path: 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3' },
    { value: 'shield', label: 'Warranty / trust', path: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10M9 12l2 2 4-4' },
    { value: 'user', label: 'Contact / user', path: 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z' },
    { value: 'heart', label: 'Favorite', path: 'M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7z', mode: 'fill' },
    { value: 'gift', label: 'Gift / promotion', path: 'M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7s1-5 3.5-5a2.5 2.5 0 0 1 0 5H12' },
    { value: 'percent', label: 'Discount %', path: 'M19 5 5 19M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z' },
    { value: 'tag', label: 'Tag / price', path: 'M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42zM7.5 7.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z' },
    { value: 'star', label: 'Star', path: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', mode: 'fill' },
    { value: 'fire', label: 'Hot / fire', path: 'M8.5 14.5A2.5 2.5 0 0 0 11 18c0 2.21 1.79 4 4 4s4-1.79 4-4c0-.88-.28-1.69-.76-2.35A10 10 0 0 0 16 6c-2 0-3.5 1.5-3.5 3.5 0 1.5-.5 3-2 5z', mode: 'fill' },
    { value: 'truck', label: 'Shipping', path: 'M10 17h4M2 9h2l2-4h8l2 4h2v8h-2a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H2V9zM14 9V5H6v4' },
    { value: 'custom', label: 'Custom SVG', path: '' },
];

const ICON_BY_VALUE = Object.fromEntries(
    WATERMARK_CTA_ICONS.filter((i) => i.value !== 'none').map((i) => [i.value, i]),
);

/** @type {WatermarkCtaIconDef[]} */
export const WATERMARK_CTA_ICON_OPTIONS = WATERMARK_CTA_ICONS.filter((i) => i.value !== 'none');

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} x — tâm icon
 * @param {number} y
 * @param {number} size — chiều cao/rộng khung icon
 * @param {string} type
 * @param {string} color
 * @param {DrawIconExtra} [extra]
 */
export function drawIcon(ctx, x, y, size, type, color, extra = {}) {
    if (!type || type === 'none') {
        return;
    }

    if (type === 'custom') {
        if (extra.customIconImage instanceof HTMLImageElement) {
            drawCustomIconImage(ctx, x, y, size, extra.customIconImage);
        }
        return;
    }

    const def = ICON_BY_VALUE[type];
    if (!def?.path) {
        return;
    }

    const mode = def.mode ?? 'stroke';
    const scale = size / 24;
    const strokeWidth = 2;

    ctx.save();
    ctx.translate(x - size / 2, y - size / 2);
    ctx.scale(scale, scale);

    const path = new Path2D(def.path);

    if (mode === 'fill') {
        ctx.fillStyle = color;
        ctx.fill(path);
    } else {
        ctx.strokeStyle = color;
        ctx.lineWidth = strokeWidth;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke(path);
    }

    ctx.restore();
}
