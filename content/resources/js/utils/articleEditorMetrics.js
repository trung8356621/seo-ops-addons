/**
 * Phase 4 — lightweight document metrics (client-only, no Livewire/fetch).
 */

/**
 * Strip tags cheaply for word counting (not a full HTML sanitizer).
 * @param {string} html
 * @returns {string}
 */
export function stripHtmlToText(html) {
    const raw = String(html ?? '');
    if (raw === '') {
        return '';
    }
    if (!/<[a-z!/]/i.test(raw)) {
        return raw.replace(/\s+/g, ' ').trim();
    }

    return raw
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * @param {string} text
 * @returns {number}
 */
export function countWordsFromText(text) {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    if (!normalized) {
        return 0;
    }

    return normalized.split(' ').filter(Boolean).length;
}

/**
 * @param {string} html
 * @returns {number}
 */
export function countWordsFromHtmlLight(html) {
    return countWordsFromText(stripHtmlToText(html));
}

/**
 * Aggregate metrics from editor blocks (canonical session content).
 * @param {Array<{ type?: string, content?: string, image?: { src?: string } }>} blocks
 * @returns {{ words: number, characters: number, headings: number, paragraphs: number, images: number, links: number }}
 */
export function computeBlockMetrics(blocks) {
    let words = 0;
    let characters = 0;
    let headings = 0;
    let paragraphs = 0;
    let images = 0;
    let links = 0;

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image') {
            if (String(block?.image?.src ?? '').trim() !== '') {
                images += 1;
            }
            continue;
        }

        const html = typeof block?.content === 'string' ? block.content : '';
        if (!html) {
            continue;
        }

        const text = stripHtmlToText(html);
        words += countWordsFromText(text);
        characters += text.length;
        if (/<h[2-4]\b/i.test(html)) {
            headings += 1;
        }
        const pMatches = html.match(/<p\b/gi);
        if (pMatches) {
            paragraphs += pMatches.length;
        }
        const imgMatches = html.match(/<img\b/gi);
        if (imgMatches) {
            images += imgMatches.length;
        }
        const aMatches = html.match(/<a\b/gi);
        if (aMatches) {
            links += aMatches.length;
        }
    }

    return { words, characters, headings, paragraphs, images, links };
}
