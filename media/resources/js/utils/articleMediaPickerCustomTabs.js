const TABS_PREFIX = 'seo-article-media-picker-custom-tabs:v1';
const BLANK_TABS_PREFIX = 'seo-article-media-picker-custom-blank-tabs:v1';
const STAGED_PREFIX = 'seo-article-media-picker-custom-staged:v1';
const FETCH_PREFIX = 'seo-article-media-picker-custom-fetch:v1';
const COOKIE_NAME = 'seo_amp_custom_wp_tabs_v1';
const COOKIE_VERSION = 1;
const COOKIE_MAX_AGE_SEC = 365 * 24 * 60 * 60;
const MAX_FETCH_AGE_MS = 7 * 24 * 60 * 60 * 1000;
const MAX_TABS_PER_DOMAIN = 30;
const MAX_KEYWORD_LENGTH = 80;

function legacyTabsKey(articleId) {
    return `${TABS_PREFIX}:${Number(articleId)}`;
}

function blankTabsKey(articleId) {
    return `${BLANK_TABS_PREFIX}:${Number(articleId)}`;
}

function stagedKey(articleId, tabId) {
    return `${STAGED_PREFIX}:${Number(articleId)}:${String(tabId)}`;
}

function fetchKey(articleId, tabId, page, keyword) {
    const normalized = String(keyword || '').trim().toLowerCase();

    return `${FETCH_PREFIX}:${Number(articleId)}:${String(tabId)}:${Number(page)}:${normalized}`;
}

function readJson(key) {
    try {
        const raw = localStorage.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

/**
 * Chuẩn hóa domain bài viết thành cookie key.
 * @param {unknown} input
 * @returns {string}
 */
export function normalizeArticleDomain(input) {
    let value = String(input || '').trim().toLowerCase();
    if (value === '') {
        return '';
    }

    value = value.replace(/^https?:\/\//, '');
    value = value.replace(/^www\./, '');
    value = value.split('/')[0] || '';
    value = value.split('?')[0] || '';
    value = value.split('#')[0] || '';
    value = value.trim().replace(/\.+$/, '');

    return value;
}

function readCookieRaw(name) {
    if (typeof document === 'undefined') {
        return null;
    }

    const encodedName = encodeURIComponent(name);
    const parts = String(document.cookie || '').split(';');

    for (let index = 0; index < parts.length; index += 1) {
        const part = parts[index].trim();
        if (!part.startsWith(`${encodedName}=`) && !part.startsWith(`${name}=`)) {
            continue;
        }

        const raw = part.slice(part.indexOf('=') + 1);

        try {
            return decodeURIComponent(raw);
        } catch {
            return raw;
        }
    }

    return null;
}

function writeCookieRaw(name, value, maxAgeSec = COOKIE_MAX_AGE_SEC) {
    if (typeof document === 'undefined') {
        return false;
    }

    try {
        const encoded = encodeURIComponent(value);
        // ~3500 chars practical budget for one cookie value
        if (encoded.length > 3500) {
            return false;
        }

        document.cookie = [
            `${encodeURIComponent(name)}=${encoded}`,
            'path=/',
            `max-age=${Math.max(0, Number(maxAgeSec) || 0)}`,
            'SameSite=Lax',
        ].join('; ');

        return true;
    } catch {
        return false;
    }
}

function emptyCookieStore() {
    return {
        version: COOKIE_VERSION,
        domains: {},
    };
}

/**
 * @returns {{version: number, domains: Record<string, string[]>}}
 */
function readCookieStore() {
    const raw = readCookieRaw(COOKIE_NAME);
    if (!raw) {
        return emptyCookieStore();
    }

    try {
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            return emptyCookieStore();
        }

        const domains = parsed.domains && typeof parsed.domains === 'object' && !Array.isArray(parsed.domains)
            ? parsed.domains
            : {};
        const normalizedDomains = {};

        Object.keys(domains).forEach((key) => {
            const domain = normalizeArticleDomain(key);
            if (domain === '') {
                return;
            }

            const list = Array.isArray(domains[key]) ? domains[key] : [];
            const keywords = [];
            const seen = new Set();

            list.forEach((item) => {
                const keyword = String(item || '').trim().slice(0, MAX_KEYWORD_LENGTH);
                if (keyword === '') {
                    return;
                }

                const lower = keyword.toLowerCase();
                if (seen.has(lower)) {
                    return;
                }

                seen.add(lower);
                keywords.push(keyword);
            });

            normalizedDomains[domain] = keywords.slice(0, MAX_TABS_PER_DOMAIN);
        });

        return {
            version: Number(parsed.version) === COOKIE_VERSION ? COOKIE_VERSION : COOKIE_VERSION,
            domains: normalizedDomains,
        };
    } catch {
        return emptyCookieStore();
    }
}

function writeCookieStore(store) {
    const payload = {
        version: COOKIE_VERSION,
        domains: store?.domains && typeof store.domains === 'object' ? store.domains : {},
    };

    return writeCookieRaw(COOKIE_NAME, JSON.stringify(payload));
}

function keywordTabId(keyword) {
    return `kw:${encodeURIComponent(String(keyword || '').trim().toLowerCase())}`;
}

function tabFromKeyword(keyword) {
    const normalized = String(keyword || '').trim();
    if (normalized === '') {
        return null;
    }

    return {
        id: keywordTabId(normalized),
        keyword: normalized,
        label: truncateLabel(normalized),
        blank: false,
        createdAt: 0,
    };
}

function loadBlankPickerTabs(articleId) {
    const id = Number(articleId);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const parsed = readJson(blankTabsKey(id));
    if (!parsed || !Array.isArray(parsed.tabs)) {
        return [];
    }

    return parsed.tabs
        .map((row) => ({
            id: String(row?.id || '').trim(),
            keyword: '',
            label: String(row?.label || '').trim() || 'Nhóm trống',
            blank: true,
            createdAt: Number(row?.createdAt || 0),
        }))
        .filter((row) => row.id !== '');
}

function saveBlankPickerTabs(articleId, tabs) {
    const id = Number(articleId);
    if (!Number.isFinite(id) || id <= 0) {
        return false;
    }

    return writeJson(blankTabsKey(id), {
        tabs: Array.isArray(tabs) ? tabs : [],
        updatedAt: Date.now(),
    });
}

/**
 * Migrate localStorage cũ (theo articleId) → cookie namespace domain bài viết hiện tại.
 * Chỉ chạy khi còn legacy key; xóa legacy sau khi ghi cookie thành công.
 */
function migrateLegacyTabsForArticle(domain, articleId) {
    const normalizedDomain = normalizeArticleDomain(domain);
    const id = Number(articleId);
    if (normalizedDomain === '' || !Number.isFinite(id) || id <= 0) {
        return;
    }

    const legacyKey = legacyTabsKey(id);
    const legacy = readJson(legacyKey);
    if (!legacy || !Array.isArray(legacy.tabs) || legacy.tabs.length === 0) {
        return;
    }

    const keywordTabs = [];
    const blankTabs = [];
    const seenKeywords = new Set();

    legacy.tabs.forEach((row) => {
        if (row?.blank === true) {
            blankTabs.push({
                id: String(row?.id || '').trim(),
                keyword: '',
                label: String(row?.label || '').trim() || 'Nhóm trống',
                blank: true,
                createdAt: Number(row?.createdAt || 0),
            });

            return;
        }

        const keyword = String(row?.keyword || '').trim().slice(0, MAX_KEYWORD_LENGTH);
        if (keyword === '') {
            return;
        }

        const lower = keyword.toLowerCase();
        if (seenKeywords.has(lower)) {
            return;
        }

        seenKeywords.add(lower);
        keywordTabs.push(keyword);
    });

    const store = readCookieStore();
    const existing = Array.isArray(store.domains[normalizedDomain])
        ? [...store.domains[normalizedDomain]]
        : [];
    const mergedSeen = new Set(existing.map((item) => item.toLowerCase()));

    keywordTabs.forEach((keyword) => {
        const lower = keyword.toLowerCase();
        if (mergedSeen.has(lower) || existing.length >= MAX_TABS_PER_DOMAIN) {
            return;
        }

        mergedSeen.add(lower);
        existing.push(keyword);
    });

    store.domains[normalizedDomain] = existing;
    const cookieOk = writeCookieStore(store);
    if (!cookieOk) {
        return;
    }

    if (blankTabs.length > 0) {
        const currentBlank = loadBlankPickerTabs(id);
        const blankIds = new Set(currentBlank.map((row) => row.id));
        blankTabs.forEach((row) => {
            if (row.id !== '' && !blankIds.has(row.id)) {
                currentBlank.push(row);
                blankIds.add(row.id);
            }
        });
        saveBlankPickerTabs(id, currentBlank);
    }

    try {
        localStorage.removeItem(legacyKey);
    } catch {
        // ignore
    }
}

function normalizeImage(image) {
    if (!image || typeof image !== 'object') {
        return null;
    }

    const url = String(image.url || '').trim();
    if (url === '') {
        return null;
    }

    const wpId = Number(image.wp_attachment_id || 0);
    const seoId = Number(image.seo_media_id || 0);
    const pickerKey = String(
        image.picker_key
            || `staged-wp-${wpId}-seo-${seoId}-${url}`,
    );

    return {
        picker_key: pickerKey,
        id: Number(image.id || (wpId > 0 ? wpId : seoId)),
        wp_attachment_id: wpId,
        seo_media_id: seoId,
        url,
        thumb_url: String(image.thumb_url || url),
        slug: String(image.slug || '').trim(),
        alt: String(image.alt || '').trim(),
        media_type: String(image.media_type || 'image').toLowerCase() === 'video' ? 'video' : 'image',
        staged: true,
    };
}

function truncateLabel(keyword, max = 18) {
    const text = String(keyword || '').trim();
    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1)}…`;
}

export function isCustomPickerTab(tab) {
    return String(tab || '').startsWith('custom:');
}

export function customTabIdFromPickerTab(tab) {
    return String(tab || '').replace(/^custom:/, '');
}

export function pickerTabFromCustomId(tabId) {
    return `custom:${String(tabId)}`;
}

/**
 * @param {string} domain domain bài viết đang sửa (đã hoặc chưa normalize)
 * @param {number|string} [articleId] dùng migrate localStorage cũ + blank tabs
 * @returns {Array<{id: string, keyword: string, label: string, blank?: boolean, createdAt: number}>}
 */
export function loadCustomPickerTabs(domain, articleId = 0) {
    const normalizedDomain = normalizeArticleDomain(domain);
    if (normalizedDomain === '') {
        return [];
    }

    migrateLegacyTabsForArticle(normalizedDomain, articleId);

    const store = readCookieStore();
    const keywords = Array.isArray(store.domains[normalizedDomain])
        ? store.domains[normalizedDomain]
        : [];
    const keywordTabs = keywords
        .map((keyword) => tabFromKeyword(keyword))
        .filter(Boolean);

    const blankTabs = loadBlankPickerTabs(articleId);

    return [...keywordTabs, ...blankTabs];
}

function saveDomainKeywords(domain, keywords) {
    const normalizedDomain = normalizeArticleDomain(domain);
    if (normalizedDomain === '') {
        return false;
    }

    const store = readCookieStore();
    store.domains[normalizedDomain] = Array.isArray(keywords) ? keywords.slice(0, MAX_TABS_PER_DOMAIN) : [];

    return writeCookieStore(store);
}

/**
 * @param {string} domain
 * @param {string} keyword
 * @param {{blank?: boolean, articleId?: number|string}} [options]
 */
export function addCustomPickerTab(domain, keyword, options = {}) {
    const blank = options?.blank === true;
    const articleId = Number(options?.articleId || 0);
    const normalizedDomain = normalizeArticleDomain(domain);
    if (normalizedDomain === '') {
        return null;
    }

    if (blank) {
        if (!Number.isFinite(articleId) || articleId <= 0) {
            return null;
        }

        const tabs = loadBlankPickerTabs(articleId);
        const blankCount = tabs.length;
        const tab = {
            id: `c${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
            keyword: '',
            label: blankCount > 0 ? `Nhóm trống ${blankCount + 1}` : 'Nhóm trống',
            blank: true,
            createdAt: Date.now(),
        };
        tabs.push(tab);
        saveBlankPickerTabs(articleId, tabs);

        return tab;
    }

    const normalized = String(keyword || '').trim().slice(0, MAX_KEYWORD_LENGTH);
    if (normalized === '') {
        return null;
    }

    const store = readCookieStore();
    const existing = Array.isArray(store.domains[normalizedDomain])
        ? [...store.domains[normalizedDomain]]
        : [];

    if (existing.some((item) => item.toLowerCase() === normalized.toLowerCase())) {
        return tabFromKeyword(existing.find((item) => item.toLowerCase() === normalized.toLowerCase()) || normalized);
    }

    if (existing.length >= MAX_TABS_PER_DOMAIN) {
        return null;
    }

    existing.push(normalized);
    if (!saveDomainKeywords(normalizedDomain, existing)) {
        return null;
    }

    return tabFromKeyword(normalized);
}

export function removeCustomPickerTab(domain, tabId, articleId = 0) {
    const id = String(tabId || '').trim();
    const normalizedDomain = normalizeArticleDomain(domain);
    if (id === '' || normalizedDomain === '') {
        return;
    }

    const blankTabs = loadBlankPickerTabs(articleId);
    const blankIndex = blankTabs.findIndex((row) => row.id === id);
    if (blankIndex !== -1) {
        blankTabs.splice(blankIndex, 1);
        saveBlankPickerTabs(articleId, blankTabs);
        clearCustomTabCaches(articleId, id);

        return;
    }

    const store = readCookieStore();
    const existing = Array.isArray(store.domains[normalizedDomain])
        ? store.domains[normalizedDomain]
        : [];
    const next = existing.filter((keyword) => keywordTabId(keyword) !== id);
    saveDomainKeywords(normalizedDomain, next);
    clearCustomTabCaches(articleId, id);
}

export function renameCustomPickerTab(articleId, tabId, label) {
    const id = String(tabId || '').trim();
    const nextLabel = String(label || '').trim();
    if (id === '' || nextLabel === '') {
        return false;
    }

    const tabs = loadBlankPickerTabs(articleId);
    const index = tabs.findIndex((row) => row.id === id);
    if (index === -1) {
        return false;
    }

    tabs[index].label = truncateLabel(nextLabel, 24);
    saveBlankPickerTabs(articleId, tabs);

    return true;
}

export function loadStagedPickerImages(articleId, tabId) {
    const parsed = readJson(stagedKey(articleId, tabId));
    if (!parsed || !Array.isArray(parsed.images)) {
        return [];
    }

    return parsed.images
        .map((row) => normalizeImage(row))
        .filter(Boolean);
}

export function stagePickerImageToTab(articleId, tabId, image) {
    const normalized = normalizeImage(image);
    if (!normalized) {
        return false;
    }

    const existing = loadStagedPickerImages(articleId, tabId);
    if (existing.some((row) => row.picker_key === normalized.picker_key)) {
        return false;
    }

    existing.unshift(normalized);

    return writeJson(stagedKey(articleId, tabId), {
        images: existing,
        updatedAt: Date.now(),
    });
}

export function unstagePickerImageFromTab(articleId, tabId, pickerKey) {
    const key = String(pickerKey || '').trim();
    const id = String(tabId || '').trim();
    if (key === '' || id === '') {
        return false;
    }

    const existing = loadStagedPickerImages(articleId, id);
    const next = existing.filter((row) => row.picker_key !== key);
    if (next.length === existing.length) {
        return false;
    }

    return writeJson(stagedKey(articleId, id), {
        images: next,
        updatedAt: Date.now(),
    });
}

export function countStagedPickerImages(articleId, tabId) {
    return loadStagedPickerImages(articleId, tabId).length;
}

export function readCustomTabFetchCache(articleId, tabId, page, keyword) {
    const parsed = readJson(fetchKey(articleId, tabId, page, keyword));
    if (!parsed || typeof parsed !== 'object') {
        return null;
    }

    const cachedAt = Number(parsed.cachedAt || 0);
    if (cachedAt > 0 && Date.now() - cachedAt > MAX_FETCH_AGE_MS) {
        localStorage.removeItem(fetchKey(articleId, tabId, page, keyword));

        return null;
    }

    if (!Array.isArray(parsed.images)) {
        return null;
    }

    return {
        tab: pickerTabFromCustomId(tabId),
        page: Math.max(1, Number(parsed.page || page)),
        totalPages: Math.max(1, Number(parsed.totalPages || 1)),
        error: parsed.error ? String(parsed.error) : null,
        images: parsed.images,
        catalog: null,
        cachedAt,
    };
}

export function writeCustomTabFetchCache(articleId, tabId, page, keyword, detail) {
    if (!detail || typeof detail !== 'object' || !Array.isArray(detail.images)) {
        return false;
    }

    return writeJson(fetchKey(articleId, tabId, page, keyword), {
        tab: pickerTabFromCustomId(tabId),
        page: Math.max(1, Number(detail.page || page)),
        totalPages: Math.max(1, Number(detail.totalPages || 1)),
        error: detail.error ? String(detail.error) : null,
        images: detail.images,
        cachedAt: Date.now(),
    });
}

export function clearCustomTabCaches(articleId, tabId) {
    const id = Number(articleId);
    const tab = String(tabId || '').trim();
    if (!Number.isFinite(id) || id <= 0 || tab === '') {
        return;
    }

    localStorage.removeItem(stagedKey(id, tab));

    const stagedPrefix = `${STAGED_PREFIX}:${id}:${tab}`;
    const fetchPrefix = `${FETCH_PREFIX}:${id}:${tab}:`;
    const keys = [];

    for (let index = 0; index < localStorage.length; index += 1) {
        const key = localStorage.key(index);
        if (!key) {
            continue;
        }

        if (key.startsWith(fetchPrefix) || key === stagedPrefix) {
            keys.push(key);
        }
    }

    keys.forEach((key) => localStorage.removeItem(key));
}

export const CUSTOM_WP_TABS_COOKIE_NAME = COOKIE_NAME;
