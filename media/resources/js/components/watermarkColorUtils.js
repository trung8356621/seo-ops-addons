/**
 * Solid / gradient color helpers for canvas watermark drawing.
 */

export function defaultColorConfig(overrides = {}) {
    return {
        type: 'solid',
        color1: '#ffffff',
        color2: '#ff2d55',
        gradType: 'linear',
        angle: 45,
        ...overrides,
    };
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {{ x: number, y: number, w: number, h: number }} rect
 * @param {{ type?: string, color1?: string, color2?: string, gradType?: string, angle?: number }} config
 * @returns {string|CanvasGradient}
 */
export function applyColorStyle(ctx, rect, config) {
    if (!config || config.type === 'solid') {
        return config?.color1 || '#ffffff';
    }

    const c1 = config.color1 || '#ff2d55';
    const c2 = config.color2 || '#3a2df5';

    let grad;
    if (config.gradType === 'radial') {
        const cx = rect.x + rect.w / 2;
        const cy = rect.y + rect.h / 2;
        const r = Math.max(rect.w, rect.h) / 2;
        grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r);
    } else {
        const angleRad = ((config.angle || 0) * Math.PI) / 180;
        const cx = rect.x + rect.w / 2;
        const cy = rect.y + rect.h / 2;
        const r = Math.max(rect.w, rect.h) / 2;
        const x1 = cx - Math.cos(angleRad) * r;
        const y1 = cy - Math.sin(angleRad) * r;
        const x2 = cx + Math.cos(angleRad) * r;
        const y2 = cy + Math.sin(angleRad) * r;
        grad = ctx.createLinearGradient(x1, y1, x2, y2);
    }

    grad.addColorStop(0, c1);
    grad.addColorStop(1, c2);

    return grad;
}

/** Màu đơn cho icon / stroke khi gradient không áp dụng trực tiếp */
export function solidFromConfig(config) {
    return config?.color1 || '#ffffff';
}
