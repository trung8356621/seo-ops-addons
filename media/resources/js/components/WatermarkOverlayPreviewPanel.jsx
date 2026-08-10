import React, { useEffect, useMemo, useRef, useState } from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import { OVERLAY_EXPORT_MAX, resolveBestVariantKey } from './overlayRatioPresets';

/**
 * @typedef {{ key: string, label: string, url: string, width: number, height: number }} OverlayPreviewItem
 */

/**
 * @param {{ variants: OverlayPreviewItem[], sampleImageUrl?: string|null }} props
 */
export default function WatermarkOverlayPreviewPanel({ variants = [], sampleImageUrl = null }) {
    const [activeKey, setActiveKey] = useState(variants[0]?.key ?? '');
    const [viewMode, setViewMode] = useState('composite');
    const [zoom, setZoom] = useState('fit');
    const [sampleImg, setSampleImg] = useState(null);

    useEffect(() => {
        if (variants.length === 0) {
            setActiveKey('');
            return;
        }
        if (!variants.some((v) => v.key === activeKey)) {
            setActiveKey(variants[0].key);
        }
    }, [variants, activeKey]);

    useEffect(() => {
        if (!sampleImageUrl) {
            setSampleImg(null);
            return undefined;
        }

        let cancelled = false;
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            if (!cancelled) {
                setSampleImg(img);
            }
        };
        img.onerror = () => {
            if (!cancelled) {
                setSampleImg(null);
            }
        };
        img.src = sampleImageUrl;

        return () => {
            cancelled = true;
        };
    }, [sampleImageUrl]);

    const bestKey = useMemo(() => {
        if (!sampleImg) {
            return null;
        }
        return resolveBestVariantKey(
            sampleImg.naturalWidth,
            sampleImg.naturalHeight,
            variants,
        );
    }, [sampleImg, variants]);

    const active = useMemo(
        () => variants.find((v) => v.key === activeKey) ?? variants[0] ?? null,
        [variants, activeKey],
    );

    const compositeVariant = useMemo(() => {
        if (!sampleImg || !bestKey) {
            return active;
        }
        return variants.find((v) => v.key === bestKey) ?? active;
    }, [sampleImg, bestKey, active, variants]);

    const displayVariant = viewMode === 'composite' && sampleImg ? compositeVariant : active;

    const ratioMismatch =
        viewMode === 'composite' &&
        sampleImg &&
        bestKey &&
        activeKey &&
        activeKey !== bestKey;

    if (variants.length === 0) {
        return (
            <div className="wm-overlay-preview wm-overlay-preview--empty">
                <p>Chưa có overlay đã lưu.</p>
                <p className="wm-hint">
                    Bấm <strong>Lưu cấu hình</strong> để xuất 8 file PNG (~{OVERLAY_EXPORT_MAX}px cạnh dài) theo
                    tỉ lệ, rồi xem lại tại đây.
                </p>
            </div>
        );
    }

    return (
        <div className="wm-overlay-preview">
            <div className="wm-overlay-preview__toolbar">
                <div className="wm-overlay-preview__ratios">
                    {variants.map((v) => (
                        <button
                            key={v.key}
                            type="button"
                            className={[
                                v.key === activeKey ? 'is-active' : '',
                                viewMode === 'composite' && v.key === bestKey ? 'is-best-match' : '',
                            ]
                                .filter(Boolean)
                                .join(' ')}
                            onClick={() => setActiveKey(v.key)}
                            title={v.label}
                        >
                            {v.key}
                        </button>
                    ))}
                </div>
                <div className="wm-overlay-preview__controls">
                    <label>
                        <span>Xem</span>
                        <SeoSelect
                            value={viewMode}
                            onChange={(e) => setViewMode(e.target.value)}
                            size="compact"
                            options={[
                                { value: 'composite', label: 'Ghép lên ảnh mẫu' },
                                { value: 'overlay', label: 'Chỉ overlay (nền caro)' },
                            ]}
                        />
                    </label>
                    <label>
                        <span>Thu phóng</span>
                        <SeoSelect
                            value={zoom}
                            onChange={(e) => setZoom(e.target.value)}
                            size="compact"
                            options={[
                                { value: 'fit', label: 'Vừa khung' },
                                { value: '50', label: '50% kích thước thật' },
                                { value: '100', label: '100% (1:1 pixel)' },
                            ]}
                        />
                    </label>
                </div>
            </div>

            {displayVariant ? (
                <p className="wm-overlay-preview__meta">
                    <strong>{displayVariant.label}</strong>
                    {' · '}
                    {displayVariant.width}×{displayVariant.height}px
                    {viewMode === 'composite' && sampleImg ? (
                        <>
                            {' · '}
                            ảnh mẫu {sampleImg.naturalWidth}×{sampleImg.naturalHeight}px
                            {bestKey ? (
                                <>
                                    {' · '}
                                    <span className="wm-overlay-preview__match">
                                        Ghép dùng <strong>{bestKey}</strong>
                                    </span>
                                </>
                            ) : null}
                        </>
                    ) : null}
                </p>
            ) : null}

            {ratioMismatch && compositeVariant ? (
                <p className="wm-hint wm-hint--warn">
                    Tab <strong>{activeKey}</strong> không khớp tỉ lệ ảnh mẫu — chế độ ghép tự dùng{' '}
                    <strong>{compositeVariant.key}</strong> (giống khi đóng dấu ảnh thật). Chọn tab khớp hoặc xem
                    「Chỉ overlay」 cho {activeKey}.
                </p>
            ) : null}

            <div className="wm-overlay-preview__stage">
                {displayVariant ? (
                    viewMode === 'composite' && sampleImg ? (
                        <CompositeOverlayPreview
                            overlayUrl={displayVariant.url}
                            sampleImage={sampleImg}
                            zoom={zoom}
                        />
                    ) : (
                        <OverlayOnlyPreview variant={displayVariant} zoom={zoom} />
                    )
                ) : null}
            </div>

            <p className="wm-hint">
                Khi đóng dấu, hệ thống chọn overlay có tỉ lệ gần ảnh nhất rồi scale full khung — preview ghép làm
                tương tự để không bị méo watermark.
            </p>
        </div>
    );
}

/**
 * @param {{ variant: OverlayPreviewItem, zoom: string }} props
 */
function OverlayOnlyPreview({ variant, zoom }) {
    const wrapStyle = previewWrapStyle(variant.width, variant.height, zoom);

    return (
        <div className="wm-overlay-preview__checker" style={wrapStyle}>
            <img
                src={variant.url}
                alt={variant.label}
                width={variant.width}
                height={variant.height}
                draggable={false}
                style={{ display: 'block', width: '100%', height: '100%' }}
            />
        </div>
    );
}

/**
 * @param {number} w
 * @param {number} h
 * @param {string} zoom
 */
function previewWrapStyle(w, h, zoom) {
    if (zoom === '50') {
        return {
            width: `${w / 2}px`,
            height: `${h / 2}px`,
            aspectRatio: `${w} / ${h}`,
        };
    }
    if (zoom === '100') {
        return {
            width: `${w}px`,
            height: `${h}px`,
            aspectRatio: `${w} / ${h}`,
        };
    }

    return {
        maxWidth: '100%',
        maxHeight: '100%',
        width: 'auto',
        height: 'auto',
        aspectRatio: `${w} / ${h}`,
    };
}

/**
 * @param {{ overlayUrl: string, sampleImage: HTMLImageElement, zoom: string }} props
 */
function CompositeOverlayPreview({ overlayUrl, sampleImage, zoom }) {
    const canvasRef = useRef(null);
    const [overlayImg, setOverlayImg] = useState(null);

    useEffect(() => {
        let cancelled = false;
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            if (!cancelled) {
                setOverlayImg(img);
            }
        };
        img.src = overlayUrl;

        return () => {
            cancelled = true;
        };
    }, [overlayUrl]);

    const w = sampleImage.naturalWidth;
    const h = sampleImage.naturalHeight;

    useEffect(() => {
        const node = canvasRef.current;
        if (!node || !overlayImg) {
            return;
        }
        const ctx = node.getContext('2d');
        if (!ctx) {
            return;
        }
        ctx.clearRect(0, 0, w, h);
        ctx.drawImage(sampleImage, 0, 0, w, h);
        ctx.drawImage(overlayImg, 0, 0, w, h);
    }, [overlayImg, sampleImage, w, h]);

    if (!overlayImg) {
        return <p className="wm-hint">Đang tải overlay…</p>;
    }

    const wrapStyle = previewWrapStyle(w, h, zoom);

    return (
        <div className="wm-overlay-preview__composite-wrap" style={wrapStyle}>
            <canvas
                ref={canvasRef}
                className="wm-overlay-preview__composite-canvas"
                width={w}
                height={h}
                style={{ width: '100%', height: '100%', display: 'block' }}
            />
        </div>
    );
}
