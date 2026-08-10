import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, Monitor, Smartphone, Sparkles, X } from 'lucide-react';
import {
    applyArticleSeoMetaSaveResult,
    executePromptHookViaApi,
    readArticleMetaFromDom,
    saveSeoMetaViaApi,
} from '@content-addon/utils/articleEditorApi.js';
import { normalizeArticleSlug } from '@content-addon/utils/articleSlugUtils.js';
import {
    SLUG_LENGTH_MAX,
    computeGoogleSerpLineScores,
    slugLengthMeterClass,
    slugLengthMeterPercent,
} from '../utils/googleSerpLineScores';
import { t } from '@content-addon/utils/i18n.js';
import GoogleSerpSnippetPreview from './GoogleSerpSnippetPreview';

function previewTitle(preview) {
    const title = String(preview?.title ?? '').trim();
    return title !== '' ? title : t('google_serp_title_placeholder');
}

function previewDescription(preview) {
    const description = String(preview?.description ?? '').trim();
    return description !== '' ? description : t('google_serp_desc_placeholder');
}

function previewUrl(preview, fallbackUrl = '#') {
    const display = String(preview?.display_url ?? '').trim();
    if (display !== '') {
        return display;
    }

    const url = String(preview?.url ?? '').trim();
    return url !== '' ? url : fallbackUrl;
}

function buildLiveDisplayUrl(slugPrefix, slug, slugSuffix, fallbackUrl) {
    const normalizedSlug = normalizeArticleSlug(slug);
    const prefix = String(slugPrefix ?? '').trim();
    const suffix = String(slugSuffix ?? '').trim();

    if (prefix === '' && normalizedSlug === '') {
        return previewUrl({}, fallbackUrl);
    }

    let host = '';
    try {
        const parsed = new URL(prefix.includes('://') ? prefix : `https://${prefix.replace(/^\/+/, '')}`);
        host = parsed.hostname;
    } catch {
        host = prefix.replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
    }

    if (host === '') {
        return normalizedSlug !== '' ? normalizedSlug : previewUrl({}, fallbackUrl);
    }

    const path = [normalizedSlug, suffix.replace(/^\//, '')].filter(Boolean).join('/');
    return path !== '' ? `${host} › ${path.replace(/\//g, ' › ')}` : host;
}

function PreviewDeviceToggle({ device, onChange }) {
    return (
        <div className="google-serp-device-toggle" role="group" aria-label={t('google_serp_device_toggle')}>
            <button
                type="button"
                className={`google-serp-device-toggle__btn${device === 'desktop' ? ' is-active' : ''}`}
                onClick={() => onChange('desktop')}
                title="Desktop"
                aria-label={t('google_serp_preview_desktop')}
                aria-pressed={device === 'desktop'}
            >
                <Monitor size={16} strokeWidth={1.75} aria-hidden />
            </button>
            <button
                type="button"
                className={`google-serp-device-toggle__btn${device === 'mobile' ? ' is-active' : ''}`}
                onClick={() => onChange('mobile')}
                title="Mobile"
                aria-label={t('google_serp_preview_mobile')}
                aria-pressed={device === 'mobile'}
            >
                <Smartphone size={16} strokeWidth={1.75} aria-hidden />
            </button>
        </div>
    );
}

export default function ArticleGoogleSerpPreview({
    articleId = 0,
    initialPreview = null,
    fallbackUrl = '#',
    skipSeoScore = false,
    initialFocusKeyword = '',
    initialSlug = '',
    permalinkBase = '',
    permalinkSuffix = '',
    promptHooks = null,
    articleTitle = '',
}) {
    const [device, setDevice] = useState('desktop');
    const [preview, setPreview] = useState(initialPreview ?? {});
    const [focusKeyword, setFocusKeyword] = useState(String(initialFocusKeyword ?? '').trim());
    const [modalOpen, setModalOpen] = useState(false);
    const [draftFocusKeyword, setDraftFocusKeyword] = useState(String(initialFocusKeyword ?? '').trim());
    const [draftDescription, setDraftDescription] = useState('');
    const [articleSlug, setArticleSlug] = useState(String(initialSlug ?? '').trim());
    const [draftSlug, setDraftSlug] = useState(String(initialSlug ?? '').trim());
    const [slugPrefix, setSlugPrefix] = useState(String(permalinkBase ?? '').trim());
    const [slugSuffix, setSlugSuffix] = useState(String(permalinkSuffix ?? '').trim());
    const [saving, setSaving] = useState(false);
    const [metaAiLoading, setMetaAiLoading] = useState(false);
    const metaAiRequestRef = useRef(0);
    const draftDescriptionRef = useRef('');

    const metaHookCfg = promptHooks?.meta_description_suggestion ?? {};
    const metaHookConfigured = metaHookCfg.configured === true;
    const metaHookKey = String(metaHookCfg.hook_key || 'article.meta_description_suggestion').trim()
        || 'article.meta_description_suggestion';

    useEffect(() => {
        draftDescriptionRef.current = draftDescription;
    }, [draftDescription]);

    const applyPreview = useCallback((nextPreview) => {
        if (!nextPreview || typeof nextPreview !== 'object') {
            return;
        }

        setPreview(nextPreview);
    }, []);

    useEffect(() => {
        // Prefer Livewire / DOM meta description when SSR SEO embed is gone.
        try {
            const meta = readArticleMetaFromDom();
            const description = String(meta?.seo_meta_description ?? '').trim();
            const slug = String(meta?.slug ?? '').trim();
            if (description !== '') {
                setPreview((prev) => ({
                    ...(prev && typeof prev === 'object' ? prev : {}),
                    description,
                }));
            }
            if (slug !== '') {
                setArticleSlug(slug);
            }
        } catch {
            /* ignore */
        }
    }, []);

    useEffect(() => {
        applyPreview(initialPreview);
    }, [initialPreview, applyPreview]);

    useEffect(() => {
        setDraftFocusKeyword(String(initialFocusKeyword ?? '').trim());
    }, [initialFocusKeyword]);

    useEffect(() => {
        setFocusKeyword(String(initialFocusKeyword ?? '').trim());
    }, [initialFocusKeyword]);

    useEffect(() => {
        setArticleSlug(String(initialSlug ?? '').trim());
    }, [initialSlug]);

    useEffect(() => {
        setSlugPrefix(String(permalinkBase ?? '').trim());
    }, [permalinkBase]);

    useEffect(() => {
        setSlugSuffix(String(permalinkSuffix ?? '').trim());
    }, [permalinkSuffix]);

    useEffect(() => {
        if (!modalOpen) {
            return;
        }

        setDraftFocusKeyword(focusKeyword);
        setDraftDescription(String(preview?.description ?? '').trim());
        setDraftSlug(articleSlug);
    }, [modalOpen, focusKeyword, preview?.description, articleSlug]);

    useEffect(() => {
        const onPreviewUpdated = (event) => {
            applyPreview(event.detail?.preview ?? event.detail);
        };

        const onOpenEdit = () => {
            setModalOpen(true);
        };

        window.addEventListener('google-serp-preview-updated', onPreviewUpdated);
        window.addEventListener('google-serp-preview-open-edit', onOpenEdit);

        return () => {
            window.removeEventListener('google-serp-preview-updated', onPreviewUpdated);
            window.removeEventListener('google-serp-preview-open-edit', onOpenEdit);
        };
    }, [applyPreview]);

    const showScore = !skipSeoScore;
    const slugPrefixDisplay = slugPrefix !== '' ? `${slugPrefix.replace(/\/$/, '')}/` : '';
    const descriptionLength = draftDescription.trim().length;
    const slugLength = draftSlug.trim().length;

    const sidebarLineScores = useMemo(
        () =>
            computeGoogleSerpLineScores({
                title: previewTitle(preview),
                description: previewDescription(preview),
                slug: articleSlug,
                focusKeyword,
            }),
        [preview, articleSlug, focusKeyword],
    );

    const modalLineScores = useMemo(
        () =>
            computeGoogleSerpLineScores({
                title: previewTitle(preview),
                description: draftDescription.trim() !== '' ? draftDescription : previewDescription(preview),
                slug: draftSlug,
                focusKeyword: draftFocusKeyword,
            }),
        [preview, draftDescription, draftSlug, draftFocusKeyword],
    );

    const modalLiveUrl = useMemo(
        () => buildLiveDisplayUrl(slugPrefix, draftSlug, slugSuffix, fallbackUrl),
        [slugPrefix, draftSlug, slugSuffix, fallbackUrl],
    );

    const sidebarPreviewProps = useMemo(
        () => {
            const titleFromArticle = String(articleTitle ?? '').trim();
            const title = titleFromArticle !== '' ? titleFromArticle : previewTitle(preview);
            const liveUrl = buildLiveDisplayUrl(slugPrefix, articleSlug, slugSuffix, fallbackUrl);

            return {
                device,
                title,
                url: liveUrl !== '#' ? liveUrl : previewUrl(preview, fallbackUrl),
                description: previewDescription(preview),
                lineScores: computeGoogleSerpLineScores({
                    title,
                    description: previewDescription(preview),
                    slug: articleSlug,
                    focusKeyword,
                }),
                showScore,
                previewMeta: preview?.meta,
                previewType: preview?.type,
            };
        },
        [device, articleTitle, preview, fallbackUrl, slugPrefix, articleSlug, slugSuffix, focusKeyword, showScore],
    );

    const modalPreviewProps = useMemo(
        () => ({
            device,
            title: previewTitle(preview),
            url: modalLiveUrl,
            description: draftDescription.trim() !== '' ? draftDescription : previewDescription(preview),
            lineScores: modalLineScores,
            showScore,
            previewMeta: preview?.meta,
            previewType: preview?.type,
        }),
        [device, preview, modalLiveUrl, draftDescription, modalLineScores, showScore],
    );

    const openModal = () => {
        setModalOpen(true);
    };

    const closeModal = () => {
        if (saving || metaAiLoading) {
            return;
        }

        setModalOpen(false);
    };

    const resolveLatestTitle = useCallback(() => {
        const fromProp = String(articleTitle ?? '').trim();
        if (fromProp !== '') {
            return fromProp;
        }
        const fromDom = String(readArticleMetaFromDom()?.title ?? '').trim();
        if (fromDom !== '') {
            return fromDom;
        }

        return String(preview?.title ?? '').trim();
    }, [articleTitle, preview?.title]);

    const suggestMetaDescription = useCallback(async () => {
        const resolvedArticleId = Number(articleId ?? 0);
        const title = resolveLatestTitle();
        const oldDescriptionRaw = String(draftDescriptionRef.current ?? '').trim();
        const descriptionSnapshot = oldDescriptionRaw;

        if (!metaHookConfigured) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('prompt_hook_meta_failed'),
                        body: t('prompt_hook_meta_no_prompt'),
                        status: 'danger',
                    },
                }),
            );

            return;
        }

        if (title === '') {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('prompt_hook_meta_failed'),
                        body: t('prompt_hook_meta_no_title'),
                        status: 'danger',
                    },
                }),
            );

            return;
        }

        if (resolvedArticleId <= 0 || metaAiLoading) {
            return;
        }

        const seq = ++metaAiRequestRef.current;
        setMetaAiLoading(true);

        try {
            const result = await executePromptHookViaApi(metaHookKey, resolvedArticleId, {
                title,
                old_description: oldDescriptionRaw === '' ? null : oldDescriptionRaw,
            });

            if (seq !== metaAiRequestRef.current) {
                return;
            }

            const value = String(result?.data?.output?.value ?? '').trim();
            if (value === '') {
                throw new Error(t('prompt_hook_meta_empty'));
            }

            const current = String(draftDescriptionRef.current ?? '').trim();
            if (current !== descriptionSnapshot) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('prompt_hook_meta_stale'),
                            body: t('prompt_hook_meta_stale_body', { text: value }),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            setDraftDescription(value);
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('prompt_hook_meta_success'),
                        body: t('prompt_hook_meta_success_body'),
                        status: 'success',
                    },
                }),
            );
        } catch (error) {
            if (seq !== metaAiRequestRef.current) {
                return;
            }
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('prompt_hook_meta_failed'),
                        body: error?.message ?? t('prompt_hook_try_again'),
                        status: 'danger',
                    },
                }),
            );
        } finally {
            if (seq === metaAiRequestRef.current) {
                setMetaAiLoading(false);
            }
        }
    }, [
        articleId,
        metaAiLoading,
        metaHookConfigured,
        metaHookKey,
        resolveLatestTitle,
    ]);

    const saveSeoMeta = async () => {
        const resolvedArticleId = Number(articleId ?? 0);
        if (resolvedArticleId <= 0) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('google_serp_save_failed'),
                        body: t('google_serp_try_again'),
                        status: 'danger',
                    },
                }),
            );

            return;
        }

        setSaving(true);

        try {
            const result = await saveSeoMetaViaApi(resolvedArticleId, {
                focus_keyword: draftFocusKeyword,
                meta_description: draftDescription,
                slug: draftSlug,
            });

            applyArticleSeoMetaSaveResult(result);
            applyPreview(result?.google_serp_preview ?? result);
            if (result?.focus_keyword != null) {
                setFocusKeyword(String(result.focus_keyword).trim());
            }
            if (result?.article_slug != null) {
                setArticleSlug(String(result.article_slug).trim());
            }
            if (result?.permalink_base != null) {
                setSlugPrefix(String(result.permalink_base).trim());
            }
            if (result?.permalink_suffix != null) {
                setSlugSuffix(String(result.permalink_suffix).trim());
            }
            setModalOpen(false);
        } catch (error) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('google_serp_save_failed'),
                        body: error?.message ?? t('google_serp_try_again'),
                        status: 'danger',
                    },
                }),
            );
        } finally {
            setSaving(false);
        }
    };

    const modalContent = modalOpen ? (
        <div className="seo-google-preview-modal" role="dialog" aria-modal="true" aria-labelledby="seo-google-preview-modal-title">
            <button type="button" className="seo-google-preview-modal__backdrop" onClick={closeModal} aria-label={t('google_serp_close')} />
            <div className="seo-google-preview-modal__panel">
                <div className="seo-google-preview-modal__header">
                    <h3 id="seo-google-preview-modal-title">{t('google_serp_edit_fields')}</h3>
                    <button type="button" className="seo-google-preview-modal__close" onClick={closeModal} aria-label={t('google_serp_close')}>
                        <X size={18} aria-hidden />
                    </button>
                </div>
                <div className="seo-google-preview-modal__body">
                    <div className="seo-google-preview-modal__preview-section">
                        <div className="seo-google-preview-modal__preview-head">
                            <h4>{t('google_serp_preview_heading')}</h4>
                            <PreviewDeviceToggle device={device} onChange={setDevice} />
                        </div>
                        <GoogleSerpSnippetPreview {...modalPreviewProps} variant="modal" />
                    </div>

                    <div className="seo-google-preview-modal__field">
                        <div className="seo-google-preview-modal__label-row">
                            <label htmlFor="seo-google-preview-focus-keyword">{t('google_serp_focus_keyword')}</label>
                            <span>{t('google_serp_chars', { count: draftFocusKeyword.trim().length })}</span>
                        </div>
                        <input
                            id="seo-google-preview-focus-keyword"
                            type="text"
                            value={draftFocusKeyword}
                            onChange={(event) => setDraftFocusKeyword(event.target.value)}
                            className="seo-google-preview-modal__input"
                            placeholder={t('google_serp_focus_keyword_placeholder')}
                        />
                    </div>

                    <div className="seo-google-preview-modal__field">
                        <div className="seo-google-preview-modal__label-row">
                            <div className="seo-google-preview-modal__label-with-ai">
                                <label htmlFor="seo-google-preview-description">{t('google_serp_meta_description')}</label>
                                <button
                                    type="button"
                                    className="seo-prompt-hook-ai-btn"
                                    onClick={suggestMetaDescription}
                                    disabled={
                                        metaAiLoading
                                        || saving
                                        || !metaHookConfigured
                                        || resolveLatestTitle() === ''
                                    }
                                    title={
                                        !metaHookConfigured
                                            ? t('prompt_hook_meta_no_prompt')
                                            : resolveLatestTitle() === ''
                                              ? t('prompt_hook_meta_no_title')
                                              : metaAiLoading
                                                ? t('prompt_hook_meta_running')
                                                : t('prompt_hook_meta_tooltip')
                                    }
                                    aria-label={t('prompt_hook_meta_tooltip')}
                                >
                                    {metaAiLoading ? (
                                        <Loader2 size={16} strokeWidth={1.75} className="seo-prompt-hook-ai-btn__spinner" aria-hidden />
                                    ) : (
                                        <Sparkles size={16} strokeWidth={1.75} aria-hidden />
                                    )}
                                </button>
                            </div>
                            <span>{descriptionLength} / 160</span>
                        </div>
                        <textarea
                            id="seo-google-preview-description"
                            value={draftDescription}
                            onChange={(event) => setDraftDescription(event.target.value)}
                            rows={5}
                            className="seo-google-preview-modal__textarea"
                            placeholder={t('google_serp_meta_description_placeholder')}
                            disabled={saving}
                        />
                        <div className="seo-google-preview-modal__meter" aria-hidden="true">
                            <div
                                className={`seo-google-preview-modal__meter-fill${
                                    descriptionLength > 160
                                        ? ' is-over'
                                        : descriptionLength >= 120
                                          ? ' is-good'
                                          : ' is-warn'
                                }`}
                                style={{ width: `${Math.min(100, Math.round((descriptionLength / 160) * 100))}%` }}
                            />
                        </div>
                    </div>

                    <div className="seo-google-preview-modal__field">
                        <div className="seo-google-preview-modal__label-row">
                            <label htmlFor="seo-google-preview-slug">{t('google_serp_permalink')}</label>
                            <span>{slugLength} / {SLUG_LENGTH_MAX}</span>
                        </div>
                        <div className="seo-google-preview-modal__slug-row">
                            {slugPrefixDisplay !== '' ? (
                                <span className="seo-google-preview-modal__slug-prefix">{slugPrefixDisplay}</span>
                            ) : null}
                            <input
                                id="seo-google-preview-slug"
                                type="text"
                                value={draftSlug}
                                onChange={(event) => setDraftSlug(normalizeArticleSlug(event.target.value))}
                                className="seo-google-preview-modal__input seo-google-preview-modal__slug-input"
                                placeholder={t('google_serp_permalink_placeholder')}
                            />
                            {slugSuffix !== '' ? (
                                <span className="seo-google-preview-modal__slug-suffix">{slugSuffix}</span>
                            ) : null}
                        </div>
                        <div className="seo-google-preview-modal__meter" aria-hidden="true">
                            <div
                                className={`seo-google-preview-modal__meter-fill${slugLengthMeterClass(slugLength)}`}
                                style={{ width: `${slugLengthMeterPercent(slugLength)}%` }}
                            />
                        </div>
                    </div>
                </div>
                <div className="seo-google-preview-modal__footer">
                    <button type="button" className="seo-google-preview-modal__btn" onClick={closeModal} disabled={saving || metaAiLoading}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="seo-google-preview-modal__btn is-primary"
                        onClick={saveSeoMeta}
                        disabled={saving || metaAiLoading}
                    >
                        {saving ? t('google_serp_saving') : t('google_serp_save')}
                    </button>
                </div>
            </div>
        </div>
    ) : null;

    return (
        <>
            <aside className="seo-article-editor-google-preview-rail" aria-label={t('google_serp_preview_rail_label')}>
                <div className="wp-postbox wp-seo-preview-box">
                    <div className="wp-postbox-header">
                        <div className="wp-seo-preview-header-title">
                            <h2>{t('google_serp_preview_heading')}</h2>
                        </div>
                        <PreviewDeviceToggle device={device} onChange={setDevice} />
                    </div>
                    <div className="wp-postbox-inside wp-seo-preview-box__inside">
                        <GoogleSerpSnippetPreview
                            {...sidebarPreviewProps}
                            variant="card"
                            clickable
                            onClick={openModal}
                        />
                    </div>
                </div>
            </aside>

            {modalContent && typeof document !== 'undefined' ? createPortal(modalContent, document.body) : null}
        </>
    );
}
