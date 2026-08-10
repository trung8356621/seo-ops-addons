import React, { useCallback, useEffect, useRef, useState } from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import { saveNewWatermarkedImage, saveWatermarkedMedia } from '../utils/watermarkApi';
import { t } from '@content-addon/utils/i18n.js';

const POSITIONS = [
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

function computeCoords(canvas, position, boxW, boxH, padding = 24) {
    const w = canvas.width;
    const h = canvas.height;

    switch (position) {
        case 'top-left':
            return { x: padding, y: padding };
        case 'top-center':
            return { x: (w - boxW) / 2, y: padding };
        case 'top-right':
            return { x: w - boxW - padding, y: padding };
        case 'center-left':
            return { x: padding, y: (h - boxH) / 2 };
        case 'center':
            return { x: (w - boxW) / 2, y: (h - boxH) / 2 };
        case 'center-right':
            return { x: w - boxW - padding, y: (h - boxH) / 2 };
        case 'bottom-left':
            return { x: padding, y: h - boxH - padding };
        case 'bottom-center':
            return { x: (w - boxW) / 2, y: h - boxH - padding };
        case 'bottom-right':
        default:
            return { x: w - boxW - padding, y: h - boxH - padding };
    }
}

export default function ImageWatermarkEditor({
    imageUrl,
    imageId = null,
    siteId = null,
    initialSettings = null,
    onClose,
    onSaveSuccess,
}) {
    const canvasRef = useRef(null);
    const [imageObj, setImageObj] = useState(null);
    const [loadError, setLoadError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saveMode, setSaveMode] = useState(imageId ? 'overwrite' : 'new');

    const [watermarkType, setWatermarkType] = useState(initialSettings?.type === 'image' ? 'image' : 'text');
    const [text, setText] = useState(initialSettings?.text_content || t('watermark_copyright_text'));
    const [textColor, setTextColor] = useState(initialSettings?.text_color || '#ffffff');
    const [textSize, setTextSize] = useState(initialSettings?.text_size || 24);
    const [opacity, setOpacity] = useState(initialSettings?.opacity ?? 0.7);
    const [position, setPosition] = useState(initialSettings?.position || 'bottom-right');
    const [logoUrl, setLogoUrl] = useState(initialSettings?.logo_url || null);
    const [logoObj, setLogoObj] = useState(null);
    const [logoScale, setLogoScale] = useState(initialSettings?.logo_width_pct || 20);

    useEffect(() => {
        if (initialSettings?.type === 'image' && initialSettings?.logo_url) {
            const logoImg = new Image();
            logoImg.crossOrigin = 'anonymous';
            logoImg.src = initialSettings.logo_url;
            logoImg.onload = () => setLogoObj(logoImg);
        }
    }, [initialSettings]);

    useEffect(() => {
        setLoadError(null);
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = imageUrl;
        img.onload = () => setImageObj(img);
        img.onerror = () => setLoadError(t('watermark_load_image_invalid'));
    }, [imageUrl]);

    const handleLogoUpload = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            setLogoUrl(event.target.result);
            const logoImg = new Image();
            logoImg.src = event.target.result;
            logoImg.onload = () => setLogoObj(logoImg);
        };
        reader.readAsDataURL(file);
    };

    const drawPreview = useCallback(() => {
        if (!imageObj || !canvasRef.current) return;

        const canvas = canvasRef.current;
        const ctx = canvas.getContext('2d');
        const maxPreview = 900;
        const scale = Math.min(1, maxPreview / imageObj.width, maxPreview / imageObj.height);
        canvas.width = Math.round(imageObj.width * scale);
        canvas.height = Math.round(imageObj.height * scale);

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(imageObj, 0, 0, canvas.width, canvas.height);
        ctx.globalAlpha = opacity;

        if (watermarkType === 'text' && text.trim()) {
            ctx.font = `bold ${textSize * scale}px Arial, sans-serif`;
            ctx.fillStyle = textColor;
            const metrics = ctx.measureText(text);
            const boxW = metrics.width;
            const boxH = textSize * scale;
            const { x, y } = computeCoords(canvas, position, boxW, boxH, 16 * scale);
            ctx.shadowColor = 'rgba(0,0,0,0.45)';
            ctx.shadowBlur = 4;
            ctx.fillText(text, x, y + boxH * 0.8);
            ctx.shadowBlur = 0;
        } else if (watermarkType === 'image' && logoObj) {
            const targetWidth = canvas.width * (logoScale / 100);
            const logoScaleFactor = targetWidth / logoObj.width;
            const targetHeight = logoObj.height * logoScaleFactor;
            const { x, y } = computeCoords(canvas, position, targetWidth, targetHeight, 16 * scale);
            ctx.drawImage(logoObj, x, y, targetWidth, targetHeight);
        }

        ctx.globalAlpha = 1;
    }, [imageObj, watermarkType, text, textColor, textSize, opacity, position, logoObj, logoScale]);

    useEffect(() => {
        drawPreview();
    }, [drawPreview]);

    const exportFullResolutionBlob = () =>
        new Promise((resolve, reject) => {
            if (!imageObj) {
                reject(new Error(t('watermark_missing_source_image')));
                return;
            }

            const exportCanvas = document.createElement('canvas');
            exportCanvas.width = imageObj.width;
            exportCanvas.height = imageObj.height;
            const ctx = exportCanvas.getContext('2d');
            ctx.drawImage(imageObj, 0, 0);
            ctx.globalAlpha = opacity;

            if (watermarkType === 'text' && text.trim()) {
                ctx.font = `bold ${textSize}px Arial, sans-serif`;
                ctx.fillStyle = textColor;
                const metrics = ctx.measureText(text);
                const boxW = metrics.width;
                const boxH = textSize;
                const { x, y } = computeCoords(exportCanvas, position, boxW, boxH);
                ctx.shadowColor = 'rgba(0,0,0,0.45)';
                ctx.shadowBlur = 6;
                ctx.fillText(text, x, y + boxH * 0.85);
            } else if (watermarkType === 'image' && logoObj) {
                const targetWidth = exportCanvas.width * (logoScale / 100);
                const logoScaleFactor = targetWidth / logoObj.width;
                const targetHeight = logoObj.height * logoScaleFactor;
                const { x, y } = computeCoords(exportCanvas, position, targetWidth, targetHeight);
                ctx.drawImage(logoObj, x, y, targetWidth, targetHeight);
            }

            exportCanvas.toBlob(
                (blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error(t('watermark_export_failed')));
                },
                'image/png',
                0.92,
            );
        });

    const handleSave = async () => {
        if (!siteId) return;

        setSaving(true);
        try {
            const blob = await exportFullResolutionBlob();
            let result;

            if (imageId && saveMode === 'overwrite') {
                result = await saveWatermarkedMedia(imageId, blob, 'overwrite');
            } else if (imageId && saveMode === 'new') {
                result = await saveWatermarkedMedia(imageId, blob, 'new');
            } else {
                result = await saveNewWatermarkedImage(siteId, blob);
            }

            onSaveSuccess?.(result);
            onClose?.();
        } catch (error) {
            window.alert(error?.message ?? t('watermark_save_failed'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="seo-watermark-editor-backdrop" role="dialog" aria-modal="true">
            <div className="seo-watermark-editor">
                <div className="seo-watermark-editor__preview">
                    {loadError ? (
                        <p className="seo-watermark-editor__error">{loadError}</p>
                    ) : (
                        <canvas ref={canvasRef} className="seo-watermark-editor__canvas" />
                    )}
                </div>

                <aside className="seo-watermark-editor__sidebar">
                    <h3 className="seo-watermark-editor__title">{t('watermark_editor_title')}</h3>

                    <label className="seo-watermark-editor__label">{t('watermark_type')}</label>
                    <div className="seo-watermark-editor__type-row">
                        <button
                            type="button"
                            className={watermarkType === 'text' ? 'is-active' : ''}
                            onClick={() => setWatermarkType('text')}
                        >
                            {t('watermark_text')}
                        </button>
                        <button
                            type="button"
                            className={watermarkType === 'image' ? 'is-active' : ''}
                            onClick={() => setWatermarkType('image')}
                        >
                            {t('watermark_logo')}
                        </button>
                    </div>

                    {watermarkType === 'text' ? (
                        <div className="seo-watermark-editor__fields">
                            <label className="seo-watermark-editor__label">{t('watermark_content')}</label>
                            <input
                                type="text"
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                className="seo-watermark-editor__input"
                            />
                            <div className="seo-watermark-editor__row-2">
                                <div>
                                    <label className="seo-watermark-editor__label">{t('watermark_color')}</label>
                                    <input
                                        type="color"
                                        value={textColor}
                                        onChange={(e) => setTextColor(e.target.value)}
                                        className="seo-watermark-editor__color"
                                    />
                                </div>
                                <div>
                                    <label className="seo-watermark-editor__label">{t('watermark_font_size')}</label>
                                    <input
                                        type="number"
                                        min={8}
                                        max={120}
                                        value={textSize}
                                        onChange={(e) => setTextSize(parseInt(e.target.value, 10) || 12)}
                                        className="seo-watermark-editor__input"
                                    />
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="seo-watermark-editor__fields">
                            <label className="seo-watermark-editor__label">{t('watermark_logo_file')}</label>
                            <input type="file" accept="image/*" onChange={handleLogoUpload} />
                            {logoUrl ? (
                                <img src={logoUrl} alt="" className="seo-watermark-editor__logo-preview" />
                            ) : null}
                            <label className="seo-watermark-editor__label">
                                {t('watermark_logo_size')}: {logoScale}%
                            </label>
                            <input
                                type="range"
                                min={5}
                                max={50}
                                value={logoScale}
                                onChange={(e) => setLogoScale(parseInt(e.target.value, 10))}
                            />
                        </div>
                    )}

                    <label className="seo-watermark-editor__label">{t('watermark_position')}</label>
                    <SeoSelect
                        value={position}
                        onChange={(e) => setPosition(e.target.value)}
                        options={POSITIONS.map((pos) => ({ value: pos, label: pos }))}
                    />

                    <label className="seo-watermark-editor__label">{t('watermark_opacity')}: {opacity}</label>
                    <input
                        type="range"
                        min={0.1}
                        max={1}
                        step={0.05}
                        value={opacity}
                        onChange={(e) => setOpacity(parseFloat(e.target.value))}
                    />

                    {imageId ? (
                        <>
                            <label className="seo-watermark-editor__label">{t('watermark_save_mode')}</label>
                            <SeoSelect
                                value={saveMode}
                                onChange={(e) => setSaveMode(e.target.value)}
                                options={[
                                    { value: 'overwrite', label: t('watermark_save_overwrite') },
                                    { value: 'new', label: t('watermark_save_new') },
                                ]}
                            />
                        </>
                    ) : (
                        <p className="seo-watermark-editor__hint">
                            {t('watermark_wp_copy_hint')}
                        </p>
                    )}

                    <div className="seo-watermark-editor__actions">
                        <button type="button" className="seo-watermark-editor__btn" onClick={onClose}>
                            {t('cancel')}
                        </button>
                        <button
                            type="button"
                            className="seo-watermark-editor__btn is-primary"
                            disabled={saving || loadError}
                            onClick={handleSave}
                        >
                            {saving ? t('watermark_saving') : t('watermark_save_result')}
                        </button>
                    </div>
                </aside>
            </div>
        </div>
    );
}
