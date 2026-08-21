import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { FileText, LayoutGrid, RefreshCw, Tag, X } from 'lucide-react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import ImageSplitterPanel from './ImageSplitterPanel';
import { fetchSeoMediaStatus } from '../utils/seoMediaApi';
import { callEditArticleLivewire } from '@content-addon/utils/articleEditorLivewire.js';
import {
    appendProductAlbumItems,
    loadProductAlbum,
    normalizeProductAlbumItem,
    syncProductAlbumToServer,
} from '../utils/articleProductAlbumStorage';
import { t } from '@content-addon/utils/i18n.js';

function normalizeGalleryPreviewItem(item, { connected = false, processing = false } = {}) {
    const normalized = normalizeProductAlbumItem(item);
    if (!normalized) {
        return null;
    }

    return {
        id: normalized.id,
        url: normalized.url,
        connected: Boolean(item?.connected ?? connected),
        processing: Boolean(item?.processing ?? processing),
    };
}

function mergeGalleryPreviewItems(...lists) {
    const byUrl = new Map();

    lists.flat().forEach((raw) => {
        const item = normalizeGalleryPreviewItem(raw);
        if (!item) {
            return;
        }

        const existing = byUrl.get(item.url);
        if (!existing) {
            byUrl.set(item.url, item);
            return;
        }

        byUrl.set(item.url, {
            ...existing,
            id: item.id > 0 ? item.id : existing.id,
            connected: existing.connected || item.connected,
            processing: item.processing && !existing.connected ? true : existing.processing && item.processing,
        });
    });

    return Array.from(byUrl.values());
}

function readProductGalleryItemsFromStorage(articleId) {
    if (!articleId) {
        return [];
    }

    return loadProductAlbum(articleId).map((item) => normalizeGalleryPreviewItem(item) ?? item);
}

function readProductGalleryItemsFromDom() {
    return Array.from(document.querySelectorAll('[data-gallery-url]'))
        .map((node) =>
            normalizeGalleryPreviewItem({
                url: String(node.dataset.galleryUrl ?? '').trim(),
                id: Number(node.dataset.galleryId ?? 0) || 0,
            }),
        )
        .filter(Boolean);
}

function isNonGalleryArtifactRole(role) {
    return ['generated_sprite', 'generated_parent', 'generated_child_reference'].includes(
        String(role ?? '').trim(),
    );
}

function gridCellLabel(rowIndex, colIndex) {
    return `${String.fromCharCode(65 + rowIndex)}${colIndex + 1}`;
}

function requestPromptPreview(detail) {
    window.dispatchEvent(
        new CustomEvent('preview-generate-article-image-prompt', {
            detail,
        }),
    );
}

function normalizePostProcessing(raw) {
    const source = raw != null && typeof raw === 'object' ? raw : {};
    const min = 2;
    const max = 6;
    const defaultGrid = 3;

    let grid = Number.parseInt(
        String(source.split_grid_size ?? source.grid_size ?? source.split_rows ?? defaultGrid),
        10,
    );
    if (! Number.isFinite(grid) || grid < min) {
        grid = defaultGrid;
    }
    grid = Math.max(min, Math.min(max, grid));

    const rowsRaw = Number.parseInt(String(source.split_rows ?? grid), 10);
    const colsRaw = Number.parseInt(String(source.split_columns ?? grid), 10);
    if (
        source.split_grid_size == null
        && source.grid_size == null
        && Number.isFinite(rowsRaw)
        && Number.isFinite(colsRaw)
        && rowsRaw === colsRaw
        && rowsRaw >= min
    ) {
        grid = Math.max(min, Math.min(max, rowsRaw));
    }

    return {
        split_enabled: Boolean(source.split_enabled),
        split_grid_size: grid,
        split_rows: grid,
        split_columns: grid,
        expected_panels: grid * grid,
        resize_enabled: Boolean(source.resize_enabled),
        resize_width:
            source.resize_width === null || source.resize_width === undefined || source.resize_width === ''
                ? null
                : Number.parseInt(String(source.resize_width), 10) || null,
        resize_height:
            source.resize_height === null || source.resize_height === undefined || source.resize_height === ''
                ? null
                : Number.parseInt(String(source.resize_height), 10) || null,
    };
}

/**
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   onSubmit: (payload: {
 *     userBrief: string,
 *     loaiSanPhamCategoryArticleId?: number,
 *     loaiSanPhamCustom?: string,
 *   }) => void,
 *   initialPrompt?: string,
 *   mode?: 'editor' | 'product-gallery',
 *   productCategoryOptions?: Array<{ id: number, label: string }>,
 *   articleId?: number | string | null,
 *   siteId?: number | string | null,
 *   productGalleryItems?: Array<{ id?: number, url: string, connected?: boolean }>,
 *   canaryProduct?: boolean,
 *   parentChildAllowed?: boolean,
 *   parentChildReason?: string,
 * }} props
 */
export default function GenerateImageModal({
    open,
    onClose,
    onSubmit,
    initialPrompt = '',
    initialLoaiSanPhamCustom = '',
    mode = 'editor',
    productCategoryOptions = [],
    articleId = null,
    siteId = null,
    productGalleryItems = [],
    canaryProduct = false,
    parentChildAllowed = false,
    parentChildReason = '',
}) {
    const [prompt, setPrompt] = useState(initialPrompt);
    const [productCategoryId, setProductCategoryId] = useState('');
    const [loaiSanPhamCustom, setLoaiSanPhamCustom] = useState(initialLoaiSanPhamCustom);
    const [submitting, setSubmitting] = useState(false);
    const [renderedPrompt, setRenderedPrompt] = useState('');
    const [renderedPromptMeta, setRenderedPromptMeta] = useState({ promptId: 0, promptName: '' });
    const [postProcessing, setPostProcessing] = useState(() => normalizePostProcessing({}));
    const [promptPreviewLoading, setPromptPreviewLoading] = useState(false);
    const [promptPreviewError, setPromptPreviewError] = useState('');
    const [galleryItems, setGalleryItems] = useState([]);
    const [sourceImage, setSourceImage] = useState(null);
    const [splitPreviewItems, setSplitPreviewItems] = useState([]);
    const [previewGrid, setPreviewGrid] = useState(3);
    const [selectedSplitUrl, setSelectedSplitUrl] = useState('');
    const [generationError, setGenerationError] = useState('');
    const [generationErrorTechnical, setGenerationErrorTechnical] = useState('');
    const [generationErrorRetryable, setGenerationErrorRetryable] = useState(false);
    const [mode1Status, setMode1Status] = useState(null);
    const [galleryGenerationMode, setGalleryGenerationMode] = useState('sprite');
    const [providerSupportsReference, setProviderSupportsReference] = useState(null);
    const [mode2Progress, setMode2Progress] = useState('');
    const pollTimerRef = useRef(null);
    const pendingMediaIdRef = useRef(null);
    const pendingExecutionIdRef = useRef(null);
    const connectedUrlsRef = useRef(new Set());
    const [pendingMediaId, setPendingMediaId] = useState(null);
    const [pendingExecutionId, setPendingExecutionId] = useState(null);

    const isProductGallery = mode === 'product-gallery';
    const twoColumn = isProductGallery;
    const numericArticleId = Number(articleId ?? 0) || 0;
    const numericSiteId = Number(siteId ?? 0) || 0;

    useEffect(() => {
        pendingMediaIdRef.current = pendingMediaId;
    }, [pendingMediaId]);

    useEffect(() => {
        pendingExecutionIdRef.current = pendingExecutionId;
    }, [pendingExecutionId]);

    const refreshGalleryItems = useCallback(() => {
        const external = mergeGalleryPreviewItems(
            productGalleryItems,
            readProductGalleryItemsFromDom(),
            readProductGalleryItemsFromStorage(articleId),
        );
        const externalUrls = new Set(external.map((item) => item.url));

        connectedUrlsRef.current.forEach((url) => {
            if (!externalUrls.has(url)) {
                connectedUrlsRef.current.delete(url);
            }
        });

        const sourceUrl = String(sourceImage?.url ?? '').trim();
        setGalleryItems(
            external
                .filter((item) => !sourceUrl || item.url !== sourceUrl)
                .filter((item) => !isNonGalleryArtifactRole(item.role))
                .map((item) => ({
                    ...item,
                    connected: item.connected || connectedUrlsRef.current.has(item.url),
                })),
        );
    }, [articleId, productGalleryItems, sourceImage]);

    useEffect(() => {
        refreshGalleryItems();
    }, [productGalleryItems, refreshGalleryItems]);

    useEffect(() => {
        if (open) {
            setPrompt(initialPrompt);
            setProductCategoryId('');
            setLoaiSanPhamCustom(initialLoaiSanPhamCustom);
            setSubmitting(false);
            setRenderedPrompt('');
            setRenderedPromptMeta({ promptId: 0, promptName: '' });
            setPostProcessing(normalizePostProcessing({}));
            setPromptPreviewError('');
            setPendingMediaId(null);
            setPendingExecutionId(null);
            setGenerationError('');
            setSourceImage(null);
            setSplitPreviewItems([]);
            setPreviewGrid(3);
            setSelectedSplitUrl('');
            setMode1Status(null);
            setGalleryGenerationMode('sprite');
            setMode2Progress('');
            setProviderSupportsReference(null);
            refreshGalleryItems();

            if (mode === 'product-gallery') {
                callEditArticleLivewire('resolveProductGalleryReferenceCapability')
                    .then((payload) => {
                        if (!payload || typeof payload !== 'object') {
                            return;
                        }
                        if (typeof payload.supports_reference_image === 'boolean') {
                            setProviderSupportsReference(payload.supports_reference_image);
                        }
                    })
                    .catch(() => {
                        setProviderSupportsReference(null);
                    });
            }
        }
    }, [open, initialPrompt, initialLoaiSanPhamCustom, refreshGalleryItems, mode]);

    useEffect(() => {
        if (!parentChildAllowed && galleryGenerationMode === 'parent_child') {
            setGalleryGenerationMode('sprite');
        }
    }, [parentChildAllowed, galleryGenerationMode]);

    const markConnectedItem = useCallback((item) => {
        const normalized = normalizeGalleryPreviewItem(item, { connected: true });
        if (!normalized) {
            return;
        }

        connectedUrlsRef.current.add(normalized.url);
        setGalleryItems((prev) => mergeGalleryPreviewItems(prev, [normalized]));
    }, []);

    const applyGalleryPreviewFromPayload = useCallback(
        (payload) => {
            const url = String(payload?.url ?? '').trim();
            const status = String(payload?.status ?? '').toLowerCase();
            const mediaId = Number(payload?.seoMediaId ?? payload?.seo_media_id ?? payload?.id ?? 0) || 0;
            const galleryRows = Array.isArray(payload?.gallery_urls)
                ? payload.gallery_urls
                : Array.isArray(payload?.galleryUrls)
                  ? payload.galleryUrls
                  : [];
            const productGallery = payload?.product_gallery && typeof payload.product_gallery === 'object'
                ? payload.product_gallery
                : null;

            if (productGallery) {
                setMode1Status(productGallery);
            }

            if (url) {
                const processing = status === 'processing' || status === 'pending';
                const role = payload?.media_artifact_role || payload?.mediaArtifactRole || null;
                const item = {
                    id: mediaId,
                    url,
                    processing,
                    connected: status === 'completed',
                    role,
                };

                if (isProductGallery && (isNonGalleryArtifactRole(role) || mediaId > 0)) {
                    setSourceImage(item);
                    setSelectedSplitUrl(url);
                } else if (!isProductGallery) {
                    if (status === 'completed') {
                        markConnectedItem(item);
                    } else {
                        setGalleryItems((prev) => mergeGalleryPreviewItems(prev, [item]));
                    }
                }
            }

            if (galleryRows.length > 0) {
                const rows = galleryRows
                    .map((row) => {
                        if (typeof row === 'string') {
                            return normalizeGalleryPreviewItem({ url: row, connected: true });
                        }

                        return normalizeGalleryPreviewItem({ ...row, connected: true });
                    })
                    .filter((row) => {
                        if (!row) {
                            return false;
                        }
                        if (url && row.url === url) {
                            return false;
                        }
                        if (mediaId > 0 && row.id === mediaId) {
                            return false;
                        }
                        return true;
                    });

                rows.forEach((row) => connectedUrlsRef.current.add(row.url));
                setSplitPreviewItems(rows);
                setGalleryItems((prev) => mergeGalleryPreviewItems(prev, rows));
            }

            if (status === 'completed' || status === 'failed') {
                setSubmitting(false);
                setPendingMediaId(null);
                setPendingExecutionId(null);
                setMode2Progress('');
                setGalleryItems((prev) =>
                    prev.map((item) => ({
                        ...item,
                        processing: false,
                    })),
                );
            }

            if (status === 'failed') {
                setGenerationError(String(payload?.error_message ?? payload?.message ?? t('editor_ai_failed')));
                setGenerationErrorTechnical(String(payload?.technical_details ?? payload?.technicalDetails ?? ''));
                setGenerationErrorRetryable(Boolean(payload?.retryable));
            }
        },
        [isProductGallery, markConnectedItem],
    );

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const onGalleryUpdated = () => refreshGalleryItems();
        window.addEventListener('seo-product-gallery-updated', onGalleryUpdated);

        return () => window.removeEventListener('seo-product-gallery-updated', onGalleryUpdated);
    }, [open, isProductGallery, refreshGalleryItems]);

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const onPromptPreview = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            setPromptPreviewLoading(false);
            if (detail.error) {
                setPromptPreviewError(String(detail.error));
                setRenderedPrompt('');
                return;
            }

            setPromptPreviewError('');
            setRenderedPrompt(String(detail.rendered ?? ''));
            setRenderedPromptMeta({
                promptId: Number(detail.prompt_id ?? detail.promptId ?? 0) || 0,
                promptName: String(detail.prompt_name ?? detail.promptName ?? ''),
            });
            setPostProcessing(normalizePostProcessing(detail.post_processing ?? detail.postProcessing ?? {}));
        };

        const onImageGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const target = String(detail.target ?? '').trim();
            if (target !== '' && target !== 'product-gallery') {
                return;
            }

            const mediaId = Number(detail.seoMediaId ?? detail.seo_media_id ?? 0) || 0;
            const executionId = String(detail.gallery_execution_id ?? detail.galleryExecutionId ?? '').trim();
            const status = String(detail.status ?? '').toLowerCase();
            const processing = status === 'processing' || status === 'pending' || status === 'queued';

            if (typeof detail.supports_reference_image === 'boolean') {
                setProviderSupportsReference(detail.supports_reference_image);
            }

            if (processing && mediaId <= 0 && executionId === '') {
                setSubmitting(false);
                setPendingMediaId(null);
                setPendingExecutionId(null);
                setGenerationError(String(detail.message ?? detail.error_message ?? t('editor_ai_failed')));
                return;
            }

            if (mediaId > 0 && processing) {
                setPendingMediaId(mediaId);
            }
            if (executionId !== '' && processing) {
                setPendingExecutionId(executionId);
                setMode2Progress(t('processing'));
            }

            setGenerationError('');
            applyGalleryPreviewFromPayload(detail);
        };

        const onMediaFailed = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const mediaId = Number(detail.seoMediaId ?? 0) || 0;
            const pendingId = pendingMediaIdRef.current;
            if (pendingId && mediaId > 0 && mediaId !== pendingId) {
                return;
            }

            setSubmitting(false);
            setPendingMediaId(null);
            setPendingExecutionId(null);
            setGenerationError(String(detail.message ?? t('editor_generate_image_failed')));
            setGenerationErrorTechnical(String(detail.technicalDetails ?? detail.technical_details ?? ''));
            setGenerationErrorRetryable(Boolean(detail.retryable));
        };

        window.addEventListener('article-generate-image-prompt-preview', onPromptPreview);
        window.addEventListener('article-ai-image-generated', onImageGenerated);
        window.addEventListener('article-ai-media-failed', onMediaFailed);

        return () => {
            window.removeEventListener('article-generate-image-prompt-preview', onPromptPreview);
            window.removeEventListener('article-ai-image-generated', onImageGenerated);
            window.removeEventListener('article-ai-media-failed', onMediaFailed);
        };
    }, [open, isProductGallery, applyGalleryPreviewFromPayload]);

    useEffect(() => {
        if (!open || !isProductGallery || !pendingMediaId) {
            return undefined;
        }

        let cancelled = false;
        let attempt = 0;
        const maxAttempts = 72;

        const poll = async () => {
            if (cancelled) {
                return;
            }

            attempt += 1;

            try {
                const payload = await fetchSeoMediaStatus(pendingMediaId);
                applyGalleryPreviewFromPayload(payload);

                const status = String(payload?.status ?? '').toLowerCase();
                if (status === 'completed' || status === 'failed') {
                    return;
                }
            } catch {
                if (attempt >= maxAttempts) {
                    setSubmitting(false);
                    setGenerationError(t('editor_ai_failed'));
                }
            }

            if (attempt >= maxAttempts) {
                setSubmitting(false);
                return;
            }

            pollTimerRef.current = window.setTimeout(poll, 5000);
        };

        pollTimerRef.current = window.setTimeout(poll, 3000);

        return () => {
            cancelled = true;
            if (pollTimerRef.current) {
                window.clearTimeout(pollTimerRef.current);
                pollTimerRef.current = null;
            }
        };
    }, [open, isProductGallery, pendingMediaId, applyGalleryPreviewFromPayload]);

    useEffect(() => {
        if (!open || !isProductGallery || !pendingExecutionId || pendingMediaId) {
            return undefined;
        }

        let cancelled = false;
        let attempt = 0;
        const maxAttempts = 90;
        let timerId = null;

        const poll = async () => {
            if (cancelled) {
                return;
            }

            attempt += 1;

            try {
                const payload = await callEditArticleLivewire(
                    'pollProductGalleryExecutionStatus',
                    pendingExecutionId,
                );
                const status = String(payload?.status ?? '').toLowerCase();

                if (status === 'completed') {
                    setSubmitting(false);
                    setPendingExecutionId(null);
                    setMode2Progress('');
                    refreshGalleryItems();
                    window.dispatchEvent(new CustomEvent('seo-product-gallery-updated'));
                    return;
                }

                if (status === 'failed') {
                    setSubmitting(false);
                    setPendingExecutionId(null);
                    setMode2Progress('');
                    setGenerationError(
                        String(payload?.message ?? payload?.failure_reason ?? t('editor_ai_failed')),
                    );
                    return;
                }

                setMode2Progress(t('processing'));
            } catch {
                if (attempt >= maxAttempts) {
                    setSubmitting(false);
                    setPendingExecutionId(null);
                    setGenerationError(t('editor_ai_failed'));
                    return;
                }
            }

            if (attempt >= maxAttempts) {
                setSubmitting(false);
                setPendingExecutionId(null);
                return;
            }

            timerId = window.setTimeout(poll, 5000);
        };

        timerId = window.setTimeout(poll, 3000);

        return () => {
            cancelled = true;
            if (timerId) {
                window.clearTimeout(timerId);
            }
        };
    }, [open, isProductGallery, pendingExecutionId, pendingMediaId, refreshGalleryItems]);

    const handleSplitSaved = useCallback(
        (data) => {
            const galleryRows = Array.isArray(data?.product_gallery_items) ? data.product_gallery_items : [];
            if (numericArticleId > 0 && galleryRows.length > 0) {
                const sourceUrl = String(sourceImage?.url ?? '').trim();
                const sourceId = Number(sourceImage?.id ?? 0) || 0;
                const pieces = galleryRows.filter((row) => {
                    const url = String(row?.url ?? '').trim();
                    const id = Number(row?.id ?? 0) || 0;
                    if (url !== '' && sourceUrl !== '' && url === sourceUrl) {
                        return false;
                    }
                    if (id > 0 && sourceId > 0 && id === sourceId) {
                        return false;
                    }
                    return true;
                });
                appendProductAlbumItems(numericArticleId, pieces);
                const normalizedRows = pieces
                    .map((row) => normalizeGalleryPreviewItem(row, { connected: true }))
                    .filter(Boolean);
                normalizedRows.forEach((row) => connectedUrlsRef.current.add(row.url));
                setSplitPreviewItems(normalizedRows);
                setGalleryItems((prev) => mergeGalleryPreviewItems(prev, normalizedRows));
            }

            if (numericArticleId > 0) {
                syncProductAlbumToServer(numericArticleId);
            }

            setSelectedSplitUrl('');
        },
        [numericArticleId, sourceImage],
    );

    const categoryId = Number.parseInt(String(productCategoryId || ''), 10) || 0;
    const customValue = String(loaiSanPhamCustom || '').trim();
    const brief = String(prompt || '').trim();

    const fetchPromptPreview = useCallback(() => {
        if (!isProductGallery) {
            return;
        }

        setPromptPreviewLoading(true);
        setPromptPreviewError('');
        requestPromptPreview({
            userBrief: brief,
            target: 'product-gallery',
            loaiSanPhamCategoryArticleId: categoryId,
            loaiSanPhamCustom: customValue,
        });
    }, [isProductGallery, brief, categoryId, customValue]);

    useEffect(() => {
        if (!open || !isProductGallery) {
            return undefined;
        }

        const timer = window.setTimeout(fetchPromptPreview, 300);

        return () => window.clearTimeout(timer);
    }, [open, isProductGallery, fetchPromptPreview]);

    const albumPreviewCells = useMemo(() => {
        const size = previewGrid;
        const cells = [];
        for (let row = 0; row < size; row += 1) {
            for (let col = 0; col < size; col += 1) {
                const index = row * size + col;
                cells.push({
                    row,
                    col,
                    label: gridCellLabel(row, col),
                    piece: splitPreviewItems[index] ?? null,
                });
            }
        }
        return cells;
    }, [previewGrid, splitPreviewItems]);

    const selectedSplitItem = sourceImage && Number(sourceImage.id) > 0 ? sourceImage : null;

    const applyPreviewGrid = (next) => {
        const grid = Math.max(2, Math.min(4, Number(next) || 3));
        setPreviewGrid(grid);
        setPostProcessing((prev) =>
            normalizePostProcessing({
                ...prev,
                split_grid_size: grid,
                split_rows: grid,
                split_columns: grid,
            }),
        );
    };

    if (!open) {
        return null;
    }

    const hasLoaiSanPham = categoryId > 0 || customValue !== '';
    const canSubmit = isProductGallery
        ? hasLoaiSanPham && !submitting
        : brief !== '' && !submitting;

    const handleSubmit = () => {
        if (!canSubmit) {
            return;
        }

        const payload = {
            userBrief: brief,
            loaiSanPhamCategoryArticleId: categoryId,
            loaiSanPhamCustom: customValue,
            galleryGenerationMode:
                galleryGenerationMode === 'parent_child' && !parentChildAllowed
                    ? 'sprite'
                    : galleryGenerationMode,
        };

        setSubmitting(true);
        setGenerationError('');
        setPendingMediaId(null);

        if (isProductGallery) {
            onSubmit(payload);
            return;
        }

        onClose();
        onSubmit(payload);
        setPrompt('');
        setProductCategoryId('');
        setLoaiSanPhamCustom('');
        window.setTimeout(() => setSubmitting(false), 500);
    };

    const formColumn = (
        <div className="seo-generate-image-modal__col seo-generate-image-modal__col--form">
            {isProductGallery ? (
                <>
                    <section className="seo-generate-image-modal__card">
                        <h4 className="seo-generate-image-modal__card-title">
                            <LayoutGrid size={16} />
                            <span>1. {t('generate_image_mode2_mode_label')}</span>
                        </h4>
                        <label className="seo-generate-image-modal__label" htmlFor="seo-generate-gallery-mode">
                            {t('generate_image_mode2_mode_label')}
                        </label>
                        <SeoSelect
                            id="seo-generate-gallery-mode"
                            value={galleryGenerationMode}
                            onChange={(event) => {
                                const next = String(event.target.value ?? '');
                                if (next === 'parent_child' && !parentChildAllowed) {
                                    return;
                                }
                                setGalleryGenerationMode(next);
                            }}
                            options={[
                                { value: 'sprite', label: t('generate_image_mode2_mode_sprite') },
                                {
                                    value: 'parent_child',
                                    label: t('generate_image_mode2_mode_parent_child'),
                                    disabled: !parentChildAllowed,
                                },
                                { value: 'auto', label: t('generate_image_mode2_mode_auto') },
                            ]}
                        />
                        {!parentChildAllowed ? (
                            <p className="seo-generate-image-modal__helper">
                                {t('generate_image_mode2_feature_disabled')}
                                {parentChildReason ? ` (${parentChildReason})` : ''}
                            </p>
                        ) : null}
                        <p className="seo-generate-image-modal__helper">
                            {t('generate_image_mode2_capability_label')}
                            {': '}
                            {providerSupportsReference === null
                                ? t('generate_image_mode2_capability_unknown')
                                : providerSupportsReference
                                  ? t('generate_image_mode2_capability_yes')
                                  : t('generate_image_mode2_capability_no')}
                        </p>
                        {galleryGenerationMode === 'parent_child' && providerSupportsReference === false ? (
                            <p className="seo-generate-image-modal__helper">
                                {t('generate_image_mode2_unsupported_hint')}
                            </p>
                        ) : null}
                        {canaryProduct ? (
                            <div className="seo-generate-image-modal__helper" data-testid="pg-canary-badge">
                                <strong>Canary Product</strong>
                                {' — '}
                                Test A Sprite · Test C Parent/Child · Test D Auto. Original media must exist before Mode 2.
                            </div>
                        ) : null}
                        {mode2Progress ? (
                            <p className="seo-generate-image-modal__helper">{mode2Progress}</p>
                        ) : null}
                    </section>

                    <section className="seo-generate-image-modal__card">
                        <h4 className="seo-generate-image-modal__card-title">
                            <Tag size={16} />
                            <span>2. {t('generate_image_product_cat_label')}</span>
                        </h4>
                        <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-product-cat">
                            {t('generate_image_product_cat_label')}
                        </label>
                        <SeoSelect
                            id="seo-generate-image-product-cat"
                            value={productCategoryId}
                            onChange={(event) => setProductCategoryId(event.target.value)}
                            placeholder={t('generate_image_product_cat_placeholder')}
                            options={productCategoryOptions.map((option) => ({
                                value: option.id,
                                label: option.label,
                            }))}
                        />
                        <p className="seo-generate-image-modal__helper">{t('generate_image_product_cat_helper')}</p>

                        <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-loai-custom">
                            {t('generate_image_loai_san_pham_custom_label')}
                        </label>
                        <input
                            id="seo-generate-image-loai-custom"
                            type="text"
                            value={loaiSanPhamCustom}
                            onChange={(event) => setLoaiSanPhamCustom(event.target.value)}
                            className="seo-generate-image-modal__input"
                            placeholder={t('generate_image_loai_san_pham_custom_placeholder')}
                        />
                    </section>
                </>
            ) : null}

            <section className={isProductGallery ? 'seo-generate-image-modal__card' : undefined}>
                {isProductGallery ? (
                    <h4 className="seo-generate-image-modal__card-title">
                        <FileText size={16} />
                        <span>3. {t('generate_image_prompt_label')}</span>
                    </h4>
                ) : null}
                <label className="seo-generate-image-modal__label" htmlFor="seo-generate-image-prompt">
                    {t('generate_image_prompt_label')}
                </label>
                <textarea
                    id="seo-generate-image-prompt"
                    value={prompt}
                    onChange={(event) => setPrompt(event.target.value.slice(0, 500))}
                    className="seo-generate-image-modal__textarea"
                    placeholder={
                        isProductGallery
                            ? t('generate_image_prompt_placeholder')
                            : t('compose_placeholder')
                    }
                    rows={isProductGallery ? 6 : 8}
                    maxLength={500}
                    autoFocus={!isProductGallery}
                />
                {isProductGallery ? (
                    <p className="seo-generate-image-modal__char-count">{`${prompt.length}/500`}</p>
                ) : null}
            </section>
        </div>
    );

    const previewColumn = twoColumn ? (
        <div className="seo-generate-image-modal__col seo-generate-image-modal__col--preview">
            <section className="seo-generate-image-modal__preview-section">
                <h4 className="seo-generate-image-modal__preview-heading">{t('generate_image_source_heading')}</h4>
                {generationError ? (
                    <div className="seo-generate-image-modal__error-box">
                        <p className="seo-generate-image-modal__error">{generationError}</p>
                        {generationErrorRetryable ? (
                            <button
                                type="button"
                                className="seo-generate-image-modal__retry"
                                onClick={() => {
                                    setGenerationError('');
                                    setGenerationErrorTechnical('');
                                    setGenerationErrorRetryable(false);
                                }}
                            >
                                {t('retry') || 'Retry'}
                            </button>
                        ) : null}
                    </div>
                ) : null}
                {sourceImage?.url ? (
                    <div className="seo-generate-image-modal__source">
                        <div className="seo-generate-image-modal__source-thumb">
                            <img src={sourceImage.url} alt="" />
                            {sourceImage.connected ? (
                                <span className="seo-generate-image-modal__image-badge is-connected">
                                    {t('generate_image_preview_connected')}
                                </span>
                            ) : null}
                            {sourceImage.processing ? (
                                <span className="seo-generate-image-modal__image-badge">{t('processing')}</span>
                            ) : null}
                        </div>
                        <p className="seo-generate-image-modal__helper">{t('generate_image_source_helper')}</p>
                    </div>
                ) : submitting ? (
                    <div className="seo-generate-image-modal__skeleton" aria-hidden="true">
                        <span />
                    </div>
                ) : (
                    <p className="seo-generate-image-modal__empty">{t('generate_image_source_empty')}</p>
                )}
            </section>

            <section className="seo-generate-image-modal__preview-section">
                <div className="seo-generate-image-modal__preview-heading-row">
                    <h4 className="seo-generate-image-modal__preview-heading">{t('generate_image_album_preview_heading')}</h4>
                    <button
                        type="button"
                        className="seo-generate-image-modal__refresh-preview"
                        onClick={fetchPromptPreview}
                        disabled={promptPreviewLoading || submitting}
                    >
                        <RefreshCw size={12} />
                        {' '}
                        {t('generate_image_preview_refresh')}
                    </button>
                </div>
                <div className="seo-generate-image-modal__grid-controls">
                    <div className="seo-generate-image-modal__segmented">
                        <span>{t('generate_image_rows')}</span>
                        {[2, 3, 4].map((n) => (
                            <button
                                key={`rows-${n}`}
                                type="button"
                                className={previewGrid === n ? 'is-active' : ''}
                                onClick={() => applyPreviewGrid(n)}
                            >
                                {n}
                            </button>
                        ))}
                    </div>
                    <div className="seo-generate-image-modal__segmented">
                        <span>{t('generate_image_cols')}</span>
                        {[2, 3, 4].map((n) => (
                            <button
                                key={`cols-${n}`}
                                type="button"
                                className={previewGrid === n ? 'is-active' : ''}
                                onClick={() => applyPreviewGrid(n)}
                            >
                                {n}
                            </button>
                        ))}
                    </div>
                </div>
                {submitting && !sourceImage?.url ? (
                    <div className="seo-generate-image-modal__sprite-grid is-loading" style={{ '--pg-grid': previewGrid }}>
                        {albumPreviewCells.map((cell) => (
                            <div key={cell.label} className="seo-generate-image-modal__sprite-cell is-skeleton">
                                <span>{cell.label}</span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="seo-generate-image-modal__sprite-grid" style={{ '--pg-grid': previewGrid }}>
                        {albumPreviewCells.map((cell) => (
                            <div key={cell.label} className="seo-generate-image-modal__sprite-cell">
                                {cell.piece?.url ? (
                                    <img src={cell.piece.url} alt="" />
                                ) : sourceImage?.url ? (
                                    <span
                                        className="seo-generate-image-modal__sprite-crop"
                                        style={{
                                            backgroundImage: `url(${sourceImage.url})`,
                                            backgroundSize: `${previewGrid * 100}% ${previewGrid * 100}%`,
                                            backgroundPosition: `${previewGrid > 1 ? (cell.col / (previewGrid - 1)) * 100 : 0}% ${previewGrid > 1 ? (cell.row / (previewGrid - 1)) * 100 : 0}%`,
                                        }}
                                    />
                                ) : (
                                    <span className="seo-generate-image-modal__sprite-placeholder" />
                                )}
                                <em>{cell.label}</em>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {selectedSplitItem && Number(selectedSplitItem.id) > 0 && splitPreviewItems.length === 0 ? (
                <section className="seo-generate-image-modal__preview-section seo-generate-image-modal__split-section">
                    <h4 className="seo-generate-image-modal__preview-heading">{t('split_grid')}</h4>
                    <p className="seo-generate-image-modal__helper">{t('generate_image_source_helper')}</p>
                    <ImageSplitterPanel
                        siteId={numericSiteId > 0 ? numericSiteId : null}
                        articleId={numericArticleId > 0 ? numericArticleId : null}
                        seoMediaId={selectedSplitItem.id}
                        imageUrl={selectedSplitItem.url}
                        variant="gallery"
                        defaultRows={previewGrid}
                        defaultCols={previewGrid}
                        autoSaveOnSplit
                        canDeleteOriginal={false}
                        onSplitSaved={handleSplitSaved}
                    />
                </section>
            ) : null}

            <details className="seo-generate-image-modal__tech-panel">
                <summary>{t('generate_image_tech_heading')}</summary>
                <ul>
                    <li>
                        {t('generate_image_mode1_mode_label')}
                        {': '}
                        {t('generate_image_mode1_mode_sprite')}
                    </li>
                    <li>
                        {t('generate_image_tech_grid')}
                        {': '}
                        {previewGrid}
                        {' × '}
                        {previewGrid}
                    </li>
                    <li>
                        {t('generate_image_tech_total')}
                        {': '}
                        {previewGrid * previewGrid}
                    </li>
                    <li>
                        {t('generate_image_tech_ratio')}
                        {': '}
                        1:1
                    </li>
                    <li>
                        {t('generate_image_tech_format')}
                        {': '}
                        PNG
                    </li>
                </ul>
            </details>
        </div>
    ) : null;

    return createPortal(
        <div
            className="seo-generate-image-modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label={t('generate_image')}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className={`seo-generate-image-modal${twoColumn ? ' seo-generate-image-modal--split' : ''}`}>
                <div className="seo-generate-image-modal__head">
                    <h3>{isProductGallery ? t('generate_product_gallery_image') : t('generate_image')}</h3>
                    <button
                        type="button"
                        className="seo-generate-image-modal__close"
                        onClick={onClose}
                        aria-label={t('magic_close')}
                    >
                        <X size={18} />
                    </button>
                </div>
                <div className={`seo-generate-image-modal__body${twoColumn ? ' seo-generate-image-modal__body--split' : ''}`}>
                    {formColumn}
                    {previewColumn}
                </div>
                <div className="seo-generate-image-modal__actions">
                    <button type="button" className="seo-generate-image-modal__cancel" onClick={onClose}>
                        {t('magic_close')}
                    </button>
                    <button
                        type="button"
                        className="seo-generate-image-modal__submit"
                        onClick={handleSubmit}
                        disabled={!canSubmit}
                        title={isProductGallery && !hasLoaiSanPham ? t('generate_image_loai_san_pham_required') : undefined}
                    >
                        {submitting ? t('generating_image') : t('generate_image')}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
