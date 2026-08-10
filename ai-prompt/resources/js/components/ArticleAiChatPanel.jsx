import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ImageIcon, Video, X } from 'lucide-react';
import { runFaqExtractFromToolbar } from '@content-addon/editor/modules/faq/faqExtractToolbarAction.js';
import { getEditorInsertionContext } from '@content-addon/utils/editorInsertionContext.js';
import { t } from '@content-addon/utils/i18n.js';

function hydratePromptTemplate(template, variables) {
    return String(template ?? '').replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g, (match, key) => {
        if (!Object.prototype.hasOwnProperty.call(variables, key)) {
            return match;
        }
        return String(variables[key] ?? '');
    });
}

/**
 * Editor AI chat — image/video generate with selection context.
 * Phase 6C.4: no internal CustomEvent for open/close/generate (host actions / props).
 */
export default function ArticleAiChatPanel({
    articleId,
    aiDebug = { enabled: false },
    canGenerateImage = true,
    canGenerateVideo = true,
    onClose = null,
    onGenerateImage = null,
    onGenerateVideo = null,
    collectContext = null,
    canApply = null,
}) {
    const [selectedText, setSelectedText] = useState('');
    const [selectedHtml, setSelectedHtml] = useState('');
    const [activeBlockId, setActiveBlockId] = useState('');
    const [input, setInput] = useState('');
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const [applyBlocked, setApplyBlocked] = useState(false);
    const generateLockRef = useRef(false);
    const inputRef = useRef(null);

    const refreshSelection = useCallback(() => {
        const ctx = typeof collectContext === 'function' ? collectContext() : null;
        const insertion = getEditorInsertionContext?.() || {};
        const text = String(ctx?.selection?.text ?? insertion?.text ?? '').trim();
        const html = String(ctx?.selection?.html ?? insertion?.html ?? '').trim();
        const blockId = String(ctx?.block_id ?? insertion?.blockId ?? '').trim();
        setSelectedText(text);
        setSelectedHtml(html);
        if (blockId) setActiveBlockId(blockId);
    }, [collectContext]);

    useEffect(() => {
        refreshSelection();
        const timer = window.setInterval(refreshSelection, 800);
        return () => window.clearInterval(timer);
    }, [refreshSelection]);

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

        // Domain completion signals from media pipeline (shell/Laravel) — not React-to-React bus.
        window.addEventListener('article-ai-media-failed', onMediaFailed);
        window.addEventListener('article-ai-image-generated', onImageDone);
        window.addEventListener('article-ai-video-generated', onVideoDone);
        return () => {
            window.removeEventListener('article-ai-media-failed', onMediaFailed);
            window.removeEventListener('article-ai-image-generated', onImageDone);
            window.removeEventListener('article-ai-video-generated', onVideoDone);
        };
    }, [canApply]);

    useEffect(() => {
        requestAnimationFrame(() => inputRef.current?.focus());
    }, []);

    const handleExtractFaq = useCallback(() => {
        const html = selectedHtml.trim();
        const text = selectedText.trim();
        if (!html && !text) return;
        const payloadHtml = html || text
            .split(/\n{2,}/)
            .map((p) => `<p>${p.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>`)
            .join('');
        void runFaqExtractFromToolbar({ html: payloadHtml });
    }, [selectedHtml, selectedText]);

    const dispatchGenerate = useCallback(
        async (type) => {
            const userBrief = input.trim();
            const selectionText = selectedText.trim();
            const selectionHtml = selectedHtml.trim();
            if (!userBrief && !selectionText) return;
            if (generateLockRef.current) return;
            if (typeof canApply === 'function' && !canApply()) {
                setApplyBlocked(true);
                return;
            }

            generateLockRef.current = true;
            const detail = {
                selectionText,
                selectionHtml,
                userBrief,
                activeBlockId,
                articleId,
            };

            try {
                if (type === 'image') {
                    setGeneratingImage(true);
                    if (typeof onGenerateImage === 'function') {
                        await onGenerateImage(detail);
                    }
                } else {
                    setGeneratingVideo(true);
                    if (typeof onGenerateVideo === 'function') {
                        await onGenerateVideo(detail);
                    }
                }
                setInput('');
            } catch {
                setGeneratingImage(false);
                setGeneratingVideo(false);
                generateLockRef.current = false;
                if (typeof canApply === 'function' && !canApply()) setApplyBlocked(true);
            }
        },
        [activeBlockId, articleId, canApply, input, onGenerateImage, onGenerateVideo, selectedHtml, selectedText],
    );

    const canGenerate = Boolean(input.trim() || selectedText.trim()) && !applyBlocked;
    const busy = generatingImage || generatingVideo;
    const userBrief = input.trim();
    const contextText = selectedText.trim();
    const contextHtml = selectedHtml.trim();
    const composedInput = useMemo(() => {
        if (userBrief && contextText) {
            return `${userBrief}\n\n---\n${t('debug_context_label')}:\n${contextText}`;
        }
        return userBrief || contextText;
    }, [contextText, userBrief]);
    const debugVariables = useMemo(
        () => ({
            input: composedInput,
            user_brief: userBrief,
            selected_text: contextText,
            selected_html: contextHtml,
            post_title: String(aiDebug?.article_title ?? ''),
            focus_keyword: String(aiDebug?.focus_keyword ?? ''),
        }),
        [aiDebug?.article_title, aiDebug?.focus_keyword, composedInput, contextHtml, contextText, userBrief],
    );
    const imageDebugPrompt = useMemo(
        () => hydratePromptTemplate(aiDebug?.image?.template ?? '', debugVariables),
        [aiDebug?.image?.template, debugVariables],
    );
    const videoDebugPrompt = useMemo(
        () => hydratePromptTemplate(aiDebug?.video?.template ?? '', debugVariables),
        [aiDebug?.video?.template, debugVariables],
    );

    return (
        <div className="seo-ai-chat-panel wp-postbox">
            <div className="wp-postbox-header seo-ai-chat-panel__header">
                <h2>{t('ai_images_videos')}</h2>
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
                            Session read-only — generation/apply blocked
                        </p>
                    ) : null}
                    <textarea
                        ref={inputRef}
                        className="seo-ai-chat-input"
                        rows={8}
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        placeholder={t('compose_placeholder')}
                        disabled={busy || applyBlocked}
                    />
                    <div className="seo-ai-chat-actions">
                        <button
                            type="button"
                            className="seo-ai-chat-generate-image"
                            onClick={() => void dispatchGenerate('image')}
                            disabled={!canGenerate || !canGenerateImage || busy || generateLockRef.current}
                            title={
                                !canGenerateImage
                                    ? t('editor_generate_image_no_prompt')
                                    : t('generate_image')
                            }
                        >
                            <ImageIcon size={15} />
                            {generatingImage ? t('generating_image') : t('generate_image')}
                        </button>
                        <button
                            type="button"
                            className="seo-ai-chat-generate-video"
                            onClick={() => void dispatchGenerate('video')}
                            disabled={!canGenerate || !canGenerateVideo || busy || generateLockRef.current}
                            title={
                                !canGenerateVideo
                                    ? t('editor_generate_video_no_prompt')
                                    : t('generate_video')
                            }
                        >
                            <Video size={15} />
                            {generatingVideo ? t('generating_video') : t('generate_video')}
                        </button>
                        {(selectedHtml.trim() || selectedText.trim()) ? (
                            <button
                                type="button"
                                className="seo-ai-chat-extract-faq"
                                onClick={handleExtractFaq}
                                disabled={busy}
                            >
                                {t('extract_faq') || 'Extract FAQ'}
                            </button>
                        ) : null}
                    </div>
                    {Boolean(aiDebug?.enabled) ? (
                        <div className="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-950 space-y-2">
                            <p className="font-semibold">
                                {t('debug_prompt_title')} <code>{composedInput || t('empty')}</code>
                            </p>
                            <div>
                                <p className="font-semibold">{t('debug_generate_image')} #{aiDebug?.image?.prompt_id ?? 'n/a'}</p>
                                <pre className="max-h-40 overflow-auto whitespace-pre-wrap wrap-break-word bg-white p-2 border rounded">
                                    {imageDebugPrompt || t('debug_no_image_prompt')}
                                </pre>
                            </div>
                            <div>
                                <p className="font-semibold">{t('debug_generate_video')} #{aiDebug?.video?.prompt_id ?? 'n/a'}</p>
                                <pre className="max-h-40 overflow-auto whitespace-pre-wrap wrap-break-word bg-white p-2 border rounded">
                                    {videoDebugPrompt || t('debug_no_video_prompt')}
                                </pre>
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
