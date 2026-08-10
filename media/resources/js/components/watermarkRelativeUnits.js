/**
 * Watermark layout in % relative to image width / height / min side.
 * Canvas preview renders at natural pixels; CSS transform scales the viewport.
 */

import { OVERLAY_EXPORT_MAX } from './overlayRatioPresets';

/** Reference size for migrating legacy absolute px configs. */
export const LAYOUT_REF_WIDTH = OVERLAY_EXPORT_MAX;
export const LAYOUT_REF_HEIGHT = Math.round((OVERLAY_EXPORT_MAX * 9) / 16);

export function minDim(width, height) {
    return Math.min(Math.max(1, width), Math.max(1, height));
}

export function pctOfWidth(percent, width) {
    return (Number(percent) || 0) / 100 * Math.max(1, width);
}

export function pctOfHeight(percent, height) {
    return (Number(percent) || 0) / 100 * Math.max(1, height);
}

export function pctOfMin(percent, width, height) {
    return (Number(percent) || 0) / 100 * minDim(width, height);
}

export function pxToPctWidth(px, width) {
    return width > 0 ? (Number(px) / width) * 100 : 0;
}

export function pxToPctHeight(px, height) {
    return height > 0 ? (Number(px) / height) * 100 : 0;
}

export function pxToPctMin(px, width, height) {
    const m = minDim(width, height);

    return m > 0 ? (Number(px) / m) * 100 : 0;
}

/** Default % values — equivalent to legacy px defaults at LAYOUT_REF_* */
export const DEFAULT_LAYOUT_PERCENT = {
    textSizePct: 1.2,
    btnPaddingXPct: 1.5,
    btnPaddingYPct: 1.33,
    btnRadiusPct: 2.67,
    borderWidthPct: 0.27,
    marginPct: 1.78,
    gridSpacingPct: 6,
    anchorOffsetPct: { x: 1, y: 1.78 },
    borderLayerWidthPct: 0.27,
};

/**
 * @param {Record<string, unknown>} config
 * @param {number} [refW]
 * @param {number} [refH]
 */
export function migrateLayoutToPercent(config, refW = LAYOUT_REF_WIDTH, refH = LAYOUT_REF_HEIGHT) {
    if (config?.layoutUnit === 'percent') {
        return {
            textSizePct: Number(config.textSizePct ?? DEFAULT_LAYOUT_PERCENT.textSizePct),
            btnPaddingXPct: Number(config.btnPaddingXPct ?? DEFAULT_LAYOUT_PERCENT.btnPaddingXPct),
            btnPaddingYPct: Number(config.btnPaddingYPct ?? DEFAULT_LAYOUT_PERCENT.btnPaddingYPct),
            btnRadiusPct: Number(config.btnRadiusPct ?? DEFAULT_LAYOUT_PERCENT.btnRadiusPct),
            borderWidthPct: Number(config.borderWidthPct ?? DEFAULT_LAYOUT_PERCENT.borderWidthPct),
            marginPct: Number(config.marginPct ?? DEFAULT_LAYOUT_PERCENT.marginPct),
            gridSpacingPct: Number(config.gridSpacingPct ?? DEFAULT_LAYOUT_PERCENT.gridSpacingPct),
            anchorOffsetPct: {
                x: Number(
                    config.anchorOffsetPct?.x ??
                        pxToPctWidth(config.anchorOffset?.x ?? 20, refW),
                ),
                y: Number(
                    config.anchorOffsetPct?.y ??
                        pxToPctHeight(config.anchorOffset?.y ?? 20, refH),
                ),
            },
            borders: normalizeBorderLayersPercent(config.borders, refW, refH),
        };
    }

    return {
        textSizePct: pxToPctWidth(config.textSize ?? 24, refW),
        btnPaddingXPct: pxToPctWidth(config.btnPaddingX ?? 30, refW),
        btnPaddingYPct: pxToPctHeight(config.btnPaddingY ?? 15, refH),
        btnRadiusPct: pxToPctMin(config.btnRadius ?? 30, refW, refH),
        borderWidthPct: pxToPctMin(config.borderWidth ?? 3, refW, refH),
        marginPct: pxToPctMin(config.margin ?? 20, refW, refH),
        gridSpacingPct: pxToPctWidth(config.gridSpacing ?? config.patternSpacing ?? 120, refW),
        anchorOffsetPct: {
            x: pxToPctWidth(config.anchorOffset?.x ?? 20, refW),
            y: pxToPctHeight(config.anchorOffset?.y ?? 20, refH),
        },
        borders: normalizeBorderLayersPercent(config.borders, refW, refH),
    };
}

/**
 * @param {unknown} borders
 * @param {number} refW
 * @param {number} refH
 */
function normalizeBorderLayersPercent(borders, refW, refH) {
    if (!Array.isArray(borders) || borders.length === 0) {
        return [
            {
                id: 1,
                widthPct: DEFAULT_LAYOUT_PERCENT.borderLayerWidthPct,
                colorConfig: null,
            },
        ];
    }

    return borders.map((border, index) => ({
        id: border.id ?? index + 1,
        widthPct:
            border.widthPct != null
                ? Number(border.widthPct)
                : pxToPctMin(border.width ?? 3, refW, refH),
        colorConfig: border.colorConfig ?? null,
    }));
}

/**
 * Resolve % layout → absolute px for a canvas of the given size.
 *
 * @param {Record<string, unknown>} layout
 * @param {number} canvasW
 * @param {number} canvasH
 */
export function resolveWatermarkLayoutPixels(layout, canvasW, canvasH) {
    const borders = Array.isArray(layout.borders) ? layout.borders : [];

    return {
        textSize: pctOfWidth(layout.textSizePct, canvasW),
        btnPaddingX: pctOfWidth(layout.btnPaddingXPct, canvasW),
        btnPaddingY: pctOfHeight(layout.btnPaddingYPct, canvasH),
        btnRadius: pctOfMin(layout.btnRadiusPct, canvasW, canvasH),
        borderWidth: pctOfMin(layout.borderWidthPct, canvasW, canvasH),
        margin: pctOfMin(layout.marginPct, canvasW, canvasH),
        gridSpacing: pctOfWidth(layout.gridSpacingPct, canvasW),
        anchorOffset: {
            x: pctOfWidth(layout.anchorOffsetPct?.x, canvasW),
            y: pctOfHeight(layout.anchorOffsetPct?.y, canvasH),
        },
        borders: borders.map((border) => ({
            ...border,
            width: pctOfMin(border.widthPct, canvasW, canvasH),
        })),
    };
}

/**
 * @param {Record<string, unknown>} layoutPercent
 * @param {Record<string, unknown>} staticFields colors, text, fonts, …
 * @param {number} canvasW
 * @param {number} canvasH
 */
export function buildResolvedDrawOpts(layoutPercent, staticFields, canvasW, canvasH) {
    const px = resolveWatermarkLayoutPixels(layoutPercent, canvasW, canvasH);

    return {
        ...staticFields,
        textSize: px.textSize,
        btnPaddingX: px.btnPaddingX,
        btnPaddingY: px.btnPaddingY,
        btnRadius: px.btnRadius,
        borderWidth: px.borderWidth,
        margin: px.margin,
        gridSpacing: px.gridSpacing,
        anchorOffset: px.anchorOffset,
        borders: px.borders.map((border, index) => {
            const source = layoutPercent.borders?.[index] ?? border;

            return {
                ...source,
                width: border.width,
            };
        }),
    };
}

/**
 * @param {Record<string, unknown>} layoutPercent
 * @param {Record<string, unknown>} positionStatic
 * @param {number} canvasW
 * @param {number} canvasH
 */
export function buildResolvedPositionBundle(layoutPercent, positionStatic, canvasW, canvasH) {
    const px = resolveWatermarkLayoutPixels(layoutPercent, canvasW, canvasH);

    return {
        ...positionStatic,
        margin: px.margin,
        anchorOffset: px.anchorOffset,
    };
}

/**
 * API / design_config payload: % + computed px snapshot.
 *
 * @param {Record<string, unknown>} layoutPercent
 * @param {Record<string, unknown>} state
 * @param {number} canvasW
 * @param {number} canvasH
 */
export function buildLayoutConfigPayload(layoutPercent, state, canvasW, canvasH) {
    const px = resolveWatermarkLayoutPixels(layoutPercent, canvasW, canvasH);

    return {
        layoutUnit: 'percent',
        textSizePct: layoutPercent.textSizePct,
        btnPaddingXPct: layoutPercent.btnPaddingXPct,
        btnPaddingYPct: layoutPercent.btnPaddingYPct,
        btnRadiusPct: layoutPercent.btnRadiusPct,
        borderWidthPct: layoutPercent.borderWidthPct,
        marginPct: layoutPercent.marginPct,
        gridSpacingPct: layoutPercent.gridSpacingPct,
        anchorOffsetPct: layoutPercent.anchorOffsetPct,
        borders: layoutPercent.borders,
        layoutResolvedPx: {
            width: canvasW,
            height: canvasH,
            textSize: Math.round(px.textSize),
            btnPaddingX: Math.round(px.btnPaddingX),
            btnPaddingY: Math.round(px.btnPaddingY),
            btnRadius: Math.round(px.btnRadius),
            borderWidth: Math.round(px.borderWidth),
            margin: Math.round(px.margin),
            gridSpacing: Math.round(px.gridSpacing),
            anchorOffset: {
                x: Math.round(px.anchorOffset.x),
                y: Math.round(px.anchorOffset.y),
            },
        },
        activePattern: state.activePattern,
        watermarkType: 'text',
        isPattern: state.activePattern === 'classic_grid',
        text: state.text1,
        text1: state.text1,
        text2: state.text2,
        textColor: state.textColorConfig?.color1 ?? state.textColor,
        textColorConfig: state.textColorConfig,
        bgColorConfig: state.bgColorConfig,
        textSize: Math.round(px.textSize),
        fontFamily: state.selectedFont,
        selectedFont: state.selectedFont,
        opacity: state.opacity,
        rotation: state.rotation,
        patternSpacing: Math.round(px.gridSpacing),
        gridSpacing: Math.round(px.gridSpacing),
        borderWidth: Math.round(px.borderWidth),
        borderColor: state.borderColor,
        backgroundColor: state.backgroundColor,
        bgOpacity: state.bgOpacity,
        positionType: state.positionType,
        positionAnchor: state.positionAnchor,
        anchorOffset: {
            x: Math.round(px.anchorOffset.x),
            y: Math.round(px.anchorOffset.y),
        },
        presetPos: String(state.presetPos ?? 'bottom-center').replace(/^middle-/, 'center-'),
        customCoords: state.customCoords,
        margin: Math.round(px.margin),
        btnPaddingX: Math.round(px.btnPaddingX),
        btnPaddingY: Math.round(px.btnPaddingY),
        btnRadius: Math.round(px.btnRadius),
        selectedIcon: state.selectedIcon,
        iconPosition: state.iconPosition,
        customIconSvg: state.customIconSvg ?? '',
    };
}

/**
 * Fit preview inside container (uniform scale, never upscale past 1×).
 */
export function computeFitScale(naturalW, naturalH, maxW, maxH) {
    if (!naturalW || !naturalH || !maxW || !maxH) {
        return 1;
    }

    return Math.min(maxW / naturalW, maxH / naturalH, 1);
}
