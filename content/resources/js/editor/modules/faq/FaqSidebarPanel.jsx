import React, { Suspense, lazy, useCallback, useEffect, useRef, useState } from 'react';
import { EditorModuleErrorBoundary } from '../../runtime/EditorModuleErrorBoundary';
import { isAbortError } from '../../../utils/articleEditorModules';
import { logModuleLoadError, normalizeFaqPayload } from '../../../utils/articleEditorPayloadAdapters';
import { csrfToken, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { t } from '../../../utils/i18n';

const ArticleFaqEditor = lazy(() => import('../../../components/ArticleFaqEditor'));

const EMPTY_FAQ_PAYLOAD = normalizeFaqPayload(null);

/**
 * Phase 6C.2 — FAQ runtime sidebar panel (fetch + render; not ModuleHost).
 */
export function FaqSidebarPanel({ articleId = null, active = false }) {
    const [view, setView] = useState(() => ({
        status: 'idle',
        payload: EMPTY_FAQ_PAYLOAD,
    }));
    const [retryKey, setRetryKey] = useState(0);
    const activeRef = useRef(active);
    activeRef.current = active;

    const retry = useCallback(() => {
        setRetryKey((value) => value + 1);
        setView({ status: 'idle', payload: EMPTY_FAQ_PAYLOAD });
    }, []);

    useEffect(() => {
        if (!active || !articleId) {
            setView({ status: 'idle', payload: EMPTY_FAQ_PAYLOAD });
            return undefined;
        }

        const controller = new AbortController();
        setView({ status: 'loading', payload: EMPTY_FAQ_PAYLOAD });
        const url =
            window.__SEO_EDITOR_LAZY_ENDPOINTS__?.faqs
            || `/api/seo/articles/${articleId}/editor/faqs`;

        void (async () => {
            try {
                const { response, data } = await seoArticleApiFetch(url, {
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                    },
                });
                if (controller.signal.aborted || !activeRef.current) {
                    return;
                }
                if (!response.ok || data?.success === false) {
                    setView({ status: 'error', payload: EMPTY_FAQ_PAYLOAD });
                    logModuleLoadError({
                        moduleName: 'faq',
                        articleId,
                        endpoint: url,
                        error: data?.message || `HTTP ${response.status}`,
                    });
                    return;
                }
                setView({
                    status: 'ready',
                    payload: normalizeFaqPayload(data) ?? EMPTY_FAQ_PAYLOAD,
                });
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
                setView({ status: 'error', payload: EMPTY_FAQ_PAYLOAD });
                logModuleLoadError({
                    moduleName: 'faq',
                    articleId,
                    endpoint: url,
                    error,
                });
            }
        })();

        return () => controller.abort();
    }, [active, articleId, retryKey]);

    if (!active) {
        return (
            <div className="seo-assistant-widget__lazy-placeholder">
                {t('editor_panel_lazy_placeholder')}
            </div>
        );
    }

    if (view.status === 'loading' || view.status === 'idle') {
        return <div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>;
    }

    if (view.status === 'error') {
        return (
            <div className="seo-module-error p-3 text-sm">
                <p className="mb-2 opacity-80">{t('editor_module_error_title')}</p>
                <button type="button" className="rounded bg-primary-600 px-3 py-1.5 text-white" onClick={retry}>
                    {t('editor_module_error_retry')}
                </button>
            </div>
        );
    }

    return (
        <EditorModuleErrorBoundary moduleId="article-editor.faq" slotName="sidebar.main">
            <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                <ArticleFaqEditor
                    articleId={articleId}
                    initialFaqs={Array.isArray(view.payload?.items) ? view.payload.items : []}
                    initialFaqSnapshot={view.payload?.faqSnapshot ?? null}
                    initialExtractDebug={view.payload?.extractDebug ?? null}
                    canGenerateFaq={view.payload?.canGenerateFaq !== false}
                    canImportMarkdownFaq={Boolean(view.payload?.canImportMarkdownFaq)}
                />
            </Suspense>
        </EditorModuleErrorBoundary>
    );
}
