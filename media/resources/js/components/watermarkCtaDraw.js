import { applyColorStyle, solidFromConfig } from './watermarkColorUtils';
import { normalizeAnchorPosition, resolveAnchorCenter } from './watermarkPosition';
import { pctOfMin } from './watermarkRelativeUnits';
import { resolveStampCenter } from './watermarkDrawUtils';
import { drawIcon } from './watermarkCtaIcons';

export { drawIcon } from './watermarkCtaIcons';

export function drawRoundRect(ctx, x, y, width, height, radius) {
    const r = Math.max(0, Math.min(radius, width / 2, height / 2));
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + width - r, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + r);
    ctx.lineTo(x + width, y + height - r);
    ctx.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
    ctx.lineTo(x + r, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} w
 * @param {number} h
 * @param {Record<string, unknown>} opts
 */
export function drawCtaButton(ctx, w, h, opts) {
    const {
        text1,
        textSize,
        selectedFont,
        textColorConfig,
        bgColorConfig,
        borders = [],
        btnPaddingX,
        btnPaddingY,
        btnRadius,
        selectedIcon,
        iconPosition,
        rotation,
        positionType,
        customCoords,
        presetPos,
        margin,
        gridSpacing,
    } = opts;

    const label = String(text1 ?? '');
    const padX = Number(btnPaddingX) || 0;
    const padY = Number(btnPaddingY) || 0;
    const radius = Number(btnRadius) || 0;
    const icon = selectedIcon === 'none' ? null : selectedIcon;
    const customIconSvg = String(opts.customIconSvg ?? '').trim();
    const iconActive =
        icon && (icon !== 'custom' || customIconSvg !== '');

    ctx.save();
    ctx.font = `bold ${textSize}px "${selectedFont}", ${selectedFont}, sans-serif`;

    const textWidth = ctx.measureText(label).width;
    const iconSize = textSize * 0.9;
    const iconGap = textSize * 0.42;
    const iconSpacing = iconActive ? iconSize + iconGap : 0;
    const btnW = textWidth + iconSpacing + padX * 2;
    const btnH = textSize + padY * 2;

    let cx;
    let cy;

    if (positionType === 'anchor') {
        const { anchor, offsetX, offsetY } = normalizeAnchorPosition({
            positionAnchor: opts.positionAnchor,
            anchorOffset: opts.anchorOffset,
        });
        const center = resolveAnchorCenter(w, h, anchor, offsetX, offsetY, btnW, btnH);
        cx = center.x;
        cy = center.y;
    } else if (positionType === 'preset') {
        const center = resolveStampCenter(w, h, positionType, customCoords, presetPos, margin, opts);
        cx = center.x;
        cy = center.y;
    } else {
        cx = w / 2;
        cy = h - btnH / 2 - Math.max(pctOfMin(2.67, w, h), Number(gridSpacing) / 2);
    }

    ctx.translate(cx, cy);
    ctx.rotate((Number(rotation) * Math.PI) / 180);

    const rectBounds = { x: -btnW / 2, y: -btnH / 2, w: btnW, h: btnH };
    const iconColor = solidFromConfig(textColorConfig);

    let accumulatedPadding = 0;
    borders.forEach((border) => {
        const bw = Math.max(1, Number(border.width) || 1);
        ctx.save();
        ctx.lineWidth = bw;
        ctx.strokeStyle = applyColorStyle(ctx, rectBounds, border.colorConfig);

        const bX = rectBounds.x - accumulatedPadding - bw / 2;
        const bY = rectBounds.y - accumulatedPadding - bw / 2;
        const bW = rectBounds.w + (accumulatedPadding + bw / 2) * 2;
        const bH = rectBounds.h + (accumulatedPadding + bw / 2) * 2;
        const bR = radius + accumulatedPadding;

        drawRoundRect(ctx, bX, bY, bW, bH, bR);
        ctx.stroke();
        ctx.restore();

        accumulatedPadding += bw + textSize * 0.08;
    });

    ctx.save();
    ctx.fillStyle = applyColorStyle(ctx, rectBounds, bgColorConfig);
    drawRoundRect(ctx, rectBounds.x, rectBounds.y, rectBounds.w, rectBounds.h, radius);
    ctx.fill();
    ctx.restore();

    ctx.save();
    const textFill = applyColorStyle(ctx, rectBounds, textColorConfig);
    ctx.fillStyle = textFill;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';

    let startX = rectBounds.x + padX;

    const iconExtra = { customIconImage: opts.customIconImage ?? null };

    if (iconActive && iconPosition === 'left') {
        drawIcon(ctx, startX + iconSize / 2, 0, iconSize, icon, iconColor, iconExtra);
        startX += iconSpacing;
    }

    ctx.fillText(label, startX, textSize * 0.08);

    if (iconActive && iconPosition === 'right') {
        drawIcon(
            ctx,
            startX + textWidth + iconGap + iconSize / 2,
            0,
            iconSize,
            icon,
            iconColor,
            iconExtra,
        );
    }

    ctx.restore();
    ctx.restore();
}
