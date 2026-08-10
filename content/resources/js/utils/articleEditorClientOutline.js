/**
 * Phase 4 — derive outline from editor blocks (canonical session document),
 * not from GET /outline. H2–H4 only.
 */

/**
 * @param {string} text
 * @returns {string}
 */
export function normalizeOutlineHeadingText(text) {
    return String(text ?? '').replace(/\s+/g, ' ').trim();
}

/**
 * Extract first H2–H4 from a text block HTML string (no full-article parse).
 * @param {{ id?: string, type?: string, content?: string }|null} block
 * @returns {{ level: number, headingText: string }|null}
 */
export function extractOutlineHeadingFromBlock(block) {
    if (!block || block.type === 'image' || typeof block.content !== 'string' || !block.content.trim()) {
        return null;
    }

    const html = block.content;
    // Fast path: avoid DOMParser when no heading tags.
    if (!/<h[2-4]\b/i.test(html)) {
        return null;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const heading = doc.body.querySelector('h2, h3, h4');
    if (!heading) {
        return null;
    }

    const text = normalizeOutlineHeadingText(heading.textContent);
    if (text === '') {
        return null;
    }

    return {
        level: Number.parseInt(heading.tagName.charAt(1), 10),
        headingText: text,
    };
}

/**
 * Build nested outline tree from blocks (document order).
 * Node ids are client-stable: `client:{blockId}` so jump can use blockId without DB.
 *
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @returns {Array<{ id: string, level: number, heading_text: string, block_id: string, children: array }>}
 */
export function buildClientOutlineTree(blocks) {
    const list = Array.isArray(blocks) ? blocks : [];
    const roots = [];
    /** @type {Array<{ node: object, level: number }>} */
    const stack = [];

    for (let index = 0; index < list.length; index += 1) {
        const block = list[index];
        const meta = extractOutlineHeadingFromBlock(block);
        if (!meta) {
            continue;
        }

        const node = {
            id: `client:${String(block.id)}`,
            level: meta.level,
            heading_text: meta.headingText,
            block_id: String(block.id),
            position: index,
            children: [],
        };

        while (stack.length > 0 && stack[stack.length - 1].level >= meta.level) {
            stack.pop();
        }

        if (stack.length === 0) {
            roots.push(node);
        } else {
            stack[stack.length - 1].node.children.push(node);
        }

        stack.push({ node, level: meta.level });
    }

    return roots;
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
 * Fingerprint for "heading structure/text changed" — skip outline rebuild when equal.
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @returns {string}
 */
export function outlineHeadingFingerprint(blocks) {
    const parts = [];
    for (const block of Array.isArray(blocks) ? blocks : []) {
        const meta = extractOutlineHeadingFromBlock(block);
        if (!meta) {
            continue;
        }
        parts.push(`${block.id}|${meta.level}|${meta.headingText}`);
    }

    return parts.join('\n');
}
