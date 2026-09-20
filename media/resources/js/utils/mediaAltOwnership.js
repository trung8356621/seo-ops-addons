/**
 * Contextual ALT ownership for Image Assistant.
 * Role is usage-context — never a permanent property of attachment_id.
 */

export const MEDIA_ROLE_ARTICLE_CONTENT = 'article_content';
export const MEDIA_ROLE_FEATURED_IMAGE = 'featured_image';
export const MEDIA_ROLE_PRODUCT_GALLERY = 'product_gallery';

export const ALT_OWNER_ARTICLE_HTML = 'article_html';
export const ALT_OWNER_WORDPRESS = 'wordpress';

export const WP_ALT_SYNC_ALLOWED = 'allowed';
export const WP_ALT_SYNC_FORBIDDEN = 'forbidden';

export const MISSING_ALT_ARTICLE_CONTENT = 'article_content_missing_alt';
export const MISSING_ALT_WP_FEATURED = 'wp_featured_missing_alt';
export const MISSING_ALT_WP_PRODUCT_GALLERY = 'wp_product_gallery_missing_alt';
export const MISSING_ALT_WP_UNRESOLVABLE = 'wp_media_unresolvable';

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {{ content: boolean, featured: boolean, gallery: boolean }}
 */
export function resolveRoleFlags(row) {
    const flags = row?.role_flags && typeof row.role_flags === 'object'
        ? row.role_flags
        : null;

    if (flags) {
        return {
            content: Boolean(flags.content),
            featured: Boolean(flags.featured),
            gallery: Boolean(flags.gallery),
        };
    }

    const origin = String(row?.origin ?? '').trim().toLowerCase();
    const mediaRole = String(row?.media_role ?? row?.mediaRole ?? '').trim().toLowerCase();

    return {
        content: mediaRole === MEDIA_ROLE_ARTICLE_CONTENT
            || Boolean(String(row?.blockId ?? row?.block_id ?? '').trim()),
        featured: mediaRole === MEDIA_ROLE_FEATURED_IMAGE || origin === 'featured',
        gallery: mediaRole === MEDIA_ROLE_PRODUCT_GALLERY || origin === 'gallery',
    };
}

/**
 * Primary role for provenance / display.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string}
 */
export function resolveMediaRole(row) {
    const explicit = String(row?.media_role ?? row?.mediaRole ?? '').trim().toLowerCase();
    if (
        explicit === MEDIA_ROLE_ARTICLE_CONTENT
        || explicit === MEDIA_ROLE_FEATURED_IMAGE
        || explicit === MEDIA_ROLE_PRODUCT_GALLERY
    ) {
        return explicit;
    }

    const flags = resolveRoleFlags(row);
    if (flags.content) {
        return MEDIA_ROLE_ARTICLE_CONTENT;
    }
    if (flags.featured) {
        return MEDIA_ROLE_FEATURED_IMAGE;
    }
    if (flags.gallery) {
        return MEDIA_ROLE_PRODUCT_GALLERY;
    }

    return MEDIA_ROLE_ARTICLE_CONTENT;
}

/**
 * @param {string} mediaRole
 * @returns {string}
 */
export function resolveAltOwner(mediaRole) {
    if (
        mediaRole === MEDIA_ROLE_FEATURED_IMAGE
        || mediaRole === MEDIA_ROLE_PRODUCT_GALLERY
    ) {
        return ALT_OWNER_WORDPRESS;
    }

    return ALT_OWNER_ARTICLE_HTML;
}

/**
 * @param {string} mediaRole
 * @returns {string}
 */
export function resolveWpAltSyncPolicy(mediaRole) {
    return mediaRole === MEDIA_ROLE_FEATURED_IMAGE || mediaRole === MEDIA_ROLE_PRODUCT_GALLERY
        ? WP_ALT_SYNC_ALLOWED
        : WP_ALT_SYNC_FORBIDDEN;
}

/**
 * Role allowed for WordPress ALT mutation from this inventory row, or null.
 * Featured/gallery flags win for staging even on dual-role rows.
 * Content-only (including body images with attachment_id) → null.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string|null}
 */
export function resolveWpAltMutationRole(row) {
    const flags = resolveRoleFlags(row);
    if (flags.featured) {
        return MEDIA_ROLE_FEATURED_IMAGE;
    }
    if (flags.gallery) {
        return MEDIA_ROLE_PRODUCT_GALLERY;
    }

    return null;
}

/**
 * Featured/gallery-only cards — WordPress owns rendered ALT.
 * Dual-role (also content) keeps article HTML editable.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function isWordPressManagedAltUi(row) {
    const flags = resolveRoleFlags(row);
    return (flags.featured || flags.gallery) && !flags.content;
}

/**
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {boolean}
 */
export function shouldShowEditableArticleAlt(row) {
    return !isWordPressManagedAltUi(row);
}

/**
 * @param {boolean} missingAlt
 * @param {Record<string, unknown>|null|undefined} row
 * @returns {string|null}
 */
export function resolveMissingAltKind(missingAlt, row) {
    if (!missingAlt) {
        return null;
    }

    const flags = resolveRoleFlags(row);
    const wpRole = resolveWpAltMutationRole(row);
    const attachmentId = Math.max(
        0,
        Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? row?.attachment_id ?? 0) || 0,
    );

    if (flags.content) {
        return MISSING_ALT_ARTICLE_CONTENT;
    }

    if (wpRole === MEDIA_ROLE_FEATURED_IMAGE) {
        return attachmentId > 0 ? MISSING_ALT_WP_FEATURED : MISSING_ALT_WP_UNRESOLVABLE;
    }

    if (wpRole === MEDIA_ROLE_PRODUCT_GALLERY) {
        return attachmentId > 0 ? MISSING_ALT_WP_PRODUCT_GALLERY : MISSING_ALT_WP_UNRESOLVABLE;
    }

    return MISSING_ALT_ARTICLE_CONTENT;
}

/**
 * Attach ownership provenance to an inventory item.
 *
 * @param {Record<string, unknown>} item
 * @returns {Record<string, unknown>}
 */
export function attachAltOwnershipProvenance(item) {
    const mediaRole = resolveMediaRole(item);
    const altOwner = resolveAltOwner(mediaRole);
    const wpAltSync = resolveWpAltSyncPolicy(mediaRole);
    const missingAlt = Boolean(item.missing_alt);
    const missingAltKind = resolveMissingAltKind(missingAlt, { ...item, media_role: mediaRole });

    return {
        ...item,
        media_role: mediaRole,
        mediaRole,
        alt_owner: altOwner,
        altOwner,
        wp_alt_sync: wpAltSync,
        wpAltSync,
        missing_alt_kind: missingAltKind,
        missingAltKind,
    };
}

/**
 * Build a staged WP ALT payload, or null when mutation is forbidden / not needed.
 * Default: fill only when current ALT is empty/placeholder.
 *
 * @param {Record<string, unknown>|null|undefined} row
 * @param {string} desiredAlt
 * @param {{ forceOverwrite?: boolean }} [options]
 * @returns {Record<string, unknown>|null}
 */
export function buildStagedWpAltItem(row, desiredAlt, options = {}) {
    const phrase = String(desiredAlt ?? '').trim();
    const mediaRole = resolveWpAltMutationRole(row);
    const attachmentId = Math.max(
        0,
        Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? row?.attachment_id ?? 0) || 0,
    );

    if (!phrase || !mediaRole || attachmentId <= 0) {
        return null;
    }

    const currentAlt = String(row?.alt ?? '').trim();
    const currentEmpty = currentAlt === '' || /^(image|img|photo|untitled)$/i.test(currentAlt);
    if (!currentEmpty && !options.forceOverwrite) {
        return null;
    }

    return {
        attachment_id: attachmentId,
        media_role: mediaRole,
        desired_alt: phrase,
        alt_text: phrase,
        title: phrase,
        fill_only_if_empty: !options.forceOverwrite,
        alt_owner: ALT_OWNER_WORDPRESS,
    };
}
