/**
 * WordPress often stores scaled URLs (-480x393.jpg). Article content should use full/original.
 */
export function isLocalSeoMediaSrc(url) {
    return String(url ?? '').toLowerCase().includes('/storage/uploads/seo_media/');
}

export function isWordPressScaledImageUrl(url) {
    const value = String(url ?? '').trim();
    if (!value || isLocalSeoMediaSrc(value)) {
        return false;
    }

    try {
        const path = new URL(value, window.location.origin).pathname;

        return /-\d+x\d+\.(jpe?g|png|gif|webp)$/i.test(path);
    } catch {
        return /-\d+x\d+\.(jpe?g|png|gif|webp)(\?|$)/i.test(value);
    }
}

export function resolveFullWordPressImageUrl(url) {
    const value = String(url ?? '').trim();
    if (!value || isLocalSeoMediaSrc(value)) {
        return value;
    }

    try {
        const parsed = new URL(value, window.location.origin);
        const nextPath = parsed.pathname.replace(/-(\d+)x(\d+)(?=\.(jpe?g|png|gif|webp)$)/i, '');
        if (nextPath === parsed.pathname) {
            return value;
        }

        parsed.pathname = nextPath;

        return parsed.href;
    } catch {
        return value.replace(/-(\d+)x(\d+)(?=\.(jpe?g|png|gif|webp)(\?|$))/i, '');
    }
}

/**
 * Prefer local Laravel copy; otherwise full-size WordPress URL.
 */
export function resolveArticleImageSrc(row) {
    if (!row || typeof row !== 'object') {
        return '';
    }

    // src là canonical sau Fix slug / finalize — ưu tiên trước localSrc (có thể stale).
    const src = String(row.src ?? '').trim();
    if (src) {
        return isLocalSeoMediaSrc(src) ? src : resolveFullWordPressImageUrl(src);
    }

    const local = String(row.localSrc ?? row.local_src ?? '').trim();
    if (local) {
        return local;
    }

    const wp = String(row.wpSrc ?? row.wp_src ?? row.wp_url ?? '').trim();

    return resolveFullWordPressImageUrl(wp);
}

/**
 * True when the image can use WordPress size variants (thumbnail, medium, large…).
 */
export function supportsWordPressImageSizes(imageOrRow) {
    if (!imageOrRow || typeof imageOrRow !== 'object') {
        return false;
    }

    const wpId = Number(imageOrRow.wpAttachmentId ?? imageOrRow.wp_attachment_id ?? 0);
    if (wpId > 0) {
        return true;
    }

    const wpSrc = String(imageOrRow.wpSrc ?? imageOrRow.wp_src ?? imageOrRow.wp_url ?? '').trim();
    if (wpSrc && !isLocalSeoMediaSrc(wpSrc)) {
        return true;
    }

    const src = String(imageOrRow.src ?? '').trim();

    return src !== '' && !isLocalSeoMediaSrc(src);
}

/**
 * URL nhỏ cho lưới chọn ảnh (giữ biến thể -WxH nếu đã có).
 */
export function toPreviewImageUrl(url) {
    const value = String(url ?? '').trim();
    if (!value || isLocalSeoMediaSrc(value)) {
        return value;
    }

    if (isWordPressScaledImageUrl(value)) {
        return value;
    }

    try {
        const parsed = new URL(value, window.location.origin);
        const match = parsed.pathname.match(/^(.*)\.([a-z0-9]+)$/i);
        if (!match) {
            return value;
        }

        parsed.pathname = `${match[1]}-300x300.${match[2]}`;

        return parsed.href;
    } catch {
        return value.replace(/(\.[a-z0-9]+)(\?.*)?$/i, '-300x300$1$2');
    }
}

export function resolveWordPressBaseUrl(imageOrRow) {
    if (!imageOrRow || typeof imageOrRow !== 'object') {
        return '';
    }

    const wpSrc = String(imageOrRow.wpSrc ?? imageOrRow.wp_src ?? imageOrRow.wp_url ?? '').trim();
    if (wpSrc && !isLocalSeoMediaSrc(wpSrc)) {
        return resolveFullWordPressImageUrl(wpSrc);
    }

    const src = String(imageOrRow.src ?? '').trim();
    if (src && !isLocalSeoMediaSrc(src)) {
        return resolveFullWordPressImageUrl(src);
    }

    return '';
}
