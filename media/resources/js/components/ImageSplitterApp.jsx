import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { LayoutGrid, Loader2 } from 'lucide-react';
import { fetchSplitterSource, saveSplitPiecesToLibrary } from '../utils/seoMediaApi';
import { t } from '@content-addon/utils/i18n.js';

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error(t('splitter_load_error')));
        img.src = src;
    });
}

function clampInt(value, min, max) {
    const n = Number.parseInt(String(value), 10);
    if (Number.isNaN(n)) return min;
    return Math.max(min, Math.min(max, n));
}

function absoluteImageUrl(url) {
    if (!url) return '';
    if (url.startsWith('http://') || url.startsWith('https://')) {
        return url;
    }
    if (url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
}

function buildEvenGuides(count) {
    const guides = [];
    for (let i = 1; i < count; i += 1) {
        guides.push(i / count);
    }

    return guides;
}

export default function ImageSplitterApp({
    siteId = null,
    articleId = null,
    seoMediaId = null,
    wpAttachmentId = null,
    slug = '',
    embedded = false,
    variant = 'full',
    defaultRows = 3,
    defaultCols = 2,
    autoSaveOnSplit = false,
    fallbackImageUrl = '',
    splitPayload = null,
    canDeleteOriginal = true,
    onSplitSaved = null,
}) {
    const isGalleryVariant = variant === 'gallery';
    const initialRows = clampInt(defaultRows, 1, 12);
    const initialCols = clampInt(defaultCols, 1, 12);
    const resultPaneRef = useRef(null);
    const previewRef = useRef(null);
    const splitPayloadRef = useRef(splitPayload);
    splitPayloadRef.current = splitPayload;
    const [imageSrc, setImageSrc] = useState('');
    const [imageName, setImageName] = useState('image');
    const [sourceMeta, setSourceMeta] = useState({
        seoMediaId: null,
        wpAttachmentId: null,
        resolvedSiteId: null,
        resolvedArticleId: null,
    });
    const [imgNatural, setImgNatural] = useState({ width: 0, height: 0 });
    const [rows, setRows] = useState(initialRows);
    const [cols, setCols] = useState(initialCols);
    const [verticalGuides, setVerticalGuides] = useState(() => buildEvenGuides(initialCols));
    const [horizontalGuides, setHorizontalGuides] = useState(() => buildEvenGuides(initialRows));
    const [dragGuide, setDragGuide] = useState(null);
    const [pieces, setPieces] = useState([]);
    const [isSplitting, setIsSplitting] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isLoadingSource, setIsLoadingSource] = useState(true);
    const [dragPieceId, setDragPieceId] = useState(null);
    const [saveMessage, setSaveMessage] = useState('');
    const [error, setError] = useState('');
    const laravelId = Number(seoMediaId ?? 0);
    const wpId = Number(wpAttachmentId ?? 0);
    const hasSourceId = laravelId > 0 || wpId > 0;

    const applyExternalPieces = useCallback((incoming) => {
        if (!incoming?.length) {
            return;
        }

        const addedCount = incoming.length;
        setPieces((prev) => {
            const normalized = incoming.map((piece, i) => ({
                ...piece,
                id: piece.id ?? `${Date.now()}-crop-${i}-${Math.random()}`,
            }));
            return [...prev, ...normalized];
        });
        setSaveMessage(t('splitter_added', { count: addedCount }));
        setError('');

        requestAnimationFrame(() => {
            resultPaneRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }, []);

    const loadImageFromUrl = async (displayUrl, meta = {}) => {
        const image = await loadImage(displayUrl);
        setImageSrc(displayUrl);
        setImageName(meta.name || 'image');
        setImgNatural({ width: image.naturalWidth, height: image.naturalHeight });
        setSourceMeta({
            seoMediaId: meta.seoMediaId ?? (laravelId > 0 ? laravelId : null),
            wpAttachmentId: meta.wpAttachmentId ?? (wpId > 0 ? wpId : null),
            resolvedSiteId: meta.resolvedSiteId ?? siteId ?? null,
            resolvedArticleId: meta.resolvedArticleId ?? articleId ?? null,
        });

        if (!splitPayloadRef.current?.pieces?.length) {
            setPieces((prev) => {
                prev.forEach((piece) => URL.revokeObjectURL(piece.url));
                return [];
            });
        }
    };

    useEffect(() => {
        return () => {
            pieces.forEach((piece) => URL.revokeObjectURL(piece.url));
        };
    }, [pieces]);

    useEffect(() => {
        setVerticalGuides(buildEvenGuides(cols));
    }, [cols]);

    useEffect(() => {
        setHorizontalGuides(buildEvenGuides(rows));
    }, [rows]);

    useEffect(() => {
        if (!isGalleryVariant) {
            return;
        }

        const nextRows = clampInt(defaultRows, 1, 12);
        const nextCols = clampInt(defaultCols, 1, 12);
        setRows(nextRows);
        setCols(nextCols);
        setVerticalGuides(buildEvenGuides(nextCols));
        setHorizontalGuides(buildEvenGuides(nextRows));
        setDragGuide(null);
        setPieces((prev) => {
            prev.forEach((piece) => URL.revokeObjectURL(piece.url));
            return [];
        });
        setSaveMessage('');
        setError('');
    }, [isGalleryVariant, defaultRows, defaultCols, seoMediaId]);

    useEffect(() => {
        let cancelled = false;

        const loadSource = async () => {
            setIsLoadingSource(true);
            setError('');
            setSaveMessage('');

            if (!hasSourceId) {
                setError(t('splitter_missing_source_id'));
                setIsLoadingSource(false);
                return;
            }

            const directUrl = absoluteImageUrl(fallbackImageUrl);
            if (directUrl && laravelId > 0) {
                try {
                    await loadImageFromUrl(directUrl, {
                        seoMediaId: laravelId,
                        wpAttachmentId: wpId > 0 ? wpId : null,
                        resolvedSiteId: siteId ?? null,
                        resolvedArticleId: articleId ?? null,
                    });
                } catch (e) {
                    if (!cancelled) {
                        setError(e.message || t('splitter_missing_source_image'));
                    }
                } finally {
                    if (!cancelled) {
                        setIsLoadingSource(false);
                    }
                }

                return;
            }

            try {
                const resolved = await fetchSplitterSource({
                    siteId,
                    seoMediaId: laravelId > 0 ? laravelId : null,
                    wpAttachmentId: wpId > 0 ? wpId : null,
                    slug,
                });

                if (cancelled) return;

                const displayUrl = absoluteImageUrl(resolved.url);
                await loadImageFromUrl(displayUrl, {
                    name: resolved.name || 'image',
                    seoMediaId: resolved.seo_media_id > 0 ? resolved.seo_media_id : null,
                    wpAttachmentId: resolved.wp_attachment_id > 0 ? resolved.wp_attachment_id : null,
                    resolvedSiteId: resolved.site_id ?? siteId ?? null,
                    resolvedArticleId:
                        resolved.article_id > 0 ? resolved.article_id : (articleId ?? null),
                });
            } catch (e) {
                if (!cancelled) {
                    setError(e.message || t('splitter_missing_source_image'));
                }
            } finally {
                if (!cancelled) {
                    setIsLoadingSource(false);
                }
            }
        };

        loadSource();

        return () => {
            cancelled = true;
        };
    }, [siteId, articleId, seoMediaId, wpAttachmentId, slug, hasSourceId, laravelId, wpId, fallbackImageUrl]);

    const effectiveSiteId = sourceMeta.resolvedSiteId ?? siteId ?? null;
    const effectiveArticleId = sourceMeta.resolvedArticleId ?? articleId ?? null;
    const hasImage = imageSrc !== '';

    useEffect(() => {
        if (splitPayload?.pieces?.length && hasImage) {
            applyExternalPieces(splitPayload.pieces);
        }
    }, [splitPayload?.id, hasImage, applyExternalPieces, splitPayload]);
    const canSave =
        pieces.length > 0 &&
        effectiveSiteId != null &&
        (canDeleteOriginal ? (sourceMeta.seoMediaId ?? 0) > 0 : true) &&
        !isSaving;

    const gridLines = useMemo(
        () => ({
            vertical: verticalGuides.map((ratio) => ratio * 100),
            horizontal: horizontalGuides.map((ratio) => ratio * 100),
        }),
        [verticalGuides, horizontalGuides],
    );

    const buildPiecesFromImage = async () => {
        const image = await loadImage(imageSrc);
        const nextPieces = [];
        const xBoundaries = [0, ...verticalGuides, 1]
            .map((ratio) => Math.round(ratio * image.naturalWidth))
            .sort((a, b) => a - b);
        const yBoundaries = [0, ...horizontalGuides, 1]
            .map((ratio) => Math.round(ratio * image.naturalHeight))
            .sort((a, b) => a - b);

        for (let r = 0; r < yBoundaries.length - 1; r += 1) {
            for (let c = 0; c < xBoundaries.length - 1; c += 1) {
                const x0 = xBoundaries[c];
                const x1 = xBoundaries[c + 1];
                const y0 = yBoundaries[r];
                const y1 = yBoundaries[r + 1];

                const width = Math.max(1, x1 - x0);
                const height = Math.max(1, y1 - y0);
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(image, x0, y0, width, height, 0, 0, width, height);

                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 1));
                if (!blob) continue;
                const objectUrl = URL.createObjectURL(blob);
                const index = r * (xBoundaries.length - 1) + c + 1;
                nextPieces.push({
                    id: `${Date.now()}-${r}-${c}-${Math.random()}`,
                    row: r + 1,
                    col: c + 1,
                    width,
                    height,
                    blob,
                    url: objectUrl,
                    filename: `${imageName}-r${r + 1}-c${c + 1}-${index}.png`,
                });
            }
        }

        return nextPieces;
    };

    const splitImage = async () => {
        if (!hasImage) {
            setError(t('splitter_no_source'));
            return [];
        }

        setIsSplitting(true);
        setError('');
        setSaveMessage('');

        try {
            const nextPieces = await buildPiecesFromImage();
            const addedCount = nextPieces.length;
            setPieces((prev) => [...prev, ...nextPieces]);
            setSaveMessage(t('splitter_added', { count: addedCount }));
            return nextPieces;
        } catch (e) {
            setError(e.message || t('splitter_split_error'));
            return [];
        } finally {
            setIsSplitting(false);
        }
    };

    const performSave = async (piecesToSave) => {
        if (!piecesToSave.length) {
            return null;
        }

        if (!effectiveSiteId) {
            setError(t('splitter_missing_site_id'));
            return null;
        }

        if (canDeleteOriginal && !(sourceMeta.seoMediaId > 0)) {
            setError(t('splitter_missing_seo_media_id'));
            return null;
        }

        setIsSaving(true);
        setError('');
        setSaveMessage('');

        try {
            const data = await saveSplitPiecesToLibrary({
                siteId: effectiveSiteId,
                articleId: effectiveArticleId,
                originalSeoMediaId: canDeleteOriginal ? sourceMeta.seoMediaId : null,
                pieces: piecesToSave,
            });

            piecesToSave.forEach((piece) => URL.revokeObjectURL(piece.url));
            setPieces((prev) => {
                prev.forEach((piece) => URL.revokeObjectURL(piece.url));
                return [];
            });
            setImageSrc('');
            setImageName('image');
            setImgNatural({ width: 0, height: 0 });
            setSourceMeta({
                seoMediaId: null,
                wpAttachmentId: null,
                resolvedSiteId: effectiveSiteId,
                resolvedArticleId: effectiveArticleId,
            });

            setSaveMessage(data.message ?? t('splitter_saved_default', { count: data.saved?.length ?? 0 }));

            const galleryItems = Array.isArray(data.product_gallery_items) ? data.product_gallery_items : [];

            if (typeof onSplitSaved === 'function') {
                onSplitSaved({
                    saved: data.saved ?? [],
                    deletedOriginal: !!data.deleted_original,
                    product_gallery_items: galleryItems,
                    article_id: effectiveArticleId,
                });
                return data;
            }

            const returnUrl =
                effectiveArticleId > 0
                    ? sessionStorage.getItem(`seo-product-gallery-split-return-${effectiveArticleId}`)
                    : null;

            if (window.opener) {
                window.opener.postMessage(
                    {
                        type: 'seo-image-splitter-saved',
                        saved: data.saved ?? [],
                        deletedOriginal: !!data.deleted_original,
                        product_gallery_items: galleryItems,
                        article_id: effectiveArticleId,
                    },
                    window.location.origin,
                );
            } else if (returnUrl && galleryItems.length > 0) {
                try {
                    sessionStorage.removeItem(`seo-product-gallery-split-return-${effectiveArticleId}`);
                } catch {
                    /* ignore */
                }
                window.location.assign(returnUrl);
            }

            return data;
        } catch (e) {
            setError(e.message || t('splitter_save_error'));
            return null;
        } finally {
            setIsSaving(false);
        }
    };

    const splitAndSave = async () => {
        if (!hasImage) {
            setError(t('splitter_no_source'));
            return;
        }

        setIsSplitting(true);
        setError('');
        setSaveMessage('');

        try {
            const nextPieces = await buildPiecesFromImage();
            if (nextPieces.length === 0) {
                setError(t('splitter_split_error'));
                return;
            }

            await performSave(nextPieces);
        } catch (e) {
            setError(e.message || t('splitter_split_error'));
        } finally {
            setIsSplitting(false);
        }
    };

    const removePiece = (id) => {
        setPieces((prev) => {
            const next = [];
            for (const piece of prev) {
                if (piece.id === id) {
                    URL.revokeObjectURL(piece.url);
                } else {
                    next.push(piece);
                }
            }
            return next;
        });
    };

    const movePieceById = useCallback((fromId, toId) => {
        if (!fromId || !toId || fromId === toId) {
            return;
        }

        setPieces((prev) => {
            const fromIndex = prev.findIndex((piece) => piece.id === fromId);
            const toIndex = prev.findIndex((piece) => piece.id === toId);
            if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
                return prev;
            }

            const next = [...prev];
            const [moved] = next.splice(fromIndex, 1);
            next.splice(toIndex, 0, moved);
            return next;
        });
    }, []);

    useEffect(() => {
        if (!dragGuide) {
            return undefined;
        }

        const MIN_GAP = 0.03;
        const onMouseMove = (event) => {
            const preview = previewRef.current;
            if (!preview) {
                return;
            }
            const rect = preview.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) {
                return;
            }

            if (dragGuide.axis === 'x') {
                const raw = (event.clientX - rect.left) / rect.width;
                setVerticalGuides((prev) => {
                    const next = [...prev];
                    const before = dragGuide.index === 0 ? 0 : next[dragGuide.index - 1];
                    const after = dragGuide.index === next.length - 1 ? 1 : next[dragGuide.index + 1];
                    next[dragGuide.index] = Math.max(before + MIN_GAP, Math.min(after - MIN_GAP, raw));
                    return next;
                });
            } else {
                const raw = (event.clientY - rect.top) / rect.height;
                setHorizontalGuides((prev) => {
                    const next = [...prev];
                    const before = dragGuide.index === 0 ? 0 : next[dragGuide.index - 1];
                    const after = dragGuide.index === next.length - 1 ? 1 : next[dragGuide.index + 1];
                    next[dragGuide.index] = Math.max(before + MIN_GAP, Math.min(after - MIN_GAP, raw));
                    return next;
                });
            }
        };

        const onMouseUp = () => setDragGuide(null);

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);

        return () => {
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('mouseup', onMouseUp);
        };
    }, [dragGuide]);

    const saveToLibrary = async () => {
        if (!canSave) {
            if (!effectiveSiteId) {
                setError(t('splitter_missing_site_id'));
            } else if (canDeleteOriginal && !(sourceMeta.seoMediaId > 0)) {
                setError(t('splitter_missing_seo_media_id'));
            }
            return;
        }

        await performSave(pieces);
    };

    const hasResults = pieces.length > 0;
    const isBusy = isSplitting || isSaving;
    const splitDisabled = !hasImage || isBusy || isLoadingSource;

    const gridOverlay = hasImage ? (
        <div className={`grid-preview${isGalleryVariant ? ' grid-preview--gallery' : ''}`} ref={previewRef}>
            <img src={imageSrc} alt="Source" />
            {gridLines.vertical.map((left, index) => (
                <React.Fragment key={`v-${index}-${left}`}>
                    <span className="grid-line v" style={{ left: `${left}%` }} />
                    {!isGalleryVariant ? (
                        <button
                            type="button"
                            className={`grid-line-handle v${dragGuide?.axis === 'x' && dragGuide?.index === index ? ' is-active' : ''}`}
                            style={{ left: `${left}%` }}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                setDragGuide({ axis: 'x', index });
                            }}
                            aria-label={t('splitter_drag_col_handle')}
                            title={t('splitter_drag_col_handle_title')}
                        />
                    ) : null}
                </React.Fragment>
            ))}
            {gridLines.horizontal.map((top, index) => (
                <React.Fragment key={`h-${index}-${top}`}>
                    <span className="grid-line h" style={{ top: `${top}%` }} />
                    {!isGalleryVariant ? (
                        <button
                            type="button"
                            className={`grid-line-handle h${dragGuide?.axis === 'y' && dragGuide?.index === index ? ' is-active' : ''}`}
                            style={{ top: `${top}%` }}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                setDragGuide({ axis: 'y', index });
                            }}
                            aria-label={t('splitter_drag_row_handle')}
                            title={t('splitter_drag_row_handle_title')}
                        />
                    ) : null}
                </React.Fragment>
            ))}
        </div>
    ) : null;

    if (isGalleryVariant) {
        return (
            <div
                className={`seo-image-splitter seo-image-splitter--gallery${
                    embedded ? ' seo-image-splitter--embedded' : ''
                }`}
            >
                <div className="splitter-gallery-toolbar">
                    <label>
                        {t('splitter_rows')}
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={rows}
                            disabled={isBusy}
                            onChange={(e) => {
                                setRows(clampInt(e.target.value, 1, 12));
                                setDragGuide(null);
                            }}
                        />
                    </label>
                    <label>
                        {t('splitter_cols')}
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={cols}
                            disabled={isBusy}
                            onChange={(e) => {
                                setCols(clampInt(e.target.value, 1, 12));
                                setDragGuide(null);
                            }}
                        />
                    </label>
                    <button
                        type="button"
                        className="splitter-icon-btn"
                        disabled={splitDisabled}
                        onClick={autoSaveOnSplit ? splitAndSave : splitImage}
                        title={isBusy ? t('splitter_splitting') : t('splitter_split')}
                        aria-label={t('splitter_split')}
                    >
                        {isBusy ? <Loader2 size={22} className="splitter-icon-btn__spinner" /> : <LayoutGrid size={22} />}
                    </button>
                </div>

                <div className="splitter-gallery-preview">
                    {isLoadingSource ? (
                        <div className="empty-card">{t('splitter_loading_from_sources')}</div>
                    ) : !hasImage ? (
                        <div className="empty-card">{t('splitter_need_source_ids')}</div>
                    ) : (
                        gridOverlay
                    )}
                </div>

                {imgNatural.width > 0 ? (
                    <div className="hint">
                        {t('splitter_source_size', { width: imgNatural.width, height: imgNatural.height })}
                        {sourceMeta.seoMediaId ? ` · Laravel #${sourceMeta.seoMediaId}` : ''}
                    </div>
                ) : null}
                {!effectiveSiteId && hasImage ? (
                    <div className="hint">{t('splitter_missing_site_hint')}</div>
                ) : null}
                {saveMessage ? <div className="splitter-success">{saveMessage}</div> : null}
                {error ? <div className="splitter-error">{error}</div> : null}
            </div>
        );
    }

    return (
        <div
            className={`seo-image-splitter${embedded ? ' seo-image-splitter--embedded' : ''}${
                hasResults ? ' seo-image-splitter--has-results' : ''
            }`}
        >
            <div className="splitter-controls">
                <div className="splitter-row">
                    <label>
                        {t('splitter_rows')}
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={rows}
                            onChange={(e) => {
                                setRows(clampInt(e.target.value, 1, 12));
                                setDragGuide(null);
                            }}
                        />
                    </label>
                    <label>
                        {t('splitter_cols')}
                        <input
                            type="number"
                            min={1}
                            max={12}
                            value={cols}
                            onChange={(e) => {
                                setCols(clampInt(e.target.value, 1, 12));
                                setDragGuide(null);
                            }}
                        />
                    </label>
                    <button
                        type="button"
                        className="btn-primary"
                        disabled={!hasImage || isSplitting || isLoadingSource}
                        onClick={splitImage}
                    >
                        {isSplitting ? t('splitter_splitting') : t('splitter_split')}
                    </button>
                </div>

                {isLoadingSource && <div className="hint">{t('splitter_loading_by_id')}</div>}
                {imgNatural.width > 0 && (
                    <div className="hint">
                        {t('splitter_source_size', { width: imgNatural.width, height: imgNatural.height })}
                        {sourceMeta.seoMediaId ? ` · Laravel #${sourceMeta.seoMediaId}` : ''}
                        {sourceMeta.wpAttachmentId ? ` · WP #${sourceMeta.wpAttachmentId}` : ''}
                    </div>
                )}
                {!effectiveSiteId && hasImage && (
                    <div className="hint">
                        {t('splitter_missing_site_hint')}
                    </div>
                )}
                {saveMessage && <div className="splitter-success">{saveMessage}</div>}
                {error && <div className="splitter-error">{error}</div>}
                {hasImage && (
                    <div className="hint">{t('splitter_drag_guides_hint')}</div>
                )}
            </div>

            <div className="splitter-workspace">
                <div className="splitter-preview-pane">
                    <h3>{t('splitter_preview_title')}</h3>
                    {isLoadingSource ? (
                        <div className="empty-card">{t('splitter_loading_from_sources')}</div>
                    ) : !hasImage ? (
                        <div className="empty-card">
                            {t('splitter_need_source_ids')}
                        </div>
                    ) : (
                        gridOverlay
                    )}
                </div>

                <div className="splitter-result-pane" ref={resultPaneRef}>
                    <div className="result-header">
                        <h3>{t('splitter_result_title', { count: pieces.length })}</h3>
                        {pieces.length > 0 && (
                            <button
                                type="button"
                                className="btn-save"
                                disabled={!canSave}
                                onClick={saveToLibrary}
                            >
                                {isSaving ? t('splitter_saving') : t('splitter_save_to_library')}
                            </button>
                        )}
                    </div>

                    {pieces.length === 0 ? (
                        <div className="empty-card">
                            {canDeleteOriginal ? t('splitter_empty_hint') : t('splitter_empty_hint_keep_original')}
                        </div>
                    ) : (
                        <div className="piece-grid">
                            {pieces.map((piece, idx) => (
                                <div
                                    key={piece.id}
                                    className={`piece-card${dragPieceId === piece.id ? ' is-dragging' : ''}`}
                                    draggable
                                    onDragStart={() => setDragPieceId(piece.id)}
                                    onDragOver={(event) => event.preventDefault()}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        movePieceById(dragPieceId, piece.id);
                                        setDragPieceId(null);
                                    }}
                                    onDragEnd={() => setDragPieceId(null)}
                                >
                                    <img src={piece.url} alt={`Piece ${idx + 1}`} />
                                    <div className="piece-meta">
                                        <strong>#{idx + 1}</strong> r{piece.row} c{piece.col} · {piece.width}x{piece.height}
                                    </div>
                                    <div className="piece-actions">
                                        <button type="button" className="btn-danger" onClick={() => removePiece(piece.id)}>
                                            {t('splitter_remove_piece')}
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
