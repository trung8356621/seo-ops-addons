import React, { Suspense, lazy } from 'react';
import { EditorModuleErrorBoundary } from '../../runtime/EditorModuleErrorBoundary';
import { t } from '../../../utils/i18n';

const ArticleVocabularySidebar = lazy(() => import('../../../components/ArticleVocabularySidebar'));

export function VocabularySidebarPanel({ articleId = null, siteId = null, active = false }) {
    return (
        <div className="wp-article-vocabulary-panel-shell">
            <EditorModuleErrorBoundary moduleId="article-editor.vocabulary" slotName="sidebar.main">
                <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                    <ArticleVocabularySidebar articleId={articleId} siteId={siteId} active={active} />
                </Suspense>
            </EditorModuleErrorBoundary>
        </div>
    );
}
