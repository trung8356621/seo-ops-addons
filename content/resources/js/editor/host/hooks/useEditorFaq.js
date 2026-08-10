import { useMemo } from 'react';
import { getEditorCommandHost } from '../../../utils/editorCommands';
import {
    applyFaqSnapshot,
    extractFaqFromSelection,
    generateFaqPreview,
} from '../../../utils/articleEditorFaqSnapshot';

/**
 * Phase 6C.2 — FAQ snapshot/API + host document apply.
 */
export function useEditorFaq() {
    return useMemo(() => ({
        generatePreview: (articleId, html, options) => generateFaqPreview(articleId, html, options),
        applySnapshot: (articleId, faqs, html, options) => applyFaqSnapshot(articleId, faqs, html, options),
        extractFromSelection: async (articleId, html, articleHtml, options) => {
            const result = await extractFaqFromSelection(articleId, html, articleHtml, options);
            const actions = getEditorCommandHost()?.actions;
            if (result && typeof actions?.applyExtractedFaqs === 'function') {
                actions.applyExtractedFaqs({
                    faqs: result.faqs,
                    editorHtml: result.editor_html,
                    faqSnapshot: result.faq_snapshot,
                });
            }
            return result;
        },
        getExportHtml: () => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.getExportHtml === 'function') {
                return String(actions.getExportHtml() ?? '');
            }
            return '';
        },
        getSelectionHtml: () => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.getSelectionHtml === 'function') {
                return String(actions.getSelectionHtml() ?? '');
            }
            return '';
        },
    }), []);
}
