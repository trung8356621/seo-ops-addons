/**
 * Content editor document/block pure helpers extracted from SeoArticleEditor.jsx
 * (Task 7 frontend extraction). Mechanical move - no behavior change.
 *
 * Pure functions only: block CRUD, HTML<->blocks parsing, outline/section
 * derivation, section stats, and product-image distribution.
 */
import { csrfToken, seoArticleApiHeaders } from '@seo-addon/utils/seoArticleApi.js';
import {
    htmlToPlainText,
    isMeaningfulHtml,
    isWordPressImageElement,
    normalizeBlocks,
    parseImageFromBlockContent,
    renderImageFigure,
    splitHtmlIntoTextAndImageChunks,
} from '@media-addon/utils/blockImageUtils.js';
import { slugFromUrl } from '@media-addon/utils/articleImagesUtils.js';
import { loadProductAlbum } from '@media-addon/utils/articleProductAlbumStorage.js';
import {
    resolveArticleImageSrc,
    resolveFullWordPressImageUrl,
    isLocalSeoMediaSrc,
    supportsWordPressImageSizes,
} from '@wordpress-addon/utils/wordpressImageUrl.js';
import { applyWordPressImageSize } from '@wordpress-addon/utils/wordpressImageSize.js';
import {
    cleanBlockHtmlForEditorDisplay,
    FAQ_SHORTCODE_HTML,
    flattenHtmlBodyNodes,
    isFaqPlaceholderHtml,
    normalizeSectionHeadingBlockHtml,
    persistBlockHtmlFromEditor,
} from './editorHtmlUtils';
import { stripEditorTransientMarkup } from './articleEditorTransientMarkup';
import { countWordsFromHtmlLight } from './articleEditorMetrics';
import { countPlainTextInHtml } from './articleLinkScroll';
import {
    flattenClientOutlineNodes,
    normalizeOutlineHeadingText,
} from './articleEditorClientOutline';
import { t } from './i18n';
export const newBlockId = (prefix) => `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;

export const createEmptyTextBlock = () => ({
    id: newBlockId('classic'),
    type: 'text',
    isWp: false,
    prefix: '',
    content: '<p></p>',
    suffix: '',
});

export const createEmptyImageBlock = () => ({
    id: newBlockId('image'),
    type: 'image',
    isWp: false,
    prefix: '',
    content: '',
    suffix: '',
    image: null,
});

export const createFaqShortcodeBlock = () => ({
    id: newBlockId('classic'),
    type: 'text',
    isWp: false,
    prefix: '',
    content: FAQ_SHORTCODE_HTML,
    suffix: '',
});

export const createEmptySectionBlock = () => {
    const id = newBlockId('classic');
    const suffix = String(id).split('-').pop()?.slice(-4) ?? String(Date.now()).slice(-4);
    const headingText = `${t('editor_new_section_heading')} ${suffix}`;

    return {
        id,
        type: 'text',
        isWp: false,
        prefix: '',
        content: `<h2>${headingText}</h2><p></p>`,
        suffix: '',
    };
};

export const articleHasFaqShortcode = (blocks) =>
    blocks.some((block) => isFaqPlaceholderHtml(block.content || ''));

export const stripLeadingH1FromHtml = (html) => {
    const trimmed = String(html || '').trim();
    if (!trimmed) {
        return trimmed;
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(trimmed, 'text/html');
        const h1 = doc.body.querySelector('h1');
        if (!h1) {
            return trimmed;
        }

        h1.remove();

        return doc.body.innerHTML.trim();
    } catch {
        return trimmed.replace(/<h1\b[^>]*>[\s\S]*?<\/h1>\s*/i, '').trim();
    }
};

export const requiresClassicInlineRegroup = (html) => {
    const source = String(html || '').trim();
    if (!source) {
        return false;
    }

    try {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        const nodes = Array.from(doc.body.childNodes);
        const hasLooseText = nodes.some(
            (node) => node.nodeType === 3 && Boolean(node.textContent?.trim()),
        );
        const hasTopLevelInline = nodes.some(
            (node) =>
                node.nodeType === 1 &&
                ![
                    'ADDRESS',
                    'ASIDE',
                    'BLOCKQUOTE',
                    'DETAILS',
                    'DL',
                    'FIELDSET',
                    'FIGURE',
                    'FOOTER',
                    'FORM',
                    'H1',
                    'H2',
                    'H3',
                    'H4',
                    'H5',
                    'H6',
                    'HEADER',
                    'HR',
                    'MAIN',
                    'NAV',
                    'OL',
                    'P',
                    'PRE',
                    'TABLE',
                    'UL',
                ].includes(node.tagName),
        );

        return hasLooseText && hasTopLevelInline;
    } catch {
        return false;
    }
};

export const extractSectionHeading = (block) => {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return null;
    }

    const normalized = normalizeSectionHeadingBlockHtml(block.content);
    const parser = new DOMParser();
    const doc = parser.parseFromString(normalized, 'text/html');
    const heading =
        doc.body.querySelector(':scope > h2') ||
        doc.body.querySelector('h2');
    if (!heading) {
        return null;
    }

    const text = (heading.textContent || '').replace(/\s+/g, ' ').trim();

    return text !== '' ? text : t('editor_section_untitled');
};

export const blockHasOutlineHeading = (block) => {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return false;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(block.content, 'text/html');

    return doc.body.querySelector('h2, h3, h4') !== null;
};

export const OUTLINE_HEADING_TEXT_MAX = 255;

/** Khớp server Str::limit(..., 255) / cột heading_text — tránh lệch key DB truncated vs editor full. */
export const truncateOutlineHeadingText = (value) =>
    Array.from(normalizeOutlineHeadingText(value)).slice(0, OUTLINE_HEADING_TEXT_MAX).join('');

export const extractOutlineApiErrorMessage = (data, response) => {
    if (response.status === 419) {
        return 'Phiên đăng nhập hết hạn — tải lại trang rồi thử lại.';
    }

    const direct = typeof data?.message === 'string' ? data.message.trim() : '';
    if (direct !== '') {
        return direct;
    }

    const errors = data?.errors;
    if (errors && typeof errors === 'object') {
        for (const key of Object.keys(errors)) {
            const first = Array.isArray(errors[key]) ? errors[key][0] : null;
            if (typeof first === 'string' && first.trim() !== '') {
                return first.trim();
            }
        }
    }

    return data?.success === false
        ? 'Yêu cầu outline thất bại.'
        : `Yêu cầu outline thất bại (HTTP ${response.status}).`;
};

export const outlineApiCsrfToken = () => csrfToken();

/** Server-persisted outline row id (numeric). Client ids like `client:{blockId}` are local-only. */
export function isPersistedOutlineHeadingId(headingId) {
    const raw = String(headingId ?? '').trim();
    if (raw === '' || raw.startsWith('pending-')) {
        return false;
    }

    return /^\d+$/.test(raw);
}

/** Resolve editor block id from outline heading id (numeric, client:, or pending-). */
export function resolveBlockIdFromOutlineHeadingId(headingId, blockIdByHeadingId = null) {
    const raw = String(headingId ?? '').trim();
    if (raw.startsWith('client:')) {
        const blockId = raw.slice('client:'.length).trim();
        return blockId !== '' ? blockId : null;
    }

    if (raw.startsWith('pending-')) {
        const blockId = raw.slice('pending-'.length).trim();
        return blockId !== '' ? blockId : null;
    }

    const targetId = Number(raw);
    if (!Number.isFinite(targetId) || !blockIdByHeadingId) {
        return null;
    }

    for (const [blockId, mappedId] of blockIdByHeadingId.entries()) {
        if (Number(mappedId) === targetId) {
            return blockId;
        }
    }

    return null;
}

export async function outlineApiRequest(articleId, path, options = {}) {
    // Phase 4: client outline ids are not server resources.
    if (/\/(?:client:|pending-)/.test(String(path ?? ''))) {
        return { success: true };
    }

    const response = await fetch(`/api/seo/articles/${articleId}/outline${path}`, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...seoArticleApiHeaders(),
            ...(outlineApiCsrfToken() ? { 'X-CSRF-TOKEN': outlineApiCsrfToken() } : {}),
            ...(options.headers ?? {}),
        },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(extractOutlineApiErrorMessage(data, response));
    }

    return data;
}

export const flattenOutlineNodes = (nodes, result = []) => flattenClientOutlineNodes(nodes, result);

export const findBlockIdForOutlineHeading = (blocks, level, headingText) => {
    const target = truncateOutlineHeadingText(headingText);
    if (!target) {
        return null;
    }

    const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';

    for (const block of blocks) {
        if (block.type !== 'text' || !block.content) {
            continue;
        }

        const doc = new DOMParser().parseFromString(block.content, 'text/html');
        const match = Array.from(doc.body.querySelectorAll(selector)).find(
            (node) => truncateOutlineHeadingText(node.textContent) === target,
        );
        if (match) {
            return block.id;
        }
    }

    return null;
};

export const flattenOutlineHeadingKeys = (nodes) => {
    const keys = new Set();

    const walk = (items) => {
        if (!Array.isArray(items)) {
            return;
        }

        for (const node of items) {
            const level = Number(node?.level ?? 0);
            const text = truncateOutlineHeadingText(node?.heading_text);
            if (level >= 2 && text !== '') {
                keys.add(outlineHeadingKey(level, text));
            }
            if (Array.isArray(node?.children) && node.children.length > 0) {
                walk(node.children);
            }
        }
    };

    walk(nodes);

    return keys;
};

export const outlineHeadingKey = (level, headingText) =>
    `${Number(level)}|${truncateOutlineHeadingText(headingText)}`;

export const isSectionHeadingBlock = (block, section) =>
    !section?.isIntro &&
    section?.blockIds?.[0] === block?.id &&
    blockHasOutlineHeading(block);

/** Section mới / lỗi: chỉ có H2 + đoạn trống, không block nội dung khác. */
export const sectionHasOnlyEmptyHeadingBody = (section, blockById) => {
    if (section?.isIntro || !section?.blockIds?.length) {
        return false;
    }

    for (let index = 1; index < section.blockIds.length; index += 1) {
        const block = blockById.get(section.blockIds[index]);
        if (!block) {
            continue;
        }

        if (block.type === 'image') {
            return false;
        }

        const plain = String(block.content ?? '')
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (plain !== '') {
            return false;
        }
    }

    const headingBlock = blockById.get(section.blockIds[0]);
    if (!headingBlock || headingBlock.type === 'image' || typeof headingBlock.content !== 'string') {
        return false;
    }

    try {
        const doc = new DOMParser().parseFromString(headingBlock.content, 'text/html');
        doc.body.querySelector('h2, h3, h4')?.remove();
        const rest = (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();

        return rest === '';
    } catch {
        return false;
    }
};

export const INTRO_SECTION_ID = 'section-intro';

export const buildEditorSections = (blocks) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return [];
    }

    const sections = [];
    let currentSection = {
        id: 'section-intro',
        title: t('editor_intro'),
        isIntro: true,
        blockIds: [],
    };

    for (const block of blocks) {
        const heading = extractSectionHeading(block);

        if (heading !== null) {
            if (currentSection.blockIds.length > 0) {
                sections.push(currentSection);
            }

            currentSection = {
                id: `section-${block.id}`,
                title: heading,
                isIntro: false,
                blockIds: [block.id],
            };

            continue;
        }

        currentSection.blockIds.push(block.id);
    }

    if (currentSection.blockIds.length > 0) {
        sections.push(currentSection);
    }

    return sections;
};

export const introSectionHasImageBlock = (blocks) => {
    const intro = buildEditorSections(blocks).find((section) => section.isIntro);
    if (!intro?.blockIds?.length) {
        return false;
    }

    return intro.blockIds.some((blockId) => {
        const block = blocks.find((item) => item.id === blockId);

        return block?.type === 'image';
    });
};

export const countKeywordInSectionBlocks = (section, blockById, needle) => {
    if (!needle || !section?.blockIds?.length) {
        return 0;
    }

    let total = 0;
    for (const blockId of section.blockIds) {
        const block = blockById.get(blockId);
        if (!block || block.type === 'image' || !block.content) {
            continue;
        }
        total += countPlainTextInHtml(block.content, needle);
    }

    return total;
};

export const buildSectionStats = (editorSections, blockById) => {
    const statsMap = new Map();

    for (const section of editorSections) {
        let imageCount = 0;
        let emptyImageSrcCount = 0;
        let tableCount = 0;
        let linkCount = 0;
        let wordCount = 0;

        for (const blockId of section.blockIds) {
            const block = blockById.get(blockId);
            if (!block) continue;

            if (block.type === 'image') {
                const src = String(block?.image?.src ?? '').trim();
                if (src !== '') {
                    imageCount += 1;
                } else {
                    emptyImageSrcCount += 1;
                }
                continue;
            }

            const html = typeof block.content === 'string' ? block.content : '';
            if (!html) continue;

            wordCount += countWordsFromHtmlLight(html);

            const imageStats = countImagesFromHtml(html);
            imageCount += imageStats.withSrc;
            emptyImageSrcCount += imageStats.emptySrc;

            const tableMatches = html.match(/<table\b/gi);
            if (tableMatches) {
                tableCount += tableMatches.length;
            }

            const linkMatches = html.match(/<a\b/gi);
            if (linkMatches) {
                linkCount += linkMatches.length;
            }
        }

        statsMap.set(section.id, {
            imageCount,
            emptyImageSrcCount,
            hasEmptyImageSrc: emptyImageSrcCount > 0,
            tableCount,
            hasTable: tableCount > 0,
            linkCount,
            wordCount,
        });
    }

    return statsMap;
};

export const countWordsFromText = (text) => {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    if (!normalized) {
        return 0;
    }

    return normalized.split(' ').filter(Boolean).length;
};

export const countWordsFromHtml = (html) => {
    if (!html?.trim()) {
        return 0;
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();

    return countWordsFromText(text);
};

export const countImagesFromHtml = (html) => {
    if (!html?.trim()) {
        return { withSrc: 0, emptySrc: 0 };
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const images = Array.from(doc.body.querySelectorAll('img'));

    let withSrc = 0;
    let emptySrc = 0;

    for (const img of images) {
        const src = (img.getAttribute('src') || '').trim();
        if (src !== '') {
            withSrc += 1;
        } else {
            emptySrc += 1;
        }
    }

    return { withSrc, emptySrc };
};

export const normalizeImageSrcKey = (src) => {
    const raw = String(src || '').trim();
    if (!raw) return '';
    try {
        const url = new URL(raw, window.location.origin);
        return `${url.pathname}`.toLowerCase();
    } catch {
        return raw.split('?')[0].toLowerCase();
    }
};

export const hasBlockH2 = (block) => {
    if (!block || String(block.type || '') !== 'text') {
        return false;
    }

    const html = String(block.content || '').trim();
    if (!html) {
        return false;
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        return Boolean(doc.body.querySelector('h2'));
    } catch {
        return /<h2[\s>]/i.test(html);
    }
};

export const DISTRIBUTE_IMAGE_ALIGN = 'center';
export const DISTRIBUTE_IMAGE_SIZE = 'full';

export const resolveDistributeImageSrc = (row) => {
    const wpRaw = String(row?.wpSrc || row?.wp_url || '').trim();
    const wpFull = wpRaw ? resolveFullWordPressImageUrl(wpRaw) : '';
    if (wpFull && !isLocalSeoMediaSrc(wpFull)) {
        return wpFull;
    }

    const src = String(row?.src || '').trim();
    if (src && !isLocalSeoMediaSrc(src)) {
        return resolveFullWordPressImageUrl(src);
    }

    const local = String(row?.localSrc || row?.local_src || '').trim();
    return local || resolveArticleImageSrc(row) || src;
};

export const buildDistributedImage = (media) => {
    const wpSrc = resolveFullWordPressImageUrl(String(media.wpSrc || '').trim());
    let image = {
        src: media.src,
        alt: media.alt || '',
        title: media.title || media.alt || '',
        caption: media.caption || '',
        align: DISTRIBUTE_IMAGE_ALIGN,
        size: DISTRIBUTE_IMAGE_SIZE,
        wpAttachmentId: media.wpAttachmentId,
        seoMediaId: media.seoMediaId,
        slug: media.slug || '',
        wpSrc: wpSrc || (isLocalSeoMediaSrc(media.src) ? '' : resolveFullWordPressImageUrl(media.src)),
        localSrc: media.localSrc || (isLocalSeoMediaSrc(media.src) ? media.src : ''),
    };

    if (supportsWordPressImageSizes(image)) {
        image = applyWordPressImageSize(image, DISTRIBUTE_IMAGE_SIZE);
    }

    return image;
};

export const distributeProductImagesToEmptySections = (blocks, supplementalImages) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return blocks;
    }

    const usedSrc = new Set();
    blocks.forEach((block) => {
        if (block.type !== 'image') {
            return;
        }
        const image = block.image ?? parseImageFromBlockContent(block.content);
        const key = normalizeImageSrcKey(image?.src);
        if (key) {
            usedSrc.add(key);
        }
    });

    const pool = [];
    const poolSeen = new Set();
    (Array.isArray(supplementalImages) ? supplementalImages : []).forEach((row) => {
        if (String(row?.origin || '').trim() !== 'gallery') {
            return;
        }
        const src = String(row?.src || '').trim();
        const srcKey = normalizeImageSrcKey(src);
        if (!srcKey || usedSrc.has(srcKey) || poolSeen.has(srcKey)) {
            return;
        }
        poolSeen.add(srcKey);
        const displaySrc = resolveDistributeImageSrc(row);
        const isLocal = isLocalSeoMediaSrc(displaySrc);
        pool.push({
            src: displaySrc,
            slug: String(row?.slug || '').trim() || slugFromUrl(displaySrc),
            alt: String(row?.alt || '').trim(),
            title: String(row?.title || '').trim(),
            caption: String(row?.caption || '').trim(),
            align: DISTRIBUTE_IMAGE_ALIGN,
            size: DISTRIBUTE_IMAGE_SIZE,
            wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
            seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
            wpSrc: resolveFullWordPressImageUrl(String(row?.wpSrc || row?.wp_url || src).trim()),
            localSrc: String(row?.localSrc || row?.local_src || '').trim() || (isLocal ? displaySrc : ''),
        });
    });

    if (pool.length === 0) {
        return blocks;
    }

    const sections = buildEditorSections(blocks).filter((section) => {
        if (section.isIntro) {
            return false;
        }

        const hasFaqShortcode = section.blockIds.some((blockId) => {
            const block = blocks.find((item) => item.id === blockId);
            if (!block || block.type !== 'text') {
                return false;
            }

            return isFaqPlaceholderHtml(String(block.content || ''));
        });

        return !hasFaqShortcode;
    });
    if (sections.length === 0) {
        return blocks;
    }

    const next = [...blocks];
    let cursor = 0;
    let inserted = 0;

    for (const section of sections) {
        const hasImage = section.blockIds.some((blockId) => {
            const block = next.find((item) => item.id === blockId);
            if (!block) return false;

            if (block.type === 'image') {
                const image = block.image ?? parseImageFromBlockContent(block.content);
                return normalizeImageSrcKey(image?.src) !== '';
            }

            const html = String(block.content || '').trim();
            if (!html) return false;
            return countImagesFromHtml(html).withSrc > 0;
        });
        if (hasImage) {
            continue;
        }

        if (cursor >= pool.length) {
            break;
        }

        let anchorId = '';
        for (const blockId of section.blockIds ?? []) {
            const block = next.find((item) => item.id === blockId);
            if (hasBlockH2(block)) {
                anchorId = String(blockId || '').trim();
                break;
            }
        }
        if (!anchorId) {
            anchorId = String(section.blockIds?.[0] || '').trim();
        }
        if (!anchorId) {
            continue;
        }
        const anchorIndex = next.findIndex((block) => block.id === anchorId);
        if (anchorIndex < 0) {
            continue;
        }

        const media = pool[cursor++];
        const image = buildDistributedImage(media);
        const imageBlock = {
            ...createEmptyImageBlock(),
            image,
            content: renderImageFigure(image),
        };

        next.splice(anchorIndex + 1, 0, imageBlock);
        inserted += 1;
    }

    return {
        blocks: inserted > 0 ? normalizeBlocks(next) : blocks,
        inserted,
    };
};

export const buildGallerySupplementalRows = (supplementalImages, storageAlbum, articleId) => {
    const rows = [];
    const seen = new Set();

    const append = (row) => {
        const src = String(row?.src || '').trim();
        if (!src) {
            return;
        }

        const key = normalizeImageSrcKey(src);
        if (!key || seen.has(key)) {
            return;
        }

        seen.add(key);
        rows.push(row);
    };

    (Array.isArray(supplementalImages) ? supplementalImages : []).forEach((row) => {
        if (String(row?.origin || '').trim() !== 'gallery') {
            return;
        }

        append({
            src: String(row?.src || '').trim(),
            slug: String(row?.slug || '').trim(),
            alt: String(row?.alt || '').trim(),
            title: String(row?.title || row?.alt || '').trim(),
            caption: String(row?.caption || '').trim(),
            align: String(row?.align || 'none').trim(),
            wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0) || null,
            seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0) || null,
            wpSrc: String(row?.wpSrc || row?.wp_url || '').trim(),
            localSrc: String(row?.localSrc || row?.local_src || '').trim(),
            origin: 'gallery',
        });
    });

    const albumItems = Array.isArray(storageAlbum) && storageAlbum.length > 0
        ? storageAlbum
        : loadProductAlbum(articleId);

    albumItems.forEach((item) => {
        const src = String(item?.url || item?.src || '').trim();
        if (!src) {
            return;
        }

        const isLocal = src.includes('/storage/uploads/seo_media/');
        const itemId = Number(item?.id ?? 0) || null;
        const wpId = Number(item?.wp_attachment_id ?? item?.wpAttachmentId ?? 0) || null;
        const seoId = Number(item?.seo_media_id ?? item?.seoMediaId ?? 0) || null;
        append({
            src,
            slug: String(item?.slug || '').trim() || src.split('/').pop()?.replace(/\.\w+$/, '') || '',
            alt: String(item?.alt || '').trim(),
            title: String(item?.alt || '').trim(),
            caption: '',
            align: 'none',
            wpAttachmentId: isLocal ? null : (wpId || itemId),
            seoMediaId: isLocal ? (seoId || itemId) : seoId,
            wpSrc: isLocal ? '' : src,
            localSrc: isLocal ? src : '',
            origin: 'gallery',
        });
    });

    return rows;
};

export const resolveSupplementalImagesWithGallery = (
    supplementalImages,
    galleryItems,
    articleId,
    supportsProductGallery = false,
) => {
    const album = Array.isArray(galleryItems) ? galleryItems : [];

    if (supportsProductGallery) {
        const nonProductMedia = (Array.isArray(supplementalImages) ? supplementalImages : []).filter(
            (row) => {
                const origin = String(row?.origin ?? '').trim();

                return origin !== 'gallery' && origin !== 'featured';
            },
        );

        if (album.length === 0) {
            return nonProductMedia;
        }

        return [...nonProductMedia, ...buildGallerySupplementalRows([], album, articleId)];
    }

    const nonGallery = (Array.isArray(supplementalImages) ? supplementalImages : []).filter(
        (row) => String(row?.origin ?? '').trim() !== 'gallery',
    );
    const galleryRows = buildGallerySupplementalRows([], galleryItems, articleId);

    return [...nonGallery, ...galleryRows];
};

export const escapeRegExp = (value) => String(value ?? '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

export const replaceTextInHtmlContent = (html, findText, replaceText) => {
    const source = String(html ?? '');
    const needle = String(findText ?? '');
    if (!source || !needle) {
        return { html: source, replacements: 0 };
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const pattern = new RegExp(escapeRegExp(needle), 'g');
    const textNodes = [];
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    while (current) {
        textNodes.push(current);
        current = walker.nextNode();
    }

    let replacements = 0;
    for (const node of textNodes) {
        const original = String(node.nodeValue ?? '');
        if (!original) continue;

        const matches = original.match(pattern);
        if (!matches?.length) continue;

        replacements += matches.length;
        node.nodeValue = original.replace(pattern, () => String(replaceText ?? ''));
    }

    return {
        html: doc.body.innerHTML,
        replacements,
    };
};

export const parseVideoMediaFromHtml = (html) => {
    const source = String(html ?? '').trim();
    if (!source) {
        return null;
    }
    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const figure = doc.body.querySelector('figure.wp-block-video, figure');
    const video = doc.body.querySelector('video');
    if (!video) {
        return null;
    }
    const src = String(video.getAttribute('src') ?? '').trim();
    if (!src) {
        return null;
    }

    const className = String(figure?.getAttribute('class') ?? '');
    let align = 'none';
    if (className.includes('alignfull')) align = 'full';
    else if (className.includes('alignright')) align = 'right';
    else if (className.includes('aligncenter')) align = 'center';
    else if (className.includes('alignleft')) align = 'left';

    const wpAttachmentId = Number(figure?.getAttribute('data-id') ?? video.getAttribute('data-id') ?? 0);
    const seoMediaId = Number(
        figure?.getAttribute('data-seo-media-id') ?? video.getAttribute('data-seo-media-id') ?? 0,
    );
    const slug = slugFromUrl(src);

    return {
        src,
        alt: '',
        title: '',
        slug: slug || undefined,
        align,
        mediaType: 'video',
        wpAttachmentId: wpAttachmentId > 0 ? wpAttachmentId : undefined,
        seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
        wpSrc: src,
    };
};

export const parseHtmlToBlocks = (html) => {
    if (!html) return [];
    const blocks = [];
    const wpRegex =
        /(<!--\s*wp:[a-zA-Z0-9\-\/]+\s*(?:\{.*?\})?\s*-->)(.*?)(<!--\s*\/wp:[a-zA-Z0-9\-\/]+\s*-->)/gs;
    let lastIndex = 0;
    let match;

    const splitClassic = (htmlContent) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlContent, 'text/html');
        const chunks = [];

        flattenHtmlBodyNodes(doc.body).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent.trim()) return;

            if (node.nodeType === 1 && isWordPressImageElement(node)) {
                const tempDiv = document.createElement('div');
                tempDiv.appendChild(node.cloneNode(true));
                const content = tempDiv.innerHTML.trim();
                const image = parseImageFromBlockContent(content) ?? parseVideoMediaFromHtml(content);
                chunks.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: false,
                    prefix: '',
                    content: image && image.mediaType !== 'video' ? renderImageFigure(image) : content,
                    suffix: '',
                    image: image ?? undefined,
                });
                return;
            }

            const tempDiv = document.createElement('div');
            tempDiv.appendChild(node.cloneNode(true));
            const content = cleanBlockHtmlForEditorDisplay(tempDiv.innerHTML.trim());
            if (!isMeaningfulHtml(content)) {
                return;
            }

            chunks.push({
                id: newBlockId('classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content,
                suffix: '',
            });
        });

        return chunks;
    };

    while ((match = wpRegex.exec(html)) !== null) {
        if (match.index > lastIndex) {
            const textBefore = html.substring(lastIndex, match.index);
            if (textBefore.trim()) blocks.push(...splitClassic(textBefore));
        }

        const wpOpen = String(match[1] || '');
        const wpInner = String(match[2] || '').trim();
        const isWpImageBlock = /<!--\s*wp:image\b/i.test(wpOpen);
        if (isWpImageBlock) {
            const image = parseImageFromBlockContent(wpInner) ?? parseVideoMediaFromHtml(wpInner);
            if (image && image.mediaType !== 'video' && image.src) {
                blocks.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: true,
                    prefix: '',
                    content: renderImageFigure(image),
                    suffix: '',
                    image,
                });
            } else if (wpInner) {
                blocks.push(...splitClassic(wpInner));
            }
        } else {
            blocks.push({
                id: newBlockId('wp'),
                isWp: true,
                type: 'text',
                prefix: wpOpen,
                content: wpInner,
                suffix: match[3],
            });
        }
        lastIndex = wpRegex.lastIndex;
    }

    if (lastIndex < html.length) {
        const textAfter = html.substring(lastIndex);
        if (textAfter.trim()) blocks.push(...splitClassic(textAfter));
    }

    return hoistInlineImagesFromTextBlocks(regroupParsedBlocksByH2(normalizeBlocks(blocks)));
};

export const hoistInlineImagesFromTextBlocks = (blocks) => {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return blocks;
    }

    const result = [];

    blocks.forEach((block) => {
        if (!block || block.type === 'image' || typeof block.content !== 'string') {
            result.push(block);

            return;
        }

        if (!/<img[\s>]/i.test(block.content)) {
            result.push(block);

            return;
        }

        const chunks = splitHtmlIntoTextAndImageChunks(block.content);
        if (chunks.length <= 1 && chunks[0]?.type === 'text') {
            result.push(block);

            return;
        }

        chunks.forEach((chunk) => {
            if (chunk.type === 'image' && chunk.image?.src) {
                result.push({
                    id: newBlockId('image'),
                    type: 'image',
                    isWp: Boolean(block.isWp),
                    prefix: '',
                    content: chunk.html || renderImageFigure(chunk.image),
                    suffix: '',
                    image: chunk.image,
                });

                return;
            }

            const html = String(chunk.html || '').trim();
            if (!html || !isMeaningfulHtml(html)) {
                return;
            }

            result.push({
                id: newBlockId(block.isWp ? 'wp' : 'classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content: cleanBlockHtmlForEditorDisplay(html),
                suffix: '',
            });
        });
    });

    return normalizeBlocks(result);
};

export const splitHtmlAtH2Sections = (htmlContent) => {
    const source = String(htmlContent || '').trim();
    if (!source) {
        return [];
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    if (doc.body.querySelectorAll('h2').length <= 1) {
        return [source];
    }

    const sections = [];
    let current = document.createElement('div');

    const flushCurrent = () => {
        const html = cleanBlockHtmlForEditorDisplay(current.innerHTML.trim());
        if (isMeaningfulHtml(html)) {
            sections.push(html);
        }
        current = document.createElement('div');
    };

    const walkNodes = (parent) => {
        Array.from(parent.childNodes).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) {
                return;
            }

            if (node.nodeType === 1 && node.tagName === 'H2') {
                flushCurrent();
                current.appendChild(node.cloneNode(true));
                return;
            }

            if (node.nodeType === 1 && typeof node.querySelector === 'function' && node.querySelector('h2')) {
                walkNodes(node);
                return;
            }

            current.appendChild(node.cloneNode(true));
        });
    };

    walkNodes(doc.body);
    flushCurrent();

    return sections.length > 0 ? sections : [source];
};

export const regroupParsedBlocksByH2 = (blocks) => {
    const result = [];

    blocks.forEach((block) => {
        if (block.type !== 'text' || block.isWp || typeof block.content !== 'string' || !block.content.trim()) {
            result.push(block);
            return;
        }

        const parts = splitHtmlAtH2Sections(block.content);
        if (parts.length <= 1) {
            result.push(block);
            return;
        }

        parts.forEach((part) => {
            result.push({
                id: newBlockId('classic'),
                type: 'text',
                isWp: false,
                prefix: '',
                content: part,
                suffix: '',
            });
        });
    });

    return normalizeBlocks(result);
};

export const hasMeaningfulExportHtml = (html) => {
    const source = String(html ?? '').trim();
    if (!source) return false;
    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const text = (doc.body.textContent || '').replace(/\u00a0/g, ' ').trim();
    if (text) return true;
    return Boolean(
        doc.body.querySelector(
            'img,video,iframe,table,ul,ol,li,blockquote,pre,code,h1,h2,h3,h4,h5,h6,hr',
        ),
    );
};

export const exportBlocksToHtml = (blocks) =>
    blocks
        .map((b) => {
            let part;
            if (b.prefix || b.suffix) {
                part = [b.prefix, b.content, b.suffix].filter(Boolean).join('\n');
            } else {
                part = b.content;
            }
            if (typeof part !== 'string') {
                return part;
            }
            const cleaned = stripEditorTransientMarkup(part);
            return hasMeaningfulExportHtml(cleaned) ? cleaned : '';
        })
        .filter(Boolean)
        .join('\n\n');

export const getBlocksInRange = (blocks, fromId, toId) => {
    const fromIdx = blocks.findIndex((b) => b.id === fromId);
    const toIdx = blocks.findIndex((b) => b.id === toId);
    if (fromIdx === -1 || toIdx === -1) return [];

    const start = Math.min(fromIdx, toIdx);
    const end = Math.max(fromIdx, toIdx);

    return blocks.slice(start, end + 1);
};

/** Gộp HTML nhiều block — chỉ hiển thị tạm trong editor, không ghi vào state block. */
export const mergeBlockHtmlContents = (rangeBlocks) => {
    const container = document.createElement('div');
    const parser = new DOMParser();

    rangeBlocks.forEach((block) => {
        const raw = block.content?.trim();
        if (!raw) return;

        const doc = parser.parseFromString(raw, 'text/html');
        flattenHtmlBodyNodes(doc.body).forEach((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) return;
            container.appendChild(node.cloneNode(true));
        });
    });

    return container.innerHTML.trim();
};

/** Plain text từ nhiều block — ngữ cảnh AI. */
export const getPlainTextFromBlocks = (rangeBlocks) => {
    const parser = new DOMParser();

    return rangeBlocks
        .map((block) => {
            const raw = block.content?.trim();
            if (!raw) return '';
            const doc = parser.parseFromString(raw, 'text/html');
            return (doc.body.textContent || '').trim();
        })
        .filter(Boolean)
        .join('\n\n');
};

/** Plain text từ heading trong block tới heading cùng/cao hơn level kế tiếp — ngữ cảnh AI theo outline. */
export const extractHeadingScopedPlainText = (html, level, headingText) => {
    const raw = String(html ?? '').trim();
    const target = normalizeOutlineHeadingText(headingText);
    if (raw === '' || target === '') {
        return '';
    }

    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';
    const headings = Array.from(doc.body.querySelectorAll(selector));
    const startIdx = headings.findIndex(
        (node) => normalizeOutlineHeadingText(node.textContent) === target,
    );
    if (startIdx < 0) {
        return '';
    }

    const startHeading = headings[startIdx];
    const startLevel = Number.parseInt(startHeading.tagName.charAt(1), 10);
    const parts = [normalizeOutlineHeadingText(startHeading.textContent)];

    let el = startHeading.nextElementSibling;
    while (el) {
        if (/^H[234]$/i.test(el.tagName)) {
            const nextLevel = Number.parseInt(el.tagName.charAt(1), 10);
            if (nextLevel <= startLevel) {
                break;
            }
        }

        const text = String(el.textContent ?? '')
            .replace(/\s+/g, ' ')
            .trim();
        if (text !== '') {
            parts.push(text);
        }

        el = el.nextElementSibling;
    }

    return parts.filter(Boolean).join('\n');
};

export function getActiveBlockContextText(blocks, activeBlockId, tempMerge) {
    if (!activeBlockId) return '';

    if (tempMerge?.rangeIds?.length) {
        const rangeBlocks = blocks.filter((b) => tempMerge.rangeIds.includes(b.id));
        return getPlainTextFromBlocks(rangeBlocks);
    }

    const block = blocks.find((b) => b.id === activeBlockId);
    if (!block?.content) return '';

    if (block.type === 'image') {
        const caption = block.image?.caption ?? '';
        const alt = block.image?.alt ?? '';
        return [alt, caption].filter(Boolean).join(' — ');
    }

    return htmlToPlainText(block.content);
}

export function getHtmlFromBlocks(blocks, activeBlockId, tempMerge) {
    if (!activeBlockId) {
        return '';
    }

    if (tempMerge?.rangeIds?.length) {
        const rangeBlocks = blocks.filter((b) => tempMerge.rangeIds.includes(b.id));

        return mergeBlockHtmlContents(rangeBlocks);
    }

    const block = blocks.find((b) => b.id === activeBlockId);
    if (!block) {
        return '';
    }

    return block.content?.trim() ?? '';
}

export function dispatchActiveBlockContext(articleId, text, html, open, activeBlockId) {
    const trimmedText = text.trim();
    const trimmedHtml = html.trim();

    window.dispatchEvent(
        new CustomEvent('seo-editor-text-selection', {
            detail: {
                hasSelection: open && Boolean(trimmedText),
                text: trimmedText,
                html: trimmedHtml,
                articleId,
                activeBlockId: open ? (activeBlockId ?? '') : '',
            },
        }),
    );
}

export function isSameTiptapBlockContent(sourceHtml, currentHtml, nextHtml) {
    return (
        persistBlockHtmlFromEditor(sourceHtml, currentHtml) ===
        persistBlockHtmlFromEditor(sourceHtml, nextHtml)
    );
}


