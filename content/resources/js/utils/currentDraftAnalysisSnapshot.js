import { createDocumentModel } from './documentModel.js';
import { blocksToDocumentJson, htmlToDocumentJson } from './htmlDocumentCompat.js';

function resolveCurrentDocument({ document, blocks, html }) {
    if (document && typeof document === 'object') {
        return document;
    }
    if (Array.isArray(blocks) && blocks.length > 0) {
        return blocksToDocumentJson(blocks);
    }

    return htmlToDocumentJson(html);
}

/**
 * Parse the current editor draft once and expose shared structural selectors.
 * Persisted article content is intentionally not accepted as an input.
 */
export function createCurrentDraftAnalysisSnapshot({
    html = '',
    document = null,
    blocks = null,
} = {}) {
    const model = createDocumentModel(resolveCurrentDocument({ document, blocks, html }));
    const headings = model.headings();
    const images = model.images().filter((image) => (
        image.src !== '' && !/placeholder/i.test(image.src)
    ));

    return {
        source: 'current_editor_draft',
        html: String(html ?? ''),
        document: model.json(),
        documentModel: model,
        text: model.plainTextEligible(),
        wordCount: model.wordCount({ eligible: true }),
        headings,
        h2Count: headings.filter((heading) => heading.level === 2).length,
        images,
        imageCount: images.length,
        missingImageAltCount: images.filter((image) => image.alt === '').length,
        tables: model.tables(),
        lists: model.lists(),
        links: model.links(),
    };
}

export default createCurrentDraftAnalysisSnapshot;
