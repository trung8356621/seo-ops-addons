/**
 * Prefer live TipTap getJSON() from block editors; fall back to blocks HTML compat.
 * Phase 3 document resolution for analysis / widgets.
 */

import { createDocumentModel } from './documentModel.js';
import { blocksToDocumentJson, htmlToDocumentJson } from './htmlDocumentCompat.js';

/**
 * Merge per-block TipTap JSON into one doc.
 *
 * @param {Map<string, import('@tiptap/core').Editor>|null|undefined} blockEditors
 * @param {Array<object>} blocks
 * @returns {{ type: 'doc', content: object[] }}
 */
export function documentJsonFromEditorsOrBlocks(blockEditors, blocks = []) {
    const content = [];
    const list = Array.isArray(blocks) ? blocks : [];
    let usedLiveJson = false;

    list.forEach((block) => {
        const id = String(block?.id ?? '').trim();
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            const img = block.image && typeof block.image === 'object' ? block.image : {};
            const src = String(img.src ?? img.url ?? '').trim();
            if (src !== '') {
                content.push({
                    type: 'articleImage',
                    attrs: {
                        src,
                        alt: String(img.alt ?? '').trim(),
                        title: String(img.title ?? '').trim(),
                    },
                });
            }
            return;
        }

        const editor = id && blockEditors?.get?.(id) ? blockEditors.get(id) : null;
        if (editor && !editor.isDestroyed && typeof editor.getJSON === 'function') {
            const json = editor.getJSON();
            const nodes = Array.isArray(json?.content) ? json.content : [];
            nodes.forEach((node) => content.push(node));
            usedLiveJson = true;
            return;
        }

        const html = String(block?.content ?? '');
        if (html.trim() === '') {
            return;
        }
        const partial = htmlToDocumentJson(html);
        (partial.content || []).forEach((node) => content.push(node));
    });

    if (content.length === 0 && list.length === 0) {
        return blocksToDocumentJson(list);
    }

    return {
        type: 'doc',
        content,
        attrs: { source: usedLiveJson ? 'tiptap_json' : 'blocks_html_compat' },
    };
}

/**
 * Prefer live TipTap getHTML() so SEO analyze does not wait for React setBlocks flush.
 *
 * @param {Map<string, import('@tiptap/core').Editor>|null|undefined} blockEditors
 * @param {Array<object>} blocks
 * @returns {string}
 */
export function htmlFromEditorsOrBlocks(blockEditors, blocks = []) {
    const parts = [];
    const list = Array.isArray(blocks) ? blocks : [];

    list.forEach((block) => {
        const id = String(block?.id ?? '').trim();
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            const img = block.image && typeof block.image === 'object' ? block.image : {};
            const src = String(img.src ?? img.url ?? '').trim();
            if (src === '') {
                return;
            }
            const alt = String(img.alt ?? '').replace(/"/g, '&quot;');
            const title = String(img.title ?? '').replace(/"/g, '&quot;');
            parts.push(`<img src="${src}" alt="${alt}" title="${title}" />`);
            return;
        }

        const editor = id && blockEditors?.get?.(id) ? blockEditors.get(id) : null;
        if (editor && !editor.isDestroyed && typeof editor.getHTML === 'function') {
            const liveHtml = String(editor.getHTML() ?? '').trim();
            if (liveHtml !== '') {
                parts.push(liveHtml);
            }
            return;
        }

        const html = String(block?.content ?? '').trim();
        if (html !== '') {
            parts.push(html);
        }
    });

    return parts.join('\n\n');
}

export function documentModelFromEditorsOrBlocks(blockEditors, blocks = []) {
    return createDocumentModel(documentJsonFromEditorsOrBlocks(blockEditors, blocks));
}

export default {
    documentJsonFromEditorsOrBlocks,
    documentModelFromEditorsOrBlocks,
    htmlFromEditorsOrBlocks,
};
