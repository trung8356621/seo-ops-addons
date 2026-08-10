/**
 * Canonical media source classification for Article Editor / Media Library.
 * Keep free of imports from articleImagesUtils to avoid circular deps.
 */

function resolveWpAttachmentId(row) {
    return Math.max(
        0,
        Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? row?.attachment_id ?? 0) || 0,
    );
}

function resolveSeoMediaId(row) {
    return Math.max(0, Number(row?.seoMediaId ?? row?.seo_media_id ?? row?.media_id ?? 0) || 0);
}

/**
 * Laravel SEO media path evidence (relative or absolute https host/storage/...).
 *
 * @param {unknown} src
 * @returns {boolean}
 */
export function isLocalSeoMediaSrc(src) {
    const value = String(src ?? '').trim();
    if (value === '') {
        return false;
    }
    if (value.startsWith('blob:')) {
        return true;
    }
    // Never treat WP uploads as local.
    if (/\/wp-content\/uploads\//i.test(value)) {
        return false;
    }

    return /\/storage\/uploads\/seo_media\//i.test(value)
        || /\/storage\/seo\//i.test(value)
        || /\/seo-media\//i.test(value)
        || /\/storage\//i.test(value)
        || /^\/?storage\//i.test(value);
}

/**
 * WordPress URL evidence only — never treat bare https:// as WP
 * (Laravel absolute media URLs would otherwise all look “protected”).
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
function hasTrustedWordPressUrl(row) {
    const candidates = [
        row?.wpSrc,
        row?.wp_url,
        row?.src,
        row?.url,
        row?.localSrc,
        row?.local_src,
    ];
    for (const candidate of candidates) {
        const value = String(candidate ?? '').trim();
        if (value !== '' && /\/wp-content\/uploads\//i.test(value)) {
            return true;
        }
    }

    return false;
}

/**
 * Primary display URL evidence. After WP sync, an image can still carry local
 * seo_media metadata, but src/url already points at the WP attachment.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
function hasPrimaryWordPressUrl(row) {
    const candidates = [row?.src, row?.url];
    for (const candidate of candidates) {
        const value = String(candidate ?? '').trim();
        if (value !== '' && /\/wp-content\/uploads\//i.test(value)) {
            return true;
        }
    }

    return false;
}

function resolveExplicitSourceType(row) {
    return String(
        row?.source_type
        ?? row?.sourceType
        ?? row?.source
        ?? row?.kind
        ?? '',
    ).trim().toLowerCase();
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {'wordpress'|'local'|'generated'|'uploaded'|'unknown'}
 */
export function classifyMediaSource(row) {
    if (!row || typeof row !== 'object') {
        return 'unknown';
    }

    const sourceType = resolveExplicitSourceType(row);
    const primaryWpUrl = hasPrimaryWordPressUrl(row);
    if (primaryWpUrl) {
        return 'wordpress';
    }

    if (sourceType === 'wordpress' || sourceType === 'wp') {
        // Explicit WP label still loses to pure Laravel storage URL without WP uploads path
        // when a stale attachment id was written into featured meta (seo media PK).
        if (!hasTrustedWordPressUrl(row) && hasLocalLaravelEvidence(row)) {
            return classifyLocalKind(row);
        }

        return 'wordpress';
    }
    if (sourceType === 'generated' || sourceType === 'ai') {
        return 'generated';
    }
    if (sourceType === 'uploaded' || sourceType === 'upload') {
        return 'uploaded';
    }
    if (sourceType === 'local' || sourceType === 'laravel' || sourceType === 'internal') {
        return 'local';
    }

    if (hasTrustedWordPressUrl(row)) {
        return 'wordpress';
    }

    // Local/SEO media evidence wins over bare/stale wp_attachment_id
    // (featured meta often stores SeoMedia PK in wp_featured_attachment_id before WP sync).
    if (hasLocalLaravelEvidence(row)) {
        return classifyLocalKind(row);
    }

    const wpAttachmentId = resolveWpAttachmentId(row);
    if (wpAttachmentId > 0) {
        return 'wordpress';
    }

    return 'unknown';
}

/**
 * @param {Record<string, unknown>} row
 * @returns {boolean}
 */
function hasLocalLaravelEvidence(row) {
    const seoMediaId = resolveSeoMediaId(row);
    const src = String(row.src ?? row.url ?? '').trim();
    const localSrc = String(row.localSrc ?? row.local_src ?? '').trim();

    return isLocalSeoMediaSrc(src)
        || isLocalSeoMediaSrc(localSrc)
        || seoMediaId > 0
        || Boolean(row.local_media || row.localMedia)
        || Boolean(row.pendingBinaryVersion || row.pending_binary_version)
        || Boolean(row.mediaVersion || row.media_version || row.revision_id || row.version_id);
}

/**
 * @param {Record<string, unknown>} row
 * @returns {'local'|'generated'|'uploaded'}
 */
function classifyLocalKind(row) {
    if (String(row.generation_status ?? row.ai_job_id ?? '').trim() !== '') {
        return 'generated';
    }
    const seoMediaId = resolveSeoMediaId(row);

    return seoMediaId > 0 ? 'local' : 'uploaded';
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isWordPressProtectedMedia(row) {
    return classifyMediaSource(row) === 'wordpress' && !isLaravelManagedMedia(row);
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isLaravelManagedMedia(row) {
    return Boolean(row && typeof row === 'object' && hasLocalLaravelEvidence(row));
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isBulkSlugRenameSafeMedia(row) {
    if (!row || isWordPressProtectedMedia(row)) {
        return false;
    }

    const source = classifyMediaSource(row);
    return source === 'local' || source === 'generated' || source === 'uploaded';
}
