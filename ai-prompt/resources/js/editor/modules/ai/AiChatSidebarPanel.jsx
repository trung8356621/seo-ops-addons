import React, { Suspense, lazy } from 'react';
import { EditorModuleErrorBoundary } from '@content-addon/editor/runtime/EditorModuleErrorBoundary.jsx';
import { useEditorAi } from '../../host/hooks/useEditorAi';
import { useEditorHostApiOptional } from '@content-addon/editor/host/EditorHostApiContext.jsx';
import { t } from '@content-addon/utils/i18n.js';

const ArticleAiChatPanel = lazy(() => import('../../../components/ArticleAiChatPanel'));

/**
 * Phase 6C.4 — AI Chat sidebar (editor-owned image/video generate + selection).
 */
export function AiChatSidebarPanel({
    articleId = null,
    active = false,
}) {
    const ai = useEditorAi();
    const hostApi = useEditorHostApiOptional();
    const canGenerateImage = hostApi?.ai?.canGenerateImage !== false;
    const canGenerateVideo = hostApi?.ai?.canGenerateVideo === true;

    if (!active) {
        return <div className="seo-assistant-widget__lazy-placeholder">{t('editor_panel_lazy_placeholder')}</div>;
    }

    return (
        <EditorModuleErrorBoundary moduleId="article-editor.ai" slotName="sidebar.main">
            <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                <ArticleAiChatPanel
                    articleId={articleId}
                    canGenerateImage={canGenerateImage}
                    canGenerateVideo={canGenerateVideo}
                    onClose={() => ai.close()}
                    canApply={ai.canApply}
                />
            </Suspense>
        </EditorModuleErrorBoundary>
    );
}
