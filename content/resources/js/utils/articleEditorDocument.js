/**
 * Phase 5A — build / apply canonical article_document envelope on the client.
 */

import { documentJsonFromEditorsOrBlocks } from './editorDocumentBridge';
import { hasInlineWhitespaceCorruption } from './inlineWhitespaceGuard';
import { hashContent } from './articleEditorStorage';

/**
 * Stable JSON for hashing — sorted object keys, mirrors server encode flags intent.
 * @param {unknown} value
 * @returns {string}
 */
export function stableSerialize(value) {
    const seen = new WeakSet();

    const walk = (input) => {
        if (input === null || typeof input !== 'object') {
            return input;
        }
        if (seen.has(input)) {
            return null;
        }
        seen.add(input);
        if (Array.isArray(input)) {
            return input.map(walk);
        }
        const out = {};
        Object.keys(input).sort().forEach((key) => {
            out[key] = walk(input[key]);
        });
        return out;
    };

    return JSON.stringify(walk(value));
}

/**
 * Client diagnostic hash of editor_document envelope (not sole authority).
 * Uses same SHA-256 hex path as body content_hash helper.
 * @param {object|null|undefined} envelope
 * @returns {string}
 */
export function hashEditorDocumentEnvelope(envelope) {
    if (!envelope || typeof envelope !== 'object') {
        return '';
    }
    try {
        return hashContent(stableSerialize(envelope));
    } catch {
        return '';
    }
}

/**
 * @param {unknown} node
 * @returns {string}
 */
export function tipTapPlainText(node) {
    if (!node || typeof node !== 'object') {
        return '';
    }
    const parts = [];
    // Keep interior spaces between marks; only normalize the joined result.
    const text = String(node.text ?? '');
    if (text !== '') {
        parts.push(text);
    }
    const content = Array.isArray(node.content) ? node.content : [];
    content.forEach((child) => {
        const childText = tipTapPlainText(child);
        if (childText !== '') {
            parts.push(childText);
        }
    });
    return parts.join('').replace(/\s+/g, ' ').trim();
}

/**
 * @param {unknown} node
 * @returns {boolean}
 */
export function tipTapHasTableContent(node) {
    if (!node || typeof node !== 'object') {
        return false;
    }
    if (String(node.type ?? '') === 'table') {
        return Array.isArray(node.content) && node.content.length > 0;
    }
    const content = Array.isArray(node.content) ? node.content : [];
    return content.some((child) => tipTapHasTableContent(child));
}

/**
 * @param {unknown} node
 * @returns {boolean}
 */
export function tipTapHasMeaningfulText(node) {
    return tipTapPlainText(node) !== '';
}

/**
 * Reject empty TipTap docs before setContent — fall back to HTML.
 * @param {unknown} doc
 * @returns {boolean}
 */
export function isUsableTipTapDocument(doc) {
    if (!doc || typeof doc !== 'object') {
        return false;
    }
    if (Array.isArray(doc) && doc.length === 0) {
        return false;
    }
    if (Object.keys(doc).length === 0) {
        return false;
    }
    const content = doc.content;
    if (!Array.isArray(content) || content.length === 0) {
        return false;
    }
    return tipTapHasMeaningfulText(doc);
}

/**
 * @param {string|null|undefined} html
 * @returns {string}
 */
function htmlPlainText(html) {
    const source = String(html ?? '').trim();
    if (!source) {
        return '';
    }
    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(source, 'text/html');
        return (doc.body?.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    } catch {
        return source.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    }
}

/**
 * @param {string|null|undefined} html
 * @returns {boolean}
 */
function htmlHasMeaningfulText(html) {
    return htmlPlainText(html) !== '';
}

/**
 * Lightweight TipTap JSON → HTML for inactive preview + outline (not full schema render).
 * @param {unknown} node
 * @returns {string}
 */
export function tipTapDocToPreviewHtml(node) {
    if (!node || typeof node !== 'object') {
        return '';
    }

    const escape = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const renderMarks = (text, marks) => {
        let out = escape(text);
        (Array.isArray(marks) ? marks : []).forEach((mark) => {
            const type = String(mark?.type ?? '');
            if (type === 'bold' || type === 'strong') out = `<strong>${out}</strong>`;
            else if (type === 'italic' || type === 'em') out = `<em>${out}</em>`;
            else if (type === 'underline') out = `<u>${out}</u>`;
            else if (type === 'code') out = `<code>${out}</code>`;
            else if (type === 'link') {
                const href = escape(mark?.attrs?.href ?? '#');
                out = `<a href="${href}">${out}</a>`;
            }
        });
        return out;
    };

    const walk = (current) => {
        if (!current || typeof current !== 'object') {
            return '';
        }
        const type = String(current.type ?? '');
        if (type === 'text') {
            return renderMarks(current.text ?? '', current.marks);
        }
        if (type === 'hardBreak') {
            return '<br>';
        }
        const kids = (Array.isArray(current.content) ? current.content : []).map(walk).join('');
        switch (type) {
            case 'doc':
                return kids;
            case 'paragraph':
                return `<p>${kids || '<br>'}</p>`;
            case 'heading': {
                const level = Math.min(6, Math.max(1, Number(current.attrs?.level || 2)));
                return `<h${level}>${kids}</h${level}>`;
            }
            case 'blockquote':
                return `<blockquote>${kids}</blockquote>`;
            case 'bulletList':
                return `<ul>${kids}</ul>`;
            case 'orderedList':
                return `<ol>${kids}</ol>`;
            case 'listItem':
                return `<li>${kids}</li>`;
            case 'codeBlock':
                return `<pre><code>${kids}</code></pre>`;
            case 'table':
                return `<table>${kids}</table>`;
            case 'tableRow':
                return `<tr>${kids}</tr>`;
            case 'tableHeader':
                return `<th>${kids || '&nbsp;'}</th>`;
            case 'tableCell':
                return `<td>${kids || '&nbsp;'}</td>`;
            default:
                return kids;
        }
    };

    return walk(node);
}

/**
 * Bootstrap/read gate — reject hollow envelopes so caller falls back to HTML body.
 * Mirrors ArticleEditorDocumentWriter::isUsableBootstrapDocument.
 *
 * @param {object|null|undefined} envelope
 * @param {string|null|undefined} bodyHtml
 * @returns {boolean}
 */
export function isUsableEditorDocumentEnvelope(envelope, bodyHtml = '') {
    if (!envelope || typeof envelope !== 'object') {
        return false;
    }
    if (String(envelope.type ?? '') !== 'article_document') {
        return false;
    }
    const blocks = Array.isArray(envelope.blocks) ? envelope.blocks : [];
    if (blocks.length === 0) {
        return false;
    }

    let hasValidBlock = false;
    let hasMeaningfulText = false;
    let textBlockCount = 0;
    let emptyTextBlockCount = 0;
    let jsonPlainLength = 0;
    let jsonPlainJoined = '';
    let jsonHasTableContent = false;

    blocks.forEach((block) => {
        if (!block || typeof block !== 'object') {
            return;
        }
        const type = String(block.type ?? 'text');
        if (type === 'image') {
            const image = block.image && typeof block.image === 'object' ? block.image : {};
            const src = String(image.src ?? image.url ?? '').trim();
            if (src !== '') {
                hasValidBlock = true;
            }
            return;
        }
        const doc = block.document && typeof block.document === 'object'
            ? block.document
            : null;
        if (!doc) {
            return;
        }
        hasValidBlock = true;
        textBlockCount += 1;
        const plain = tipTapPlainText(doc);
        jsonPlainLength += plain.length;
        if (plain !== '') {
            hasMeaningfulText = true;
            jsonPlainJoined = `${jsonPlainJoined} ${plain}`.trim();
        } else {
            emptyTextBlockCount += 1;
        }
        if (tipTapHasTableContent(doc)) {
            jsonHasTableContent = true;
        }
    });

    if (!hasValidBlock) {
        return false;
    }

    const bodyPlain = htmlPlainText(bodyHtml);
    const bodyPlainLength = bodyPlain.length;
    const bodyHasTable = /<table\b/i.test(String(bodyHtml ?? ''));

    if (bodyPlainLength > 0 && !hasMeaningfulText) {
        return false;
    }

    if (bodyHasTable && !jsonHasTableContent) {
        return false;
    }

    if (
        bodyPlainLength >= 80
        && (
            (textBlockCount > 0 && emptyTextBlockCount * 2 >= textBlockCount)
            || jsonPlainLength * 2 < bodyPlainLength
            || (bodyPlainLength - jsonPlainLength) >= 120
        )
    ) {
        return false;
    }

    // JSON lost inter-word spaces around inline marks vs body → prefer HTML fallback.
    if (bodyPlainLength > 0 && hasInlineWhitespaceCorruption(bodyPlain, jsonPlainJoined)) {
        return false;
    }

    return true;
}

/**
 * @param {Array<object>} blocks
 * @param {Map<string, import('@tiptap/core').Editor>|null|undefined} blockEditors
 * @returns {{ schema_version: number, type: string, blocks: object[] }}
 */
export function buildEditorDocumentEnvelope(blocks, blockEditors = null) {
    const list = Array.isArray(blocks) ? blocks : [];
    const out = [];

    list.forEach((block) => {
        const id = String(block?.id ?? '').trim();
        if (id === '') {
            return;
        }
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            const img = block.image && typeof block.image === 'object' ? block.image : {};
            out.push({
                id,
                type: 'image',
                image: {
                    src: String(img.src ?? img.url ?? '').trim(),
                    alt: String(img.alt ?? '').trim(),
                    title: String(img.title ?? '').trim(),
                    caption: String(img.caption ?? '').trim(),
                    align: String(img.align ?? 'none').trim() || 'none',
                },
            });
            return;
        }

        const editor = blockEditors?.get?.(id);
        let document = null;
        if (editor && !editor.isDestroyed && typeof editor.getJSON === 'function') {
            document = editor.getJSON();
        } else {
            const partial = documentJsonFromEditorsOrBlocks(
                new Map(editor ? [[id, editor]] : []),
                [block],
            );
            document = {
                type: 'doc',
                content: Array.isArray(partial?.content) ? partial.content : [],
            };
        }

        out.push({
            id,
            type: 'text',
            document: document && typeof document === 'object'
                ? document
                : { type: 'doc', content: [] },
        });
    });

    return {
        schema_version: 1,
        type: 'article_document',
        blocks: out,
    };
}

/**
 * Convert server envelope → client blocks[].
 * Fills `content` from TipTap preview HTML so inactive preview + outline see text/headings.
 * Returns null when envelope unusable so caller falls back to body HTML.
 *
 * @param {object|null|undefined} envelope
 * @param {string|null|undefined} bodyHtml  Optional body for hollow-JSON detection
 * @returns {Array<object>|null}
 */
export function blocksFromEditorDocumentEnvelope(envelope, bodyHtml = '') {
    if (!isUsableEditorDocumentEnvelope(envelope, bodyHtml)) {
        return null;
    }
    const blocks = Array.isArray(envelope.blocks) ? envelope.blocks : [];

    return blocks.map((block) => {
        const id = String(block?.id ?? '').trim();
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            return {
                id,
                type: 'image',
                content: '',
                image: block.image && typeof block.image === 'object' ? block.image : {},
            };
        }

        const editorDocument = block.document && typeof block.document === 'object'
            ? block.document
            : { type: 'doc', content: [] };

        return {
            id,
            type: 'text',
            content: tipTapDocToPreviewHtml(editorDocument),
            editorDocument,
        };
    });
}

export default {
    buildEditorDocumentEnvelope,
    blocksFromEditorDocumentEnvelope,
    isUsableEditorDocumentEnvelope,
    isUsableTipTapDocument,
    tipTapHasMeaningfulText,
    tipTapHasTableContent,
    tipTapPlainText,
    tipTapDocToPreviewHtml,
    stableSerialize,
    hashEditorDocumentEnvelope,
};
