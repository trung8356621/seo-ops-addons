import { isLocalSeoMediaSrc, resolveFullWordPressImageUrl, resolveWordPressBaseUrl, supportsWordPressImageSizes } from './wordpressImageUrl';

/** Standard WordPress registered image sizes (Settings → Media). */
export const WP_IMAGE_SIZE_OPTIONS = [
    { id: 'full', labelKey: 'wp_image_size_full' },
    { id: 'large', labelKey: 'wp_image_size_large' },
    { id: 'medium_large', labelKey: 'wp_image_size_medium_large' },
    { id: 'medium', labelKey: 'wp_image_size_medium' },
    { id: 'thumbnail', labelKey: 'wp_image_size_thumbnail' },
];

const DEFAULT_SIZE_DIMENSIONS = {
    thumbnail: [150, 150],
    medium: [300, 300],
    medium_large: [768, 0],
    large: [1024, 1024],
};

function pathWithDimensions(pathname, width, height) {
    const base = resolveFullWordPressImageUrl(pathname);
    try {
        const url = new URL(base, window.location.origin);
        const extMatch = url.pathname.match(/(\.(jpe?g|png|gif|webp))$/i);
        if (!extMatch) {
            return base;
        }

        const ext = extMatch[1];
        const stem = url.pathname.slice(0, -ext.length);
        const suffix = height > 0 ? `-${width}x${height}` : `-${width}x${width}`;

        url.pathname = `${stem}${suffix}${ext}`;

        return url.href;
    } catch {
        const extMatch = String(base).match(/(\.(jpe?g|png|gif|webp))(\?|$)/i);
        if (!extMatch) {
            return base;
        }

        const ext = extMatch[1];
        const rest = String(base).slice(0, extMatch.index);
        const suffix = height > 0 ? `-${width}x${height}` : `-${width}x${width}`;

        return `${rest}${suffix}${ext}${extMatch[3] === '?' ? String(base).slice(extMatch.index + ext.length) : ''}`;
    }
}

/**
 * Guess WP size slug from a scaled URL suffix.
 */
export function detectWordPressImageSize(url) {
    const value = String(url ?? '').trim();
    if (!value || isLocalSeoMediaSrc(value)) {
        return 'full';
    }

    const full = resolveFullWordPressImageUrl(value);
    if (full === value) {
        return 'full';
    }

    let width = 0;
    let height = 0;

    try {
        const path = new URL(value, window.location.origin).pathname;
        const match = path.match(/-(\d+)x(\d+)\.(jpe?g|png|gif|webp)$/i);
        if (match) {
            width = Number(match[1]);
            height = Number(match[2]);
        }
    } catch {
        const match = value.match(/-(\d+)x(\d+)\.(jpe?g|png|gif|webp)(\?|$)/i);
        if (match) {
            width = Number(match[1]);
            height = Number(match[2]);
        }
    }

    if (width <= 0) {
        return 'full';
    }

    if (width <= 150 && height <= 150) {
        return 'thumbnail';
    }
    if (width <= 300 && height <= 300) {
        return 'medium';
    }
    if (width <= 768) {
        return 'medium_large';
    }
    if (width <= 1024) {
        return 'large';
    }

    return 'full';
}

/**
 * Resolve display src for a WordPress image size.
 *
 * @param {string} fullUrl Original / full-size URL
 * @param {string} size WP size slug
 * @param {Record<string, string>|null|undefined} wpSizes Optional map from WP media_details.sizes
 */
export function resolveWordPressImageUrlForSize(fullUrl, size, wpSizes = null) {
    const base = resolveFullWordPressImageUrl(String(fullUrl ?? '').trim());
    if (!base || isLocalSeoMediaSrc(base)) {
        return base;
    }

    const slug = String(size ?? 'full').trim() || 'full';
    if (slug === 'full') {
        return base;
    }

    const mapped = wpSizes?.[slug];
    if (mapped && String(mapped).trim() !== '') {
        return String(mapped).trim();
    }

    const dims = DEFAULT_SIZE_DIMENSIONS[slug];
    if (!dims) {
        return base;
    }

    const [width, height] = dims;

    return pathWithDimensions(base, width, height);
}

export function applyWordPressImageSize(image, size) {
    if (!image || typeof image !== 'object') {
        return image;
    }

    const wpSrc = resolveWordPressBaseUrl(image);
    if (!wpSrc) {
        return {
            ...image,
            size: 'full',
        };
    }

    const nextSize = String(size ?? 'full').trim() || 'full';
    const nextSrc = resolveWordPressImageUrlForSize(wpSrc, nextSize, image.wpSizes ?? image.wp_sizes);

    return {
        ...image,
        wpSrc,
        localSrc: String(image.localSrc ?? image.local_src ?? '').trim() || undefined,
        size: nextSize,
        src: nextSrc,
        width: null,
        height: null,
    };
}
