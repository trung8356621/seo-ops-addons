import { stripEditorTransientMarkup } from './articleEditorTransientMarkup';
import { normalizeInlineLinks } from './inlineLinkNormalizer';

const HEADING_TAG_RE = /^h([1-6])$/i;
const BLOCK_WRAPPER_TAGS = new Set(['p', 'div']);
const CLASSIC_BLOCK_TAGS = new Set([
    'address',
    'aside',
    'blockquote',
    'details',
    'dl',
    'fieldset',
    'figcaption',
    'figure',
    'footer',
    'form',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'header',
    'hr',
    'img',
    'main',
    'nav',
    'ol',
    'p',
    'pre',
    'table',
    'ul',
    'video',
]);

export const FAQ_SHORTCODE_PLACEHOLDER = '[omi_faq]';

export const FAQ_SHORTCODE_HTML = `<p class="omi-faq-placeholder" data-omi-faq="1">${FAQ_SHORTCODE_PLACEHOLDER}</p>`;

export function isFaqPlaceholderHtml(html) {
    return /omi-faq-placeholder|\[omi_faq\]/i.test(html || '');
}

function isEmptyBlockNode(node) {
    if (!node) {
        return true;
    }

    if (node.nodeType === 3) {
        return !(node.textContent || '').replace(/\u00a0/g, ' ').trim();
    }

    if (node.nodeType !== 1) {
        return true;
    }

    const tag = node.tagName.toLowerCase();
    if (tag === 'br') {
        return true;
    }

    if (tag !== 'p' && tag !== 'div') {
        return false;
    }

    const inner = (node.innerHTML || '')
        .replace(/<br\s*\/?>/gi, '')
        .replace(/&nbsp;/gi, ' ')
        .trim();
    const text = (node.textContent || '').replace(/\u00a0/g, ' ').trim();

    return !text && !inner.replace(/<[^>]+>/gi, '').trim();
}

function meaningfulBodyNodes(html) {
    const doc = new DOMParser().parseFromString((html || '').trim(), 'text/html');

    return Array.from(doc.body.childNodes).filter((node) => !isEmptyBlockNode(node));
}

/**
 * Một block chỉ gồm đúng một thẻ heading (thường gặp khi tách block từ WP).
 *
 * @returns {number|null} level 1–6 hoặc null
 */
export function standaloneHeadingLevel(html) {
    const trimmed = (html || '').trim();
    if (!trimmed) return null;

    const nodes = meaningfulBodyNodes(trimmed);

    if (nodes.length !== 1 || nodes[0].nodeType !== 1) return null;

    const tag = nodes[0].tagName.toLowerCase();
    const match = tag.match(HEADING_TAG_RE);
    return match ? Number(match[1]) : null;
}

/**
 * Canonical locked heading block: one H2–H4 (optional empty TipTap trailing <p>).
 * Not a raw heading nested inside a body editor.
 */
export function isCanonicalLockedHeadingHtml(html) {
    const level = standaloneHeadingLevel(html);

    return level != null && level >= 2 && level <= 4;
}

/**
 * Section heading block: heading đầu tiên (thường h2), có thể kèm <p></p> phía sau cho TipTap.
 *
 * @returns {number|null}
 */
export function leadingHeadingLevel(html) {
    const nodes = meaningfulBodyNodes(html);
    if (nodes.length === 0 || nodes[0].nodeType !== 1) {
        return null;
    }

    const match = nodes[0].tagName.toLowerCase().match(HEADING_TAG_RE);

    return match ? Number(match[1]) : null;
}

function standaloneBodyNodes(html) {
    const doc = new DOMParser().parseFromString((html || '').trim(), 'text/html');

    return Array.from(doc.body.childNodes).filter((node) => {
        if (node.nodeType === 3) {
            return Boolean(node.textContent?.trim());
        }

        return node.nodeType === 1;
    });
}

function extractStandaloneInnerHtml(exportedHtml) {
    const trimmed = (exportedHtml || '').trim();
    if (!trimmed) {
        return '';
    }

    const nodes = standaloneBodyNodes(trimmed);
    if (nodes.length === 1 && nodes[0].nodeType === 1) {
        const element = nodes[0];
        const tag = element.tagName.toLowerCase();
        if (BLOCK_WRAPPER_TAGS.has(tag)) {
            return element.innerHTML.trim();
        }

        return element.outerHTML.trim();
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');

    return doc.body.innerHTML.trim();
}

function rebuildStandaloneHeadingHtml(originalHtml, innerHtml, level) {
    const doc = new DOMParser().parseFromString((originalHtml || '').trim(), 'text/html');
    const originalHeading = doc.body.querySelector('h1,h2,h3,h4,h5,h6');
    const className = originalHeading?.getAttribute('class');
    const classAttr = className ? ` class="${className}"` : '';

    return `<h${level}${classAttr}>${innerHtml}</h${level}>`;
}

/**
 * TipTap cần một paragraph sau heading để đặt con trỏ; chỉ dùng khi hydrate editor, không lưu state.
 */
export function ensureTiptapHeadingCursorParagraph(html) {
    const trimmed = (html || '').trim();
    if (!trimmed || leadingHeadingLevel(trimmed) === null) {
        return trimmed;
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');
    const nodes = Array.from(doc.body.childNodes).filter((node) => !isEmptyBlockNode(node));

    if (nodes.length !== 1 || nodes[0].nodeType !== 1) {
        return trimmed;
    }

    const tag = nodes[0].tagName.toLowerCase();
    if (!HEADING_TAG_RE.test(tag)) {
        return trimmed;
    }

    return `${nodes[0].outerHTML}<p></p>`;
}

/**
 * Bỏ <p>/<div> rỗng ở cấp body (TipTap hay sinh khi lưu nháp / undo).
 */
export function stripEmptyParagraphsFromHtml(html) {
    const trimmed = (html || '').trim();
    if (!trimmed) {
        return trimmed;
    }

    if (isFaqPlaceholderHtml(trimmed)) {
        return stripEditorTransientMarkup(trimmed);
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');
    const kept = Array.from(doc.body.childNodes).filter((node) => !isEmptyBlockNode(node));

    if (kept.length === 0) {
        return '';
    }

    const rebuilt = kept
        .map((node) => {
            if (node.nodeType === 1) {
                return node.outerHTML;
            }

            return node.textContent || '';
        })
        .join('');

    return stripEditorTransientMarkup(rebuilt);
}

/**
 * Chuẩn hóa HTML block để hiển thị / lưu nháp (không thêm <p></p> sau heading).
 */
export function cleanBlockHtmlForEditorDisplay(html) {
    return normalizeSectionHeadingBlockHtml(html);
}

/**
 * HTML lưu vào block state sau khi TipTap export (coalesce + bỏ paragraph rỗng + merge link tách).
 */
export function persistBlockHtmlFromEditor(originalHtml, exportedHtml) {
    return normalizeInlineLinks(
        normalizeSectionHeadingBlockHtml(coalesceTiptapExportHtml(originalHtml, exportedHtml)),
    );
}

/**
 * Gỡ <p></p> thừa quanh heading section (TipTap hay chèn khi flush / chèn ảnh).
 */
export function normalizeSectionHeadingBlockHtml(html) {
    const trimmed = stripEmptyParagraphsFromHtml(html);
    if (!trimmed) {
        return trimmed;
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');
    const nodes = Array.from(doc.body.childNodes);

    while (nodes.length > 0 && isEmptyBlockNode(nodes[0])) {
        nodes.shift();
    }

    if (nodes.length === 0 || nodes[0].nodeType !== 1) {
        return stripEditorTransientMarkup(trimmed);
    }

    const firstTag = nodes[0].tagName.toLowerCase();
    if (!HEADING_TAG_RE.test(firstTag)) {
        return trimmed;
    }

    const headingHtml = nodes[0].outerHTML;
    const rest = nodes.slice(1);
    const meaningfulRest = rest.filter((node) => !isEmptyBlockNode(node));

    if (meaningfulRest.length === 0) {
        return stripEditorTransientMarkup(headingHtml);
    }

    const restHtml = meaningfulRest
        .map((node) => {
            if (node.nodeType === 1) {
                return node.outerHTML;
            }

            return node.textContent || '';
        })
        .join('');

    return stripEditorTransientMarkup(`${headingHtml}${restHtml}`);
}

/**
 * TipTap đôi khi đổi `<h2>…</h2>` thành `<p><strong>…</strong></p>` khi block chỉ có heading.
 * Giữ cấp heading và nội dung người dùng vừa sửa thay vì revert về HTML gốc.
 */
export function coalesceTiptapExportHtml(originalHtml, exportedHtml) {
    if (isFaqPlaceholderHtml(originalHtml)) {
        const exportText = exportedHtml || '';
        if (!/\[omi_faq\]/i.test(exportText)) {
            return originalHtml;
        }
        if (!/omi-faq-placeholder/i.test(exportText)) {
            return FAQ_SHORTCODE_HTML;
        }

        return normalizeSectionHeadingBlockHtml(exportedHtml);
    }

    const originalLevel = leadingHeadingLevel(originalHtml);
    if (originalLevel === null) {
        return normalizeSectionHeadingBlockHtml(exportedHtml);
    }

    const exportedLevel = leadingHeadingLevel(exportedHtml);
    if (exportedLevel !== null) {
        return normalizeSectionHeadingBlockHtml(exportedHtml);
    }

    const trimmedExport = (exportedHtml || '').trim();
    if (!trimmedExport) {
        return originalHtml;
    }

    const innerHtml = extractStandaloneInnerHtml(trimmedExport);
    if (!innerHtml) {
        return originalHtml;
    }

    return normalizeSectionHeadingBlockHtml(
        rebuildStandaloneHeadingHtml(originalHtml, innerHtml, originalLevel),
    );
}

/**
 * Bóc các wrapper div/section (vd. `.term-description`) để mỗi đoạn thành một block riêng.
 */
export function flattenHtmlBodyNodes(parent) {
    const result = [];
    let inlineParagraph = null;

    const ensureInlineParagraph = () => {
        if (!inlineParagraph) {
            inlineParagraph = parent.ownerDocument.createElement('p');
        }

        return inlineParagraph;
    };

    const flushInlineParagraph = () => {
        if (!inlineParagraph) {
            return;
        }

        const text = (inlineParagraph.textContent || '').replace(/\u00a0/g, ' ').trim();
        const hasMedia = Boolean(inlineParagraph.querySelector('img,video,iframe'));
        if (text || hasMedia) {
            result.push(inlineParagraph);
        }

        inlineParagraph = null;
    };

    const appendLooseText = (text) => {
        const source = String(text || '').replace(/\r\n?/g, '\n');
        const blankLinePattern = /\n[ \t]*\n+/g;
        let offset = 0;
        let match;

        while ((match = blankLinePattern.exec(source)) !== null) {
            const segment = source.slice(offset, match.index).replace(/\n+/g, ' ');
            if (segment) {
                ensureInlineParagraph().appendChild(parent.ownerDocument.createTextNode(segment));
            }
            flushInlineParagraph();
            offset = blankLinePattern.lastIndex;
        }

        const tail = source.slice(offset).replace(/\n+/g, ' ');
        if (tail) {
            ensureInlineParagraph().appendChild(parent.ownerDocument.createTextNode(tail));
        }
    };

    Array.from(parent.childNodes).forEach((node) => {
        if (node.nodeType === 3) {
            appendLooseText(node.textContent);
            return;
        }

        if (node.nodeType !== 1) {
            return;
        }

        const tag = node.tagName.toLowerCase();
        const unwrap =
            (tag === 'div' || tag === 'section' || tag === 'article') &&
            !node.classList?.contains('wp-block-image') &&
            !node.classList?.contains('wp-caption');

        if (unwrap) {
            flushInlineParagraph();
            result.push(...flattenHtmlBodyNodes(node));
            return;
        }

        if (CLASSIC_BLOCK_TAGS.has(tag)) {
            flushInlineParagraph();
            result.push(node);
            return;
        }

        ensureInlineParagraph().appendChild(node.cloneNode(true));
    });

    flushInlineParagraph();

    return result;
}

/**
 * Không ghi transaction hydrate/setContent vào undo stack TipTap.
 *
 * @param {{ view?: { dispatch: (tr: unknown) => void }, state?: { tr: { setMeta: (key: string, value: boolean) => unknown } }, isDestroyed?: boolean } | null | undefined} editor
 */
export function resetTipTapEditorHistory(editor) {
    if (!editor?.view || !editor.state || editor.isDestroyed) {
        return;
    }

    const { state, view } = editor;
    view.dispatch(state.tr.setMeta('addToHistory', false));
}
