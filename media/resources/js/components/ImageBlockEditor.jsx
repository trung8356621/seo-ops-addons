import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlignCenter, AlignLeft, AlignRight, ExternalLink, Images, Maximize2, Pencil, RefreshCcw, Trash2 } from 'lucide-react';
import {
    parseImageFromBlockContent,
    renderImageFigure,
    withDefaultImageInsertAlign,
} from '../utils/blockImageUtils';
import { importSeoMediaFromUrl, processClipboardImagePaste } from '../utils/seoMediaApi';
import { ImageBlockPickerBox } from '@content-addon/components/BlockInsertMenu.jsx';
import { appendProductAlbumItems } from '../utils/articleProductAlbumStorage';
import { t } from '@content-addon/utils/i18n.js';
import ImageMetaEditForm from './imageMeta/ImageMetaEditForm';
import { applyWordPressImageSize, detectWordPressImageSize } from '@wordpress-addon/utils/wordpressImageSize.js';
import { resolveWordPressBaseUrl, resolveFullWordPressImageUrl } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { slugFromUrl } from '../utils/articleImagesUtils';
import { installBrokenImageGuard } from '../utils/brokenImageGuard';
import { openMediaPicker } from '@content-addon/editor/runtime/editorMediaPickerStore.js';
import { executeEditorCommand, getEditorCommandHost } from '@content-addon/utils/editorCommands/index.js';

const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: t('toolbar_align_left') },
    { id: 'center', icon: AlignCenter, title: t('toolbar_align_center') },
    { id: 'right', icon: AlignRight, title: t('toolbar_align_right') },
    { id: 'full', icon: Maximize2, title: t('image_align_full_width') },
];
const IMAGE_BLOCK_CLIPBOARD_KEY = '__SEO_IMAGE_BLOCK_CLIPBOARD__';

function clearImageBlockClipboard() {
    if (typeof window === 'undefined') {
        return;
    }

    delete window[IMAGE_BLOCK_CLIPBOARD_KEY];
}

function isVideoMedia(image) {
    if (!image?.src) return false;
    const kind = String(image.mediaType ?? image.media_type ?? '').toLowerCase();
    if (kind === 'video') return true;
    const src = String(image.src).toLowerCase();
    return /\.(mp4|webm|mov|m4v|ogv|ogg|avi|mpeg|mpg)(\?.*)?$/.test(src);
}

function renderVideoFigure(video) {
    const align = String(video?.align ?? 'none').trim();
    const alignClass = align && align !== 'none' ? ` ${align === 'full' ? 'alignfull' : `align${align}`}` : '';
    const safeUrl = String(video?.src ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;');
    const attrs = [
        video?.wpAttachmentId ? ` data-id="${Math.round(Number(video.wpAttachmentId))}"` : '',
        video?.seoMediaId ? ` data-seo-media-id="${Math.round(Number(video.seoMediaId))}"` : '',
    ].join('');
    return `<figure class="wp-block-video${alignClass}"${attrs}><video controls src="${safeUrl}"></video></figure>`;
}

function parseVideoFromBlockContent(html) {
    const source = String(html ?? '').trim();
    if (!source) return null;
    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const figure = doc.body.querySelector('figure.wp-block-video, figure');
    const video = doc.body.querySelector('video');
    if (!video) return null;
    const src = String(video.getAttribute('src') ?? '').trim();
    if (!src) return null;

    const className = String(figure?.getAttribute('class') ?? '');
    let align = 'none';
    if (className.includes('alignfull')) align = 'full';
    else if (className.includes('alignright')) align = 'right';
    else if (className.includes('aligncenter')) align = 'center';
    else if (className.includes('alignleft')) align = 'left';

    const wpAttachmentId = Number(figure?.getAttribute('data-id') ?? video.getAttribute('data-id') ?? 0);
    const seoMediaId = Number(
        figure?.getAttribute('data-seo-media-id') ?? video.getAttribute('data-seo-media-id') ?? 0,
    );

    return {
        src,
        alt: '',
        title: '',
        align,
        mediaType: 'video',
        wpAttachmentId: wpAttachmentId > 0 ? wpAttachmentId : undefined,
        seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
        wpSrc: src,
    };
}

function ImageMetaFormPortal({ anchorRef, image, onSave, onCancel }) {
    const panelRef = useRef(null);
    const [position, setPosition] = useState({ top: 0, left: 0 });
    const [alt, setAlt] = useState(image.alt ?? '');
    const [title, setTitle] = useState(image.title ?? '');
    const [caption, setCaption] = useState(image.caption ?? '');
    const [align, setAlign] = useState(image.align ?? 'none');
    const [size, setSize] = useState(
        image.size ?? detectWordPressImageSize(resolveWordPressBaseUrl(image) || image.src),
    );

    useEffect(() => {
        setAlt(image.alt ?? '');
        setTitle(image.title ?? '');
        setCaption(image.caption ?? '');
        setAlign(image.align ?? 'none');
        setSize(image.size ?? detectWordPressImageSize(resolveWordPressBaseUrl(image) || image.src));
    }, [image.alt, image.title, image.caption, image.align, image.size, image.src, image.wpSrc, image.wpAttachmentId]);

    useLayoutEffect(() => {
        if (!anchorRef.current || !panelRef.current) return;

        const rect = anchorRef.current.getBoundingClientRect();
        const width = panelRef.current.offsetWidth;
        const left = rect.left + rect.width / 2 - width / 2;
        const top = rect.bottom + 8;

        setPosition({
            top: Math.min(top, window.innerHeight - panelRef.current.offsetHeight - 8),
            left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),
        });
    }, [anchorRef]);

    useEffect(() => {
        const onKeyDown = (e) => {
            if (e.key === 'Escape') onCancel();
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onCancel]);

    useEffect(() => {
        const onMouseDown = (e) => {
            if (panelRef.current?.contains(e.target)) return;
            if (anchorRef.current?.contains(e.target)) return;
            onCancel();
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [anchorRef, onCancel]);

    const panel = (
        <div
            ref={panelRef}
            className="seo-image-meta-panel"
            style={{ top: `${position.top}px`, left: `${position.left}px` }}
            onMouseDown={(e) => e.stopPropagation()}
        >
            <ImageMetaEditForm
                idPrefix="seo-block-img"
                src={image.src ?? ''}
                wpSrc={image.wpSrc ?? image.wp_src ?? ''}
                wpAttachmentId={image.wpAttachmentId ?? null}
                size={size}
                onSizeChange={setSize}
                align={align}
                onAlignChange={setAlign}
                alt={alt}
                onAltChange={setAlt}
                title={title}
                onTitleChange={setTitle}
                caption={caption}
                onCaptionChange={setCaption}
                onCancel={onCancel}
                onApply={() => {
                    const sized = applyWordPressImageSize(image, size);

                    onSave({
                        ...sized,
                        alt: alt.trim(),
                        title: title.trim(),
                        caption: caption.trim(),
                        align: align || 'none',
                    });
                }}
            />
        </div>
    );

    return createPortal(panel, document.body);
}

export default function ImageBlockEditor({
    block,
    isActive,
    isHiddenInMerge,
    canShiftMerge,
    onActivate,
    onShiftMerge,
    onUpdate,
    onDelete,
    canDeleteBlock,
    articleId = null,
    siteId = null,
    supportsProductGallery = false,
    imagesLocked = false,
    onArmOutsideClickGuard = null,
}) {
    const [editingMeta, setEditingMeta] = useState(false);
    const [pasteUploading, setPasteUploading] = useState(false);
    const [importLoading, setImportLoading] = useState(false);
    const [pickerInteractionReady, setPickerInteractionReady] = useState(false);
    const toolbarRef = useRef(null);
    const emptyFrameRef = useRef(null);
    const previewHtmlRef = useRef(null);
    const activeHtmlRef = useRef(null);
    const brokenSrcKeysRef = useRef(new Set());

    const image = useMemo(() => {
        if (block.image) return block.image;
        return parseImageFromBlockContent(block.content) ?? parseVideoFromBlockContent(block.content);
    }, [block.image, block.content]);
    const isVideo = isVideoMedia(image);

    const figureHtml = image ? (isVideo ? renderVideoFigure(image) : renderImageFigure(image)) : block.content;

    useLayoutEffect(() => {
        const cleanups = [];
        if (previewHtmlRef.current) {
            cleanups.push(installBrokenImageGuard(previewHtmlRef.current, brokenSrcKeysRef.current));
        }
        if (activeHtmlRef.current) {
            cleanups.push(installBrokenImageGuard(activeHtmlRef.current, brokenSrcKeysRef.current));
        }

        return () => {
            cleanups.forEach((cleanup) => cleanup());
        };
    }, [figureHtml, isActive, image?.src]);

    const commitImage = (nextImage) => {
        const normalized = isVideoMedia(nextImage) ? nextImage : withDefaultImageInsertAlign(nextImage);
        onUpdate(
            isVideoMedia(normalized) ? renderVideoFigure(normalized) : renderImageFigure(normalized),
            normalized,
        );
    };

    const resetImageToPicker = useCallback(() => {
        setEditingMeta(false);
        onUpdate('', null);
    }, [onUpdate]);

    useEffect(() => {
        if (!isActive) {
            setEditingMeta(false);
            setPasteUploading(false);
            setImportLoading(false);
        }
    }, [isActive]);

    const applyUploadedImageToBlock = useCallback(
        (data) => {
            const url = (data?.url ?? '').trim();
            if (!url) return;

            const embedMode = String(data?.embed_mode ?? '').toLowerCase();
            const slug = (data?.slug ?? '').trim() || slugFromUrl(url);
            const altText = (data?.alt_text ?? slug).trim() || slug;
            const wpAttachmentId = Number(data?.wp_attachment_id ?? 0);
            const seoMediaId = Number(data?.id ?? data?.seo_media_id ?? 0);

            if (embedMode === 'wordpress') {
                const wpUrl = resolveFullWordPressImageUrl(url);
                commitImage({
                    src: wpUrl,
                    alt: altText,
                    title: altText,
                    slug: slug || undefined,
                    wpSrc: wpUrl,
                    wpAttachmentId: wpAttachmentId > 0 ? wpAttachmentId : undefined,
                    seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                });

                return;
            }

            commitImage({
                src: url,
                alt: altText,
                title: altText,
                slug: slug || undefined,
                seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                // Paste/upload mới: xóa hẳn liên kết WP cũ của block (tránh rename ID stale).
                wpAttachmentId: undefined,
                wpSrc: undefined,
                localSrc: undefined,
            });
        },
        // commitImage closes over onUpdate — stable enough per block session
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [onUpdate, block.id],
    );

    const handleEmptyFramePaste = useCallback(
        (event) => {
            if (imagesLocked || pasteUploading) return false;

            const handled = processClipboardImagePaste(event, {
                articleId,
                siteId,
                source: 'clipboard',
                notifyOnSuccess: false,
                onUploaded: (data) => {
                    setPasteUploading(false);
                    applyUploadedImageToBlock(data);
                },
                onError: () => setPasteUploading(false),
            });

            if (handled) {
                setPasteUploading(true);
                event.stopImmediatePropagation();
            }

            return handled;
        },
        [articleId, siteId, pasteUploading, applyUploadedImageToBlock],
    );

    const handleImportFromUrl = useCallback(
        async (remoteUrl, options = {}) => {
            if (importLoading) return;

            setImportLoading(true);
            try {
                const data = await importSeoMediaFromUrl(remoteUrl, {
                    articleId,
                    siteId,
                    randomFilename: Boolean(options?.randomFilename),
                });
                applyUploadedImageToBlock(data);
            } catch (error) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_import_failed'),
                            body: error?.message ?? t('image_import_failed_body'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setImportLoading(false);
            }
        },
        [articleId, siteId, importLoading, applyUploadedImageToBlock],
    );

    useEffect(() => {
        if (!isActive || image) {
            setPickerInteractionReady(false);

            return undefined;
        }

        setPickerInteractionReady(false);
        let cancelled = false;
        let frameId = 0;

        frameId = window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                if (!cancelled) {
                    setPickerInteractionReady(true);
                }
            });
        });

        return () => {
            cancelled = true;
            window.cancelAnimationFrame(frameId);
        };
    }, [block.id, image, isActive]);

    useEffect(() => {
        if (!isActive) {
            return undefined;
        }

        const onWindowPaste = (event) => {
            handleEmptyFramePaste(event);
        };

        window.addEventListener('paste', onWindowPaste, true);

        return () => {
            window.removeEventListener('paste', onWindowPaste, true);
        };
    }, [handleEmptyFramePaste, isActive]);

    const isTypingTarget = (target) =>
        Boolean(
            target?.closest?.(
                'input, textarea, [contenteditable="true"], [contenteditable=""], .ProseMirror',
            ),
        );

    useEffect(() => {
        if (!isActive || image || !pickerInteractionReady) {
            return undefined;
        }

        const focusTimer = window.setTimeout(() => {
            const frame = emptyFrameRef.current;
            if (!frame) {
                return;
            }

            const activeEl = document.activeElement;
            if (
                activeEl &&
                (frame.contains(activeEl) || isTypingTarget(activeEl))
            ) {
                return;
            }

            frame.focus({ preventScroll: true });
        }, 120);

        return () => {
            window.clearTimeout(focusTimer);
        };
    }, [block.id, image, isActive, pickerInteractionReady]);

    const handlePreviewClick = (e) => {
        if (e.target.closest('a')) {
            e.preventDefault();
        }
        if (e.shiftKey && canShiftMerge) {
            e.preventDefault();
            e.stopPropagation();
            onShiftMerge(block.id);
            return;
        }
        onArmOutsideClickGuard?.(360);
        onActivate();
    };

    const handlePreviewMouseDown = (e) => {
        e.stopPropagation();
        onArmOutsideClickGuard?.(360);
    };

    const copyCurrentImage = useCallback(
        async (_notify = true) => {
            if (!image?.src) {
                return false;
            }

            window[IMAGE_BLOCK_CLIPBOARD_KEY] = {
                block: {
                    type: block.type,
                    content: block.content,
                    image: block.image ?? image,
                },
                image: {
                    ...image,
                    src: String(image.src).trim(),
                },
                copiedAt: Date.now(),
            };

            try {
                if (navigator?.clipboard?.writeText) {
                    await navigator.clipboard.writeText(String(image.src).trim());
                }
            } catch {
                // Browser can block clipboard API; keep internal clipboard only.
            }

            return true;
        },
        [block, image],
    );

    const pasteImageFromInternalClipboard = useCallback(() => {
        const payload = window[IMAGE_BLOCK_CLIPBOARD_KEY];
        const copied = payload?.block?.image ?? payload?.image;
        if (!copied?.src) {
            return false;
        }

        commitImage({
            ...copied,
            src: String(copied.src).trim(),
        });
        clearImageBlockClipboard();

        return true;
    }, [commitImage]);

    const handleAppendToProductAlbum = useCallback(() => {
        const src = String(image?.src ?? '').trim();
        if (!src || !articleId) {
            return;
        }

        appendProductAlbumItems(articleId, [{
            url: src,
            wpAttachmentId: Number(image?.wpAttachmentId ?? 0),
            seoMediaId: Number(image?.seoMediaId ?? 0),
            slug: String(image?.slug ?? '').trim(),
            alt: String(image?.alt ?? '').trim(),
        }]);
    }, [image, articleId]);

    const handleJumpToImagesTab = useCallback(() => {
        const src = String(image?.src ?? '').trim();
        if (!src) {
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-open-images-tab', {
                detail: {
                    seoMediaId: Number(image?.seoMediaId ?? image?.seo_media_id ?? 0),
                    src,
                },
            }),
        );
    }, [image]);

    useEffect(() => {
        if (!isActive) {
            return undefined;
        }

        const onWindowKeyDown = (event) => {
            const mod = event.ctrlKey || event.metaKey;
            if (!mod || event.altKey || isTypingTarget(event.target)) {
                return;
            }

            const key = String(event.key || '').toLowerCase();

            if (key === 'c') {
                if (image?.src) {
                    event.preventDefault();
                    copyCurrentImage();
                }
                return;
            }

            if (key === 'x') {
                if (image?.src) {
                    event.preventDefault();
                    copyCurrentImage(false).then((copied) => {
                        if (!copied) {
                            return;
                        }
                        if (canDeleteBlock) {
                            onDelete?.();
                        } else {
                            // Fallback cho trường hợp block cuối không được xóa.
                            resetImageToPicker();
                        }
                    });
                }
                return;
            }

            if (key === 'v') {
                const pasted = pasteImageFromInternalClipboard();
                if (pasted) {
                    event.preventDefault();
                }
            }
        };

        window.addEventListener('keydown', onWindowKeyDown, true);

        return () => {
            window.removeEventListener('keydown', onWindowKeyDown, true);
        };
    }, [isActive, image, copyCurrentImage, pasteImageFromInternalClipboard, resetImageToPicker, canDeleteBlock, onDelete]);

    if (isHiddenInMerge) {
        return null;
    }

    if (!isActive) {
        return (
            <div
                ref={previewHtmlRef}
                className="seo-block-preview seo-wp-content seo-block-image-preview-wrap p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-pointer"
                dangerouslySetInnerHTML={{ __html: figureHtml }}
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        if (e.shiftKey && canShiftMerge) {
                            onShiftMerge(block.id);
                        } else {
                            onActivate();
                        }
                    }
                }}
                role="button"
                tabIndex={0}
                title={
                    canShiftMerge
                        ? t('image_click_edit_shift_merge')
                        : t('image_click_edit')
                }
            />
        );
    }

    if (!image) {
        if (isActive) {
            if (imagesLocked) {
                return (
                    <div
                        className="block-image-active block-image-active--empty block-image-active--locked"
                        onMouseDown={(e) => {
                            if (!isTypingTarget(e.target)) e.stopPropagation();
                        }}
                    >
                        <span className="block-editor-badge">{t('image_block_label')}</span>
                        <button
                            type="button"
                            className="block-image-delete"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => canDeleteBlock && onDelete?.()}
                            disabled={!canDeleteBlock}
                            title={canDeleteBlock ? t('delete_image_block') : t('cannot_delete_last_block')}
                        >
                            <Trash2 size={16} />
                        </button>
                        <p className="seo-image-block-paste-hint text-amber-700 dark:text-amber-200">
                            {t('editor_intro_no_images')}
                        </p>
                    </div>
                );
            }

            return (
                <div
                    ref={emptyFrameRef}
                    className="block-image-active block-image-active--empty"
                    tabIndex={0}
                    role="region"
                    aria-label={t('image_block_clipboard_aria')}
                    onMouseDown={(e) => {
                        if (!isTypingTarget(e.target)) e.stopPropagation();
                    }}
                >
                    <span className="block-editor-badge">{t('image_block_label')}</span>
                    <button
                        type="button"
                        className="block-image-delete"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => canDeleteBlock && onDelete?.()}
                        disabled={!canDeleteBlock}
                        title={canDeleteBlock ? t('delete_image_block') : t('cannot_delete_last_block')}
                    >
                        <Trash2 size={16} />
                    </button>
                    {pasteUploading ? (
                        <p className="seo-image-block-paste-status" aria-live="polite">{t('uploading_clipboard')}</p>
                    ) : (
                        <p className="seo-image-block-paste-hint">{t('paste_hint')}</p>
                    )}
                    <ImageBlockPickerBox
                        blockId={block.id}
                        interactionReady={pickerInteractionReady}
                        onOpenMediaLibrary={(event) => {
                            event?.preventDefault?.();
                            event?.stopPropagation?.();
                            const blockId = block.id;
                            openMediaPicker({
                                mode: 'content_image',
                                selection: 'single',
                                target: { blockId },
                                onConfirm: async (items) => {
                                    const item = items?.[0];
                                    if (!item?.url) return;
                                    const host = getEditorCommandHost();
                                    if (typeof host?.actions?.applyEditorBlockImage === 'function') {
                                        host.actions.applyEditorBlockImage({
                                            blockId,
                                            url: item.url,
                                            alt: item.alt || '',
                                            slug: item.slug || '',
                                            attachmentId: Number(item.wp_attachment_id || 0) || 0,
                                            seoMediaId: Number(item.seo_media_id || 0) || 0,
                                            mediaType: item.media_type || 'image',
                                        });
                                        return;
                                    }
                                    const result = executeEditorCommand('insert_image', {
                                        src: item.url,
                                        url: item.url,
                                        alt: item.alt || '',
                                        attrs: {
                                            slug: item.slug || '',
                                            wpAttachmentId: Number(item.wp_attachment_id || 0) || undefined,
                                            seoMediaId: Number(item.seo_media_id || 0) || undefined,
                                        },
                                    });
                                    if (result && result.ok === false) {
                                        window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                                            detail: {
                                                title: t('image_block_label'),
                                                body: String(result.message || result.code || 'insert_failed'),
                                                status: 'warning',
                                            },
                                        }));
                                    }
                                },
                            });
                        }}
                        onImportFromUrl={handleImportFromUrl}
                        importLoading={importLoading || pasteUploading}
                    />
                </div>
            );
        }

        return (
            <div
                className="seo-block-preview seo-block-image-empty-preview p-3 -mx-1 rounded border border-dashed border-gray-300 dark:border-slate-600 cursor-pointer text-center text-sm text-gray-500"
                onMouseDown={imagesLocked ? undefined : handlePreviewMouseDown}
                onClick={imagesLocked ? undefined : handlePreviewClick}
                onKeyDown={(e) => {
                    if (imagesLocked) {
                        return;
                    }
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        onActivate();
                    }
                }}
                role={imagesLocked ? 'note' : 'button'}
                tabIndex={imagesLocked ? -1 : 0}
            >
                {imagesLocked ? t('editor_intro_no_images') : t('image_block_click_to_choose')}
            </div>
        );
    }

    return (
        <div className="block-image-active" onMouseDown={(e) => {
            if (!isTypingTarget(e.target)) e.stopPropagation();
        }}>
                    <span className="block-editor-badge">{t('image_block_label')}</span>
            <button
                type="button"
                className="block-image-delete"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => canDeleteBlock && onDelete?.()}
                disabled={!canDeleteBlock}
                title={canDeleteBlock ? t('delete_image') : t('cannot_delete_last_block')}
            >
                <Trash2 size={16} />
            </button>

            <div className="seo-block-image-stage seo-wp-content">
                <div className="seo-block-image-edit-wrap">
                    <div ref={toolbarRef} className="seo-image-toolbar seo-image-toolbar--inline">
                        {ALIGN_OPTIONS.map(({ id, icon: Icon, title }) => (
                            <button
                                key={id}
                                type="button"
                                className={`seo-image-toolbar-btn ${image.align === id ? 'is-active' : ''}`}
                                title={title}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => commitImage({ ...image, align: id })}
                            >
                                <Icon size={18} strokeWidth={1.75} />
                            </button>
                        ))}
                        <span className="seo-image-toolbar-sep" />
                        <button
                            type="button"
                            className={`seo-image-toolbar-btn ${editingMeta ? 'is-active' : ''}`}
                            title={t('edit_image_meta')}
                            aria-pressed={editingMeta}
                            disabled={isVideo}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={(e) => {
                                e.stopPropagation();
                                setEditingMeta((v) => !v);
                            }}
                        >
                            <Pencil size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title="Mở trong tab Hình ảnh"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={handleJumpToImagesTab}
                        >
                            <ExternalLink size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title={t('replace_image')}
                            disabled={isVideo}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={resetImageToPicker}
                        >
                            <RefreshCcw size={18} strokeWidth={1.75} />
                        </button>
                        {supportsProductGallery && !isVideo ? (
                            <button
                                type="button"
                                className="seo-image-toolbar-btn"
                                title={t('append_to_product_album')}
                                aria-label={t('append_to_product_album')}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={handleAppendToProductAlbum}
                            >
                                <Images size={18} strokeWidth={1.75} />
                            </button>
                        ) : null}
                        <button
                            type="button"
                            className="seo-image-toolbar-btn is-danger"
                            title={t('delete_image')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => canDeleteBlock && onDelete?.()}
                            disabled={!canDeleteBlock}
                        >
                            <Trash2 size={18} strokeWidth={1.75} />
                        </button>
                    </div>
                    <div
                        ref={activeHtmlRef}
                        className="seo-block-image-figure-host"
                        dangerouslySetInnerHTML={{ __html: figureHtml }}
                    />
                </div>
            </div>

            {editingMeta && !isVideo ? (
                <ImageMetaFormPortal
                    anchorRef={toolbarRef}
                    image={image}
                    onSave={(next) => {
                        commitImage(next);
                        setEditingMeta(false);
                    }}
                    onCancel={() => setEditingMeta(false)}
                />
            ) : null}
        </div>
    );
}
