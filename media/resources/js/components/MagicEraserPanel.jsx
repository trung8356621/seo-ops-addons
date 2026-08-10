import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
    Brush,
    Circle,
    Eraser,
    Maximize2,
    PaintBucket,
    Pentagon,
    Pipette,
    Redo2,
    Save,
    Square,
    Undo2,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { saveEditedSeoMedia } from '../utils/seoMediaApi';
import {
    MAGIC_ERASER_SHORTCUT_GROUPS,
    TOOL_SHORTCUT_LABELS,
    toolFromKeyboardEvent,
} from './magicEraserShortcuts';
import { MagicEraserShortcutsPanel, MagicEraserToolbarButton } from './MagicEraserToolbar';
import { createPieceFromSelection } from '../utils/imageSelectionUtils';
import { t } from '@content-addon/utils/i18n.js';

const TOOL_BRUSH = 'brush';
const TOOL_EYEDROPPER = 'eyedropper';
const TOOL_RECT = 'rect';
const TOOL_ELLIPSE = 'ellipse';
const TOOL_POLYGON = 'polygon';

const ZOOM_MIN = 0.05;
const ZOOM_MAX = 16;
const ZOOM_STEP = 0.15;
const MASK_FILL = 'rgba(239, 68, 68, 0.55)';
const MASK_STROKE = 'rgba(239, 68, 68, 0.9)';

function resolveImageUrl(url) {
    if (!url || typeof url !== 'string') {
        return '';
    }
    const trimmed = url.trim();
    if (/^https?:\/\//i.test(trimmed)) {
        return trimmed;
    }
    const path = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;

    return `${window.location.origin}${path}`;
}

function isTypingTarget(target) {
    if (!target) {
        return false;
    }
    const tag = target.tagName?.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
}

function maskHasPixels(canvas) {
    if (!canvas) {
        return false;
    }
    const ctx = canvas.getContext('2d');
    const { width, height } = canvas;
    if (width === 0 || height === 0) {
        return false;
    }
    const data = ctx.getImageData(0, 0, width, height).data;
    for (let i = 3; i < data.length; i += 4) {
        if (data[i] > 0) {
            return true;
        }
    }

    return false;
}

function loadImageToCanvas(canvas, dataUrl) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0);
            resolve();
        };
        img.src = dataUrl;
    });
}

function drawShapePath(ctx, type, x0, y0, x1, y1) {
    const left = Math.min(x0, x1);
    const top = Math.min(y0, y1);
    const w = Math.abs(x1 - x0);
    const h = Math.abs(y1 - y0);

    ctx.beginPath();
    if (type === TOOL_RECT) {
        ctx.rect(left, top, w, h);
    } else {
        const cx = left + w / 2;
        const cy = top + h / 2;
        ctx.ellipse(cx, cy, Math.max(w / 2, 0.5), Math.max(h / 2, 0.5), 0, 0, Math.PI * 2);
    }
}

function paintShapeOnMask(ctx, type, x0, y0, x1, y1) {
    drawShapePath(ctx, type, x0, y0, x1, y1);
    ctx.fillStyle = MASK_FILL;
    ctx.fill();
    ctx.strokeStyle = MASK_STROKE;
    ctx.lineWidth = 1;
    ctx.stroke();
}

function paintPolygonOnMask(ctx, points) {
    if (points.length < 3) {
        return;
    }
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i++) {
        ctx.lineTo(points[i].x, points[i].y);
    }
    ctx.closePath();
    ctx.fillStyle = MASK_FILL;
    ctx.fill();
    ctx.strokeStyle = MASK_STROKE;
    ctx.lineWidth = 1;
    ctx.stroke();
}

export default function MagicEraserPanel({
    imageUrl,
    imageId,
    onSave,
    onClose,
    onRequestSplit,
}) {
    const imageCanvasRef = useRef(null);
    const maskCanvasRef = useRef(null);
    const previewCanvasRef = useRef(null);
    const canvasAreaRef = useRef(null);
    const historyStepRef = useRef(-1);
    const drewThisStrokeRef = useRef(false);
    const shapeStartRef = useRef(null);
    const panStartRef = useRef(null);
    const panRef = useRef({ x: 0, y: 0 });
    const spaceHeldRef = useRef(false);
    const isPanningRef = useRef(false);

    const [imageObj, setImageObj] = useState(null);
    const [isDrawing, setIsDrawing] = useState(false);
    const [hasDrawn, setHasDrawn] = useState(false);
    const [activeTool, setActiveTool] = useState(TOOL_BRUSH);
    const [polygonPoints, setPolygonPoints] = useState([]);
    const [polygonHover, setPolygonHover] = useState(null);
    const [shapePreview, setShapePreview] = useState(null);

    const [brushSize, setBrushSize] = useState(30);
    const [fillColor, setFillColor] = useState('#ffffff');
    const [isProcessing, setIsProcessing] = useState(false);

    const [history, setHistory] = useState([]);
    const [historyStep, setHistoryStep] = useState(-1);
    const [zoom, setZoom] = useState(1);
    const [pan, setPan] = useState({ x: 0, y: 0 });
    const [spaceHeld, setSpaceHeld] = useState(false);
    const [isPanning, setIsPanning] = useState(false);

    const resolvedUrl = resolveImageUrl(imageUrl);
    const isPickingColor = activeTool === TOOL_EYEDROPPER;
    const isHandMode = spaceHeld || isPanning;

    const syncHasDrawnFromMask = useCallback(() => {
        setHasDrawn(maskHasPixels(maskCanvasRef.current));
    }, []);

    const captureSnapshot = useCallback(() => {
        const imgCanvas = imageCanvasRef.current;
        const mskCanvas = maskCanvasRef.current;
        if (!imgCanvas || !mskCanvas) {
            return null;
        }

        return {
            image: imgCanvas.toDataURL('image/png'),
            mask: mskCanvas.toDataURL('image/png'),
        };
    }, []);

    const pushHistory = useCallback(() => {
        const snapshot = captureSnapshot();
        if (!snapshot) {
            return;
        }

        setHistory((prevHistory) => {
            const step = historyStepRef.current;
            const newHistory = prevHistory.slice(0, step + 1);
            newHistory.push(snapshot);
            const newStep = newHistory.length - 1;
            historyStepRef.current = newStep;
            setHistoryStep(newStep);

            return newHistory;
        });
    }, [captureSnapshot]);

    const restoreSnapshot = useCallback(
        async (snapshot) => {
            if (!snapshot) {
                return;
            }
            await loadImageToCanvas(imageCanvasRef.current, snapshot.image);
            await loadImageToCanvas(maskCanvasRef.current, snapshot.mask);
            syncHasDrawnFromMask();
            setPolygonPoints([]);
            setShapePreview(null);
        },
        [syncHasDrawnFromMask],
    );

    const clearPreviewCanvas = useCallback(() => {
        const preview = previewCanvasRef.current;
        if (!preview) {
            return;
        }
        const ctx = preview.getContext('2d');
        ctx.clearRect(0, 0, preview.width, preview.height);
    }, []);

    const redrawPolygonPreview = useCallback(
        (points, hover = null) => {
            const preview = previewCanvasRef.current;
            if (!preview) {
                return;
            }
            const ctx = preview.getContext('2d');
            ctx.clearRect(0, 0, preview.width, preview.height);
            if (points.length === 0) {
                return;
            }

            ctx.strokeStyle = MASK_STROKE;
            ctx.fillStyle = MASK_FILL;
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 4]);

            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                ctx.lineTo(points[i].x, points[i].y);
            }
            if (hover) {
                ctx.lineTo(hover.x, hover.y);
            }
            if (points.length >= 3 && !hover) {
                ctx.closePath();
                ctx.fill();
            }
            ctx.stroke();
            ctx.setLineDash([]);

            points.forEach((p, i) => {
                ctx.beginPath();
                ctx.arc(p.x, p.y, i === 0 ? 5 : 4, 0, Math.PI * 2);
                ctx.fillStyle = i === 0 ? '#fff' : MASK_STROKE;
                ctx.fill();
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 1;
                ctx.stroke();
            });
        },
        [],
    );

    const handleClearMask = useCallback(
        (recordHistory = true) => {
            const canvas = maskCanvasRef.current;
            if (!canvas) {
                return;
            }
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            setHasDrawn(false);
            setPolygonPoints([]);
            clearPreviewCanvas();
            setShapePreview(null);
            if (recordHistory) {
                pushHistory();
            }
        },
        [clearPreviewCanvas, pushHistory],
    );

    /** Chỉ giữ một vùng chọn — xóa mask cũ trước khi vẽ vùng mới (không ghi history). */
    const clearMaskForNewSelection = useCallback(() => {
        handleClearMask(false);
    }, [handleClearMask]);

    const handleSplitFromSelection = useCallback(async () => {
        const imgCanvas = imageCanvasRef.current;
        const maskCanvas = maskCanvasRef.current;

        if (!imgCanvas || !maskCanvas || !maskHasPixels(maskCanvas)) {
            return;
        }

        setIsProcessing(true);

        try {
            const baseName =
                imageUrl?.split('/').pop()?.replace(/\?.*$/, '').replace(/\.[^.]+$/, '') || 'image';
            const piece = await createPieceFromSelection(imgCanvas, maskCanvas, baseName);
            if (typeof onRequestSplit === 'function') {
                onRequestSplit({ pieces: [piece] });
            }
        } catch (err) {
            console.error(err);
            alert(err?.message ?? t('magic_split_selection_failed'));
        } finally {
            setIsProcessing(false);
        }
    }, [imageUrl, onRequestSplit]);

    const handleUndo = useCallback(() => {
        if (historyStepRef.current <= 0) {
            return;
        }
        const prevStep = historyStepRef.current - 1;
        setHistory((prev) => {
            const snapshot = prev[prevStep];
            if (snapshot) {
                restoreSnapshot(snapshot);
            }
            historyStepRef.current = prevStep;
            setHistoryStep(prevStep);

            return prev;
        });
    }, [restoreSnapshot]);

    const handleRedo = useCallback(() => {
        setHistory((prev) => {
            if (historyStepRef.current >= prev.length - 1) {
                return prev;
            }
            const nextStep = historyStepRef.current + 1;
            const snapshot = prev[nextStep];
            if (snapshot) {
                restoreSnapshot(snapshot);
            }
            historyStepRef.current = nextStep;
            setHistoryStep(nextStep);

            return prev;
        });
    }, [restoreSnapshot]);

    const centerImage = useCallback(
        (nextZoom) => {
            if (!imageObj || !canvasAreaRef.current) {
                return;
            }
            const area = canvasAreaRef.current;
            const w = imageObj.width * nextZoom;
            const h = imageObj.height * nextZoom;
            const nextPan = {
                x: (area.clientWidth - w) / 2,
                y: (area.clientHeight - h) / 2,
            };
            panRef.current = nextPan;
            setPan(nextPan);
        },
        [imageObj],
    );

    const fitZoomToView = useCallback(() => {
        if (!imageObj || !canvasAreaRef.current) {
            return;
        }
        const area = canvasAreaRef.current;
        const padding = 32;
        const fit = Math.min(
            (area.clientWidth - padding) / imageObj.width,
            (area.clientHeight - padding) / imageObj.height,
            1,
        );
        const nextZoom = Math.max(ZOOM_MIN, fit);
        setZoom(nextZoom);
        requestAnimationFrame(() => centerImage(nextZoom));
    }, [centerImage, imageObj]);

    const zoomAtPoint = useCallback(
        (clientX, clientY, delta) => {
            if (!canvasAreaRef.current || !imageObj) {
                return;
            }
            const areaRect = canvasAreaRef.current.getBoundingClientRect();
            const mx = clientX - areaRect.left;
            const my = clientY - areaRect.top;
            const p = panRef.current;

            setZoom((prevZoom) => {
                const nextZoom = Math.max(
                    ZOOM_MIN,
                    Math.min(ZOOM_MAX, Math.round((prevZoom + delta) * 100) / 100),
                );
                const ratio = nextZoom / prevZoom;
                const imgX = (mx - p.x) / prevZoom;
                const imgY = (my - p.y) / prevZoom;
                const nextPan = {
                    x: mx - imgX * nextZoom,
                    y: my - imgY * nextZoom,
                };
                panRef.current = nextPan;
                setPan(nextPan);

                return nextZoom;
            });
        },
        [imageObj],
    );

    const changeZoom = useCallback(
        (delta, clientX, clientY) => {
            if (clientX != null && clientY != null) {
                zoomAtPoint(clientX, clientY, delta);

                return;
            }
            setZoom((z) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, Math.round((z + delta) * 100) / 100)));
        },
        [zoomAtPoint],
    );

    const commitPolygon = useCallback(() => {
        if (polygonPoints.length < 3) {
            return;
        }
        const ctx = maskCanvasRef.current?.getContext('2d');
        if (!ctx) {
            return;
        }
        paintPolygonOnMask(ctx, polygonPoints);
        setPolygonPoints([]);
        setPolygonHover(null);
        clearPreviewCanvas();
        setHasDrawn(true);
        pushHistory();
    }, [clearPreviewCanvas, polygonPoints, pushHistory]);

    useEffect(() => {
        panRef.current = pan;
    }, [pan]);

    useEffect(() => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = resolvedUrl;
        img.onload = () => setImageObj(img);
        img.onerror = () => {
            alert(t('magic_load_image_failed'));
        };
    }, [resolvedUrl]);

    useEffect(() => {
        if (!imageObj || !imageCanvasRef.current || !maskCanvasRef.current || !previewCanvasRef.current) {
            return;
        }

        const imgCanvas = imageCanvasRef.current;
        const mskCanvas = maskCanvasRef.current;
        const preview = previewCanvasRef.current;
        const imgCtx = imgCanvas.getContext('2d');
        const mskCtx = mskCanvas.getContext('2d');

        imgCanvas.width = imageObj.width;
        imgCanvas.height = imageObj.height;
        mskCanvas.width = imageObj.width;
        mskCanvas.height = imageObj.height;
        preview.width = imageObj.width;
        preview.height = imageObj.height;

        imgCtx.drawImage(imageObj, 0, 0);

        mskCtx.lineCap = 'round';
        mskCtx.lineJoin = 'round';
        mskCtx.clearRect(0, 0, mskCanvas.width, mskCanvas.height);

        const snapshot = {
            image: imgCanvas.toDataURL('image/png'),
            mask: mskCanvas.toDataURL('image/png'),
        };
        setHistory([snapshot]);
        historyStepRef.current = 0;
        setHistoryStep(0);
        setHasDrawn(false);
        setPolygonPoints([]);
        setZoom(1);
        panRef.current = { x: 0, y: 0 };
        setPan({ x: 0, y: 0 });

        requestAnimationFrame(() => fitZoomToView());
    }, [imageObj, fitZoomToView]);

    useEffect(() => {
        redrawPolygonPreview(polygonPoints, polygonHover);
    }, [polygonPoints, polygonHover, redrawPolygonPreview]);

    const handleFillColor = useCallback(async () => {
        if (!maskHasPixels(maskCanvasRef.current)) {
            return;
        }
        setIsProcessing(true);

        const imgCanvas = imageCanvasRef.current;
        const mskCanvas = maskCanvasRef.current;
        const imgCtx = imgCanvas.getContext('2d');
        const mskCtx = mskCanvas.getContext('2d');

        const width = imgCanvas.width;
        const height = imgCanvas.height;

        const imgData = imgCtx.getImageData(0, 0, width, height);
        const mskData = mskCtx.getImageData(0, 0, width, height);

        const hex = fillColor.replace('#', '');
        const r = parseInt(hex.substring(0, 2), 16);
        const g = parseInt(hex.substring(2, 4), 16);
        const b = parseInt(hex.substring(4, 6), 16);

        for (let i = 0; i < mskData.data.length; i += 4) {
            if (mskData.data[i + 3] > 0) {
                imgData.data[i] = r;
                imgData.data[i + 1] = g;
                imgData.data[i + 2] = b;
                imgData.data[i + 3] = 255;
            }
        }

        imgCtx.putImageData(imgData, 0, 0);
        mskCtx.clearRect(0, 0, mskCanvas.width, mskCanvas.height);
        clearPreviewCanvas();
        setHasDrawn(false);
        setPolygonPoints([]);
        pushHistory();
        setIsProcessing(false);
    }, [clearPreviewCanvas, fillColor, pushHistory]);

    const handleSaveImage = useCallback(async () => {
        setIsProcessing(true);
        try {
            const blob = await new Promise((resolve, reject) => {
                imageCanvasRef.current.toBlob((b) => {
                    if (b) {
                        resolve(b);
                    } else {
                        reject(new Error(t('magic_export_image_failed')));
                    }
                }, 'image/png');
            });
            const data = await saveEditedSeoMedia(imageId, blob);
            onSave(data.url);
        } catch (err) {
            console.error(err);
            alert(err?.message ?? t('magic_save_image_failed'));
        } finally {
            setIsProcessing(false);
        }
    }, [imageId, onSave]);

    const getCoordinates = (e) => {
        const canvas = maskCanvasRef.current;
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const rawX = (e.clientX - rect.left) * scaleX;
        const rawY = (e.clientY - rect.top) * scaleY;

        return {
            offsetX: Math.max(0, Math.min(Math.max(0, canvas.width - 1), rawX)),
            offsetY: Math.max(0, Math.min(Math.max(0, canvas.height - 1), rawY)),
        };
    };

    const rgbToHex = (r, g, b) => `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`;

    const startPan = (e) => {
        isPanningRef.current = true;
        setIsPanning(true);
        panStartRef.current = {
            x: e.clientX,
            y: e.clientY,
            panX: panRef.current.x,
            panY: panRef.current.y,
        };
    };

    const movePan = (e) => {
        if (!isPanningRef.current || !panStartRef.current) {
            return;
        }
        const dx = e.clientX - panStartRef.current.x;
        const dy = e.clientY - panStartRef.current.y;
        const next = {
            x: panStartRef.current.panX + dx,
            y: panStartRef.current.panY + dy,
        };
        panRef.current = next;
        setPan(next);
    };

    const endPan = () => {
        isPanningRef.current = false;
        setIsPanning(false);
        panStartRef.current = null;
    };

    const handleWheel = useCallback(
        (e) => {
            if (!imageObj) {
                return;
            }
            e.preventDefault();
            const delta = e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP;
            if (e.ctrlKey || e.metaKey || e.altKey) {
                zoomAtPoint(e.clientX, e.clientY, delta);
            } else {
                const p = panRef.current;
                const next = { x: p.x - e.deltaX, y: p.y - e.deltaY };
                panRef.current = next;
                setPan(next);
            }
        },
        [imageObj, zoomAtPoint],
    );

    useEffect(() => {
        const area = canvasAreaRef.current;
        if (!area) {
            return undefined;
        }
        area.addEventListener('wheel', handleWheel, { passive: false });

        return () => area.removeEventListener('wheel', handleWheel);
    }, [handleWheel, imageObj]);

    useEffect(() => {
        const onKeyDown = (e) => {
            if (isTypingTarget(e.target)) {
                return;
            }

            const key = e.key.toLowerCase();
            const mod = e.ctrlKey || e.metaKey;

            if (key === ' ' && !spaceHeldRef.current) {
                e.preventDefault();
                spaceHeldRef.current = true;
                setSpaceHeld(true);

                return;
            }

            const toolShortcut = toolFromKeyboardEvent(e);
            if (toolShortcut) {
                e.preventDefault();
                const toolMap = {
                    brush: TOOL_BRUSH,
                    rect: TOOL_RECT,
                    ellipse: TOOL_ELLIPSE,
                    polygon: TOOL_POLYGON,
                    eyedropper: TOOL_EYEDROPPER,
                };
                setActiveTool(toolMap[toolShortcut]);
                if (toolShortcut === 'polygon') {
                    setPolygonPoints([]);
                    clearPreviewCanvas();
                } else if (toolShortcut !== 'eyedropper') {
                    setPolygonPoints([]);
                }

                return;
            }

            if (mod && (key === 'z' || key === 'y')) {
                e.preventDefault();
                if (key === 'y' || (key === 'z' && e.shiftKey)) {
                    handleRedo();
                } else {
                    handleUndo();
                }

                return;
            }

            if (mod && (key === '=' || key === '+')) {
                e.preventDefault();
                changeZoom(ZOOM_STEP);

                return;
            }

            if (mod && key === '-') {
                e.preventDefault();
                changeZoom(-ZOOM_STEP);

                return;
            }

            if (mod && key === '0') {
                e.preventDefault();
                fitZoomToView();

                return;
            }

            if (mod && key === 's') {
                e.preventDefault();
                handleSaveImage();

                return;
            }

            if (mod && key === 'd') {
                e.preventDefault();
                if (hasDrawn || polygonPoints.length) {
                    handleClearMask(true);
                }

                return;
            }

            if (key === 'enter') {
                if (activeTool === TOOL_POLYGON && polygonPoints.length >= 3) {
                    e.preventDefault();
                    commitPolygon();

                    return;
                }
                if (hasDrawn) {
                    e.preventDefault();
                    handleFillColor();
                }

                return;
            }

            if (key === 'escape') {
                e.preventDefault();
                if (polygonPoints.length) {
                    setPolygonPoints([]);
                    setPolygonHover(null);
                    clearPreviewCanvas();

                    return;
                }
                onClose();

                return;
            }

            if (key === 'backspace' && activeTool === TOOL_POLYGON && polygonPoints.length) {
                e.preventDefault();
                setPolygonPoints((pts) => pts.slice(0, -1));

                return;
            }

            if (key === 'h') {
                e.preventDefault();
                spaceHeldRef.current = true;
                setSpaceHeld(true);

                return;
            }

            if (key === '[') {
                e.preventDefault();
                setBrushSize((s) => Math.max(5, s - 5));

                return;
            }

            if (key === ']') {
                e.preventDefault();
                setBrushSize((s) => Math.min(150, s + 5));
            }
        };

        const onKeyUp = (e) => {
            if (e.key === ' ' || e.key === 'Spacebar') {
                spaceHeldRef.current = false;
                setSpaceHeld(false);
                endPan();
            }
            if (e.key.toLowerCase() === 'h') {
                spaceHeldRef.current = false;
                setSpaceHeld(false);
                endPan();
            }
        };

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('keyup', onKeyUp);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('keyup', onKeyUp);
        };
    }, [
        activeTool,
        changeZoom,
        clearPreviewCanvas,
        commitPolygon,
        fitZoomToView,
        handleClearMask,
        handleFillColor,
        handleRedo,
        handleSaveImage,
        handleUndo,
        hasDrawn,
        onClose,
        polygonPoints.length,
    ]);

    const drawShapePreview = (x0, y0, x1, y1, type) => {
        const preview = previewCanvasRef.current;
        if (!preview) {
            return;
        }
        const ctx = preview.getContext('2d');
        ctx.clearRect(0, 0, preview.width, preview.height);
        drawShapePath(ctx, type, x0, y0, x1, y1);
        ctx.fillStyle = MASK_FILL;
        ctx.fill();
        ctx.strokeStyle = MASK_STROKE;
        ctx.lineWidth = 2;
        ctx.setLineDash([4, 4]);
        ctx.stroke();
        ctx.setLineDash([]);
    };

    const handleMouseDown = (e) => {
        if (e.button === 1 || spaceHeldRef.current || isHandMode) {
            e.preventDefault();
            startPan(e);

            return;
        }

        if (e.button !== 0) {
            return;
        }

        const { offsetX, offsetY } = getCoordinates(e);

        if (isPickingColor) {
            const ctx = imageCanvasRef.current.getContext('2d');
            const pixel = ctx.getImageData(offsetX, offsetY, 1, 1).data;
            setFillColor(rgbToHex(pixel[0], pixel[1], pixel[2]));
            setActiveTool(TOOL_BRUSH);

            return;
        }

        if (activeTool === TOOL_POLYGON) {
            if (polygonPoints.length >= 3) {
                const first = polygonPoints[0];
                const dist = Math.hypot(offsetX - first.x, offsetY - first.y);
                if (dist < 12) {
                    commitPolygon();

                    return;
                }
            }

            if (polygonPoints.length === 0) {
                clearMaskForNewSelection();
            }

            setPolygonPoints((pts) => [...pts, { x: offsetX, y: offsetY }]);

            return;
        }

        if (activeTool === TOOL_RECT || activeTool === TOOL_ELLIPSE) {
            clearMaskForNewSelection();
            shapeStartRef.current = { x: offsetX, y: offsetY, type: activeTool };
            setShapePreview({ x0: offsetX, y0: offsetY, x1: offsetX, y1: offsetY, type: activeTool });
            setIsDrawing(true);

            return;
        }

        clearMaskForNewSelection();
        const ctx = maskCanvasRef.current.getContext('2d');
        ctx.beginPath();
        ctx.moveTo(offsetX, offsetY);
        setIsDrawing(true);
        drewThisStrokeRef.current = true;
    };

    const handleMouseMove = (e) => {
        if (isPanningRef.current) {
            movePan(e);

            return;
        }

        const { offsetX, offsetY } = getCoordinates(e);

        if (activeTool === TOOL_POLYGON && polygonPoints.length > 0 && !isDrawing) {
            setPolygonHover({ x: offsetX, y: offsetY });

            return;
        }

        if (!isDrawing) {
            return;
        }

        if (shapeStartRef.current) {
            const { x: x0, y: y0, type } = shapeStartRef.current;
            drawShapePreview(x0, y0, offsetX, offsetY, type);
            setShapePreview({ x0, y0, x1: offsetX, y1: offsetY, type });

            return;
        }

        if (isPickingColor) {
            return;
        }

        const ctx = maskCanvasRef.current.getContext('2d');
        ctx.lineWidth = brushSize;
        ctx.strokeStyle = 'rgba(239, 68, 68, 0.6)';
        ctx.lineTo(offsetX, offsetY);
        ctx.stroke();
        setHasDrawn(true);
    };

    const handleMouseUp = (e) => {
        if (isPanningRef.current) {
            endPan();

            return;
        }

        if (!isDrawing) {
            return;
        }

        if (shapeStartRef.current && shapePreview) {
            const { x0, y0, x1, y1, type } = shapePreview;
            const w = Math.abs(x1 - x0);
            const h = Math.abs(y1 - y0);
            if (w > 2 && h > 2) {
                const ctx = maskCanvasRef.current.getContext('2d');
                paintShapeOnMask(ctx, type, x0, y0, x1, y1);
                setHasDrawn(true);
                pushHistory();
            }
            shapeStartRef.current = null;
            setShapePreview(null);
            clearPreviewCanvas();
        } else if (drewThisStrokeRef.current) {
            pushHistory();
            syncHasDrawnFromMask();
        }

        setIsDrawing(false);
        drewThisStrokeRef.current = false;
    };

    useEffect(() => {
        const onWindowMouseMove = (event) => {
            if (!isDrawing && !isPanningRef.current) {
                return;
            }
            handleMouseMove(event);
        };

        const onWindowMouseUp = (event) => {
            if (!isDrawing && !isPanningRef.current) {
                return;
            }
            handleMouseUp(event);
        };

        window.addEventListener('mousemove', onWindowMouseMove);
        window.addEventListener('mouseup', onWindowMouseUp);

        return () => {
            window.removeEventListener('mousemove', onWindowMouseMove);
            window.removeEventListener('mouseup', onWindowMouseUp);
        };
    }, [isDrawing, handleMouseMove, handleMouseUp]);

    const zoomPercent = Math.round(zoom * 100);

    const stageStyle =
        imageObj != null
            ? {
                  width: imageObj.width,
                  height: imageObj.height,
                  transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
              }
            : undefined;

    const cursorClass = isHandMode
        ? 'is-hand'
        : isPickingColor
          ? 'is-picking'
          : activeTool === TOOL_BRUSH
            ? 'is-brush'
            : 'is-crosshair';

    return (
        <div className="magic-eraser-panel">
            <div className="magic-eraser-topbar">
                    <div className="magic-eraser-topbar-left">
                        <div className="magic-eraser-tool-group" role="toolbar" aria-label={t('magic_tools')}>
                            <MagicEraserToolbarButton
                                icon={Brush}
                                label={t('magic_brush')}
                                shortcut={TOOL_SHORTCUT_LABELS.brush}
                                active={activeTool === TOOL_BRUSH}
                                onClick={() => setActiveTool(TOOL_BRUSH)}
                            />
                            <MagicEraserToolbarButton
                                icon={Square}
                                label={t('magic_rectangle')}
                                shortcut={TOOL_SHORTCUT_LABELS.rect}
                                active={activeTool === TOOL_RECT}
                                onClick={() => setActiveTool(TOOL_RECT)}
                            />
                            <MagicEraserToolbarButton
                                icon={Circle}
                                label={t('magic_ellipse')}
                                shortcut={TOOL_SHORTCUT_LABELS.ellipse}
                                active={activeTool === TOOL_ELLIPSE}
                                onClick={() => setActiveTool(TOOL_ELLIPSE)}
                            />
                            <MagicEraserToolbarButton
                                icon={Pentagon}
                                label={t('magic_polygon')}
                                shortcut={TOOL_SHORTCUT_LABELS.polygon}
                                active={activeTool === TOOL_POLYGON}
                                onClick={() => {
                                    setActiveTool(TOOL_POLYGON);
                                    setPolygonPoints([]);
                                }}
                            />
                            <MagicEraserToolbarButton
                                icon={Pipette}
                                label={t('magic_eyedropper')}
                                shortcut={TOOL_SHORTCUT_LABELS.eyedropper}
                                active={activeTool === TOOL_EYEDROPPER}
                                onClick={() => setActiveTool(TOOL_EYEDROPPER)}
                            />
                        </div>
                        <div className="magic-eraser-zoom-group">
                            <MagicEraserToolbarButton
                                icon={ZoomOut}
                                label={t('magic_zoom_out')}
                                shortcut="Ctrl+-"
                                variant="icon"
                                onClick={() => changeZoom(-ZOOM_STEP)}
                            />
                            <span className="magic-eraser-zoom-label">{zoomPercent}%</span>
                            <MagicEraserToolbarButton
                                icon={ZoomIn}
                                label={t('magic_zoom_in')}
                                shortcut="Ctrl++"
                                variant="icon"
                                onClick={() => changeZoom(ZOOM_STEP)}
                            />
                            <MagicEraserToolbarButton
                                icon={Maximize2}
                                label={t('magic_fit_view')}
                                shortcut="Ctrl+0"
                                variant="icon"
                                onClick={fitZoomToView}
                            />
                        </div>
                    </div>

                    <div className="magic-eraser-undo-group">
                        <MagicEraserToolbarButton
                            icon={Undo2}
                            label={t('magic_undo')}
                            shortcut="Ctrl+Z"
                            variant="icon"
                            disabled={historyStep <= 0}
                            onClick={handleUndo}
                        />
                        <div className="magic-eraser-divider" />
                        <MagicEraserToolbarButton
                            icon={Redo2}
                            label={t('magic_redo')}
                            shortcut="Ctrl+Y"
                            variant="icon"
                            disabled={historyStep >= history.length - 1}
                            onClick={handleRedo}
                        />
                    </div>

                    <div className="magic-eraser-topbar-actions">
                        <MagicEraserToolbarButton
                            icon={X}
                            label={t('magic_close')}
                            shortcut="Esc"
                            variant="action-secondary"
                            disabled={isProcessing}
                            onClick={onClose}
                        />
                        <MagicEraserToolbarButton
                            icon={Save}
                            label={isProcessing ? t('magic_saving') : t('magic_save_image')}
                            shortcut="Ctrl+S"
                            variant="action-primary"
                            disabled={isProcessing}
                            onClick={handleSaveImage}
                        />
                    </div>
                </div>

                <div className="magic-eraser-body">
                    <div
                        ref={canvasAreaRef}
                        className={`magic-eraser-canvas-area ${isHandMode ? 'is-hand-mode' : ''}`}
                    >
                        {!imageObj && <div className="magic-eraser-loading">{t('magic_loading_image')}</div>}

                        {imageObj && (
                            <div className="magic-eraser-stage-outer">
                                <div className="magic-eraser-stage" style={stageStyle}>
                                    <div className={`magic-eraser-canvas-wrap ${cursorClass}`}>
                                        <canvas
                                            ref={imageCanvasRef}
                                            width={imageObj.width}
                                            height={imageObj.height}
                                            className="magic-eraser-canvas-image"
                                        />
                                        <canvas
                                            ref={maskCanvasRef}
                                            width={imageObj.width}
                                            height={imageObj.height}
                                            onMouseDown={handleMouseDown}
                                            onMouseMove={handleMouseMove}
                                            onMouseUp={handleMouseUp}
                                            onMouseLeave={() => {
                                                if (activeTool === TOOL_POLYGON && !isDrawing) {
                                                    setPolygonHover(null);
                                                }
                                            }}
                                            onContextMenu={(e) => e.preventDefault()}
                                            className="magic-eraser-canvas-mask"
                                        />
                                        <canvas
                                            ref={previewCanvasRef}
                                            width={imageObj.width}
                                            height={imageObj.height}
                                            className="magic-eraser-canvas-preview"
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="magic-eraser-sidebar">
                        <div className="magic-eraser-panel">
                            <h4 className="magic-eraser-panel-title">{t('magic_color', { shortcut: TOOL_SHORTCUT_LABELS.eyedropper })}</h4>
                            <div className="magic-eraser-color-row">
                                <input
                                    type="color"
                                    value={fillColor}
                                    onChange={(e) => setFillColor(e.target.value)}
                                    className="magic-eraser-color-input"
                                    aria-label={t('magic_pick_fill_color')}
                                />
                                <span className="magic-eraser-hex">{fillColor}</span>
                                <MagicEraserToolbarButton
                                    icon={Pipette}
                                    label={t('magic_eyedropper')}
                                    shortcut={TOOL_SHORTCUT_LABELS.eyedropper}
                                    variant="tool"
                                    active={activeTool === TOOL_EYEDROPPER}
                                    onClick={() => setActiveTool(TOOL_EYEDROPPER)}
                                    className="magic-eraser-color-pick-btn"
                                />
                            </div>
                        </div>

                        <div className="magic-eraser-panel">
                            <h4 className="magic-eraser-panel-title">
                                {t('magic_brush_size', { shortcut: TOOL_SHORTCUT_LABELS.brush })}
                            </h4>
                            <div className="magic-eraser-brush-row">
                                <input
                                    type="range"
                                    min="5"
                                    max="150"
                                    value={brushSize}
                                    onChange={(e) => setBrushSize(parseInt(e.target.value, 10))}
                                    className="magic-eraser-range"
                                />
                                <span className="magic-eraser-brush-size">{brushSize}px</span>
                            </div>
                            <MagicEraserToolbarButton
                                icon={Eraser}
                                label={t('magic_clear_selection')}
                                shortcut="Ctrl+D"
                                variant="sidebar"
                                className="magic-eraser-sidebar-action"
                                disabled={!hasDrawn && polygonPoints.length === 0}
                                onClick={() => handleClearMask(true)}
                            >
                                <span className="magic-eraser-sidebar-action-label">{t('magic_clear_selection')}</span>
                            </MagicEraserToolbarButton>
                        </div>

                        {activeTool === TOOL_POLYGON && (
                            <div className="magic-eraser-panel">
                                <p className="magic-eraser-hint">
                                    {t('magic_polygon_hint')}
                                </p>
                                <MagicEraserToolbarButton
                                    icon={Pentagon}
                                    label={t('magic_close_polygon')}
                                    shortcut="Enter"
                                    variant="sidebar"
                                    className="magic-eraser-sidebar-action"
                                    disabled={polygonPoints.length < 3}
                                    onClick={commitPolygon}
                                >
                                    <span className="magic-eraser-sidebar-action-label">{t('magic_close_polygon')}</span>
                                </MagicEraserToolbarButton>
                            </div>
                        )}

                        <MagicEraserShortcutsPanel groups={MAGIC_ERASER_SHORTCUT_GROUPS} />

                        <div className="magic-eraser-panel-footer">
                            <MagicEraserToolbarButton
                                icon={PaintBucket}
                                label={t('magic_fill_selection')}
                                shortcut="Enter"
                                variant="fill"
                                disabled={!hasDrawn || isProcessing}
                                onClick={handleFillColor}
                            >
                                <span className="magic-eraser-sidebar-action-label">{t('magic_fill')}</span>
                            </MagicEraserToolbarButton>

                            {hasDrawn && (
                                <MagicEraserToolbarButton
                                    icon={Square}
                                    label={t('magic_split_image')}
                                    shortcut=""
                                    variant="fill"
                                    disabled={isProcessing}
                                    onClick={handleSplitFromSelection}
                                >
                                    <span className="magic-eraser-sidebar-action-label">{t('magic_split_image')}</span>
                                </MagicEraserToolbarButton>
                            )}
                        </div>
                    </div>
                </div>
        </div>
    );
}
