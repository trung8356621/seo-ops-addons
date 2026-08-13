import { detectWordPressImageSize } from '@wordpress-addon/utils/wordpressImageSize.js';
import { isLocalSeoMediaSrc, resolveFullWordPressImageUrl } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { isAiPlaceholderLoadingSrc } from './seoMediaApi.js';

export { isAiPlaceholderLoadingSrc };

const ALIGN_CLASSES = {
    none: '',
    left: 'alignleft',
    center: 'aligncenter',
    right: 'alignright',
    full: 'alignfull',
};

/** Mặc định khi chèn ảnh mới (media picker, paste, AI, import URL, …). */
export const DEFAULT_IMAGE_INSERT_ALIGN = 'center';

export function withDefaultImageInsertAlign(image = {}) {
    const align = image.align;
    if (align === undefined || align === null || align === '') {
        return { ...image, align: DEFAULT_IMAGE_INSERT_ALIGN };
    }

    return image;
}

export const IMAGE_ALIGN_OPTIONS = [
    { id: 'none', labelKey: 'image_align_default' },
    { id: 'left', labelKey: 'toolbar_align_left' },
    { id: 'center', labelKey: 'toolbar_align_center' },
    { id: 'right', labelKey: 'toolbar_align_right' },
    { id: 'full', labelKey: 'image_align_full_width' },
];

function alignFromElement(el) {
    if (!el) return 'none';
    const cls = typeof el.className === 'string' ? el.className : '';
    if (cls.includes('alignfull')) return 'full';
    if (cls.includes('alignright')) return 'right';
    if (cls.includes('aligncenter')) return 'center';
    if (cls.includes('alignleft')) return 'left';
    return 'none';
}

function figureClassForAlign(align) {
    return ALIGN_CLASSES[align] || '';
}

function parseWpAttachmentIdFromImg(img) {
    if (!img) return null;
    const cls = img.getAttribute('class') ?? '';
    const m = cls.match(/\bwp-image-(\d+)\b/);
    if (m) return Number(m[1]);
    const dataId = Number(img.getAttribute('data-id'));
    return dataId > 0 ? dataId : null;
}

function parseSeoMediaIdFromImg(img) {
    if (!img) return null;
    const id = Number(img.getAttribute('data-seo-media-id'));
    return id > 0 ? id : null;
}

function parseWpAttachmentIdFromVideo(el) {
    if (!el) return null;
    const id = Number(el.getAttribute('data-id'));
    return id > 0 ? id : null;
}

function parseSeoMediaIdFromVideo(el) {
    if (!el) return null;
    const id = Number(el.getAttribute('data-seo-media-id'));
    return id > 0 ? id : null;
}

function slugFromSrc(src) {
    if (!src) return '';
    try {
        const path = new URL(src, window.location.origin).pathname;
        const base = path.split('/').pop() || '';
        const dot = base.lastIndexOf('.');
        return dot > 0 ? base.slice(0, dot) : base;
    } catch {
        return '';
    }
}

function parseImageFromFigure(fig, id) {
    const img = fig.querySelector('img');
    if (!img?.getAttribute('src')) return null;

    const widthAttr = img.getAttribute('width');
    const heightAttr = img.getAttribute('height');
    const src = img.getAttribute('src');

    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: isLocal ? 'full' : detectWordPressImageSize(src ?? ''),
        slug: slugFromSrc(src),
        alt: img.getAttribute('alt') ?? '',
        title: img.getAttribute('title') ?? '',
        caption: fig.querySelector('figcaption')?.textContent?.trim() ?? '',
        align: alignFromElement(fig) || alignFromElement(img),
        width: widthAttr ? Number(widthAttr) : null,
        height: heightAttr ? Number(heightAttr) : null,
        wpImageClass: img.getAttribute('class') ?? '',
        wpAttachmentId: parseWpAttachmentIdFromImg(img),
        seoMediaId: parseSeoMediaIdFromImg(img),
        isProcessing: fig.hasAttribute('data-ai-processing'),
        excludeQuickFix:
            fig.hasAttribute('data-exclude-quick-fix') || img.hasAttribute('data-exclude-quick-fix'),
    };
}

function parseImageFromImg(img, id) {
    if (!img.getAttribute('src')) return null;

    const parent = img.closest('figure');
    const widthAttr = img.getAttribute('width');
    const heightAttr = img.getAttribute('height');

    const src = img.getAttribute('src');

    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: isLocal ? 'full' : detectWordPressImageSize(src ?? ''),
        slug: slugFromSrc(src),
        alt: img.getAttribute('alt') ?? '',
        title: img.getAttribute('title') ?? '',
        caption: parent?.querySelector('figcaption')?.textContent?.trim() ?? '',
        align: parent ? alignFromElement(parent) : alignFromElement(img),
        width: widthAttr ? Number(widthAttr) : null,
        height: heightAttr ? Number(heightAttr) : null,
        wpImageClass: img.getAttribute('class') ?? '',
        wpAttachmentId: parseWpAttachmentIdFromImg(img),
        seoMediaId: parseSeoMediaIdFromImg(img),
        isProcessing:
            img.hasAttribute('data-ai-processing') ||
            Boolean(img.closest('[data-ai-processing]')),
        excludeQuickFix:
            img.hasAttribute('data-exclude-quick-fix') ||
            Boolean(img.closest('[data-exclude-quick-fix]')),
    };
}

function parseVideoFromFigure(fig, id) {
    const video = fig.querySelector('video');
    const src = video?.getAttribute('src');
    if (!src) return null;

    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: 'full',
        slug: slugFromSrc(src),
        alt: '',
        title: '',
        caption: '',
        align: alignFromElement(fig) || alignFromElement(video),
        width: null,
        height: null,
        wpImageClass: '',
        mediaType: 'video',
        wpAttachmentId: parseWpAttachmentIdFromVideo(fig) ?? parseWpAttachmentIdFromVideo(video),
        seoMediaId: parseSeoMediaIdFromVideo(fig) ?? parseSeoMediaIdFromVideo(video),
        isProcessing: false,
    };
}

function parseVideoFromTag(video, id) {
    const src = video?.getAttribute('src');
    if (!src) return null;
    const parent = video.closest('figure');
    const isLocal = isLocalSeoMediaSrc(src ?? '');

    return {
        id,
        src,
        wpSrc: isLocal ? '' : resolveFullWordPressImageUrl(src ?? ''),
        size: 'full',
        slug: slugFromSrc(src),
        alt: '',
        title: '',
        caption: '',
        align: parent ? alignFromElement(parent) : alignFromElement(video),
        width: null,
        height: null,
        wpImageClass: '',
        mediaType: 'video',
        wpAttachmentId: parseWpAttachmentIdFromVideo(parent) ?? parseWpAttachmentIdFromVideo(video),
        seoMediaId: parseSeoMediaIdFromVideo(parent) ?? parseSeoMediaIdFromVideo(video),
        isProcessing: false,
    };
}

export function isWordPressImageElement(el) {
    if (!el || el.nodeType !== 1) return false;
    const tag = el.tagName.toLowerCase();

    if (tag === 'img' || tag === 'video') return true;
    if (tag === 'figure' && (el.querySelector('img') || el.querySelector('video'))) return true;
    if (el.classList.contains('wp-block-image') || el.classList.contains('wp-block-video')) return true;
    if (el.classList.contains('wp-caption') && el.querySelector('img')) return true;

    // <p><img></p> / wrapper chỉ chứa media — TipTap block-image sẽ nuốt img trong paragraph.
    if ((tag === 'p' || tag === 'div' || tag === 'span') && el.querySelector('img, video')) {
        const clone = el.cloneNode(true);
        clone.querySelectorAll('img, video, br, figure, picture, source').forEach((node) => node.remove());
        const text = (clone.textContent || '').replace(/\u00a0/g, ' ').trim();

        return text === '';
    }

    return false;
}

/**
 * Tách HTML text thành các chunk text / image để hydrate thành image block riêng.
 *
 * @returns {Array<{ type: 'text', html: string } | { type: 'image', html: string, image: object }>}
 */
export function splitHtmlIntoTextAndImageChunks(html) {
    const source = String(html || '').trim();
    if (!source) {
        return [];
    }

    if (!/<img[\s>]|<video[\s>]|<figure\b/i.test(source)) {
        return [{ type: 'text', html: source }];
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const chunks = [];
    let textBuffer = doc.createElement('div');

    const flushText = () => {
        const htmlChunk = textBuffer.innerHTML.trim();
        if (htmlChunk) {
            chunks.push({ type: 'text', html: htmlChunk });
        }
        textBuffer = doc.createElement('div');
    };

    const pushImageNode = (node) => {
        const wrap = doc.createElement('div');
        wrap.appendChild(node.cloneNode(true));
        const content = wrap.innerHTML.trim();
        const image = parseImageFromBlockContent(content);
        if (!image?.src) {
            textBuffer.appendChild(node.cloneNode(true));

            return;
        }

        flushText();
        chunks.push({
            type: 'image',
            html: renderImageFigure(image),
            image,
        });
    };

    const walk = (parent) => {
        Array.from(parent.childNodes).forEach((node) => {
            if (node.nodeType === 3) {
                if (node.textContent?.trim()) {
                    textBuffer.appendChild(node.cloneNode(true));
                }

                return;
            }

            if (node.nodeType !== 1) {
                return;
            }

            if (isWordPressImageElement(node)) {
                pushImageNode(node);

                return;
            }

            const tag = node.tagName.toLowerCase();
            const unwrapWrapper =
                (tag === 'div' || tag === 'section' || tag === 'article') &&
                node.querySelector('img, video') &&
                !node.classList.contains('wp-block-image') &&
                !node.classList.contains('wp-caption');

            if (unwrapWrapper) {
                walk(node);

                return;
            }

            if (typeof node.querySelector === 'function' && node.querySelector('img, video')) {
                const working = node.cloneNode(true);
                const mediaHosts = [];
                working.querySelectorAll('img, video').forEach((media) => {
                    const host =
                        media.closest('figure, .wp-block-image, .wp-caption, p') || media;
                    if (!mediaHosts.includes(host)) {
                        mediaHosts.push(host);
                    }
                });

                mediaHosts.forEach((host) => {
                    pushImageNode(host);
                    host.remove();
                });

                const remainderText = (working.textContent || '').replace(/\u00a0/g, ' ').trim();
                const hasStructure = Boolean(working.querySelector('ul,ol,table,a,h1,h2,h3,h4,h5,h6,p,li'));
                if (remainderText !== '' || hasStructure) {
                    const remainderWrap = doc.createElement(tag);
                    for (const attr of Array.from(node.attributes ?? [])) {
                        remainderWrap.setAttribute(attr.name, attr.value);
                    }
                    remainderWrap.innerHTML = working.innerHTML;
                    textBuffer.appendChild(remainderWrap);
                }

                return;
            }

            textBuffer.appendChild(node.cloneNode(true));
        });
    };

    walk(doc.body);
    flushText();

    return chunks.length > 0 ? chunks : [{ type: 'text', html: source }];
}

/**
 * Trích xuất danh sách ảnh từ HTML (figure.wp-caption, .wp-block-image, img…).
 */
export function extractImagesFromHtml(html) {
    if (!html?.trim()) return [];

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const images = [];
    const consumed = new Set();
    let index = 0;

    const registerFigure = (fig) => {
        if (!fig || consumed.has(fig)) return;
        const id = `img_${index++}`;
        const data = parseImageFromFigure(fig, id);
        if (!data) return;
        images.push(data);
        consumed.add(fig);
        fig.querySelectorAll('img').forEach((img) => consumed.add(img));
    };

    doc.body.querySelectorAll('.wp-block-image').forEach((wrap) => {
        const fig = wrap.querySelector('figure');
        if (fig) {
            registerFigure(fig);
            return;
        }
        const img = wrap.querySelector('img');
        if (img && !consumed.has(img)) {
            const id = `img_${index++}`;
            const data = parseImageFromImg(img, id);
            if (data) {
                images.push({ ...data, align: alignFromElement(wrap) || data.align });
                consumed.add(img);
            }
        }
    });

    doc.body.querySelectorAll('figure').forEach((fig) => {
        if (fig.querySelector('img')) registerFigure(fig);
        if (fig.querySelector('video') && !consumed.has(fig)) {
            const id = `img_${index++}`;
            const data = parseVideoFromFigure(fig, id);
            if (data) {
                images.push(data);
                consumed.add(fig);
                fig.querySelectorAll('video').forEach((video) => consumed.add(video));
            }
        }
    });

    doc.body.querySelectorAll('img').forEach((img) => {
        if (consumed.has(img)) return;
        const id = `img_${index++}`;
        const data = parseImageFromImg(img, id);
        if (data) images.push(data);
    });

    doc.body.querySelectorAll('video').forEach((video) => {
        if (consumed.has(video)) return;
        const id = `img_${index++}`;
        const data = parseVideoFromTag(video, id);
        if (data) images.push(data);
    });

    return images;
}

/**
 * HTML chỉ còn phần chữ sau khi tách ảnh.
 */
export function stripImagesFromHtml(html) {
    if (!html?.trim()) return '';

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    Array.from(doc.body.childNodes).forEach((node) => {
        if (node.nodeType === 1 && isWordPressImageElement(node)) {
            node.remove();
        }
    });

    doc.body.querySelectorAll('figure, img, .wp-block-image').forEach((el) => el.remove());

    return doc.body.innerHTML.trim();
}

/** @deprecated Ảnh đã tách thành block riêng — giữ cho tương thích. */
export function splitBlockHtml(html) {
    const images = extractImagesFromHtml(html);
    return {
        textHtml: stripImagesFromHtml(html),
        images,
    };
}

export function parseImageFromBlockContent(html) {
    const images = extractImagesFromHtml(html);
    if (images.length === 1) return images[0];

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    const fig = doc.body.querySelector('figure');
    if (fig) {
        if (fig.querySelector('video')) {
            return parseVideoFromFigure(fig, `img_${Date.now()}`);
        }
        return parseImageFromFigure(fig, `img_${Date.now()}`);
    }
    const img = doc.body.querySelector('img');
    if (img) {
        return parseImageFromImg(img, `img_${Date.now()}`);
    }
    const video = doc.body.querySelector('video');
    if (video) {
        return parseVideoFromTag(video, `img_${Date.now()}`);
    }

    return null;
}

export function renderImageFigure(image) {
    image = withDefaultImageInsertAlign(image);

    if (String(image?.mediaType ?? '').toLowerCase() === 'video') {
        const alignClass = figureClassForAlign(image.align);
        const figureClass = ['wp-block-video', alignClass].filter(Boolean).join(' ');
        const attrs = [
            image.wpAttachmentId ? ` data-id="${Math.round(image.wpAttachmentId)}"` : '',
            image.seoMediaId ? ` data-seo-media-id="${Math.round(image.seoMediaId)}"` : '',
        ]
            .filter(Boolean)
            .join('');
        return `<figure class="${figureClass}"${attrs}><video controls src="${escapeAttr(image.src)}"></video></figure>`;
    }

    const alignClass = figureClassForAlign(image.align);
    const isProcessing = Boolean(
        image.isProcessing || isAiPlaceholderLoadingSrc(image.src),
    );
    const captionText = String(image.caption ?? '').trim();
    const hasCaption = captionText !== '';
    const figureClasses = ['wp-caption', alignClass, isProcessing ? 'seo-ai-media-loading' : '']
        .filter(Boolean)
        .join(' ');
    const style =
        image.width && !Number.isNaN(image.width)
            ? ` style="width: ${Math.round(image.width)}px"`
            : '';

    let imgClass = image.wpImageClass ?? '';
    if (!hasCaption && alignClass && !new RegExp(`\\b${alignClass}\\b`).test(imgClass)) {
        imgClass = imgClass ? `${imgClass} ${alignClass}` : alignClass;
    }
    if (image.wpAttachmentId && !/\bwp-image-\d+\b/.test(imgClass)) {
        const wpClass = `wp-image-${image.wpAttachmentId}`;
        imgClass = imgClass ? `${imgClass} ${wpClass}` : wpClass;
    }

    const imgAttrs = [
        `src="${escapeAttr(image.src)}"`,
        `alt="${escapeAttr(image.alt)}"`,
        image.title ? `title="${escapeAttr(image.title)}"` : '',
        image.width ? `width="${Math.round(image.width)}"` : '',
        image.height ? `height="${Math.round(image.height)}"` : '',
        imgClass ? `class="${escapeAttr(imgClass)}"` : '',
        image.wpAttachmentId ? `data-id="${Math.round(image.wpAttachmentId)}"` : '',
        image.seoMediaId ? `data-seo-media-id="${Math.round(image.seoMediaId)}"` : '',
        isProcessing ? 'data-ai-processing="1"' : '',
        image.excludeQuickFix ? 'data-exclude-quick-fix="1"' : '',
        'draggable="false"',
    ]
        .filter(Boolean)
        .join(' ');

    const caption = hasCaption
        ? `<figcaption class="wp-caption-text">${escapeHtml(captionText)}</figcaption>`
        : '';
    const processingLabel = isProcessing
        ? '<p class="seo-ai-media-loading__label">AI đang tạo ảnh…</p>'
        : '';

    if (!hasCaption && !isProcessing) {
        return `<img ${imgAttrs} />`;
    }

    return `<figure class="${figureClasses}" data-node="article-image"${
        isProcessing ? ' data-ai-processing="1"' : ''
    }${image.excludeQuickFix ? ' data-exclude-quick-fix="1"' : ''}${style}><img ${imgAttrs} />${processingLabel}${caption}</figure>`;
}

function escapeAttr(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Ghép lại HTML block text (chỉ marker — ảnh đã là block riêng).
 */
export function mergeBlockHtml(textHtml, images) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(textHtml || '<p></p>', 'text/html');
    const imageMap = Object.fromEntries((images ?? []).map((img) => [img.id, img]));

    doc.body.querySelectorAll('[data-seo-image-marker]').forEach((marker) => {
        marker.remove();
        const id = marker.getAttribute('data-seo-image-marker');
        if (imageMap[id]) {
            const wrap = doc.createElement('div');
            wrap.innerHTML = renderImageFigure(imageMap[id]);
            marker.replaceWith(wrap.firstElementChild);
        }
    });

    const parts = Array.from(doc.body.childNodes)
        .map((node) => {
            if (node.nodeType === 3 && !node.textContent?.trim()) return '';
            const temp = doc.createElement('div');
            temp.appendChild(node.cloneNode(true));
            return temp.innerHTML.trim();
        })
        .filter(Boolean);

    return parts.join('\n\n');
}

export function htmlToPlainText(html) {
    if (!html?.trim()) return '';
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

export function isMeaningfulHtml(html) {
    const source = String(html ?? '').trim();
    if (!source) return false;

    const parser = new DOMParser();
    const doc = parser.parseFromString(source, 'text/html');
    const text = (doc.body.textContent || '').replace(/\u00a0/g, ' ').trim();
    if (text) return true;

    // Keep structural content that is not plain text.
    return Boolean(
        doc.body.querySelector(
            'img,video,iframe,table,ul,ol,li,blockquote,pre,code,h1,h2,h3,h4,h5,h6,hr',
        ),
    );
}

/**
 * Tách HTML Featured Snippet thành các block text trong section hiện tại (không H2 section mới).
 *
 * @param {string} html
 * @param {() => object} createTextBlock
 * @returns {object[]}
 */
export function prepareFeaturedSnippetBlocksForInsert(html, createTextBlock) {
    const source = String(html ?? '').trim();
    if (!source) {
        return [];
    }

    const doc = new DOMParser().parseFromString(source, 'text/html');
    const blocks = [];

    const pushBlock = (content) => {
        const trimmed = String(content ?? '').trim();
        if (!trimmed || !isMeaningfulHtml(trimmed)) {
            return;
        }

        blocks.push({
            ...createTextBlock(),
            content: trimmed,
        });
    };

    const downgradeHeading = (headingEl) => {
        const p = doc.createElement('p');
        const strong = doc.createElement('strong');
        strong.textContent = (headingEl.textContent || '').replace(/\s+/g, ' ').trim();
        p.appendChild(strong);

        return p.outerHTML;
    };

    for (const node of Array.from(doc.body.childNodes)) {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
            if (text) {
                pushBlock(`<p>${text}</p>`);
            }

            continue;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            continue;
        }

        const tag = node.tagName.toLowerCase();
        if (/^h[1-6]$/.test(tag)) {
            pushBlock(downgradeHeading(node));
        } else {
            pushBlock(node.outerHTML);
        }
    }

    if (blocks.length === 0) {
        pushBlock(source);
    }

    return blocks;
}

function escapeHtmlText(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Tách HTML Featured Snippet thành block H2 (section mới) + các block nội dung.
 *
 * @param {string} html
 * @param {() => object} createTextBlock
 * @param {string} [fallbackTitle]
 * @returns {{ headingBlock: object|null, contentBlocks: object[] }}
 */
export function parseFeaturedSnippetNewSectionBlocks(html, createTextBlock, fallbackTitle = '') {
    const source = String(html ?? '').trim();
    if (!source) {
        return { headingBlock: null, contentBlocks: [] };
    }

    const doc = new DOMParser().parseFromString(source, 'text/html');
    const elements = Array.from(doc.body.childNodes).filter((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            return Boolean((node.textContent || '').trim());
        }

        return node.nodeType === Node.ELEMENT_NODE;
    });

    let h2Index = -1;
    elements.forEach((node, index) => {
        if (h2Index >= 0 || node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        if (node.tagName.toLowerCase() === 'h2') {
            h2Index = index;
        }
    });

    const h2Node = h2Index >= 0 ? elements[h2Index] : null;
    const headingText =
        (h2Node?.textContent || '').replace(/\s+/g, ' ').trim() ||
        String(fallbackTitle || '').trim() ||
        'Featured Snippet';

    const headingBlock = {
        ...createTextBlock(),
        content: `<h2>${escapeHtmlText(headingText)}</h2><p></p>`,
    };

    const restHtml = elements
        .filter((_, index) => index !== h2Index)
        .map((node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                const text = (node.textContent || '').replace(/\s+/g, ' ').trim();

                return text ? `<p>${escapeHtmlText(text)}</p>` : '';
            }

            return node.outerHTML || '';
        })
        .filter(Boolean)
        .join('');

    const contentBlocks = restHtml ? prepareFeaturedSnippetBlocksForInsert(restHtml, createTextBlock) : [];

    return { headingBlock, contentBlocks };
}

const newBlockId = (prefix) => `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;

/**
 * Tách ảnh nhúng trong block text thành block ảnh riêng (WordPress-style).
 */
import { cleanBlockHtmlForEditorDisplay } from '@content-addon/utils/editorHtmlUtils.js';

export function pruneEmptyTextBlocks(blocks) {
    if (!Array.isArray(blocks)) {
        return [];
    }

    return blocks.filter((block) => {
        if (!block || block.type === 'image' || block.isWp) {
            return Boolean(block);
        }

        const content =
            typeof block.content === 'string'
                ? cleanBlockHtmlForEditorDisplay(block.content)
                : '';

        return isMeaningfulHtml(content);
    });
}

export function normalizeBlocks(blocks) {
    const result = [];

    blocks.forEach((block) => {
        if (block.type === 'image' || block.isWp) {
            if (block.type === 'image' && !block.image) {
                const image = parseImageFromBlockContent(block.content);
                result.push({
                    ...block,
                    type: 'image',
                    image: image ?? undefined,
                    content: image ? renderImageFigure(image) : block.content,
                });
                return;
            }
            result.push(block);
            return;
        }

        const images = extractImagesFromHtml(block.content);
        const textHtml = cleanBlockHtmlForEditorDisplay(stripImagesFromHtml(block.content));

        if (isMeaningfulHtml(textHtml)) {
            result.push({
                ...block,
                type: 'text',
                content: textHtml,
            });
        }

        images.forEach((image) => {
            result.push({
                id: newBlockId('image'),
                type: 'image',
                isWp: false,
                prefix: '',
                suffix: '',
                content: renderImageFigure(image),
                image,
            });
        });

    });

    return pruneEmptyTextBlocks(result);
}
