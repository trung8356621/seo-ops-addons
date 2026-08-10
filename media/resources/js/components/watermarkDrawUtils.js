/**
 * Canvas drawing helpers for watermark stamp patterns.
 * All dimensional opts are absolute pixels resolved for the target canvas size.
 */

import { applyColorStyle } from './watermarkColorUtils';
import { normalizeAnchorPosition, resolveAnchorCenter } from './watermarkPosition';
import { minDim, pctOfMin } from './watermarkRelativeUnits';

function fillFromOpts(ctx, rect, opts) {
    if (opts.textColorConfig) {
        return applyColorStyle(ctx, rect, opts.textColorConfig);
    }

    return opts.textColor ?? '#ffffff';
}

export function drawTextArc(ctx, str, x, y, radius, startAngle, endAngle, isReverse = false) {
    const chars = String(str).split('');
    const angleRange = endAngle - startAngle;

    ctx.save();
    ctx.translate(x, y);

    chars.forEach((char, i) => {
        const percent = chars.length > 1 ? i / (chars.length - 1) : 0.5;
        const angle = startAngle + angleRange * percent;

        ctx.save();
        ctx.rotate(angle);
        ctx.translate(0, isReverse ? radius : -radius);
        if (isReverse) {
            ctx.rotate(Math.PI);
        }
        ctx.fillText(char, 0, 0);
        ctx.restore();
    });

    ctx.restore();
}

export function resolveStampCenter(
    canvasW,
    canvasH,
    positionType,
    customCoords,
    presetPos,
    margin,
    position = {},
) {
    const m = minDim(canvasW, canvasH);

    if (positionType === 'anchor') {
        const { anchor, offsetX, offsetY } = normalizeAnchorPosition(position);
        const stampW = pctOfMin(14, canvasW, canvasH);
        const stampH = pctOfMin(6, canvasW, canvasH);

        return resolveAnchorCenter(canvasW, canvasH, anchor, offsetX, offsetY, stampW, stampH);
    }

    const pad = Number(margin) || 0;
    const inset = pctOfMin(12.5, canvasW, canvasH);
    switch (presetPos) {
        case 'top-left':
            return { x: pad + inset, y: pad + inset };
        case 'top-center':
            return { x: canvasW / 2, y: pad + inset };
        case 'top-right':
            return { x: canvasW - pad - inset, y: pad + inset };
        case 'center-left':
            return { x: pad + inset, y: canvasH / 2 };
        case 'center-right':
            return { x: canvasW - pad - inset, y: canvasH / 2 };
        case 'bottom-left':
            return { x: pad + inset, y: canvasH - pad - inset };
        case 'bottom-center':
            return { x: canvasW / 2, y: canvasH - pad - inset };
        case 'bottom-right':
        default:
            return { x: canvasW - pad - inset, y: canvasH - pad - inset };
    }
}

export function drawClassicGrid(ctx, w, h, opts) {
    const { text1, textSize, selectedFont, rotation, gridSpacing } = opts;

    ctx.save();
    ctx.translate(w / 2, h / 2);
    ctx.rotate((rotation * Math.PI) / 180);
    ctx.font = `bold ${textSize}px "${selectedFont}", ${selectedFont}, sans-serif`;
    ctx.fillStyle = fillFromOpts(ctx, { x: -w / 2, y: -h / 2, w, h }, opts);
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const step = gridSpacing + textSize;
    for (let x = -w * 1.5; x < w * 1.5; x += step * 2) {
        for (let y = -h * 1.5; y < h * 1.5; y += step) {
            ctx.fillText(text1, x, y);
        }
    }
    ctx.restore();
}

export function drawCircularBadge(ctx, cx, cy, opts) {
    const {
        text1,
        text2,
        textSize,
        selectedFont,
        textColor,
        borderColor,
        borderWidth,
        backgroundColor,
        bgOpacity,
        rotation,
    } = opts;

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate((rotation * Math.PI) / 180);

    const radius = Math.max(textSize * 4, textSize * 4.2);

    if (bgOpacity > 0) {
        ctx.save();
        ctx.globalAlpha = bgOpacity;
        ctx.fillStyle = backgroundColor;
        ctx.beginPath();
        ctx.arc(0, 0, radius + textSize * 0.85, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    }

    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.beginPath();
    ctx.arc(0, 0, radius, 0, Math.PI * 2);
    ctx.stroke();

    ctx.lineWidth = Math.max(1, borderWidth / 2);
    ctx.beginPath();
    ctx.arc(0, 0, radius - textSize * 0.33, 0, Math.PI * 2);
    ctx.stroke();

    ctx.font = `bold ${textSize * 0.75}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const arcRadius = radius - textSize * 0.92;
    drawTextArc(ctx, String(text1).toUpperCase(), 0, 0, arcRadius, -Math.PI / 1.45, Math.PI / 1.45);
    drawTextArc(
        ctx,
        String(text2).toUpperCase(),
        0,
        0,
        arcRadius,
        Math.PI / 1.45,
        -Math.PI / 1.45,
        true,
    );

    ctx.font = `bold ${textSize}px ${selectedFont}`;
    ctx.fillText('★', 0, textSize * 0.08);

    ctx.restore();
}

export function drawSecurityRect(ctx, cx, cy, opts) {
    const {
        text1,
        textSize,
        selectedFont,
        textColor,
        borderColor,
        borderWidth,
        backgroundColor,
        bgOpacity,
        rotation,
    } = opts;

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate((rotation * Math.PI) / 180);

    ctx.font = `bold ${textSize}px ${selectedFont}`;
    const label = String(text1).toUpperCase();
    const textWidth = ctx.measureText(label).width;
    const rectW = textWidth + textSize * 2.5;
    const rectH = textSize * 2.5;

    if (bgOpacity > 0) {
        ctx.save();
        ctx.globalAlpha = bgOpacity;
        ctx.fillStyle = backgroundColor;
        ctx.fillRect(-rectW / 2, -rectH / 2, rectW, rectH);
        ctx.restore();
    }

    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.setLineDash([textSize * 0.25, textSize * 0.17]);
    ctx.strokeRect(-rectW / 2, -rectH / 2, rectW, rectH);
    ctx.setLineDash([]);

    const innerPad = textSize * 0.21;
    ctx.lineWidth = Math.max(1, borderWidth / 3);
    ctx.strokeRect(
        -rectW / 2 + innerPad,
        -rectH / 2 + innerPad,
        rectW - innerPad * 2,
        rectH - innerPad * 2,
    );

    ctx.save();
    ctx.rotate((-15 * Math.PI) / 180);
    ctx.fillStyle = textColor;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, 0, 0);
    ctx.restore();

    ctx.restore();
}

export function drawElegantSignature(ctx, x, y, opts) {
    const { text1, textSize, selectedFont, textColor, borderColor, rotation } = opts;

    ctx.save();
    ctx.translate(x, y);
    ctx.rotate((rotation * Math.PI) / 180);

    ctx.font = `italic ${textSize}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(text1, 0, 0);

    const w = ctx.measureText(text1).width;
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = Math.max(1, textSize * 0.06);
    ctx.beginPath();
    ctx.moveTo(-textSize * 0.42, textSize * 0.5);
    ctx.quadraticCurveTo(w / 2, textSize * 0.92, w + textSize * 1.25, textSize * 0.25);
    ctx.stroke();

    ctx.restore();
}

export function drawMinimalFrame(ctx, w, h, opts) {
    const { text1, textSize, selectedFont, textColor, borderColor, borderWidth } = opts;
    const padding = pctOfMin(2.67, w, h);
    const innerGap = pctOfMin(0.71, w, h);
    const labelInset = pctOfMin(1.78, w, h);

    ctx.save();
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.strokeRect(padding, padding, w - padding * 2, h - padding * 2);

    ctx.lineWidth = Math.max(1, borderWidth / 2);
    ctx.strokeRect(
        padding + innerGap,
        padding + innerGap,
        w - (padding + innerGap) * 2,
        h - (padding + innerGap) * 2,
    );

    ctx.font = `bold ${textSize * 0.7}px ${selectedFont}`;
    ctx.fillStyle = textColor;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'alphabetic';
    ctx.fillText(text1, w - padding - labelInset, h - padding - labelInset);

    ctx.restore();
}

function fontCss(selectedFont, size, style = 'bold') {
    return `${style} ${size}px "${selectedFont}", ${selectedFont}, sans-serif`;
}

function drawVerticalBrand(ctx, text, x, startY, letterSpacing, direction = 1) {
    const letters = String(text).replace(/\s+/g, ' ').trim().toUpperCase().split('');
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    letters.forEach((char, i) => {
        if (char === ' ') {
            return;
        }
        const y = startY + i * letterSpacing * direction;
        ctx.fillText(char, x, y);
    });
}

export function drawAestheticCorners(ctx, w, h, opts) {
    const { text1, text2, textSize, selectedFont, textColor, borderColor, borderWidth, gridSpacing } =
        opts;

    const padding = Math.max(pctOfMin(1.33, w, h), gridSpacing / 4);
    const len = Math.max(textSize * 1.6, textSize * 1.17);
    const sideText = String(text2 || text1).trim();
    const cornerLabel = String(text1).trim();
    const letterSpacing = Math.max(textSize * 0.82, textSize * 0.58);
    const smallSize = Math.max(textSize * 0.55, textSize * 0.46);

    ctx.save();
    ctx.strokeStyle = borderColor;
    ctx.lineWidth = borderWidth;
    ctx.lineCap = 'square';
    ctx.lineJoin = 'miter';

    const drawL = (x1, y1, x2, y2, x3, y3) => {
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.lineTo(x3, y3);
        ctx.stroke();
    };

    drawL(padding + len, padding, padding, padding, padding, padding + len);
    drawL(w - padding - len, padding, w - padding, padding, w - padding, padding + len);
    drawL(padding + len, h - padding, padding, h - padding, padding, h - padding - len);
    drawL(w - padding - len, h - padding, w - padding, h - padding, w - padding, h - padding - len);

    ctx.fillStyle = borderColor;
    const dotR = Math.max(borderWidth * 0.9, borderWidth * 0.83);
    const dotOffset = textSize * 0.58;
    const dots = [
        [padding + len + dotOffset, padding + dotOffset],
        [w - padding - len - dotOffset, padding + dotOffset],
        [padding + len + dotOffset, h - padding - dotOffset],
        [w - padding - len - dotOffset, h - padding - dotOffset],
    ];
    dots.forEach(([dx, dy]) => {
        ctx.beginPath();
        ctx.arc(dx, dy, dotR, 0, Math.PI * 2);
        ctx.fill();
    });

    ctx.fillStyle = textColor;
    ctx.font = fontCss(selectedFont, Math.max(textSize * 0.72, textSize * 0.5));
    ctx.textBaseline = 'middle';

    const labelPad = textSize * 0.5;
    const labelOffset = textSize * 0.75;
    ctx.textAlign = 'left';
    ctx.fillText(cornerLabel, padding + labelPad, padding + len + labelOffset);
    ctx.textAlign = 'right';
    ctx.fillText(cornerLabel, w - padding - labelPad, padding + len + labelOffset);
    ctx.textAlign = 'left';
    ctx.fillText(cornerLabel, padding + labelPad, h - padding - len - labelOffset);
    ctx.textAlign = 'right';
    ctx.fillText(cornerLabel, w - padding - labelPad, h - padding - len - labelOffset);

    ctx.font = fontCss(selectedFont, smallSize, 'normal');

    const sideStartY = padding + len + textSize * 1.5;
    const sideEndY = h - padding - len - textSize * 1.5;
    const leftX = padding + textSize * 0.42;
    const rightX = w - padding - textSize * 0.42;

    drawVerticalBrand(ctx, sideText, leftX, sideStartY, letterSpacing, 1);

    ctx.save();
    ctx.translate(rightX, sideEndY);
    ctx.rotate(Math.PI);
    drawVerticalBrand(ctx, sideText, 0, 0, letterSpacing, 1);
    ctx.restore();

    ctx.restore();
}
