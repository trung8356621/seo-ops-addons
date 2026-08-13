import { getPlainTextFromBlocks } from './contentDocumentHelpers';

function blockPlainText(block) {
    if (!block) {
        return '';
    }
    if (block.type === 'image') {
        return '';
    }
    return getPlainTextFromBlocks([block]).trim();
}

function findHeadingBlock(blocks) {
    return blocks.find((block) => {
        if (block?.type === 'heading') {
            return true;
        }
        const content = String(block?.content ?? '');
        return /<h[1-6]\b/i.test(content);
    }) ?? null;
}

function findEmptyImageBlockId(blocks) {
    for (const block of blocks) {
        if (block?.type !== 'image') {
            continue;
        }
        const src = String(block?.image?.src ?? '').trim();
        if (!src) {
            return String(block.id ?? '').trim();
        }
    }
    return '';
}

/**
 * @param {object} params
 * @param {object} params.section
 * @param {Map<string, object>} params.blockById
 * @param {string} [params.articleTitle]
 * @param {string} [params.focusKeyword]
 */
export function buildAiMediaContextFromSection({
    section,
    blockById,
    articleTitle = '',
    focusKeyword = '',
}) {
    const sectionBlocks = (section?.blockIds ?? [])
        .map((blockId) => blockById.get(blockId))
        .filter(Boolean);
    const headingBlock = findHeadingBlock(sectionBlocks);
    const headingText = blockPlainText(headingBlock);
    const bodyBlocks = sectionBlocks.filter((block) => block !== headingBlock && block?.type !== 'image');
    const bodyText = getPlainTextFromBlocks(bodyBlocks).trim();
    const keyword = String(focusKeyword || articleTitle || '').trim();

    const parts = [];
    if (headingText) {
        parts.push(headingText);
    }
    if (bodyText) {
        parts.push(bodyText);
    }

    let prompt = parts.join('\n\n');
    if (keyword && prompt) {
        prompt = `${keyword}\n\n${prompt}`;
    } else if (keyword && !prompt) {
        prompt = keyword;
    }

    const targetBlockId = findEmptyImageBlockId(sectionBlocks)
        || String(section?.blockIds?.[0] ?? '').trim();

    return {
        prompt,
        targetBlockId: targetBlockId || null,
        mediaType: 'image',
    };
}

/**
 * @param {object} params
 * @param {string} params.blockId
 * @param {Map<string, object>} params.blockById
 * @param {Map<string, object>} [params.sectionByBlockId]
 * @param {string} [params.articleTitle]
 * @param {string} [params.focusKeyword]
 */
export function buildAiMediaContextFromBlock({
    blockId,
    blockById,
    sectionByBlockId,
    articleTitle = '',
    focusKeyword = '',
}) {
    const normalizedBlockId = String(blockId ?? '').trim();
    const section = sectionByBlockId?.get(normalizedBlockId);
    if (section) {
        const fromSection = buildAiMediaContextFromSection({
            section,
            blockById,
            articleTitle,
            focusKeyword,
        });
        return {
            ...fromSection,
            targetBlockId: normalizedBlockId || fromSection.targetBlockId,
        };
    }

    const keyword = String(focusKeyword || articleTitle || '').trim();
    const block = blockById.get(normalizedBlockId);
    const nearbyText = blockPlainText(block);

    return {
        prompt: keyword && nearbyText ? `${keyword}\n\n${nearbyText}` : (nearbyText || keyword),
        targetBlockId: normalizedBlockId || null,
        mediaType: 'image',
    };
}
