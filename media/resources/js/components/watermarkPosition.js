/**
 * Place watermark by anchor corner + pixel offset.
 */

/** @typedef {'top-left'|'top-center'|'top-right'|'center-left'|'center'|'center-right'|'bottom-left'|'bottom-center'|'bottom-right'} WatermarkAnchor */

export const WATERMARK_ANCHORS = [
    { value: 'top-left', label: 'Top - Left' },
    { value: 'top-center', label: 'Top - Center' },
    { value: 'top-right', label: 'Top - Right' },
    { value: 'center-left', label: 'Center - Left' },
    { value: 'center', label: 'Center' },
    { value: 'center-right', label: 'Center - Right' },
    { value: 'bottom-left', label: 'Bottom - Left' },
    { value: 'bottom-center', label: 'Bottom - Center' },
    { value: 'bottom-right', label: 'Bottom - Right' },
];

export const DEFAULT_POSITION_ANCHOR = 'bottom-right';

export const DEFAULT_ANCHOR_OFFSET = { x: 20, y: 20 };

/**
 * Element center from anchor corner + offset px.
 * offsetX: distance from right edge (for "right") or left edge.
 * offsetY: distance from bottom edge (for "bottom") or top edge.
 *
 * @param {number} canvasW
 * @param {number} canvasH
 * @param {WatermarkAnchor|string} anchor
 * @param {number} offsetX
 * @param {number} offsetY
 * @param {number} elemW
 * @param {number} elemH
 * @returns {{ x: number, y: number }}
 */
export function resolveAnchorCenter(canvasW, canvasH, anchor, offsetX, offsetY, elemW, elemH) {
    const offX = Math.max(0, Number(offsetX) || 0);
    const offY = Math.max(0, Number(offsetY) || 0);
    const halfW = elemW / 2;
    const halfH = elemH / 2;
    const a = String(anchor || DEFAULT_POSITION_ANCHOR);

    let cx;
    let cy;

    if (a.includes('left')) {
        cx = offX + halfW;
    } else if (a.includes('right')) {
        cx = canvasW - offX - halfW;
    } else {
        cx = canvasW / 2;
    }

    if (a.includes('top')) {
        cy = offY + halfH;
    } else if (a.includes('bottom')) {
        cy = canvasH - offY - halfH;
    } else {
        cy = canvasH / 2;
    }

    return {
        x: Math.max(halfW, Math.min(canvasW - halfW, cx)),
        y: Math.max(halfH, Math.min(canvasH - halfH, cy)),
    };
}

/**
 * @param {Record<string, unknown>} position
 * @returns {{ anchor: string, offsetX: number, offsetY: number }}
 */
export function normalizeAnchorPosition(position) {
    return {
        anchor: String(position?.positionAnchor ?? position?.anchor ?? DEFAULT_POSITION_ANCHOR),
        offsetX: Math.max(0, Number(position?.anchorOffset?.x ?? position?.offsetX ?? DEFAULT_ANCHOR_OFFSET.x)),
        offsetY: Math.max(0, Number(position?.anchorOffset?.y ?? position?.offsetY ?? DEFAULT_ANCHOR_OFFSET.y)),
    };
}

/**
 * Convert center point -> anchor offsets.
 *
 * @param {number} canvasW
 * @param {number} canvasH
 * @param {number} centerX
 * @param {number} centerY
 * @param {number} elemW
 * @param {number} elemH
 * @param {WatermarkAnchor|string} anchor
 */
export function centerToAnchorOffset(canvasW, canvasH, centerX, centerY, elemW, elemH, anchor) {
    const halfW = elemW / 2;
    const halfH = elemH / 2;
    const a = String(anchor || DEFAULT_POSITION_ANCHOR);

    let offsetX = 0;
    let offsetY = 0;

    if (a.includes('left')) {
        offsetX = Math.round(centerX - halfW);
    } else if (a.includes('right')) {
        offsetX = Math.round(canvasW - centerX - halfW);
    }

    if (a.includes('top')) {
        offsetY = Math.round(centerY - halfH);
    } else if (a.includes('bottom')) {
        offsetY = Math.round(canvasH - centerY - halfH);
    }

    return {
        x: Math.max(0, offsetX),
        y: Math.max(0, offsetY),
    };
}

/**
 * @param {Record<string, unknown>} config
 */
export function migratePositionFromLegacy(config) {
    if (config.positionType === 'anchor' && config.positionAnchor) {
        return config;
    }

    if (config.positionType === 'custom' && config.customCoords) {
        return {
            ...config,
            positionType: 'anchor',
            positionAnchor: DEFAULT_POSITION_ANCHOR,
            anchorOffset: { ...DEFAULT_ANCHOR_OFFSET },
        };
    }

    return {
        ...config,
        positionType: config.positionType === 'preset' ? 'preset' : 'anchor',
        positionAnchor: config.positionAnchor ?? DEFAULT_POSITION_ANCHOR,
        anchorOffset: config.anchorOffset ?? { ...DEFAULT_ANCHOR_OFFSET },
    };
}
