/**
 * Canonical unified Images inventory for Article Editor.
 *
 * Inventory (panel / slug / ALT / Fix Slug All) ≠ content-image ratio count.
 * Ratio must keep using body content occurrences only.
 */

import {
    classifyMediaSource,
    isBulkSlugRenameSafeMedia,
    isWordPressProtectedMedia,
} from './mediaSourceClassification';

/**
 * @param {string} value
 * @returns {string}
 */
export function normalizeUnifiedImageUrl(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    try {
        const url = new URL(raw, typeof window !== 'undefined' ? window.location.origin : 'https://local.test');
        // Drop cache-busting / tracking query; keep path identity.
        return `${url.origin}${url.pathname}`.toLowerCase();
    } catch {
        return raw.split('?')[0].split('#')[0].toLowerCase();
    }
}

/**
 * @param {string} value
 * @returns {string}
 */
export function normalizeUnifiedLocalPath(value) {
    const raw = String(value ?? '').trim().replace(/\\/g, '/');
    if (raw === '') {
        return '';
    }

    let path = raw;
    try {
        const url = new URL(raw, typeof window !== 'undefined' ? window.location.origin : 'https://local.test');
        path = url.pathname;
    } catch {
        path = raw.split('?')[0];
    }

    const storageIdx = path.toLowerCase().indexOf('/storage/');
    if (storageIdx >= 0) {
        path = path.slice(storageIdx);
    }

    return path.replace(/^\/+/, '/').toLowerCase();
}

/**
 * Identity priority:
 * 1. canonical local media id (seo_media)
 * 2. wp_attachment_id
 * 3. normalized canonical URL
 * 4. normalized local path
 * 5. fallback fingerprint
 *
 * Never dedupe by basename alone.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string}
 */
export function unifiedImageIdentityKey(row) {
    if (!row || typeof row !== 'object') {
        return '';
    }

    const seoId = Math.max(0, Number(row.media_id ?? row.seoMediaId ?? row.seo_media_id ?? 0) || 0);
    if (seoId > 0) {
        return `seo:${seoId}`;
    }

    const wpId = Math.max(
        0,
        Number(row.wp_attachment_id ?? row.wpAttachmentId ?? row.attachment_id ?? 0) || 0,
    );
    if (wpId > 0) {
        return `wp:${wpId}`;
    }

    const url = normalizeUnifiedImageUrl(
        row.canonical_url ?? row.url ?? row.src ?? row.wpSrc ?? row.wp_url ?? '',
    );
    if (url !== '') {
        return `url:${url}`;
    }

    const localPath = normalizeUnifiedLocalPath(
        row.local_path ?? row.localSrc ?? row.local_src ?? row.path ?? '',
    );
    if (localPath !== '' && localPath !== '/') {
        return `path:${localPath}`;
    }

    const filename = String(row.filename ?? row.slug ?? '').trim().toLowerCase();
    const fingerprint = [
        filename,
        String(row.blockId ?? row.block_id ?? ''),
        String(row.key ?? ''),
    ].filter(Boolean).join('|');

    return fingerprint !== '' ? `fp:${fingerprint}` : '';
}

/**
 * @param {unknown} value
 * @returns {boolean}
 */
function isPlaceholderAlt(value) {
    const alt = String(value ?? '').trim();
    return alt === '' || /^(image|img|photo|untitled)$/i.test(alt);
}

/**
 * @param {Record<string, unknown>} row
 * @returns {boolean}
 */
export function rowRequiresLocalSlugFix(row) {
    if (!row || isWordPressProtectedMedia(row) || !isBulkSlugRenameSafeMedia(row)) {
        return false;
    }

    const src = String(row.src ?? row.url ?? row.localSrc ?? row.local_src ?? '').trim();
    const basenameRaw = src.split('/').pop()?.split('?')[0] ?? '';
    const basename = basenameRaw.replace(/\.[^.]+$/, '');
    const slug = String(row.slug ?? '').trim() || basename;
    if (slug === '') {
        return true;
    }

    return /^(image|img|photo|untitled|download|dsc|img_)[-_]?\d*$/i.test(slug)
        || /^(paste|clipboard|import)-[a-f0-9]{8,}$/i.test(slug)
        || /placeholder/i.test(slug);
}

/**
 * @param {Record<string, unknown>|null|undefined} raw
 * @param {{ content?: boolean, featured?: boolean, gallery?: boolean }} roles
 * @returns {Record<string, unknown>|null}
 */
function normalizeInventorySeed(raw, roles = {}) {
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    const src = String(
        raw.src
        ?? raw.url
        ?? raw.localSrc
        ?? raw.local_src
        ?? raw.wpSrc
        ?? raw.wp_url
        ?? '',
    ).trim();
    if (src === '') {
        return null;
    }

    const seoMediaId = Math.max(0, Number(raw.seoMediaId ?? raw.seo_media_id ?? raw.media_id ?? 0) || 0) || null;
    const wpAttachmentId = Math.max(
        0,
        Number(raw.wpAttachmentId ?? raw.wp_attachment_id ?? raw.attachment_id ?? 0) || 0,
    ) || null;
    const localSrc = String(raw.localSrc ?? raw.local_src ?? '').trim();
    const canonicalUrl = normalizeUnifiedImageUrl(raw.canonical_url ?? src);
    const localPath = normalizeUnifiedLocalPath(raw.local_path || localSrc || src);
    const filename = String(
        raw.filename
        ?? (src.split('/').pop() || '').split('?')[0]
        ?? '',
    ).trim();
    const slug = String(raw.slug ?? '').trim()
        || filename.replace(/\.[^.]+$/, '');
    const alt = String(raw.alt ?? '').trim();
    const source = classifyMediaSource({
        ...raw,
        source_type: raw.source_type ?? raw.sourceType ?? raw.source ?? raw.kind,
        src,
        seoMediaId,
        wpAttachmentId,
        localSrc,
    });
    // Drop stale wp id when asset is pure Laravel storage (featured meta often stores SeoMedia PK).
    const effectiveWpAttachmentId = source === 'wordpress' ? wpAttachmentId : null;

    const seed = {
        media_id: seoMediaId,
        seoMediaId,
        seo_media_id: seoMediaId,
        wp_attachment_id: effectiveWpAttachmentId,
        wpAttachmentId: effectiveWpAttachmentId,
        source,
        source_type: source,
        url: src,
        src,
        canonical_url: canonicalUrl || src,
        local_path: localPath,
        localSrc: localSrc || (/\/storage\//i.test(src) ? src : ''),
        local_src: localSrc || (/\/storage\//i.test(src) ? src : ''),
        wpSrc: String(raw.wpSrc ?? raw.wp_url ?? '').trim(),
        wp_url: String(raw.wpSrc ?? raw.wp_url ?? '').trim(),
        filename,
        slug,
        alt,
        title: String(raw.title ?? alt).trim(),
        caption: String(raw.caption ?? '').trim(),
        align: String(raw.align || 'none').trim(),
        blockId: String(raw.blockId ?? raw.block_id ?? '').trim(),
        block_id: String(raw.blockId ?? raw.block_id ?? '').trim(),
        key: String(raw.key ?? '').trim(),
        origin: String(raw.origin ?? '').trim(),
        originLabel: String(raw.originLabel ?? raw.origin_label ?? '').trim(),
        excludeQuickFix: Boolean(raw.excludeQuickFix ?? raw.exclude_quick_fix),
        role_flags: {
            content: Boolean(roles.content),
            featured: Boolean(roles.featured),
            gallery: Boolean(roles.gallery),
        },
        occurrences: Math.max(1, Number(raw.occurrences) || 1),
    };

    seed.identity_key = unifiedImageIdentityKey(seed);

    return seed.identity_key !== '' ? seed : null;
}

/**
 * @param {Record<string, unknown>} existing
 * @param {Record<string, unknown>} incoming
 * @returns {Record<string, unknown>}
 */
function mergeInventoryItems(existing, incoming) {
    const role_flags = {
        content: Boolean(existing.role_flags?.content || incoming.role_flags?.content),
        featured: Boolean(existing.role_flags?.featured || incoming.role_flags?.featured),
        gallery: Boolean(existing.role_flags?.gallery || incoming.role_flags?.gallery),
    };

    const pick = (a, b) => {
        const left = String(a ?? '').trim();
        if (left !== '') {
            return left;
        }

        return String(b ?? '').trim();
    };

    const merged = {
        ...existing,
        ...incoming,
        role_flags,
        media_id: existing.media_id || incoming.media_id || null,
        seoMediaId: existing.seoMediaId || incoming.seoMediaId || null,
        seo_media_id: existing.seo_media_id || incoming.seo_media_id || null,
        wp_attachment_id: existing.wp_attachment_id || incoming.wp_attachment_id || null,
        wpAttachmentId: existing.wpAttachmentId || incoming.wpAttachmentId || null,
        url: pick(existing.url, incoming.url),
        src: pick(existing.src, incoming.src),
        canonical_url: pick(existing.canonical_url, incoming.canonical_url),
        local_path: pick(existing.local_path, incoming.local_path),
        localSrc: pick(existing.localSrc, incoming.localSrc),
        local_src: pick(existing.local_src, incoming.local_src),
        wpSrc: pick(existing.wpSrc, incoming.wpSrc),
        wp_url: pick(existing.wp_url, incoming.wp_url),
        filename: pick(existing.filename, incoming.filename),
        slug: pick(existing.slug, incoming.slug),
        // Prefer non-empty ALT; do not invent from title.
        alt: pick(existing.alt, incoming.alt),
        title: pick(existing.title, incoming.title),
        blockId: pick(existing.blockId, incoming.blockId),
        block_id: pick(existing.block_id, incoming.block_id),
        key: pick(existing.key, incoming.key),
        origin: pick(existing.origin, incoming.origin),
        excludeQuickFix: Boolean(existing.excludeQuickFix || incoming.excludeQuickFix),
    };

    // Content occurrences stay body-only; Featured/Gallery merge must not inflate ratio count.
    if (role_flags.content) {
        const existingContent = existing.role_flags?.content
            ? Math.max(1, Number(existing.occurrences) || 1)
            : 0;
        const incomingContent = incoming.role_flags?.content
            ? Math.max(1, Number(incoming.occurrences) || 1)
            : 0;
        merged.occurrences = Math.max(existingContent, incomingContent, 1);
    } else {
        merged.occurrences = 1;
    }

    merged.identity_key = existing.identity_key || incoming.identity_key || unifiedImageIdentityKey(merged);
    merged.source = classifyMediaSource(merged);

    return finalizeInventoryItem(merged);
}

/**
 * @param {Record<string, unknown>} item
 * @returns {Record<string, unknown>}
 */
function finalizeInventoryItem(item) {
    const requiresSlugFix = rowRequiresLocalSlugFix(item);
    const slugFixEligible = requiresSlugFix && isBulkSlugRenameSafeMedia(item) && !isWordPressProtectedMedia(item);
    const missingAlt = isPlaceholderAlt(item.alt);
    const health = {
        missing_alt: missingAlt,
        requires_slug_fix: requiresSlugFix,
        slug_fix_eligible: slugFixEligible,
        protected_from_bulk_rename: isWordPressProtectedMedia(item),
    };

    const roleLabels = [];
    if (item.role_flags?.content) {
        roleLabels.push('Content');
    }
    if (item.role_flags?.featured) {
        roleLabels.push('Featured');
    }
    if (item.role_flags?.gallery) {
        roleLabels.push('Gallery');
    }

    return {
        ...item,
        requires_slug_fix: requiresSlugFix,
        slug_fix_eligible: slugFixEligible,
        missing_alt: missingAlt,
        health,
        originLabel: roleLabels.join(' · ') || String(item.originLabel ?? '').trim(),
        origin_label: roleLabels.join(' · ') || String(item.origin_label ?? '').trim(),
    };
}

/**
 * @param {object} input
 * @param {Array<Record<string, unknown>>} [input.contentImages]
 * @param {Record<string, unknown>|null} [input.featuredImage]
 * @param {Array<Record<string, unknown>>} [input.galleryImages]
 * @param {Array<Record<string, unknown>>} [input.supplementalImages]
 * @returns {Array<Record<string, unknown>>}
 */
export function buildUnifiedArticleImagesInventory({
    contentImages = [],
    featuredImage = null,
    galleryImages = [],
    supplementalImages = [],
} = {}) {
    /** @type {Map<string, Record<string, unknown>>} */
    const byKey = new Map();

    const upsert = (seed) => {
        if (!seed) {
            return;
        }
        const key = seed.identity_key;
        if (!key) {
            return;
        }
        const existing = byKey.get(key);
        if (!existing) {
            byKey.set(key, finalizeInventoryItem(seed));
            return;
        }
        byKey.set(key, mergeInventoryItems(existing, seed));
    };

    (Array.isArray(contentImages) ? contentImages : []).forEach((row) => {
        upsert(normalizeInventorySeed(row, { content: true }));
    });

    if (featuredImage) {
        upsert(normalizeInventorySeed(featuredImage, { featured: true }));
    }

    (Array.isArray(galleryImages) ? galleryImages : []).forEach((row) => {
        upsert(normalizeInventorySeed(row, { gallery: true }));
    });

    (Array.isArray(supplementalImages) ? supplementalImages : []).forEach((row) => {
        const origin = String(row?.origin ?? '').trim().toLowerCase();
        upsert(normalizeInventorySeed(row, {
            featured: origin === 'featured',
            gallery: origin === 'gallery',
            content: origin !== 'featured' && origin !== 'gallery' && Boolean(
                String(row?.blockId ?? row?.block_id ?? '').trim(),
            ),
        }));
    });

    return Array.from(byKey.values());
}

/**
 * Panel / health row shape (compatible with ArticleImagesTab + analyzeImageRowsHealth).
 *
 * @param {Array<Record<string, unknown>>} inventory
 * @returns {Array<Record<string, unknown>>}
 */
export function unifiedInventoryToImageRows(inventory) {
    return (Array.isArray(inventory) ? inventory : []).map((item, index) => ({
        key: item.key || item.identity_key || `unified-${index}`,
        identity_key: item.identity_key,
        blockId: item.blockId || '',
        block_id: item.block_id || '',
        wpAttachmentId: item.wpAttachmentId || null,
        wp_attachment_id: item.wp_attachment_id || null,
        seoMediaId: item.seoMediaId || null,
        seo_media_id: item.seo_media_id || null,
        media_id: item.media_id || null,
        src: item.src || item.url || '',
        url: item.url || item.src || '',
        canonical_url: item.canonical_url || '',
        wpSrc: item.wpSrc || '',
        wp_url: item.wp_url || '',
        localSrc: item.localSrc || '',
        local_src: item.local_src || '',
        local_path: item.local_path || '',
        slug: item.slug || '',
        filename: item.filename || '',
        alt: item.alt || '',
        title: item.title || '',
        caption: item.caption || '',
        align: item.align || 'none',
        origin: item.origin || '',
        originLabel: item.originLabel || '',
        origin_label: item.origin_label || '',
        role_flags: item.role_flags || { content: false, featured: false, gallery: false },
        source: item.source || 'unknown',
        occurrences: item.occurrences || 1,
        requires_slug_fix: Boolean(item.requires_slug_fix),
        slug_fix_eligible: Boolean(item.slug_fix_eligible),
        missing_alt: Boolean(item.missing_alt),
        health: item.health || null,
        excludeQuickFix: Boolean(item.excludeQuickFix),
    }));
}

/**
 * Local Fix Slug All candidates from unified inventory (never WP).
 *
 * @param {Array<Record<string, unknown>>} inventory
 * @returns {Array<Record<string, unknown>>}
 */
export function unifiedInventorySlugFixCandidates(inventory) {
    return unifiedInventoryToImageRows(inventory).filter((row) => (
        !row.excludeQuickFix
        && Boolean(row.slug_fix_eligible)
        && !isWordPressProtectedMedia(row)
        && isBulkSlugRenameSafeMedia(row)
    ));
}

/**
 * Content-only occurrence count for image ratio (never Featured/Gallery-only).
 *
 * @param {Array<Record<string, unknown>>} inventory
 * @returns {number}
 */
export function countContentImageOccurrencesFromInventory(inventory) {
    return (Array.isArray(inventory) ? inventory : []).reduce((sum, item) => {
        if (!item?.role_flags?.content) {
            return sum;
        }

        return sum + Math.max(1, Number(item.occurrences) || 1);
    }, 0);
}
