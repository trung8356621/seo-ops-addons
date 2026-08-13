import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    ExternalLink,
    FlaskConical,
    ImageUp,
    Link2,
    Loader2,
    MoreHorizontal,
    RotateCcw,
    Scissors,
    Shield,
    Trash2,
    Type,
} from 'lucide-react';
import {
    assignInArticleQuickFixIndices,
    collectImagesFromBlocks,
    filterSupplementalDuplicatesOfBlockRows,
    hasTrustedWordPressUrl,
    mergeArticleImageRow,
    resolveArticleImageRemoveTarget,
} from '../utils/articleImagesUtils';
import { isLaravelManagedMedia, isWordPressProtectedMedia } from '../utils/mediaSourceClassification';
import { t } from '@content-addon/utils/i18n.js';
import {
    AI_PLACEHOLDER_LOADING_URL,
    applyWatermarkToImage,
    buildMediaImageEditorUrl,
    fetchArticleAiMediaJobs,
    prepareImageEditorUrl,
    deleteAiMediaJob,
    retryAiMediaGeneration,
    testOptimizeLocalWebp,
} from '../utils/seoMediaApi';
import { BROKEN_IMAGE_PLACEHOLDER } from '../utils/brokenImageGuard';

const LOCAL_MEDIA_PATH = '/storage/uploads/seo_media/';
/** Poll danh sách job AI tối đa 1 lần / phút khi còn job đang xử lý. */
const AI_JOBS_POLL_MS = 60_000;

const AI_STATUS_LABELS = {
    processing: t('processing'),
    failed: t('failed'),
};

function isLocalSeoMediaSrc(src) {
    return typeof src === 'string' && src.includes(LOCAL_MEDIA_PATH);
}

function isLegacyRandomFolderSeoMediaSrc(src) {
    if (typeof src !== 'string') {
        return false;
    }
    // /storage/uploads/seo_media/<random>/<filename>
    return /\/storage\/uploads\/seo_media\/[^/]+\/[^/]+$/i.test(src.trim());
}

function canProcessArticleImage(row) {
    const seoMediaId = Number(row.seoMediaId ?? 0);
    const wpAttachmentId = Number(row.wpAttachmentId ?? 0);

    if (seoMediaId > 0 || wpAttachmentId > 0) {
        return true;
    }

    return isLocalSeoMediaSrc(row.src);
}

function distinctUrls(primary, secondary) {
    const a = String(primary || '').trim();
    const b = String(secondary || '').trim();
    if (!a || !b) return false;
    return a !== b;
}

function AiMediaJobRow({ job, onRetry, onFocusBlock, onNotify }) {
    const [retrying, setRetrying] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [showRetryInput, setShowRetryInput] = useState(false);
    const [retryInput, setRetryInput] = useState(String(job.retry_input || ''));
    const status = String(job.status ?? 'processing').toLowerCase();
    const statusLabel = AI_STATUS_LABELS[status] ?? status;
    const previewSrc = job.url?.includes('placeholder-loading')
        ? AI_PLACEHOLDER_LOADING_URL
        : job.url || AI_PLACEHOLDER_LOADING_URL;
    const mediaTypeLabel = job.media_type === 'video' ? t('ai_video') : t('ai_image');
    const editorBlockId = (job.editor_block_id ?? '').trim();

    useEffect(() => {
        setRetryInput(String(job.retry_input || ''));
    }, [job.retry_input, job.id]);

    const handleRetry = async () => {
        if (retrying || deleting || !job.id) {
            return;
        }

        setRetrying(true);
        try {
            const data = await retryAiMediaGeneration(job.id, retryInput);
            onRetry?.(data);
            setShowRetryInput(false);

            if (data.editor_block_id) {
                window.dispatchEvent(
                    new CustomEvent('article-ai-image-generated', {
                        detail: {
                            url: data.url,
                            activeBlockId: data.editor_block_id,
                            seoMediaId: data.id,
                            status: data.status ?? 'processing',
                            mediaType: data.media_type ?? 'image',
                        },
                    }),
                );
            }
        } catch (error) {
            onNotify?.({
                title: t('ai_retry_failed'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setRetrying(false);
        }
    };

    const handleDelete = async () => {
        if (retrying || deleting || !job.id) {
            return;
        }

        const ok = window.confirm(t('ai_delete_confirm'));
        if (!ok) {
            return;
        }

        setDeleting(true);
        try {
            await deleteAiMediaJob(job.id);
            onRetry?.();
        } catch (error) {
            onNotify?.({
                title: t('ai_delete_failed'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setDeleting(false);
        }
    };

    return (
        <li className={`seo-article-images-row seo-article-images-row--ai-job is-${status}`}>
            <div className="seo-article-images-preview">
                <button
                    type="button"
                    className="seo-article-images-thumb-btn"
                    onClick={() => editorBlockId && onFocusBlock?.(editorBlockId)}
                    title={editorBlockId ? t('ai_focus_editor_block') : t('ai_job')}
                    disabled={!editorBlockId}
                >
                    <div className="seo-article-images-thumb seo-article-images-thumb--ai-placeholder">
                        <img src={previewSrc} alt="" className="seo-article-images-thumb__img" />
                        {status === 'processing' ? (
                            <span className="seo-article-images-thumb__spinner" aria-hidden="true">
                                <Loader2 size={22} className="animate-spin" />
                            </span>
                        ) : null}
                    </div>
                </button>
                <p className="seo-article-images-alt">
                    {mediaTypeLabel}
                    <span className={`seo-article-images-ai-status is-${status}`}>{statusLabel}</span>
                </p>
            </div>

            <div className="seo-article-images-fields">
                <p className="seo-article-images-ai-meta">
                    Job #{job.id}
                    {job.slug ? ` · ${job.slug}` : ''}
                </p>

                {status === 'failed' && job.error_message ? (
                    <p className="seo-article-images-ai-error" role="alert">
                        {job.error_message}
                    </p>
                ) : null}

                <div className="seo-article-images-actions">
                    {status === 'failed' ? (
                        <>
                            <button
                                type="button"
                                className="seo-article-images-edit-btn"
                                disabled={retrying || deleting}
                                onClick={() => setShowRetryInput((prev) => !prev)}
                                title={t('ai_open_retry_input')}
                            >
                                <RotateCcw size={14} className={retrying ? 'animate-spin' : ''} />
                                {showRetryInput ? t('ai_close_retry_input') : t('retry')}
                            </button>
                            {showRetryInput ? (
                                <div style={{ width: '100%', marginTop: 8 }}>
                                    <textarea
                                        value={retryInput}
                                        onChange={(event) => setRetryInput(event.target.value)}
                                        rows={4}
                                        placeholder={t('ai_retry_input_placeholder')}
                                        className="w-full rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-900"
                                    />
                                    <div className="mt-2 flex items-center gap-2">
                                        <button
                                            type="button"
                                            className="seo-article-images-edit-btn"
                                            disabled={retrying || deleting}
                                            onClick={handleRetry}
                                            title={t('ai_retry_submit_title')}
                                        >
                                            <RotateCcw size={14} className={retrying ? 'animate-spin' : ''} />
                                            {retrying ? t('ai_submitting') : t('submit_retry')}
                                        </button>
                                    </div>
                                </div>
                            ) : null}
                        </>
                    ) : (
                        <p className="seo-article-images-ai-wait">{t('processing_wait')}</p>
                    )}
                    <button
                        type="button"
                        className="seo-article-images-delete-btn"
                        disabled={retrying || deleting}
                        onClick={handleDelete}
                        title={t('ai_delete_prompt_title')}
                    >
                        <Trash2 size={14} />
                        {deleting ? t('processing') : t('delete')}
                    </button>
                </div>
            </div>
        </li>
    );
}

function ImageRow({
    row,
    siteId,
    articleId,
    blocks = [],
    supplementalImages = [],
    onPatch,
    onFocusBlock,
    onQuickFixSlug,
    onQuickFixAltTitle,
    onRemoveImage,
    onRemoveSupplementalImage,
    onAltTitleChange,
    onMakeFeatured,
    canQuickFix = false,
    onNotify,
}) {
    const [alt, setAlt] = useState(row.alt ?? '');
    const [openingEditor, setOpeningEditor] = useState(false);
    const [applyingWatermark, setApplyingWatermark] = useState(false);
    const [optimizingWebp, setOptimizingWebp] = useState(false);
    const [makingFeatured, setMakingFeatured] = useState(false);
    const [webpTestResult, setWebpTestResult] = useState(null);
    const [moreOpen, setMoreOpen] = useState(false);
    const [thumbBroken, setThumbBroken] = useState(false);
    const moreMenuRef = useRef(null);
    const canPatchInEditor = Boolean(row.blockId);
    const removeTarget = useMemo(
        () => resolveArticleImageRemoveTarget(row, blocks, supplementalImages),
        [row, blocks, supplementalImages],
    );
    const canRemove = Boolean(removeTarget) && (
        removeTarget.kind === 'block'
            ? Boolean(onRemoveImage)
            : Boolean(onRemoveSupplementalImage)
    );
    const removeDisabledReason = !canRemove
        ? (thumbBroken ? t('image_tab_remove_unmatched_404') : t('image_tab_remove_no_block'))
        : (canPatchInEditor ? t('image_tab_remove_hint') : t('image_tab_remove_supplemental_hint'));
    const slugText = (row.slug || '').trim();
    const showActions = canProcessArticleImage(row);
    const busy = openingEditor || applyingWatermark || optimizingWebp || makingFeatured;
    const rawWpSrc = String(row.wpSrc || '').trim();
    const trustedWpUrl = hasTrustedWordPressUrl(row)
        ? (!isLocalSeoMediaSrc(rawWpSrc) ? rawWpSrc : String(row.src || '').trim())
        : '';
    const localUrl = (() => {
        const src = String(row.src || '').trim();
        const local = String(row.localSrc || '').trim();
        // src local mới thắng localSrc stale (sau Fix slug rename file).
        if (isLocalSeoMediaSrc(src)) {
            return src;
        }
        if (isLocalSeoMediaSrc(local)) {
            return local;
        }
        return '';
    })();
    const primaryUrl = trustedWpUrl || localUrl || String(row.src || '').trim();
    const showLocalExtra = Boolean(trustedWpUrl) && distinctUrls(trustedWpUrl, localUrl);
    const seoMediaId = Number(row.seoMediaId ?? row.seo_media_id ?? 0);
    const isWpProtected = isWordPressProtectedMedia(row);
    const isManagedByLaravel = isLaravelManagedMedia(row);
    const canQuickFixSlugOne = isWpProtected
        ? Number(row.wpAttachmentId ?? row.wp_attachment_id ?? 0) > 0
        : (
            canQuickFix &&
            (
                seoMediaId > 0 ||
                isLocalSeoMediaSrc(String(row.src || '').trim()) ||
                isLocalSeoMediaSrc(String(row.localSrc || '').trim())
            )
        );

    useEffect(() => {
        setAlt(row.alt ?? '');
    }, [row.alt, row.src]);

    useEffect(() => {
        setThumbBroken(false);
    }, [row.src]);

    useEffect(() => {
        if (!moreOpen) {
            return undefined;
        }

        const onMouseDown = (event) => {
            if (moreMenuRef.current?.contains(event.target)) {
                return;
            }

            setMoreOpen(false);
        };

        document.addEventListener('mousedown', onMouseDown);

        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [moreOpen]);

    const openImageEditor = async () => {
        if (!siteId || busy) {
            return;
        }

        setOpeningEditor(true);
        try {
            const data = await prepareImageEditorUrl({
                siteId,
                seoMediaId: row.seoMediaId,
                wpAttachmentId: row.wpAttachmentId,
                url: row.src,
                slug: row.slug,
            });
            if (data.editor_url) {
                window.open(data.editor_url, '_blank', 'noopener,noreferrer');
            }
        } catch (error) {
            onNotify?.({
                title: t('editor_cannot_open_image_editor'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setOpeningEditor(false);
        }
    };

    const handleApplyWatermark = async () => {
        if (!siteId || busy) {
            return;
        }

        setApplyingWatermark(true);
        try {
            const data = await applyWatermarkToImage({
                siteId,
                seoMediaId: row.seoMediaId,
                wpAttachmentId: row.wpAttachmentId,
                url: row.src,
                slug: row.slug,
            });
            if (data.url) {
                onPatch?.(row.blockId, { src: data.url });
            }
        } catch (error) {
            onNotify?.({
                title: t('image_watermark_failed'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setApplyingWatermark(false);
        }
    };

    const openImageSplitter = () => {
        const splitterUrl = buildMediaImageEditorUrl({
            seoMediaId: row.seoMediaId,
            tab: 'splitter',
        });
        if (!splitterUrl) {
            onNotify?.({
                title: t('image_splitter_open_failed'),
                body: t('image_splitter_open_failed_body'),
                status: 'warning',
            });
            return;
        }
        window.open(splitterUrl, '_blank', 'noopener,noreferrer');
    };

    const handleTestOptimizeWebp = async () => {
        if (!siteId || seoMediaId <= 0 || busy) {
            return;
        }

        setOptimizingWebp(true);
        setWebpTestResult(null);
        onNotify?.({
            title: t('test_optimize_webp'),
            body: t('test_optimize_webp_working'),
            status: 'info',
        });

        try {
            const data = await testOptimizeLocalWebp({ siteId, seoMediaId });
            const result = {
                url: String(data.url || '').trim(),
                path: String(data.path || '').trim(),
                width: Number(data.width ?? 0),
                height: Number(data.height ?? 0),
                bytes: Number(data.bytes ?? 0),
            };
            setWebpTestResult(result);

            const kb = Math.max(1, Math.round(result.bytes / 1024));
            onNotify?.({
                title: t('test_optimize_webp_success'),
                body: t('test_optimize_webp_success_body', {
                    width: result.width,
                    height: result.height,
                    kb,
                    path: result.path || '—',
                    url: result.url || '—',
                }),
                status: 'success',
            });
            // Không auto-open tab (dễ bị blocker / about:blank). Hiện link ngay dưới ảnh.
        } catch (error) {
            setWebpTestResult(null);
            onNotify?.({
                title: t('test_optimize_webp_failed'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setOptimizingWebp(false);
        }
    };

    const handleMakeFeatured = async () => {
        if (!articleId || !onMakeFeatured || busy) {
            return;
        }

        setMakingFeatured(true);
        try {
            await onMakeFeatured(row);
        } catch (error) {
            onNotify?.({
                title: t('make_featured_image_failed'),
                body: error?.message ?? t('editor_try_again_later'),
                status: 'danger',
            });
        } finally {
            setMakingFeatured(false);
        }
    };

    return (
        <li
            className="seo-article-images-row"
            data-seo-media-id={Number(row.seoMediaId ?? 0) > 0 ? Number(row.seoMediaId) : undefined}
            data-image-src={String(row.src || '').trim()}
        >
            <div className="seo-article-images-preview">
                <button
                    type="button"
                    className="seo-article-images-thumb-btn"
                    onClick={() => canPatchInEditor && onFocusBlock?.(row.blockId)}
                    title={canPatchInEditor ? t('image_focus_editor') : t('image_from_featured_or_album')}
                    disabled={!canPatchInEditor}
                >
                    <img
                        key={`${row.blockId}-${row.src}`}
                        src={thumbBroken ? BROKEN_IMAGE_PLACEHOLDER : row.src}
                        alt={(alt || row.title || '').trim()}
                        className={`seo-article-images-thumb${thumbBroken ? ' seo-img-broken' : ''}`}
                        onError={() => setThumbBroken(true)}
                    />
                </button>
                <p className="seo-article-images-slug" title={slugText || t('image_slug_placeholder')}>
                    {slugText || '—'}
                </p>
                {row?.role_flags && (row.role_flags.content || row.role_flags.featured || row.role_flags.gallery) ? (
                    <div className="seo-article-images-role-badges">
                        {row.role_flags.content ? <span className="seo-article-images-role-badge">Content</span> : null}
                        {row.role_flags.featured ? <span className="seo-article-images-role-badge">Featured</span> : null}
                        {row.role_flags.gallery ? <span className="seo-article-images-role-badge">Gallery</span> : null}
                    </div>
                ) : (row?.originLabel ? (
                    <p className="seo-article-images-origin">{row.originLabel}</p>
                ) : null)}
            </div>

            <div className="seo-article-images-fields">
                <div className="seo-article-images-field-row">
                    <label className="seo-image-meta-label">{t('image_alt_label')}</label>
                    <input
                        type="text"
                        className="seo-image-meta-input"
                        value={alt}
                        onChange={(e) => setAlt(e.target.value)}
                        onBlur={() => {
                            const trimmed = alt.trim();
                            if (trimmed === (row.alt || '').trim()) {
                                return;
                            }

                            if (onAltTitleChange) {
                                onAltTitleChange(row, trimmed);
                                return;
                            }

                            if (!canPatchInEditor) {
                                return;
                            }

                            onPatch?.(row.blockId, { alt: trimmed, title: trimmed });
                        }}
                        placeholder={t('image_alt_placeholder')}
                        disabled={!canPatchInEditor && !onAltTitleChange}
                    />
                    {row.wpAttachmentId && trustedWpUrl ? (
                        <a
                            href={trustedWpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="seo-article-images-wp-id"
                            title={trustedWpUrl}
                        >
                            {t('image_used_in_other_article', { url: trustedWpUrl })}
                        </a>
                    ) : null}
                </div>

                {trustedWpUrl ? (
                    <a
                        href={trustedWpUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="seo-article-images-src-link"
                    >
                        <ExternalLink size={14} />
                        <span className="truncate">{`WP: ${trustedWpUrl}`}</span>
                    </a>
                ) : primaryUrl ? (
                    <a
                        href={primaryUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="seo-article-images-src-link"
                    >
                        <ExternalLink size={14} />
                        <span className="truncate">
                            {isLocalSeoMediaSrc(primaryUrl) ? `Local: ${primaryUrl}` : primaryUrl}
                        </span>
                    </a>
                ) : null}
                {showLocalExtra ? (
                    <a
                        href={localUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="seo-article-images-src-link"
                    >
                        <ExternalLink size={14} />
                        <span className="truncate">{`Local: ${localUrl}`}</span>
                    </a>
                ) : null}

                {webpTestResult?.url ? (
                    <div className="seo-article-images-webp-test">
                        <p className="seo-article-images-webp-test__label">
                            {t('test_optimize_webp_result_label')}
                            {webpTestResult.width > 0
                                ? ` · ${webpTestResult.width}×${webpTestResult.height}px · ${Math.max(1, Math.round(webpTestResult.bytes / 1024))}KB`
                                : ''}
                        </p>
                        <a
                            href={webpTestResult.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="seo-article-images-src-link"
                            title={webpTestResult.url}
                        >
                            <ExternalLink size={14} />
                            <span className="truncate">{`WebP: ${webpTestResult.path || webpTestResult.url}`}</span>
                        </a>
                        <div className="seo-article-images-webp-test__actions">
                            <button
                                type="button"
                                className="seo-article-images-webp-test__btn"
                                onClick={() => window.open(webpTestResult.url, '_blank', 'noopener,noreferrer')}
                            >
                                {t('test_optimize_webp_open')}
                            </button>
                            <button
                                type="button"
                                className="seo-article-images-webp-test__btn"
                                onClick={async () => {
                                    try {
                                        await navigator.clipboard.writeText(webpTestResult.url);
                                        onNotify?.({
                                            title: t('test_optimize_webp_copy'),
                                            body: webpTestResult.url,
                                            status: 'success',
                                        });
                                    } catch {
                                        onNotify?.({
                                            title: t('test_optimize_webp_copy'),
                                            body: webpTestResult.url,
                                            status: 'info',
                                        });
                                    }
                                }}
                            >
                                {t('test_optimize_webp_copy')}
                            </button>
                        </div>
                    </div>
                ) : null}

                {showActions ? (
                    <div className="seo-article-images-actions">
                        {isManagedByLaravel ? (
                            <span
                                className="seo-article-images-watermark-btn"
                                title={t('laravel_managed_media_hint')}
                            >
                                {t('laravel_managed_media')}
                            </span>
                        ) : isWpProtected ? (
                            <span
                                className="seo-article-images-watermark-btn is-protected"
                                title={t('wp_media_bulk_protected_hint')}
                            >
                                <Shield size={14} />
                                {t('wp_media_bulk_protected')}
                            </span>
                        ) : null}

                        <div className="seo-article-images-more-wrap" ref={moreMenuRef}>
                            <button
                                type="button"
                                className={`seo-article-images-more-btn${moreOpen ? ' is-open' : ''}`}
                                disabled={busy}
                                aria-expanded={moreOpen}
                                aria-haspopup="menu"
                                title={t('image_more_actions')}
                                onClick={() => setMoreOpen((open) => !open)}
                            >
                                <MoreHorizontal size={16} />
                            </button>

                            {moreOpen ? (
                                <div className="seo-article-images-more-menu" role="menu">
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={busy || !canQuickFixSlugOne}
                                        title={
                                            isWpProtected
                                                ? t('wp_media_rename_menu_hint')
                                                : (
                                                    !canQuickFix
                                                        ? t('image_quick_fix_missing_keyword')
                                                        : t('image_quick_fix_slug_hint')
                                                )
                                        }
                                        onClick={() => {
                                            setMoreOpen(false);
                                            onQuickFixSlug?.(row);
                                        }}
                                    >
                                        <Link2 size={14} />
                                        {isWpProtected ? t('wp_media_rename_menu') : t('fix_slug')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={busy || !canQuickFix}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            onQuickFixAltTitle?.(row);
                                        }}
                                    >
                                        <Type size={14} />
                                        {t('fix_alt_title')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={busy || seoMediaId <= 0 || !siteId}
                                        title={seoMediaId > 0 ? t('test_optimize_webp_hint') : t('test_optimize_webp_local_only')}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            handleTestOptimizeWebp();
                                        }}
                                    >
                                        {optimizingWebp ? <Loader2 size={14} className="animate-spin" /> : <FlaskConical size={14} />}
                                        {optimizingWebp ? t('processing') : t('test_optimize_webp')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={busy || !articleId || !onMakeFeatured}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            handleMakeFeatured();
                                        }}
                                    >
                                        {makingFeatured ? <Loader2 size={14} className="animate-spin" /> : <ImageUp size={14} />}
                                        {makingFeatured ? t('processing') : t('make_featured_image')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item seo-article-images-more-item--danger"
                                        role="menuitem"
                                        disabled={busy || !canRemove}
                                        title={removeDisabledReason}
                                        onClick={() => {
                                            if (!canRemove || !removeTarget) {
                                                return;
                                            }
                                            setMoreOpen(false);
                                            if (removeTarget.kind === 'block') {
                                                onRemoveImage?.(row);
                                                return;
                                            }

                                            onRemoveSupplementalImage?.(row);
                                        }}
                                    >
                                        <Trash2 size={14} />
                                        {t('remove_image')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={!siteId || busy}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            openImageEditor();
                                        }}
                                    >
                                        {openingEditor ? t('processing') : t('open_image_editor')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={!siteId || busy}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            handleApplyWatermark();
                                        }}
                                    >
                                        {applyingWatermark ? t('processing') : t('apply_watermark')}
                                    </button>
                                    <button
                                        type="button"
                                        className="seo-article-images-more-item"
                                        role="menuitem"
                                        disabled={!siteId || busy}
                                        onClick={() => {
                                            setMoreOpen(false);
                                            openImageSplitter();
                                        }}
                                    >
                                        <Scissors size={14} />
                                        {t('split_grid')}
                                    </button>
                                </div>
                            ) : null}
                        </div>
                    </div>
                ) : canRemove ? (
                    <div className="seo-article-images-actions">
                        <button
                            type="button"
                            className="seo-article-images-delete-btn"
                            disabled={busy}
                            onClick={() => {
                                if (!removeTarget) {
                                    return;
                                }
                                if (removeTarget.kind === 'block') {
                                    onRemoveImage?.(row);
                                    return;
                                }
                                onRemoveSupplementalImage?.(row);
                            }}
                            title={removeDisabledReason}
                        >
                            <Trash2 size={14} />
                            {t('remove_image')}
                        </button>
                    </div>
                ) : null}
            </div>
        </li>
    );
}

export default function ArticleImagesTab({
    blocks,
    extraImages = [],
    featuredImage = null,
    galleryImages = [],
    useUnifiedInventory = false,
    siteId = null,
    articleId = null,
    jumpTarget = null,
    focusKeyword,
    articleTitle = '',
    onPatchImage,
    onFocusBlock,
    onQuickFixSlugAll,
    quickFixSlugAllBusy = false,
    onQuickFixSlugOne,
    onQuickFixAltTitleAll,
    onQuickFixAltTitleOne,
    onRemoveImage,
    onRemoveSupplementalImage,
    onAltTitleChange,
    onMakeFeatured,
    onNotify,
}) {
    const blockImages = useMemo(() => collectImagesFromBlocks(blocks), [blocks]);
    const mergedImages = useMemo(() => {
        // Canonical path: host already composed unified inventory (content+featured+gallery).
        if (useUnifiedInventory) {
            return assignInArticleQuickFixIndices(
                (Array.isArray(extraImages) ? extraImages : []).map((row, index) => ({
                    ...row,
                    key: row?.key || row?.identity_key || `unified-${index}`,
                    blockId: String(row?.blockId || row?.block_id || '').trim(),
                    wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
                    seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
                    src: String(row?.src || row?.url || '').trim(),
                    wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
                    localSrc: String(row?.localSrc || row?.local_src || '').trim(),
                    slug: String(row?.slug || '').trim(),
                    alt: String(row?.alt || '').trim(),
                    title: String(row?.title || '').trim(),
                    caption: String(row?.caption || '').trim(),
                    align: String(row?.align || 'none').trim(),
                    origin: String(row?.origin ?? '').trim(),
                    originLabel: String(row?.originLabel || row?.origin_label || '').trim(),
                    role_flags: row?.role_flags || null,
                    excludeQuickFix: Boolean(row?.excludeQuickFix ?? row?.exclude_quick_fix),
                })).filter((row) => String(row.src || '').trim() !== ''),
            );
        }

        const normalizeSrc = (value) => {
            const raw = String(value || '').trim();
            if (!raw) return '';
            try {
                const url = new URL(raw, window.location.origin);
                return `${url.pathname}`.toLowerCase();
            } catch {
                return raw.split('?')[0].toLowerCase();
            }
        };

        const mergeRow = mergeArticleImageRow;

        const normalizedRows = [
            ...(Array.isArray(extraImages)
                ? extraImages
                      .map((row, index) => {
                          const src = String(row?.src || '').trim();
                          if (!src) return null;

                          return {
                              key: row?.key || `extra-${index}-${src}`,
                              blockId: String(row?.blockId || row?.block_id || '').trim(),
                              wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
                              seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
                              src,
                              wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
                              localSrc: String(row?.localSrc || row?.local_src || '').trim(),
                              slug: String(row?.slug || '').trim(),
                              alt: String(row?.alt || '').trim(),
                              title: String(row?.title || '').trim(),
                              caption: String(row?.caption || '').trim(),
                              align: String(row?.align || 'none').trim(),
                              origin: String(row?.origin ?? '').trim(),
                              originLabel: String(row?.originLabel || row?.origin_label || '').trim(),
                              excludeQuickFix: Boolean(row?.excludeQuickFix ?? row?.exclude_quick_fix),
                          };
                      })
                      .filter(Boolean)
                : []),
            ...blockImages,
        ];

        const merged = [];
        normalizedRows.forEach((row) => {
            const srcKey = normalizeSrc(row?.src);
            const wpId = Number(row?.wpAttachmentId ?? 0);
            const seoId = Number(row?.seoMediaId ?? 0);

            const index = merged.findIndex((existing) => {
                const eWp = Number(existing?.wpAttachmentId ?? 0);
                const eSeo = Number(existing?.seoMediaId ?? 0);
                const eSrc = normalizeSrc(existing?.src);

                if (wpId > 0 && eWp > 0 && wpId === eWp) return true;
                if (seoId > 0 && eSeo > 0 && seoId === eSeo) return true;
                if (srcKey !== '' && eSrc !== '' && srcKey === eSrc) return true;
                return false;
            });

            if (index < 0) {
                merged.push(row);
                return;
            }

            merged[index] = mergeRow(merged[index], row);
        });

        return assignInArticleQuickFixIndices(filterSupplementalDuplicatesOfBlockRows(merged));
    }, [blockImages, extraImages, useUnifiedInventory, featuredImage, galleryImages]);
    const [aiJobs, setAiJobs] = useState([]);
    const lastJumpTokenRef = useRef(null);
    const aiJobsInFlightRef = useRef(false);

    const loadAiJobs = useCallback(async () => {
        if (!articleId) {
            setAiJobs([]);
            return;
        }

        if (aiJobsInFlightRef.current) {
            return;
        }

        aiJobsInFlightRef.current = true;
        try {
            const items = await fetchArticleAiMediaJobs(articleId);
            setAiJobs(items);
        } catch {
            // Giữ danh sách cũ khi poll lỗi mạng tạm thời.
        } finally {
            aiJobsInFlightRef.current = false;
        }
    }, [articleId]);

    useEffect(() => {
        loadAiJobs();
    }, [loadAiJobs]);

    useEffect(() => {
        const refresh = () => loadAiJobs();
        window.addEventListener('article-ai-image-generated', refresh);
        window.addEventListener('article-ai-video-generated', refresh);
        window.addEventListener('article-ai-media-job-updated', refresh);

        return () => {
            window.removeEventListener('article-ai-image-generated', refresh);
            window.removeEventListener('article-ai-video-generated', refresh);
            window.removeEventListener('article-ai-media-job-updated', refresh);
        };
    }, [loadAiJobs]);

    useEffect(() => {
        if (!articleId || aiJobs.length === 0) {
            return undefined;
        }

        const hasProcessing = aiJobs.some((job) => String(job.status).toLowerCase() === 'processing');
        if (!hasProcessing) {
            return undefined;
        }

        let timer = null;
        const clear = () => {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        };
        const arm = () => {
            clear();
            if (document.hidden) {
                return;
            }
            timer = window.setInterval(loadAiJobs, AI_JOBS_POLL_MS);
        };
        const onVisibility = () => {
            arm();
        };

        arm();
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            clear();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [articleId, aiJobs, loadAiJobs]);

    useEffect(() => {
        if (!jumpTarget || jumpTarget.token === lastJumpTokenRef.current) {
            return;
        }

        lastJumpTokenRef.current = jumpTarget.token;
        const targetMediaId = Number(jumpTarget?.seoMediaId ?? 0);
        const targetSrc = String(jumpTarget?.src ?? '').trim();

        const jump = () => {
            let targetNode = null;
            if (targetMediaId > 0) {
                targetNode = document.querySelector(
                    `.seo-article-images-row[data-seo-media-id="${targetMediaId}"]`,
                );
            }

            if (!targetNode && targetSrc !== '') {
                const rows = Array.from(document.querySelectorAll('.seo-article-images-row[data-image-src]'));
                targetNode = rows.find(
                    (node) => String(node?.dataset?.imageSrc ?? '').trim() === targetSrc,
                ) ?? null;
            }

            if (!targetNode) {
                return;
            }

            targetNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetNode.classList.add('is-jump-focus');
            window.setTimeout(() => targetNode?.classList.remove('is-jump-focus'), 2000);
        };

        window.setTimeout(jump, 80);
    }, [jumpTarget, mergedImages, aiJobs]);

    const totalCount = aiJobs.length + mergedImages.length;
    const hasWpImages = mergedImages.some((row) => row.wpAttachmentId && hasTrustedWordPressUrl(row));
    const hasLocalImages = mergedImages.some(
        (row) =>
            (!row.wpAttachmentId && isLocalSeoMediaSrc(row.src)) ||
            isLocalSeoMediaSrc(String(row.localSrc || '').trim()),
    );
    const keywordSource = (focusKeyword || articleTitle || '').trim();
    const canQuickFix = keywordSource.length > 0 && mergedImages.length > 0;
    const canQuickFixSlug = canQuickFix;

    if (!totalCount) {
        return (
            <div className="seo-tab-panel seo-images-tab">
                <p className="seo-images-tab-empty">{t('images_tab_empty')}</p>
            </div>
        );
    }

    return (
        <div className="seo-tab-panel seo-images-tab">
            <div className="seo-images-tab-toolbar">
                <div className="seo-images-tab-intro-wrap">
                    <p className="seo-images-tab-intro">
                        {totalCount} mục
                        {aiJobs.length > 0
                            ? ` (${aiJobs.length} job AI, ${mergedImages.length} ảnh)`
                            : ` · ${mergedImages.length} ảnh`}
                        . {t('images_tab_intro_suffix')}
                    </p>
                    {hasLocalImages ? (
                        <p className="seo-images-tab-info">
                            {t('images_tab_local_info')}
                        </p>
                    ) : null}
                    {hasWpImages ? (
                        <details className="seo-images-tab-warning-details">
                            <summary className="seo-images-tab-warning-summary">
                                {t('images_tab_wp_slug_warning')}
                            </summary>
                            <p className="seo-images-tab-warning">{t('editor_fix_slug_all_local_only_warning')}</p>
                        </details>
                    ) : null}
                </div>
                <div className="seo-images-tab-toolbar-actions">
                    <button
                        type="button"
                        className={`seo-images-quick-fix-btn${quickFixSlugAllBusy ? ' is-loading' : ''}`}
                        disabled={!canQuickFixSlug || quickFixSlugAllBusy}
                        title={
                            quickFixSlugAllBusy
                                ? t('fix_slug_all_loading')
                                : !keywordSource
                                  ? t('image_quick_fix_missing_keyword')
                                  : t('images_tab_quick_fix_slug_all_hint')
                        }
                        aria-busy={quickFixSlugAllBusy}
                        onClick={() => onQuickFixSlugAll?.(mergedImages)}
                    >
                        {quickFixSlugAllBusy ? (
                            <Loader2 size={16} className="seo-images-quick-fix-btn__spinner" aria-hidden="true" />
                        ) : (
                            <Link2 size={16} aria-hidden="true" />
                        )}
                        {quickFixSlugAllBusy ? t('fix_slug_all_loading') : t('fix_slug_all')}
                    </button>
                    <button
                        type="button"
                        className="seo-images-quick-fix-btn"
                        disabled={!canQuickFix}
                        title={
                            canQuickFix
                                ? t('images_tab_quick_fix_alt_title_all_hint')
                                : t('image_quick_fix_missing_keyword')
                        }
                        onClick={() => onQuickFixAltTitleAll?.(mergedImages)}
                    >
                        <Type size={16} />
                        {t('fix_alt_title_all')}
                    </button>
                </div>
            </div>
            <ul className="seo-article-images-list">
                {aiJobs.map((job) => (
                    <AiMediaJobRow
                        key={`ai-job-${job.id}`}
                        job={job}
                        onRetry={loadAiJobs}
                        onFocusBlock={onFocusBlock}
                        onNotify={onNotify}
                    />
                ))}
                {mergedImages.map((row) => (
                    <ImageRow
                        key={row.key || row.blockId || row.src}
                        row={row}
                        siteId={siteId}
                        articleId={articleId}
                        blocks={blocks}
                        supplementalImages={extraImages}
                        onPatch={onPatchImage}
                        onFocusBlock={onFocusBlock}
                        onQuickFixSlug={onQuickFixSlugOne}
                        onQuickFixAltTitle={onQuickFixAltTitleOne}
                        onRemoveImage={onRemoveImage}
                        onRemoveSupplementalImage={onRemoveSupplementalImage}
                        onAltTitleChange={onAltTitleChange}
                        onMakeFeatured={onMakeFeatured}
                        canQuickFix={canQuickFix}
                        onNotify={onNotify}
                    />
                ))}
            </ul>
        </div>
    );
}
