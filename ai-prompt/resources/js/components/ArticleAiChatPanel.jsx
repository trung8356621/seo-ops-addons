import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Check, Copy, ImageIcon, RefreshCw, Video, X } from 'lucide-react';
import {
    getAiMediaLaunchContext,
    subscribeAiMediaLaunchContext,
} from '@content-addon/editor/runtime/editorAiMediaWorkspace.js';
import { resolveAiMediaPrompt } from '@content-addon/utils/resolveAiMediaPrompt.js';
import { t } from '@content-addon/utils/i18n.js';

/**
 * AI Media — copy-prompt-first helper for external web generators (Gemini, etc.).
 * API generation remains optional secondary action when configured.
 */
export default function ArticleAiChatPanel({
    articleId,
    canGenerateImage = false,
    canGenerateVideo = false,
    onClose = null,
    canApply = null,
}) {
    const [mediaType, setMediaType] = useState('image');
    const [prompt, setPrompt] = useState('');
    const [targetBlockId, setTargetBlockId] = useState('');
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const [applyBlocked, setApplyBlocked] = useState(false);
    const [copied, setCopied] = useState(false);
    const [resolveLoading, setResolveLoading] = useState(false);
    const [resolveError, setResolveError] = useState('');
    const [finalPrompt, setFinalPrompt] = useState('');
    const [finalPromptMeta, setFinalPromptMeta] = useState(null);
    const generateLockRef = useRef(false);
    const inputRef = useRef(null);
    const copiedTimerRef = useRef(null);
    const resolveRequestRef = useRef(0);

    const applyLaunchContext = useCallback((ctx) => {
        if (!ctx) {
            return;
        }
        if (ctx.mediaType === 'video' || ctx.mediaType === 'image') {
            setMediaType(ctx.mediaType);
        }
        if (typeof ctx.prompt === 'string') {
            setPrompt(ctx.prompt);
        }
        if (ctx.targetBlockId) {
            setTargetBlockId(String(ctx.targetBlockId));
        }
    }, []);

    useEffect(() => {
        applyLaunchContext(getAiMediaLaunchContext());
        return subscribeAiMediaLaunchContext(applyLaunchContext);
    }, [applyLaunchContext]);

    useEffect(() => {
        setFinalPrompt('');
        setFinalPromptMeta(null);
        setResolveError('');
        setCopied(false);
    }, [prompt, mediaType]);

    useEffect(() => {
        const onMediaFailed = (e) => {
            const type = e.detail?.type;
            if (type === 'image') setGeneratingImage(false);
            else if (type === 'video') setGeneratingVideo(false);
            generateLockRef.current = false;
            if (typeof canApply === 'function' && !canApply()) {
                setApplyBlocked(true);
            }
        };
        const onImageDone = (event) => {
            const status = String(event.detail?.status ?? '').toLowerCase();
            if (status !== 'processing' && status !== 'pending' && status !== 'completed') return;
            setGeneratingImage(false);
            generateLockRef.current = false;
            if (typeof canApply === 'function' && !canApply()) setApplyBlocked(true);
        };
        const onVideoDone = (event) => {
            const status = String(event.detail?.status ?? '').toLowerCase();
            if (status !== 'processing' && status !== 'pending' && status !== 'completed') return;
            setGeneratingVideo(false);
            generateLockRef.current = false;
            if (typeof canApply === 'function' && !canApply()) setApplyBlocked(true);
        };

        window.addEventListener('article-ai-media-failed', onMediaFailed);
        window.addEventListener('article-ai-image-generated', onImageDone);
        window.addEventListener('article-ai-video-generated', onVideoDone);
        return () => {
            window.removeEventListener('article-ai-media-failed', onMediaFailed);
            window.removeEventListener('article-ai-image-generated', onImageDone);
            window.removeEventListener('article-ai-video-generated', onVideoDone);
        };
    }, [canApply]);

    useEffect(() => () => {
        if (copiedTimerRef.current) {
            window.clearTimeout(copiedTimerRef.current);
        }
    }, []);

    const canGenerateWithApi = mediaType === 'video'
        ? Boolean(canGenerateVideo)
        : Boolean(canGenerateImage);
    const busy = generatingImage || generatingVideo || resolveLoading;
    const hasPrompt = Boolean(prompt.trim());

    const resolveFinalPrompt = useCallback(async ({ force = false } = {}) => {
        const context = prompt.trim();
        if (!context) {
            throw new Error(t('ai_media_prompt_empty'));
        }

        if (!force && finalPrompt.trim() !== '' && finalPromptMeta) {
            return { rendered: finalPrompt, meta: finalPromptMeta };
        }

        const requestId = ++resolveRequestRef.current;
        setResolveLoading(true);
        setResolveError('');

        try {
            const resolved = await resolveAiMediaPrompt({
                userBrief: context,
                selectionText: context,
                mediaType,
                target: 'editor',
                articleId: Number(articleId) || undefined,
            });

            if (requestId !== resolveRequestRef.current) {
                return { rendered: resolved.rendered, meta: resolved };
            }

            setFinalPrompt(resolved.rendered);
            setFinalPromptMeta(resolved);
            return { rendered: resolved.rendered, meta: resolved };
        } catch (error) {
            if (requestId === resolveRequestRef.current) {
                setFinalPrompt('');
                setFinalPromptMeta(null);
                setResolveError(String(error?.message ?? t('resolve_prompt_failed')));
            }
            throw error;
        } finally {
            if (requestId === resolveRequestRef.current) {
                setResolveLoading(false);
            }
        }
    }, [articleId, finalPrompt, finalPromptMeta, mediaType, prompt]);

    const notifyResolveError = useCallback((error) => {
        window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
            detail: {
                title: t('resolve_prompt_failed'),
                body: String(error?.message ?? t('resolve_prompt_failed')),
                status: 'danger',
            },
        }));
    }, []);

    const handleRetryResolve = useCallback(async () => {
        try {
            await resolveFinalPrompt({ force: true });
        } catch (error) {
            notifyResolveError(error);
        }
    }, [notifyResolveError, resolveFinalPrompt]);

    const handleCopyPrompt = useCallback(async () => {
        try {
            const { rendered } = await resolveFinalPrompt({ force: true });
            await navigator.clipboard.writeText(rendered);
            setCopied(true);
            if (copiedTimerRef.current) {
                window.clearTimeout(copiedTimerRef.current);
            }
            copiedTimerRef.current = window.setTimeout(() => setCopied(false), 1800);
        } catch (error) {
            notifyResolveError(error);
        }
    }, [notifyResolveError, resolveFinalPrompt]);

    const dispatchGenerateWithApi = useCallback(() => {
        const userBrief = prompt.trim();
        if (!userBrief) return;
        if (generateLockRef.current) return;
        if (typeof canApply === 'function' && !canApply()) {
            setApplyBlocked(true);
            return;
        }

        generateLockRef.current = true;
        if (mediaType === 'image') {
            setGeneratingImage(true);
        } else {
            setGeneratingVideo(true);
        }

        window.dispatchEvent(new CustomEvent(mediaType === 'video' ? 'generate-article-video' : 'generate-article-image', {
            detail: {
                selectionText: '',
                selectionHtml: '',
                userBrief,
                activeBlockId: targetBlockId,
                target: 'editor',
                articleId,
            },
        }));
    }, [articleId, canApply, mediaType, prompt, targetBlockId]);

    return (
        <div className="seo-ai-chat-panel wp-postbox">
            <div className="wp-postbox-header seo-ai-chat-panel__header">
                <h2>{t('ai_media')}</h2>
                <button
                    type="button"
                    className="seo-ai-chat-panel__close"
                    title={t('close_panel')}
                    aria-label={t('close_panel')}
                    onClick={() => {
                        if (typeof onClose === 'function') onClose();
                    }}
                >
                    <X size={18} />
                </button>
            </div>
            <div className="seo-ai-chat-body">
                <div className="seo-ai-chat-compose">
                    {applyBlocked ? (
                        <p className="mb-2 text-xs text-amber-700 dark:text-amber-300">
                            {t('editor_session_read_only_generate')}
                        </p>
                    ) : null}

                    <div className="seo-ai-media-mode-tabs" role="tablist" aria-label={t('ai_media')}>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={mediaType === 'image'}
                            className={`seo-ai-media-mode-tab${mediaType === 'image' ? ' is-active' : ''}`}
                            onClick={() => setMediaType('image')}
                            disabled={busy}
                        >
                            <ImageIcon size={14} aria-hidden />
                            {t('image_block_label')}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={mediaType === 'video'}
                            className={`seo-ai-media-mode-tab${mediaType === 'video' ? ' is-active' : ''}`}
                            onClick={() => setMediaType('video')}
                            disabled={busy}
                        >
                            <Video size={14} aria-hidden />
                            {t('generate_video')}
                        </button>
                    </div>

                    <textarea
                        ref={inputRef}
                        className="seo-ai-chat-input"
                        rows={10}
                        value={prompt}
                        onChange={(e) => setPrompt(e.target.value)}
                        placeholder={t('ai_media_prompt_placeholder')}
                        disabled={busy || applyBlocked}
                    />

                    {resolveError ? (
                        <div className="seo-ai-media-resolve-error" role="alert">
                            <p>{resolveError}</p>
                            <button
                                type="button"
                                className="seo-ai-media-retry-resolve"
                                onClick={() => void handleRetryResolve()}
                                disabled={!hasPrompt || busy}
                            >
                                <RefreshCw size={14} aria-hidden />
                                {t('retry')}
                            </button>
                        </div>
                    ) : null}

                    <div className="seo-ai-chat-actions seo-ai-media-actions">
                        <button
                            type="button"
                            className="seo-ai-media-copy-prompt"
                            onClick={() => void handleCopyPrompt()}
                            disabled={!hasPrompt || busy}
                            title={t('copy_prompt')}
                        >
                            {copied ? <Check size={15} /> : <Copy size={15} />}
                            {copied ? t('copy_prompt_done') : t('copy_prompt')}
                        </button>

                        {canGenerateWithApi ? (
                            <button
                                type="button"
                                className="seo-ai-media-generate-api"
                                onClick={dispatchGenerateWithApi}
                                disabled={!hasPrompt || busy || applyBlocked || generateLockRef.current}
                                title={t('generate_with_api')}
                            >
                                {busy && (generatingImage || generatingVideo)
                                    ? (mediaType === 'video' ? t('generating_video') : t('generating_image'))
                                    : t('generate_with_api')}
                            </button>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );

}
