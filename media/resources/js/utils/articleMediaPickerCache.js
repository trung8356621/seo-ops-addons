const STORAGE_PREFIX = 'seo-article-media-picker:v2';
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
const CACHEABLE_TABS = new Set(['original', 'local']);

function cacheScopeSuffix() {
    const picker = typeof window !== 'undefined' ? window.__SEO_ARTICLE_MEDIA_PICKER__ : null;
    const scope = picker && typeof picker === 'object' ? String(picker.cacheScope || '') : '';

    return scope !== '' ? scope : 'default';
}

function buildCacheKey(siteId, tab, page) {
    return `${STORAGE_PREFIX}:${cacheScopeSuffix()}:${Number(siteId)}:${String(tab)}:${Number(page)}`;
}

function isCacheableTab(tab) {
    return CACHEABLE_TABS.has(String(tab));
}

function buildStoredPayload(detail, tab, page) {
    return {
        tab: String(detail.tab || tab),
        page: Math.max(1, Number(detail.page || page)),
        totalPages: Math.max(1, Number(detail.totalPages || 1)),
        error: detail.error ? String(detail.error) : null,
        images: detail.images,
        cachedAt: Date.now(),
    };
}

/** Stable fingerprint — bỏ qua cachedAt khi so sánh. */
function payloadFingerprint(payload) {
    const images = Array.isArray(payload?.images) ? payload.images : [];
    const rows = images.map((row) => ({
        k: String(row.picker_key || ''),
        wp: Number(row.wp_attachment_id || 0),
        seo: Number(row.seo_media_id || 0),
        url: String(row.url || ''),
        thumb: String(row.thumb_url || ''),
        alt: String(row.alt || ''),
        slug: String(row.slug || ''),
        type: String(row.media_type || 'image'),
    }));

    return JSON.stringify({
        tab: String(payload?.tab || ''),
        page: Number(payload?.page || 1),
        totalPages: Number(payload?.totalPages || 1),
        error: payload?.error || null,
        images: rows,
    });
}

function readStoredRaw(siteId, tab, page) {
    try {
        const raw = localStorage.getItem(buildCacheKey(siteId, tab, page));

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

/**
 * @param {number} siteId
 * @param {'original'|'local'|string} tab
 * @param {number} page
 * @returns {object|null}
 */
export function readArticleMediaPickerCache(siteId, tab, page) {
    if (!isCacheableTab(tab) || Number(siteId) <= 0) {
        return null;
    }

    const parsed = readStoredRaw(siteId, tab, page);
    if (!parsed || typeof parsed !== 'object') {
        return null;
    }

    const cachedAt = Number(parsed.cachedAt || 0);
    if (cachedAt > 0 && Date.now() - cachedAt > MAX_AGE_MS) {
        localStorage.removeItem(buildCacheKey(siteId, tab, page));

        return null;
    }

    if (!Array.isArray(parsed.images)) {
        return null;
    }

    return {
        tab: String(parsed.tab || tab),
        page: Math.max(1, Number(parsed.page || page)),
        totalPages: Math.max(1, Number(parsed.totalPages || 1)),
        error: parsed.error ? String(parsed.error) : null,
        images: parsed.images,
        catalog: null,
        cachedAt,
    };
}

/**
 * Lưu sau fetch thành công: luôn ghi lần đầu; các lần sau chỉ ghi khi dữ liệu khác cache hiện có.
 *
 * @returns {boolean} true nếu đã ghi localStorage
 */
export function writeArticleMediaPickerCache(siteId, tab, page, detail) {
    if (!isCacheableTab(tab) || Number(siteId) <= 0 || !detail || typeof detail !== 'object') {
        return false;
    }

    if (!Array.isArray(detail.images)) {
        return false;
    }

    const resolvedPage = Math.max(1, Number(detail.page || page));
    const payload = buildStoredPayload(detail, tab, resolvedPage);
    const existing = readStoredRaw(siteId, tab, resolvedPage);

    if (existing && payloadFingerprint(existing) === payloadFingerprint(payload)) {
        return false;
    }

    try {
        localStorage.setItem(buildCacheKey(siteId, tab, resolvedPage), JSON.stringify(payload));

        return true;
    } catch {
        return false;
    }
}

export function isArticleMediaPickerCacheableTab(tab) {
    return isCacheableTab(tab);
}

export function clearArticleMediaPickerCache(siteId) {
    const id = Number(siteId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    const prefix = `${STORAGE_PREFIX}:${cacheScopeSuffix()}:${id}:`;
    const keys = [];
    for (let index = 0; index < localStorage.length; index += 1) {
        const key = localStorage.key(index);
        if (key?.startsWith(prefix)) {
            keys.push(key);
        }
    }
    keys.forEach((key) => localStorage.removeItem(key));

    window.dispatchEvent(new CustomEvent('seo-article-media-picker-cache-invalidated', {
        detail: { siteId: id },
    }));
}
