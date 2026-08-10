/**
 * Pure selectors over DocumentModel (Phase 3).
 * No React. Prefer createDocumentModel(doc) once, then select*.
 */

import { createDocumentModel } from './documentModel';

function asModel(docOrModel) {
    if (docOrModel && typeof docOrModel.wordCount === 'function' && typeof docOrModel.json === 'function') {
        return docOrModel;
    }

    return createDocumentModel(docOrModel);
}

export function selectHeadings(docOrModel, { minLevel = 1, maxLevel = 6 } = {}) {
    return asModel(docOrModel)
        .headings()
        .filter((h) => h.level >= minLevel && h.level <= maxLevel);
}

export function selectH2(docOrModel) {
    return selectHeadings(docOrModel, { minLevel: 2, maxLevel: 2 });
}

export function selectLinks(docOrModel) {
    return asModel(docOrModel).links();
}

export function selectImages(docOrModel) {
    return asModel(docOrModel).images();
}

export function selectParagraphs(docOrModel) {
    return asModel(docOrModel).paragraphs();
}

export function selectBlockquotes(docOrModel) {
    return asModel(docOrModel).blockquotes();
}

export function selectLists(docOrModel) {
    return asModel(docOrModel).lists();
}

export function selectTables(docOrModel) {
    return asModel(docOrModel).tables();
}

export function selectFaqPlaceholders(docOrModel) {
    return asModel(docOrModel)
        .paragraphs()
        .filter((node) => {
            const cls = String(node.attrs?.class ?? '');
            const text = String(node.content?.map?.((c) => c.text ?? '').join('') ?? '');
            return cls.includes('omi-faq-placeholder')
                || cls.includes('omi-faq')
                || /\[omi_faq\]/i.test(text);
        });
}

export function selectCtaParagraphs(docOrModel) {
    return asModel(docOrModel)
        .paragraphs()
        .filter((node) => String(node.attrs?.class ?? '').includes('article-cta'));
}

export function selectPlainText(docOrModel) {
    return asModel(docOrModel).plainTextEligible();
}

export function selectWordCount(docOrModel) {
    return asModel(docOrModel).wordCount({ eligible: true });
}

export default {
    selectHeadings,
    selectH2,
    selectLinks,
    selectImages,
    selectParagraphs,
    selectBlockquotes,
    selectLists,
    selectTables,
    selectFaqPlaceholders,
    selectCtaParagraphs,
    selectPlainText,
    selectWordCount,
};
