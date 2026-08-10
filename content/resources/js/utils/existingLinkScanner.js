/**
 * Client-side existing-link scanner for current TipTap / block document.
 * Does not call server. Domain catalog / suggestions stay separate.
 */

import {
    extractLinksFromBlocks,
    extractLinksFromHtml,
    isInternalLinkHref,
} from './articleLinkScroll';

/**
 * @param {string} href
 * @returns {boolean}
 */
export function isSkippableLinkHref(href) {
    const value = String(href ?? '').trim();
    if (value === '' || value === '#') {
        return true;
    }
    const lower = value.toLowerCase();
    return (
        lower.startsWith('mailto:')
        || lower.startsWith('tel:')
        || lower.startsWith('javascript:')
        || lower.startsWith('#')
    );
}

/**
 * @param {string} href
 * @param {string} siteDomain
 * @returns {'internal'|'external'|'skip'}
 */
export function classifyLinkHref(href, siteDomain = '') {
    if (isSkippableLinkHref(href)) {
        return 'skip';
    }

    return isInternalLinkHref(href, siteDomain) ? 'internal' : 'external';
}

/**
 * Scan current article blocks for existing <a href> links.
 *
 * @param {Array<{ id?: string, type?: string, content?: string }>} blocks
 * @param {string} siteDomain
 * @returns {{
 *   internal: Array<{ href: string, text: string, type: 'internal', blockId: string|null, sectionId: string|null, position: number }>,
 *   external: Array<{ href: string, text: string, type: 'external', blockId: string|null, sectionId: string|null, position: number }>,
 * }}
 */
export function scanExistingLinksFromBlocks(blocks, siteDomain = '') {
    const internal = [];
    const external = [];
    let position = 0;

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image' || !block?.content) {
            continue;
        }

        const part = extractLinksFromHtml(block.content, siteDomain);
        const blockId = block?.id != null ? String(block.id) : null;

        for (const item of part.internal ?? []) {
            position += 1;
            internal.push({
                href: String(item.href ?? ''),
                text: String(item.text ?? ''),
                type: 'internal',
                blockId,
                sectionId: null,
                position,
                is_nofollow: Boolean(item.is_nofollow),
            });
        }

        for (const item of part.external ?? []) {
            if (isSkippableLinkHref(item?.href)) {
                continue;
            }
            position += 1;
            external.push({
                href: String(item.href ?? ''),
                text: String(item.text ?? ''),
                type: 'external',
                blockId,
                sectionId: null,
                position,
                is_nofollow: Boolean(item.is_nofollow),
            });
        }
    }

    return { internal, external };
}

/**
 * Compatibility wrapper — same shape as extractLinksFromBlocks for sidebar.
 *
 * @param {Array<{ id?: string, type?: string, content?: string }>} blocks
 * @param {string} siteDomain
 */
export function scanExistingLinksCompat(blocks, siteDomain = '') {
    const scanned = scanExistingLinksFromBlocks(blocks, siteDomain);
    // Keep legacy list shape (deduped) for sidebar counts when preferred.
    const legacy = extractLinksFromBlocks(blocks, siteDomain);

    return {
        internal: legacy.internal.map((item, index) => ({
            ...item,
            type: 'internal',
            blockId: scanned.internal[index]?.blockId ?? null,
            sectionId: null,
            position: index + 1,
        })),
        external: legacy.external.map((item, index) => ({
            ...item,
            type: 'external',
            blockId: scanned.external[index]?.blockId ?? null,
            sectionId: null,
            position: index + 1,
        })),
    };
}
