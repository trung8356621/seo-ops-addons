/**
 * Phase 4 — derive outline from editor blocks (canonical session document),
 * not from GET /outline. H2–H4 only. Every heading in a block is projected.
 */

/**
 * @param {string} text
 * @returns {string}
 */
export function normalizeOutlineHeadingText(text) {
    return String(text ?? '').replace(/\s+/g, ' ').trim();
}

/**
 * @param {string} headingText
 * @returns {string}
 */
export function localDuplicateHeadingKey(headingText) {
    return normalizeOutlineHeadingText(headingText).toLocaleLowerCase('vi');
}

function headingVisibleFromAttrs(attrs) {
    const raw = String(attrs ?? '');

    return !/data-outline-visible\s*=\s*(['"]?)false\1/i.test(raw);
}

function headingIdFromAttrs(attrs, blockId, index) {
    const match = /data-omi-heading-id\s*=\s*(['"])(.*?)\1/i.exec(String(attrs ?? ''));
    if (match?.[2]) {
        return match[2];
    }

    return `client:${String(blockId)}:${index}`;
}

function stripTags(html) {
    return String(html ?? '').replace(/<[^>]+>/g, ' ');
}

/**
 * @param {string} html
 * @param {string} blockId
 * @returns {Array<{
 *   id: string,
 *   level: number,
 *   heading_text: string,
 *   block_id: string,
 *   heading_index: number,
 *   outline_visible: boolean,
 *   children: array,
 * }>}
 */
export function extractOutlineHeadingsFromHtml(html, blockId) {
    const source = String(html ?? '');
    if (!/<h[2-4]\b/i.test(source)) {
        return [];
    }

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        return Array.from(doc.body.querySelectorAll('h2, h3, h4')).map((heading, index) => {
            const text = normalizeOutlineHeadingText(heading.textContent);
            const visible = heading.getAttribute('data-outline-visible') !== 'false';
            const headingId = heading.getAttribute('data-omi-heading-id') || `client:${String(blockId)}:${index}`;

            return {
                id: headingId,
                level: Number.parseInt(heading.tagName.charAt(1), 10),
                heading_text: text,
                block_id: String(blockId),
                heading_index: index,
                outline_visible: visible,
                children: [],
            };
        });
    }

    const headings = [];
    const re = /<h([2-4])\b([^>]*)>([\s\S]*?)<\/h\1>/gi;
    let match;
    while ((match = re.exec(source)) !== null) {
        const index = headings.length;
        headings.push({
            id: headingIdFromAttrs(match[2], blockId, index),
            level: Number.parseInt(match[1], 10),
            heading_text: normalizeOutlineHeadingText(stripTags(match[3])),
            block_id: String(blockId),
            heading_index: index,
            outline_visible: headingVisibleFromAttrs(match[2]),
            children: [],
        });
    }

    return headings;
}

/**
 * @param {{ id?: string, type?: string, content?: string }|null} block
 * @returns {ReturnType<typeof extractOutlineHeadingsFromHtml>}
 */
export function extractOutlineHeadingsFromBlock(block) {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return [];
    }

    return extractOutlineHeadingsFromHtml(block.content, block.id ?? '');
}

/**
 * Extract first H2–H4 from a text block HTML string (no full-article parse).
 * @param {{ id?: string, type?: string, content?: string }|null} block
 * @returns {{ level: number, headingText: string }|null}
 */
export function extractOutlineHeadingFromBlock(block) {
    const first = extractOutlineHeadingsFromBlock(block).find((item) => item.heading_text !== '');
    if (!first) {
        return null;
    }

    return {
        level: first.level,
        headingText: first.heading_text,
    };
}

/**
 * @param {Array<{ level: number, heading_text: string, outline_visible?: boolean, children?: array }>} headings
 * @returns {Array<object>}
 */
export function buildOutlineTreeFromHeadings(headings) {
    const roots = [];
    /** @type {Array<{ node: object, level: number }>} */
    const stack = [];

    for (const heading of Array.isArray(headings) ? headings : []) {
        if (heading?.outline_visible === false || normalizeOutlineHeadingText(heading?.heading_text) === '') {
            continue;
        }

        const node = {
            ...heading,
            heading_text: normalizeOutlineHeadingText(heading.heading_text),
            children: [],
        };

        while (stack.length > 0 && stack[stack.length - 1].level >= node.level) {
            stack.pop();
        }

        if (stack.length === 0) {
            roots.push(node);
        } else {
            stack[stack.length - 1].node.children.push(node);
        }

        stack.push({ node, level: node.level });
    }

    return roots;
}

/**
 * Build nested outline tree from blocks (document order).
 * Node ids: `client:{blockId}:{headingIndex}` plus block_id for jump.
 *
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @returns {Array<object>}
 */
export function buildClientOutlineTree(blocks) {
    const list = Array.isArray(blocks) ? blocks : [];
    const headings = [];

    for (let index = 0; index < list.length; index += 1) {
        const block = list[index];
        const extracted = extractOutlineHeadingsFromBlock(block);
        for (const heading of extracted) {
            headings.push({
                ...heading,
                position: index,
            });
        }
    }

    return buildOutlineTreeFromHeadings(headings);
}

/**
 * @param {Array<object>} nodes
 * @param {Array<object>} [result]
 * @returns {Array<object>}
 */
export function flattenClientOutlineNodes(nodes, result = []) {
    for (const node of nodes ?? []) {
        result.push(node);
        if (Array.isArray(node.children) && node.children.length > 0) {
            flattenClientOutlineNodes(node.children, result);
        }
    }

    return result;
}

/**
 * @param {Array<object>} nodes
 * @returns {Set<string>}
 */
export function findLocalDuplicateHeadingKeys(nodes) {
    const counts = new Map();
    for (const node of flattenClientOutlineNodes(nodes)) {
        const key = localDuplicateHeadingKey(node?.heading_text);
        if (key === '') {
            continue;
        }
        counts.set(key, (counts.get(key) ?? 0) + 1);
    }

    const duplicates = new Set();
    for (const [key, count] of counts.entries()) {
        if (count > 1) {
            duplicates.add(key);
        }
    }

    return duplicates;
}

/**
 * Fingerprint for "heading structure/text changed" — skip outline rebuild when equal.
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @returns {string}
 */
export function outlineHeadingFingerprint(blocks) {
    const parts = [];
    for (const block of Array.isArray(blocks) ? blocks : []) {
        for (const heading of extractOutlineHeadingsFromBlock(block)) {
            parts.push(
                `${block.id}|${heading.heading_index}|${heading.level}|${heading.heading_text}|${heading.outline_visible ? 1 : 0}`,
            );
        }
    }

    return parts.join('\n');
}
