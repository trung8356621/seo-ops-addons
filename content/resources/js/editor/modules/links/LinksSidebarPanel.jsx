import React, { Suspense, lazy } from 'react';
import { EditorModuleErrorBoundary } from '../../runtime/EditorModuleErrorBoundary';
import { t } from '../../../utils/i18n';

const ArticleLinksSidebar = lazy(() => import('../../../components/ArticleLinksSidebar'));

/**
 * Phase 6C.2 — Links runtime sidebar panel (host: editor).
 * CTA chip aliases this panel (link-section=cta); CTA module owns insert semantics.
 */
export function LinksSidebarPanel({ articleId = null, siteId = null }) {
    return (
        <EditorModuleErrorBoundary moduleId="article-editor.links" slotName="sidebar.main">
            <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
                <ArticleLinksSidebar
                    articleId={articleId}
                    siteId={siteId}
                    initialDomainLinkList={[]}
                    initialDomainLinkCatalog={[]}
                    initialDomainCtaList={[]}
                />
            </Suspense>
        </EditorModuleErrorBoundary>
    );
}
