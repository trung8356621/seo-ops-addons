/**
 * Canonical content-image counting for assistant Images widget + SEO ratio presentation.
 * Featured / gallery are separate — never mixed into content_image_count.
 */

/**
 * @param {string} html
 * @returns {Array<{ src: string, alt: string }>}
 */
export function extractValidImagesFromHtml(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return [];
    }

    const found = [];
    const pattern = /<img\b([^>]*)>/giu;
    let match;
    while ((match = pattern.exec(source)) !== null) {
        const attrs = match[1] ?? '';
        const srcMatch = attrs.match(/\bsrc\s*=\s*(["'])([^"']*)\1/i);
        const src = String(srcMatch?.[2] ?? '').trim();
        if (src === '' || /placeholder|data:image\/svg/i.test(src)) {
            continue;
        }
        const altMatch = attrs.match(/\balt\s*=\s*(["'])([^"']*)\1/i);
        found.push({
            src,
            alt: String(altMatch?.[2] ?? '').trim(),
        });
    }

    return found;
}

/**
 * @param {Array<Record<string, unknown>>} blocks
 * @returns {{
 *   content_image_count: number,
 *   valid_content_image_count: number,
 *   invalid_image_count: number,
 *   rows: Array<Record<string, unknown>>,
 * }}
 */
export function collectContentImagesFromArticle(blocks) {
    const list = Array.isArray(blocks) ? blocks : [];
    const rows = [];
    let invalid = 0;

    list.forEach((block) => {
        if (!block || typeof block !== 'object') {
            return;
        }

        const blockId = String(block.id ?? '').trim();
        const type = String(block.type ?? '').trim();

        if (type === 'image') {
            const image = block.image && typeof block.image === 'object' ? block.image : null;
            const src = String(image?.src ?? '').trim();
            if (!src || image?.isProcessing || /placeholder|data:image\/svg/i.test(src)) {
                invalid += 1;
                return;
            }
            rows.push({
                key: String(image?.id || blockId || src),
                blockId,
                src,
                alt: String(image?.alt ?? ''),
                wpAttachmentId: image?.wpAttachmentId ?? null,
                seoMediaId: image?.seoMediaId ?? null,
                slug: image?.slug ?? '',
                origin: 'image-block',
            });
            return;
        }

        // Content / heading HTML may embed <img> / <figure><img>.
        const html = String(block.content ?? '');
        if (!/<img\b/i.test(html)) {
            return;
        }

        extractValidImagesFromHtml(html).forEach((img, index) => {
            rows.push({
                key: `${blockId || 'content'}-inline-${index}-${img.src}`,
                blockId,
                src: img.src,
                alt: img.alt,
                wpAttachmentId: null,
                seoMediaId: null,
                slug: '',
                origin: 'inline-html',
            });
        });
    });

    // Deduplicate same block image-block row only (keep distinct inline imgs even if same URL).
    const seenBlockImages = new Set();
    const deduped = [];
    rows.forEach((row) => {
        if (row.origin === 'image-block' && row.blockId) {
            if (seenBlockImages.has(row.blockId)) {
                return;
            }
            seenBlockImages.add(row.blockId);
        }
        deduped.push(row);
    });

    return {
        content_image_count: deduped.length,
        valid_content_image_count: deduped.length,
        invalid_image_count: invalid,
        rows: deduped,
    };
}
