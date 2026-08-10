/**
 * Phase 6C.2 — FAQ extract toolbar/module action (no CustomEvent loop).
 */

import { extractFaqFromSelection } from '../../../utils/articleEditorFaqSnapshot';
import { getEditorCommandHost } from '../../../utils/editorCommands';
import { openPanel } from '../../runtime/editorRuntimeNavigation';

/**
 * @param {{ articleId?: number|string|null }} [options]
 */
export async function runFaqExtractFromToolbar(options = {}) {
    const host = getEditorCommandHost();
    const articleId = options.articleId ?? host?.articleId ?? null;
    if (!articleId) {
        host?.notify?.({
            title: 'FAQ',
            body: 'Missing article id',
            status: 'warning',
        });
        return null;
    }

    const html = String(
        options.html
        ?? (typeof host?.actions?.getSelectionHtml === 'function' ? host.actions.getSelectionHtml() : '')
        ?? '',
    );
    const articleHtml = String(
        options.articleHtml
        ?? (typeof host?.actions?.getExportHtml === 'function' ? host.actions.getExportHtml() : '')
        ?? '',
    );

    if (html.trim() === '') {
        host?.notify?.({
            title: 'FAQ',
            body: 'Select FAQ content in the editor first.',
            status: 'warning',
        });
        return null;
    }

    openPanel('faq', { source: 'faq_extract_toolbar' });

    try {
        const result = await extractFaqFromSelection(articleId, html, articleHtml);
        if (typeof host?.actions?.applyExtractedFaqs === 'function') {
            host.actions.applyExtractedFaqs({
                faqs: result.faqs,
                editorHtml: result.editor_html,
                faqSnapshot: result.faq_snapshot,
            });
        }
        host?.notify?.({
            title: 'FAQ extracted and saved',
            body: `FAQ items: ${Array.isArray(result.faqs) ? result.faqs.length : 0}`,
            status: 'success',
        });
        return result;
    } catch (error) {
        host?.notify?.({
            title: 'Unable to extract FAQ',
            body: String(error?.message || 'faq_extract_failed'),
            status: 'warning',
        });
        return null;
    }
}
