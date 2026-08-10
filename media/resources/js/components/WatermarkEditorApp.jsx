import React, { useCallback, useEffect, useRef, useState } from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import GradientColorPicker from './GradientColorPicker';
import PreciseControl from './PreciseControl';
import WatermarkMediaPicker from './WatermarkMediaPicker';
import { saveWatermarkSettings, saveWatermarkedMedia, saveNewWatermarkedImage } from '../utils/watermarkApi';
import { exportAllWatermarkOverlayBlobs, OVERLAY_EXPORT_MAX } from './watermarkOverlayExport';
import {
    centerToAnchorOffset,
    DEFAULT_POSITION_ANCHOR,
    migratePositionFromLegacy,
    resolveAnchorCenter,
    WATERMARK_ANCHORS,
} from './watermarkPosition';
import { defaultColorConfig } from './watermarkColorUtils';
import { drawCtaButton } from './watermarkCtaDraw';
import { WATERMARK_CTA_ICONS } from './watermarkCtaIcons';
import { loadCustomIconImage } from './watermarkCustomIcon';
import WatermarkOverlayPreviewPanel from './WatermarkOverlayPreviewPanel';
import { t } from '@content-addon/utils/i18n.js';

import {
    buildLayoutConfigPayload,
    buildResolvedDrawOpts,
    buildResolvedPositionBundle,
    computeFitScale,
    DEFAULT_LAYOUT_PERCENT,
    migrateLayoutToPercent,
    pxToPctHeight,
    pxToPctWidth,
    resolveWatermarkLayoutPixels,
} from './watermarkRelativeUnits';
import {
    drawAestheticCorners,
    drawCircularBadge,
    drawClassicGrid,
    drawElegantSignature,
    drawMinimalFrame,
    drawSecurityRect,
    resolveStampCenter,
} from './watermarkDrawUtils';

function extractOverlayPreviews(config) {
    return Array.isArray(config?.overlay_previews) ? config.overlay_previews : [];
}

const SYSTEM_FONTS = ['Arial', 'Georgia', 'Times New Roman', 'Courier New', 'Impact'];

// Dữ liệu từ resources/js/data/google-fonts.json
const GOOGLE_FONTS_DATA = [
    { family: 'Roboto', url_param: 'Roboto' },
    { family: 'Open Sans', url_param: 'Open+Sans' },
    { family: 'Montserrat', url_param: 'Montserrat' },
    { family: 'Inter', url_param: 'Inter' },
    { family: 'Playfair Display', url_param: 'Playfair+Display' },
    { family: 'Lora', url_param: 'Lora' },
    { family: 'Oswald', url_param: 'Oswald' },
    { family: 'Work Sans', url_param: 'Work+Sans' },
    { family: 'Inconsolata', url_param: 'Inconsolata' },
    { family: 'IBM Plex Sans', url_param: 'IBM+Plex+Sans' },
    { family: 'Nunito', url_param: 'Nunito' },
    { family: 'Merriweather', url_param: 'Merriweather' },
    { family: 'PT Sans', url_param: 'PT+Sans' },
    { family: 'Noto Sans', url_param: 'Noto+Sans' },
    { family: 'Quicksand', url_param: 'Quicksand' },
    { family: 'Raleway', url_param: 'Raleway' },
    { family: 'Poppins', url_param: 'Poppins' },
    { family: 'Barlow', url_param: 'Barlow' },
    { family: 'Mulish', url_param: 'Mulish' },
    { family: 'Cabin', url_param: 'Cabin' },
];

const PRESET_GRID = [
    'top-left',
    'top-center',
    'top-right',
    'center-left',
    'center',
    'center-right',
    'bottom-left',
    'bottom-center',
    'bottom-right',
];

const DEFAULT_SAMPLE =
    'https://images.unsplash.com/photo-1579546929518-9e396f3cc809?w=800';

const CANVAS_MAX_HEIGHT_VH = 0.72;
const CANVAS_AREA_PADDING_PX = 48;

const ZOOM_STEPS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2];
const ZOOM_MIN = ZOOM_STEPS[0];
const ZOOM_MAX = ZOOM_STEPS[ZOOM_STEPS.length - 1];

function nearestZoomStepIndex(level) {
    let bestIndex = 0;
    let bestDiff = Infinity;

    ZOOM_STEPS.forEach((step, index) => {
        const diff = Math.abs(step - level);
        if (diff < bestDiff) {
            bestDiff = diff;
            bestIndex = index;
        }
    });

    return bestIndex;
}

function stepZoomLevel(level, direction) {
    const index = nearestZoomStepIndex(level);
    const nextIndex = Math.min(ZOOM_STEPS.length - 1, Math.max(0, index + direction));

    return ZOOM_STEPS[nextIndex];
}

const DRAGGABLE_PATTERNS = new Set(['cta_button', 'circular_badge', 'security_rect', 'elegant_sig']);

const POSITIONABLE_PATTERNS = new Set(['cta_button', 'circular_badge', 'security_rect', 'elegant_sig']);

const PATTERNS = [
    {
        id: 'cta_button',
        name: t('watermark_pattern_cta_name'),
        description: t('watermark_pattern_cta_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <rect x="3" y="7" width="18" height="10" rx="3" strokeWidth={1.5} />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M8 12h8" />
            </svg>
        ),
    },
    {
        id: 'classic_grid',
        name: t('watermark_pattern_grid_name'),
        description: t('watermark_pattern_grid_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 6h16M4 12h16M4 18h16M10 6v12M14 6v12" />
            </svg>
        ),
    },
    {
        id: 'circular_badge',
        name: t('watermark_pattern_badge_name'),
        description: t('watermark_pattern_badge_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <circle cx="12" cy="12" r="9" strokeWidth={1.5} />
                <circle cx="12" cy="12" r="6" strokeWidth={1} strokeDasharray="2 2" />
            </svg>
        ),
    },
    {
        id: 'security_rect',
        name: t('watermark_pattern_security_name'),
        description: t('watermark_pattern_security_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <rect x="3" y="6" width="18" height="12" rx="2" strokeWidth={1.5} strokeDasharray="3 2" />
                <path strokeLinecap="round" strokeWidth={1.5} d="M7 10h10M7 14h6" />
            </svg>
        ),
    },
    {
        id: 'elegant_sig',
        name: t('watermark_pattern_signature_name'),
        description: t('watermark_pattern_signature_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={1.5}
                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                />
            </svg>
        ),
    },
    {
        id: 'minimal_frame',
        name: t('watermark_pattern_frame_name'),
        description: t('watermark_pattern_frame_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <rect x="4" y="4" width="16" height="16" strokeWidth={1.5} />
                <rect x="6" y="6" width="12" height="12" strokeWidth={1} />
            </svg>
        ),
    },
    {
        id: 'full_cross',
        name: t('watermark_pattern_corner_name'),
        description: t('watermark_pattern_corner_desc'),
        icon: (
            <svg className="wm-pattern-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={1.5}
                    d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"
                />
            </svg>
        ),
    },
];

function normalizePreset(pos) {
    return String(pos).replace(/^middle-/, 'center-');
}

function withDefaultBorderColorConfigs(borders) {
    return borders.map((border) => ({
        ...border,
        colorConfig:
            border.colorConfig ??
            defaultColorConfig({ type: 'solid', color1: '#ffffff' }),
    }));
}

function hasCtaIcon(selectedIcon, customIconSvg) {
    if (!selectedIcon || selectedIcon === 'none') {
        return false;
    }
    if (selectedIcon === 'custom') {
        return String(customIconSvg ?? '').trim() !== '';
    }
    return true;
}

function googleFontStylesheetUrl(urlParam) {
    return `https://fonts.googleapis.com/css2?family=${urlParam}:wght@400;700&display=swap`;
}

export default function WatermarkEditorApp({
    initialImageUrl = '',
    imageId = null,
    siteId = null,
    siteDomain = '',
    backUrl = '',
    initialConfig: rawInitialConfig = {},
    mediaSamples = [],
    onClose,
}) {
    const initialConfig = migratePositionFromLegacy(rawInitialConfig);
    const initialLayout = migrateLayoutToPercent(initialConfig);
    const canvasRef = useRef(null);
    const frameRef = useRef(null);
    const canvasAreaRef = useRef(null);
    const dragRef = useRef(null);
    const loadedFontLinksRef = useRef(new Set());

    const [sampleImage, setSampleImage] = useState(null);
    const [naturalSize, setNaturalSize] = useState({ width: 0, height: 0 });
    const [fitScale, setFitScale] = useState(1);
    const [zoomLevel, setZoomLevel] = useState(1);
    const [sampleUrl, setSampleUrl] = useState(initialImageUrl || DEFAULT_SAMPLE);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);
    const [fontRevision, setFontRevision] = useState(0);
    const [workspaceTab, setWorkspaceTab] = useState('design');
    const [overlayPreviews, setOverlayPreviews] = useState(() => extractOverlayPreviews(initialConfig));

    const [activePattern, setActivePattern] = useState(() => {
        const p =
            initialConfig.activePattern ??
            (initialConfig.isPattern ? 'classic_grid' : 'cta_button');
        if (p === 'shield_stamp') {
            return 'full_cross';
        }
        return p;
    });

    const [selectedFont, setSelectedFont] = useState(() => {
        const initial = initialConfig.selectedFont ?? initialConfig.fontFamily ?? 'Arial';
        if (GOOGLE_FONTS_DATA.some((f) => f.family === initial)) {
            return initial;
        }
        if (SYSTEM_FONTS.includes(initial)) {
            return initial;
        }
        return 'Arial';
    });
    const [fontStatus, setFontStatus] = useState('');

    const [text1, setText1] = useState(
        initialConfig.text1 ?? initialConfig.text ?? '© OMI SEO WORKFLOW',
    );
    const [text2, setText2] = useState(initialConfig.text2 ?? t('watermark_contact_now'));
    const [textColorConfig, setTextColorConfig] = useState(
        initialConfig.textColorConfig ??
            defaultColorConfig({
                type: 'solid',
                color1: initialConfig.textColor ?? '#ffffff',
            }),
    );
    const [bgColorConfig, setBgColorConfig] = useState(
        initialConfig.bgColorConfig ??
            defaultColorConfig({
                type: 'gradient',
                color1: '#ff2d55',
                color2: '#ff8a00',
                gradType: 'linear',
                angle: 90,
            }),
    );
    const [textSizePct, setTextSizePct] = useState(initialLayout.textSizePct);
    const [opacity, setOpacity] = useState(initialConfig.opacity ?? 0.95);
    const [rotation, setRotation] = useState(initialConfig.rotation ?? 0);

    const [btnPaddingXPct, setBtnPaddingXPct] = useState(initialLayout.btnPaddingXPct);
    const [btnPaddingYPct, setBtnPaddingYPct] = useState(initialLayout.btnPaddingYPct);
    const [btnRadiusPct, setBtnRadiusPct] = useState(initialLayout.btnRadiusPct);
    const [selectedIcon, setSelectedIcon] = useState(initialConfig.selectedIcon ?? 'arrow');
    const [customIconSvg, setCustomIconSvg] = useState(initialConfig.customIconSvg ?? '');
    const [customIconImage, setCustomIconImage] = useState(null);
    const [customIconError, setCustomIconError] = useState('');
    const [iconPosition, setIconPosition] = useState(initialConfig.iconPosition ?? 'right');
    const [borders, setBorders] = useState(() =>
        withDefaultBorderColorConfigs(initialLayout.borders),
    );

    const [borderWidthPct, setBorderWidthPct] = useState(initialLayout.borderWidthPct);
    const [borderColor, setBorderColor] = useState(initialConfig.borderColor ?? '#ff2d55');
    const [backgroundColor, setBackgroundColor] = useState(initialConfig.backgroundColor ?? '#ffffff');
    const [bgOpacity, setBgOpacity] = useState(initialConfig.bgOpacity ?? 0);

    const [gridSpacingPct, setGridSpacingPct] = useState(initialLayout.gridSpacingPct);

    const [positionType, setPositionType] = useState(
        initialConfig.positionType === 'custom' ? 'anchor' : (initialConfig.positionType ?? 'anchor'),
    );
    const [positionAnchor, setPositionAnchor] = useState(
        initialConfig.positionAnchor ?? DEFAULT_POSITION_ANCHOR,
    );
    const [anchorOffsetPct, setAnchorOffsetPct] = useState(initialLayout.anchorOffsetPct);
    const [presetPos, setPresetPos] = useState(
        normalizePreset(initialConfig.presetPos ?? 'bottom-center'),
    );
    const [customCoords, setCustomCoords] = useState(
        initialConfig.customCoords ?? { x: 50, y: 50 },
    );
    const [marginPct, setMarginPct] = useState(initialLayout.marginPct);

    useEffect(() => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = sampleUrl;
        img.onload = () => {
            setSampleImage(img);
            setNaturalSize({
                width: img.naturalWidth || img.width,
                height: img.naturalHeight || img.height,
            });
            setMessage(null);
        };
        img.onerror = () => setMessage({ type: 'error', text: t('watermark_sample_image_load_failed') });
    }, [sampleUrl]);

    useEffect(() => {
        setZoomLevel(1);
    }, [sampleUrl]);

    const updateFitScale = useCallback(() => {
        if (!naturalSize.width || !canvasAreaRef.current) {
            return;
        }

        const area = canvasAreaRef.current;
        const viewport = area.querySelector('.wm-canvas-viewport');
        const maxW = Math.max(1, (viewport?.clientWidth ?? area.clientWidth) - CANVAS_AREA_PADDING_PX);
        const maxH = Math.max(
            1,
            Math.min(
                window.innerHeight * CANVAS_MAX_HEIGHT_VH,
                (viewport?.clientHeight ?? area.clientHeight) - CANVAS_AREA_PADDING_PX,
            ),
        );

        setFitScale(computeFitScale(naturalSize.width, naturalSize.height, maxW, maxH));
    }, [naturalSize]);

    useEffect(() => {
        updateFitScale();

        const area = canvasAreaRef.current;
        if (!area) {
            return undefined;
        }

        const observer = new ResizeObserver(() => updateFitScale());
        observer.observe(area);
        window.addEventListener('resize', updateFitScale);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', updateFitScale);
        };
    }, [updateFitScale]);

    // Tự động nạp Google Font khi chọn trên select
    useEffect(() => {
        const fontObj = GOOGLE_FONTS_DATA.find((f) => f.family === selectedFont);

        const triggerRedraw = () => {
            document.fonts
                .load(`700 24px "${selectedFont}"`)
                .finally(() => {
                    setFontStatus('');
                    setFontRevision((n) => n + 1);
                });
        };

        if (!fontObj) {
            setFontStatus('');
            setFontRevision((n) => n + 1);
            return;
        }

        const fontUrl = googleFontStylesheetUrl(fontObj.url_param);
        setFontStatus(t('watermark_loading_font'));

        let link = document.querySelector(`link[data-wm-font="${fontObj.url_param}"]`);
        if (!link) {
            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = fontUrl;
            link.dataset.wmFont = fontObj.url_param;
            document.head.appendChild(link);
        }

        const onReady = () => {
            loadedFontLinksRef.current.add(fontUrl);
            document.fonts.ready.then(triggerRedraw);
        };

        if (loadedFontLinksRef.current.has(fontUrl)) {
            onReady();
            return;
        }

        link.onload = onReady;
        link.onerror = () => {
            setFontStatus(t('watermark_font_load_error'));
            setTimeout(() => setFontStatus(''), 3000);
        };
    }, [selectedFont]);

    useEffect(() => {
        if (selectedIcon !== 'custom' || !customIconSvg.trim()) {
            setCustomIconImage(null);
            setCustomIconError('');
            return undefined;
        }

        let cancelled = false;
        const color = textColorConfig?.color1 ?? '#ffffff';

        loadCustomIconImage(customIconSvg, color)
            .then((img) => {
                if (!cancelled) {
                    setCustomIconImage(img);
                    setCustomIconError('');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setCustomIconImage(null);
                    setCustomIconError(t('watermark_custom_svg_invalid'));
                }
            });

        return () => {
            cancelled = true;
        };
    }, [selectedIcon, customIconSvg, textColorConfig]);

    const layoutPercent = useCallback(
        () => ({
            textSizePct,
            btnPaddingXPct,
            btnPaddingYPct,
            btnRadiusPct,
            borderWidthPct,
            marginPct,
            gridSpacingPct,
            anchorOffsetPct,
            borders,
        }),
        [
            textSizePct,
            btnPaddingXPct,
            btnPaddingYPct,
            btnRadiusPct,
            borderWidthPct,
            marginPct,
            gridSpacingPct,
            anchorOffsetPct,
            borders,
        ],
    );

    const staticDrawFields = useCallback(
        () => ({
            text1,
            text2,
            selectedFont,
            textColor: textColorConfig?.color1 ?? '#ffffff',
            textColorConfig,
            bgColorConfig,
            selectedIcon,
            customIconSvg,
            customIconImage,
            iconPosition,
            borderColor,
            backgroundColor,
            bgOpacity,
            rotation,
            positionType,
            positionAnchor,
            customCoords,
            presetPos,
        }),
        [
            text1,
            text2,
            selectedFont,
            textColorConfig,
            bgColorConfig,
            selectedIcon,
            customIconSvg,
            customIconImage,
            iconPosition,
            borderColor,
            backgroundColor,
            bgOpacity,
            rotation,
            positionType,
            positionAnchor,
            customCoords,
            presetPos,
        ],
    );

    const positionStatic = useCallback(
        () => ({
            positionType,
            positionAnchor,
            customCoords,
            presetPos: normalizePreset(presetPos),
        }),
        [positionType, positionAnchor, customCoords, presetPos],
    );

    const resolveDrawAt = useCallback(
        (canvasW, canvasH) =>
            buildResolvedDrawOpts(layoutPercent(), staticDrawFields(), canvasW, canvasH),
        [layoutPercent, staticDrawFields],
    );

    const resolvePositionAt = useCallback(
        (canvasW, canvasH) =>
            buildResolvedPositionBundle(layoutPercent(), positionStatic(), canvasW, canvasH),
        [layoutPercent, positionStatic],
    );

    const visualScale = fitScale * zoomLevel;

    const zoomOut = useCallback(() => {
        setZoomLevel((current) => stepZoomLevel(current, -1));
    }, []);

    const zoomIn = useCallback(() => {
        setZoomLevel((current) => stepZoomLevel(current, 1));
    }, []);

    const resetZoom = useCallback(() => {
        setZoomLevel(1);
    }, []);

    const drawWatermarkLayer = useCallback(
        (ctx, canvasW, canvasH) => {
            const opts = resolveDrawAt(canvasW, canvasH);
            const pos = resolvePositionAt(canvasW, canvasH);

            switch (activePattern) {
                case 'cta_button':
                    drawCtaButton(ctx, canvasW, canvasH, opts);
                    break;
                case 'classic_grid':
                    drawClassicGrid(ctx, canvasW, canvasH, opts);
                    break;
                case 'circular_badge': {
                    const center = resolveStampCenter(
                        canvasW,
                        canvasH,
                        positionType,
                        customCoords,
                        presetPos,
                        pos.margin,
                        pos,
                    );
                    drawCircularBadge(ctx, center.x, center.y, opts);
                    break;
                }
                case 'security_rect': {
                    const center = resolveStampCenter(
                        canvasW,
                        canvasH,
                        positionType,
                        customCoords,
                        presetPos,
                        pos.margin,
                        pos,
                    );
                    drawSecurityRect(ctx, center.x, center.y, opts);
                    break;
                }
                case 'elegant_sig': {
                    const center = resolveStampCenter(
                        canvasW,
                        canvasH,
                        positionType,
                        customCoords,
                        presetPos,
                        pos.margin,
                        pos,
                    );
                    drawElegantSignature(ctx, center.x, center.y, opts);
                    break;
                }
                case 'minimal_frame':
                    drawMinimalFrame(ctx, canvasW, canvasH, opts);
                    break;
                case 'full_cross':
                    drawAestheticCorners(ctx, canvasW, canvasH, opts);
                    break;
                default:
                    break;
            }
        },
        [
            activePattern,
            resolveDrawAt,
            resolvePositionAt,
            positionType,
            customCoords,
            presetPos,
        ],
    );

    const drawCanvas = useCallback(() => {
        if (!sampleImage || !canvasRef.current || !naturalSize.width) {
            return;
        }

        const canvas = canvasRef.current;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        const w = naturalSize.width;
        const h = naturalSize.height;

        canvas.width = w;
        canvas.height = h;
        canvas.style.width = `${w}px`;
        canvas.style.height = `${h}px`;

        ctx.clearRect(0, 0, w, h);
        ctx.drawImage(sampleImage, 0, 0, w, h);
        ctx.globalAlpha = opacity;

        drawWatermarkLayer(ctx, w, h);

        ctx.globalAlpha = 1;
    }, [sampleImage, naturalSize, opacity, drawWatermarkLayer, fontRevision]);

    useEffect(() => {
        drawCanvas();
    }, [drawCanvas]);

    useEffect(() => {
        const onMove = (e) => {
            if (!dragRef.current || !naturalSize.width) {
                return;
            }
            const { rect, scaleX, scaleY, elemW, elemH } = dragRef.current;
            const newCenterX = (e.clientX - rect.left) * scaleX;
            const newCenterY = (e.clientY - rect.top) * scaleY;
            const offsetPx = centerToAnchorOffset(
                naturalSize.width,
                naturalSize.height,
                newCenterX,
                newCenterY,
                elemW,
                elemH,
                positionAnchor,
            );
            setAnchorOffsetPct({
                x: pxToPctWidth(offsetPx.x, naturalSize.width),
                y: pxToPctHeight(offsetPx.y, naturalSize.height),
            });
        };
        const onUp = () => {
            dragRef.current = null;
        };
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
        return () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };
    }, [naturalSize, positionAnchor]);

    const exportFullResolutionBlob = () =>
        new Promise((resolve, reject) => {
            if (!sampleImage || !naturalSize.width) {
                reject(new Error(t('watermark_canvas_not_ready')));
                return;
            }

            const exportCanvas = document.createElement('canvas');
            exportCanvas.width = naturalSize.width;
            exportCanvas.height = naturalSize.height;
            const ctx = exportCanvas.getContext('2d');
            if (!ctx) {
                reject(new Error(t('watermark_canvas_not_ready')));
                return;
            }

            ctx.drawImage(sampleImage, 0, 0, naturalSize.width, naturalSize.height);
            ctx.globalAlpha = opacity;
            drawWatermarkLayer(ctx, naturalSize.width, naturalSize.height);
            ctx.globalAlpha = 1;

            exportCanvas.toBlob(
                (blob) => (blob ? resolve(blob) : reject(new Error(t('watermark_export_failed_alt')))),
                'image/png',
                0.92,
            );
        });

    const exportBlob = () => exportFullResolutionBlob();

    const handleDownload = async () => {
        try {
            const blob = await exportBlob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'watermark-preview.png';
            a.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        }
    };

    const handleSaveConfig = async () => {
        if (!siteId) {
            setMessage({ type: 'error', text: t('watermark_choose_site_before_save') });
            return;
        }

        setSaving(true);
        setMessage(null);

        const state = {
            activePattern,
            text1,
            text2,
            selectedFont,
            textColorConfig,
            bgColorConfig,
            opacity,
            rotation,
            borderColor,
            backgroundColor,
            bgOpacity,
            positionType,
            positionAnchor,
            presetPos,
            customCoords,
            selectedIcon,
            customIconSvg,
            iconPosition,
        };

        const refW = naturalSize.width || 2000;
        const refH = naturalSize.height || 1125;
        const resolvedRef = resolveWatermarkLayoutPixels(layoutPercent(), refW, refH);

        try {
            const overlayVariants = await exportAllWatermarkOverlayBlobs(
                activePattern,
                opacity,
                resolveDrawAt,
                resolvePositionAt,
            );

            const saveResult = await saveWatermarkSettings(
                siteId,
                {
                    type: 'text',
                    auto_watermark: true,
                    design_config: {
                        ...buildLayoutConfigPayload(layoutPercent(), state, refW, refH),
                        overlay_export_max: OVERLAY_EXPORT_MAX,
                        overlay_variant_keys: overlayVariants.map((v) => v.key),
                    },
                    text_content: text1,
                    text_color: textColorConfig?.color1 ?? '#ffffff',
                    text_size: Math.round(resolvedRef.textSize),
                    position: normalizePreset(presetPos),
                    opacity,
                },
                null,
                overlayVariants,
            );
            const previews = extractOverlayPreviews(saveResult?.settings ?? {});
            if (previews.length > 0) {
                setOverlayPreviews(previews);
            }
            const domainHint = siteDomain
                ? ` Thư mục: uploads/watermarks/overlays/${siteDomain.replace(/[.:]/g, '-')}/`
                : '';
            setMessage({
                type: 'success',
                text: `Đã lưu thiết kế và ${overlayVariants.length} bản overlay (cạnh dài ~${OVERLAY_EXPORT_MAX}px).${domainHint}`,
            });
            setWorkspaceTab('overlay');
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setSaving(false);
        }
    };

    const handleApplyToImage = async (mode) => {
        if (!siteId) {
            return;
        }
        setSaving(true);
        try {
            const blob = await exportBlob();
            if (imageId) {
                await saveWatermarkedMedia(imageId, blob, mode);
                setMessage({
                    type: 'success',
                    text: mode === 'new' ? t('watermark_saved_new_image') : t('watermark_saved_overwritten'),
                });
            } else {
                await saveNewWatermarkedImage(siteId, blob);
                setMessage({ type: 'success', text: t('watermark_saved_to_internal_library') });
            }
        } catch (err) {
            setMessage({ type: 'error', text: err.message });
        } finally {
            setSaving(false);
        }
    };

    const handleClose = () => {
        if (typeof onClose === 'function') {
            onClose();
        } else if (backUrl) {
            window.location.href = backUrl;
        }
    };

    const ctaApproxSize = useCallback(() => {
        if (!naturalSize.width) {
            return { w: 0, h: 0 };
        }

        const px = resolveWatermarkLayoutPixels(layoutPercent(), naturalSize.width, naturalSize.height);
        const padX = px.btnPaddingX;
        const padY = px.btnPaddingY;
        const textSize = px.textSize;
        const label = String(text1 ?? '');
        const approxTextW = label.length * textSize * 0.55;
        const iconSize = textSize * 0.9;
        const iconSpacing = hasCtaIcon(selectedIcon, customIconSvg) ? iconSize + textSize * 0.42 : 0;

        return {
            w: approxTextW + iconSpacing + padX * 2,
            h: textSize + padY * 2,
        };
    }, [naturalSize, layoutPercent, text1, selectedIcon, customIconSvg]);

    const dragHandlePercent = useCallback(() => {
        if (!naturalSize.width) {
            return { left: '90%', top: '90%' };
        }

        const px = resolveWatermarkLayoutPixels(layoutPercent(), naturalSize.width, naturalSize.height);
        const { w, h } = ctaApproxSize();
        const center = resolveAnchorCenter(
            naturalSize.width,
            naturalSize.height,
            positionAnchor,
            px.anchorOffset.x,
            px.anchorOffset.y,
            w,
            h,
        );

        return {
            left: `${(center.x / naturalSize.width) * 100}%`,
            top: `${(center.y / naturalSize.height) * 100}%`,
        };
    }, [naturalSize, layoutPercent, positionAnchor, ctaApproxSize]);

    const showDragOverlay =
        activePattern === 'cta_button' && positionType === 'anchor';

    const showPositionControls = POSITIONABLE_PATTERNS.has(activePattern);

    const showBorderBgControls =
        activePattern !== 'cta_button' &&
        ['circular_badge', 'security_rect', 'minimal_frame', 'full_cross'].includes(activePattern);

    const showBgOpacityControls = ['circular_badge', 'security_rect'].includes(activePattern);

    const addBorder = () => {
        setBorders((prev) => [
            ...prev,
            {
                id: Date.now(),
                widthPct: DEFAULT_LAYOUT_PERCENT.borderLayerWidthPct,
                colorConfig: defaultColorConfig({ type: 'solid', color1: '#ffffff' }),
            },
        ]);
    };

    const removeBorder = (id) => {
        setBorders((prev) => prev.filter((b) => b.id !== id));
    };

    const updateBorder = (id, key, val) => {
        setBorders((prev) => prev.map((b) => (b.id === id ? { ...b, [key]: val } : b)));
    };

    const handlePatternSelect = (id) => {
        setActivePattern(id);
        if (id === 'cta_button') {
            setRotation(0);
            setPositionType('anchor');
            setPositionAnchor(DEFAULT_POSITION_ANCHOR);
            setAnchorOffsetPct({ ...DEFAULT_LAYOUT_PERCENT.anchorOffsetPct });
        } else if (id === 'classic_grid') {
            setRotation(-30);
        } else if (id === 'security_rect') {
            setRotation(-15);
        } else if (id === 'minimal_frame' || id === 'full_cross') {
            setRotation(0);
        }
    };

    return (
        <div className="wm-app wm-app--three-col">
            {/* Cột trái: mẫu đóng dấu */}
            <aside className="wm-patterns">
                <div className="wm-patterns__head">
                    <span>{t('watermark_step_1_pattern')}</span>
                </div>
                <div className="wm-patterns__list">
                    {PATTERNS.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            className={`wm-pattern-btn ${activePattern === p.id ? 'is-active' : ''}`}
                            onClick={() => handlePatternSelect(p.id)}
                        >
                            <span className="wm-pattern-btn__icon">{p.icon}</span>
                            <span className="wm-pattern-btn__text">
                                <strong>{p.name}</strong>
                                <small>{p.description}</small>
                            </span>
                        </button>
                    ))}
                </div>
                <div className="wm-patterns__foot">
                    <button type="button" className="wm-btn wm-btn--ghost wm-btn--block" onClick={handleClose}>
                        {t('watermark_back')}
                    </button>
                </div>
            </aside>

            {/* Cột giữa: canvas */}
            <div className="wm-workspace">
                <div className="wm-topbar">
                    <span className="wm-topbar__title">
                        {t('watermark_design_suite')}
                        {siteDomain ? (
                            <span className="wm-topbar__domain" title={t('watermark_configuring_domain')}>
                                {' '}
                                · {siteDomain}
                            </span>
                        ) : siteId ? (
                            <span className="wm-topbar__domain"> · site #{siteId}</span>
                        ) : null}
                        {imageId ? ` · ảnh #${imageId}` : ''}
                    </span>
                    <div className="wm-topbar__actions">
                        <button type="button" className="wm-btn wm-btn--ghost" onClick={() => setPickerOpen(true)}>
                            {t('watermark_sample_image')}
                        </button>
                        <button type="button" className="wm-btn wm-btn--ghost" onClick={handleDownload}>
                            {t('watermark_download_test')}
                        </button>
                        <button
                            type="button"
                            className="wm-btn wm-btn--primary"
                            disabled={saving}
                            onClick={handleSaveConfig}
                        >
                            {t('watermark_save_config')}
                        </button>
                        {imageId ? (
                            <>
                                <button
                                    type="button"
                                    className="wm-btn wm-btn--ghost"
                                    disabled={saving}
                                    onClick={() => handleApplyToImage('overwrite')}
                                >
                                    {t('watermark_save_overwrite_short')}
                                </button>
                                <button
                                    type="button"
                                    className="wm-btn wm-btn--primary"
                                    disabled={saving}
                                    onClick={() => handleApplyToImage('new')}
                                >
                                    {t('watermark_new_image')}
                                </button>
                            </>
                        ) : null}
                        <button type="button" className="wm-btn wm-btn--danger" onClick={handleClose}>
                            {t('cancel')}
                        </button>
                    </div>
                </div>

                {message ? (
                    <div className={`wm-flash ${message.type === 'error' ? 'is-error' : 'is-success'}`}>
                        {message.text}
                    </div>
                ) : null}

                <div className="wm-workspace-tabs" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        className={workspaceTab === 'design' ? 'is-active' : ''}
                        aria-selected={workspaceTab === 'design'}
                        onClick={() => setWorkspaceTab('design')}
                    >
                        {t('watermark_design')}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        className={workspaceTab === 'overlay' ? 'is-active' : ''}
                        aria-selected={workspaceTab === 'overlay'}
                        onClick={() => setWorkspaceTab('overlay')}
                    >
                        {t('watermark_view_overlay_2k')}
                        {overlayPreviews.length > 0 ? (
                            <span className="wm-workspace-tabs__badge">{overlayPreviews.length}</span>
                        ) : null}
                    </button>
                </div>

                {workspaceTab === 'overlay' ? (
                    <WatermarkOverlayPreviewPanel
                        variants={overlayPreviews}
                        sampleImageUrl={sampleUrl}
                    />
                ) : null}

                <div className={`wm-canvas-area ${workspaceTab !== 'design' ? 'is-hidden' : ''}`} ref={canvasAreaRef}>
                    <div className="wm-canvas-zoom-toolbar" role="toolbar" aria-label={t('watermark_preview_zoom')}>
                        <button
                            type="button"
                            className="wm-canvas-zoom-btn"
                            onClick={zoomOut}
                            disabled={zoomLevel <= ZOOM_MIN}
                            title={t('magic_zoom_out')}
                            aria-label={t('magic_zoom_out')}
                        >
                            −
                        </button>
                        <span className="wm-canvas-zoom-label" aria-live="polite">
                            {Math.round(zoomLevel * 100)}%
                        </span>
                        <button
                            type="button"
                            className="wm-canvas-zoom-btn"
                            onClick={zoomIn}
                            disabled={zoomLevel >= ZOOM_MAX}
                            title={t('magic_zoom_in')}
                            aria-label={t('magic_zoom_in')}
                        >
                            +
                        </button>
                        <button
                            type="button"
                            className="wm-canvas-zoom-btn wm-canvas-zoom-btn--reset"
                            onClick={resetZoom}
                            title={t('watermark_zoom_fit_screen')}
                        >
                            {t('watermark_zoom_fit_screen')}
                        </button>
                        {naturalSize.width > 0 ? (
                            <span className="wm-canvas-zoom-meta">
                                {naturalSize.width}×{naturalSize.height}px · fit{' '}
                                {Math.round(fitScale * 100)}%
                            </span>
                        ) : null}
                    </div>

                    <div className="wm-canvas-viewport">
                        <div
                            className="wm-canvas-zoom-spacer"
                            style={
                                naturalSize.width > 0
                                    ? {
                                          width: Math.round(naturalSize.width * visualScale),
                                          height: Math.round(naturalSize.height * visualScale),
                                      }
                                    : undefined
                            }
                        >
                            <div
                                className="wm-canvas-frame"
                                ref={frameRef}
                                style={
                                    naturalSize.width > 0
                                        ? {
                                              width: naturalSize.width,
                                              height: naturalSize.height,
                                              transform: `scale(${visualScale})`,
                                              transformOrigin: 'center center',
                                              transition: 'transform 0.2s ease',
                                          }
                                        : undefined
                                }
                            >
                                <canvas ref={canvasRef} />
                            </div>
                            {showDragOverlay ? (
                                <div className="wm-drag-overlay">
                                    <div
                                        className="wm-drag-handle"
                                        style={dragHandlePercent()}
                                        onMouseDown={(e) => {
                                            e.preventDefault();
                                            if (!naturalSize.width || !frameRef.current) {
                                                return;
                                            }
                                            const rect = frameRef.current.getBoundingClientRect();
                                            const scaleX = naturalSize.width / rect.width;
                                            const scaleY = naturalSize.height / rect.height;
                                            const { w, h } = ctaApproxSize();
                                            const startX = (e.clientX - rect.left) * scaleX;
                                            const startY = (e.clientY - rect.top) * scaleY;
                                            dragRef.current = {
                                                rect,
                                                scaleX,
                                                scaleY,
                                                elemW: w,
                                                elemH: h,
                                                startX,
                                                startY,
                                                startOffset: { ...anchorOffsetPct },
                                            };
                                        }}
                                        title={t('watermark_drag_offset_hint')}
                                    />
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            {/* Cột phải: cấu hình */}
            <aside className="wm-sidebar wm-sidebar--wide">
                <div className="wm-sidebar__head">
                    <span>{t('watermark_step_2_customize')}</span>
                </div>

                <div className="wm-sidebar__body">
                    <section className="wm-section">
                        <h4 className="wm-section__title">{t('watermark_text_content')}</h4>
                        <div className="wm-field">
                            <label>{t('watermark_main_line')}</label>
                            <input
                                type="text"
                                value={text1}
                                onChange={(e) => setText1(e.target.value)}
                                placeholder={t('watermark_main_line_placeholder')}
                            />
                        </div>
                        {['circular_badge', 'full_cross'].includes(activePattern) ? (
                            <div className="wm-field">
                                <label>
                                    {activePattern === 'full_cross'
                                        ? t('watermark_vertical_text')
                                        : t('watermark_secondary_line')}
                                </label>
                                <input
                                    type="text"
                                    value={text2}
                                    onChange={(e) => setText2(e.target.value)}
                                    placeholder={
                                        activePattern === 'full_cross'
                                            ? t('watermark_vertical_brand_placeholder')
                                            : t('watermark_secondary_line_placeholder')
                                    }
                                />
                            </div>
                        ) : null}
                    </section>

                    <section className="wm-section">
                        <h4 className="wm-section__title">{t('watermark_font')}</h4>
                        <div className="wm-field">
                            <label>{t('watermark_choose_font')}</label>
                            <SeoSelect value={selectedFont} onChange={(e) => setSelectedFont(e.target.value)} size="compact">
                                <optgroup label={t('watermark_system_fonts')}>
                                    {SYSTEM_FONTS.map((f) => (
                                        <option key={f} value={f}>
                                            {f}
                                        </option>
                                    ))}
                                </optgroup>
                                <optgroup label={t('watermark_google_fonts_auto')}>
                                    {GOOGLE_FONTS_DATA.map((f) => (
                                        <option key={f.family} value={f.family}>
                                            {f.family}
                                        </option>
                                    ))}
                                </optgroup>
                            </SeoSelect>
                            {fontStatus ? <p className="wm-font-loader__status">{fontStatus}</p> : null}
                        </div>
                    </section>

                    <section className="wm-section">
                        <GradientColorPicker
                            label={t('watermark_text_color')}
                            value={textColorConfig}
                            onChange={setTextColorConfig}
                        />
                    </section>

                    {activePattern === 'cta_button' ? (
                        <section className="wm-section">
                            <h4 className="wm-section__title">{t('watermark_cta_settings')}</h4>
                            <GradientColorPicker
                                label={t('watermark_button_background_color')}
                                value={bgColorConfig}
                                onChange={setBgColorConfig}
                            />
                            <div className="wm-field-row">
                                <div className="wm-field">
                                    <label>{t('watermark_border_radius')}</label>
                                    <PreciseControl
                                        min={0}
                                        max={8}
                                        step={0.05}
                                        value={btnRadiusPct}
                                        onChange={setBtnRadiusPct}
                                        suffix="%"
                                    />
                                </div>
                                <div className="wm-field">
                                    <label>{t('watermark_padding_x')}</label>
                                    <PreciseControl
                                        min={0}
                                        max={8}
                                        step={0.05}
                                        value={btnPaddingXPct}
                                        onChange={setBtnPaddingXPct}
                                        suffix="%"
                                    />
                                </div>
                            </div>
                            <div className="wm-field">
                                <label>{t('watermark_padding_y')}</label>
                                <PreciseControl
                                    min={0}
                                    max={6}
                                    step={0.05}
                                    value={btnPaddingYPct}
                                    onChange={setBtnPaddingYPct}
                                    suffix="%"
                                />
                            </div>
                            <div className="wm-field-row">
                                <div className="wm-field">
                                    <label>{t('watermark_icon')}</label>
                                    <SeoSelect
                                        value={selectedIcon}
                                        onChange={(e) => setSelectedIcon(e.target.value)}
                                        size="compact"
                                        options={WATERMARK_CTA_ICONS.map((icon) => ({
                                            value: icon.value,
                                            label: icon.label,
                                        }))}
                                    />
                                </div>
                                <div className="wm-field">
                                    <label>{t('watermark_icon_position')}</label>
                                    <SeoSelect
                                        value={iconPosition}
                                        onChange={(e) => setIconPosition(e.target.value)}
                                        disabled={selectedIcon === 'none'}
                                        size="compact"
                                        options={[
                                            { value: 'left', label: t('left') },
                                            { value: 'right', label: t('right') },
                                        ]}
                                    />
                                </div>
                            </div>
                            {selectedIcon === 'custom' ? (
                                <div className="wm-field wm-field--custom-svg">
                                    <label>{t('watermark_custom_svg_code')}</label>
                                    <textarea
                                        className="wm-custom-svg"
                                        rows={6}
                                        spellCheck={false}
                                        placeholder={t('watermark_custom_svg_placeholder')}
                                        value={customIconSvg}
                                        onChange={(e) => setCustomIconSvg(e.target.value)}
                                    />
                                    {customIconError ? (
                                        <p className="wm-hint wm-hint--error">{customIconError}</p>
                                    ) : (
                                        <p className="wm-hint">
                                            Icon dùng màu chữ CTA. Khuyến nghị SVG 24×24, nền trong suốt.
                                        </p>
                                    )}
                                </div>
                            ) : null}
                            <div className="wm-cta-borders">
                                <div className="wm-cta-borders__head">
                                    <span>Lớp viền chồng nhau</span>
                                    <button type="button" className="wm-btn wm-btn--primary wm-btn--sm" onClick={addBorder}>
                                        + Thêm viền
                                    </button>
                                </div>
                                <div className="wm-cta-borders__list">
                                    {borders.map((border, index) => (
                                        <div key={border.id} className="wm-cta-border-item">
                                            <div className="wm-cta-border-item__head">
                                                <span>Viền lớp #{index + 1}</span>
                                                {borders.length > 1 ? (
                                                    <button
                                                        type="button"
                                                        className="wm-cta-border-item__remove"
                                                        onClick={() => removeBorder(border.id)}
                                                    >
                                                        Xóa
                                                    </button>
                                                ) : null}
                                            </div>
                                            <div className="wm-field">
                                                <label>Độ dày viền</label>
                                                <PreciseControl
                                                    min={0.05}
                                                    max={1}
                                                    step={0.05}
                                                    value={border.widthPct}
                                                    onChange={(n) => updateBorder(border.id, 'widthPct', n)}
                                                    suffix="%"
                                                />
                                            </div>
                                            <GradientColorPicker
                                                label={t('watermark_border_color')}
                                                value={border.colorConfig}
                                                onChange={(val) => updateBorder(border.id, 'colorConfig', val)}
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>
                    ) : null}

                    {showBorderBgControls ? (
                        <section className="wm-section">
                            <h4 className="wm-section__title">{t('watermark_stamp_border_background')}</h4>
                            <div className="wm-field-row">
                                <div className="wm-field">
                                    <label>{t('watermark_border_color')}</label>
                                    <input
                                        type="color"
                                        value={borderColor}
                                        onChange={(e) => setBorderColor(e.target.value)}
                                    />
                                </div>
                                <div className="wm-field">
                                    <label>{t('watermark_border_thickness')}</label>
                                    <PreciseControl
                                        min={0.05}
                                        max={1}
                                        step={0.05}
                                        value={borderWidthPct}
                                        onChange={setBorderWidthPct}
                                        suffix="%"
                                    />
                                </div>
                            </div>
                            {showBgOpacityControls ? (
                                <div className="wm-field-row">
                                    <div className="wm-field">
                                        <label>{t('watermark_overlay_bg_color')}</label>
                                        <input
                                            type="color"
                                            value={backgroundColor}
                                            onChange={(e) => setBackgroundColor(e.target.value)}
                                        />
                                    </div>
                                    <div className="wm-field">
                                        <label>{t('watermark_bg_opacity')}</label>
                                        <PreciseControl
                                            min={0}
                                            max={100}
                                            step={5}
                                            value={Math.round(bgOpacity * 100)}
                                            onChange={(n) => setBgOpacity(n / 100)}
                                            suffix="%"
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </section>
                    ) : null}

                    {showPositionControls ? (
                        <section className="wm-section">
                            <h4 className="wm-section__title">{t('watermark_position')}</h4>
                            <div className="wm-field">
                                <label>{t('watermark_mode')}</label>
                                <SeoSelect
                                    value={positionType}
                                    onChange={(e) => setPositionType(e.target.value)}
                                    size="compact"
                                    options={[
                                        { value: 'anchor', label: t('watermark_anchor_offset_mode') },
                                        { value: 'preset', label: t('watermark_grid_9_mode') },
                                    ]}
                                />
                            </div>
                            {positionType === 'anchor' ? (
                                <>
                                    <div className="wm-field">
                                        <label>{t('watermark_anchor_corner')}</label>
                                        <SeoSelect
                                            value={positionAnchor}
                                            onChange={(e) => setPositionAnchor(e.target.value)}
                                            size="compact"
                                            options={WATERMARK_ANCHORS.map((a) => ({
                                                value: a.value,
                                                label: a.label,
                                            }))}
                                        />
                                    </div>
                                    <div className="wm-field">
                                        <label>
                                            {t('watermark_offset_x')}
                                            <span className="wm-label-hint">
                                                {positionAnchor.includes('right')
                                                    ? t('watermark_from_right_edge')
                                                    : positionAnchor.includes('left')
                                                      ? t('watermark_from_left_edge')
                                                      : t('watermark_from_horizontal_center')}
                                            </span>
                                        </label>
                                        <PreciseControl
                                            min={0}
                                            max={25}
                                            step={0.1}
                                            value={anchorOffsetPct.x}
                                            onChange={(x) =>
                                                setAnchorOffsetPct({ ...anchorOffsetPct, x })
                                            }
                                            suffix="%"
                                        />
                                    </div>
                                    <div className="wm-field">
                                        <label>
                                            {t('watermark_offset_y')}
                                            <span className="wm-label-hint">
                                                {positionAnchor.includes('bottom')
                                                    ? t('watermark_from_bottom_edge')
                                                    : positionAnchor.includes('top')
                                                      ? t('watermark_from_top_edge')
                                                      : t('watermark_from_vertical_center')}
                                            </span>
                                        </label>
                                        <PreciseControl
                                            min={0}
                                            max={25}
                                            step={0.1}
                                            value={anchorOffsetPct.y}
                                            onChange={(y) =>
                                                setAnchorOffsetPct({ ...anchorOffsetPct, y })
                                            }
                                            suffix="%"
                                        />
                                    </div>
                                    {activePattern === 'cta_button' ? (
                                        <p className="wm-hint">
                                            Kéo nút xanh trên ảnh hoặc nhập offset — ví dụ góc dưới-phải:
                                            X=20, Y=20.
                                        </p>
                                    ) : null}
                                </>
                            ) : positionType === 'preset' ? (
                                <>
                                    <div className="wm-grid-9">
                                        {PRESET_GRID.map((pos) => (
                                            <button
                                                key={pos}
                                                type="button"
                                                className={presetPos === pos ? 'is-active' : ''}
                                                onClick={() => setPresetPos(pos)}
                                                title={pos}
                                            />
                                        ))}
                                    </div>
                                    <div className="wm-field">
                                        <label>{t('watermark_margin')}</label>
                                        <PreciseControl
                                            min={0.5}
                                            max={12}
                                            step={0.1}
                                            value={marginPct}
                                            onChange={setMarginPct}
                                            suffix="%"
                                        />
                                    </div>
                                </>
                            ) : null}
                        </section>
                    ) : null}

                    <section className="wm-section">
                        <h4 className="wm-section__title">{t('watermark_effects')}</h4>
                        <div className="wm-field">
                            <label>{t('watermark_font_size')}</label>
                            <PreciseControl
                                min={0.5}
                                max={5}
                                step={0.05}
                                value={textSizePct}
                                onChange={setTextSizePct}
                                suffix="%"
                            />
                        </div>
                        {['classic_grid', 'full_cross', 'cta_button'].includes(activePattern) ? (
                            <div className="wm-field">
                                <label>
                                    {activePattern === 'cta_button'
                                        ? t('watermark_align_spacing')
                                        : activePattern === 'full_cross'
                                          ? t('watermark_spacing_from_margin')
                                          : t('watermark_repeat_spacing')}
                                </label>
                                <PreciseControl
                                    min={activePattern === 'classic_grid' ? 2 : 1}
                                    max={15}
                                    step={0.1}
                                    value={gridSpacingPct}
                                    onChange={setGridSpacingPct}
                                    suffix="%"
                                />
                            </div>
                        ) : null}
                        <div className="wm-field">
                            <label>{t('watermark_opacity')}</label>
                            <PreciseControl
                                min={10}
                                max={100}
                                step={5}
                                value={Math.round(opacity * 100)}
                                onChange={(n) => setOpacity(n / 100)}
                                suffix="%"
                            />
                        </div>
                        {!['minimal_frame', 'full_cross'].includes(activePattern) ? (
                            <div className="wm-field">
                                <label>{t('watermark_rotation')}</label>
                                <PreciseControl
                                    min={-180}
                                    max={180}
                                    step={1}
                                    value={rotation}
                                    onChange={setRotation}
                                    suffix="°"
                                />
                            </div>
                        ) : null}
                    </section>
                </div>
            </aside>

            <WatermarkMediaPicker
                open={pickerOpen}
                samples={mediaSamples}
                onSelect={(item) => setSampleUrl(item.url)}
                onClose={() => setPickerOpen(false)}
            />
        </div>
    );
}
