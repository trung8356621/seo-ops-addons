const storageKey = (articleId, siteId) =>
    `seo_article_excluded_link_suggestions_${Number(siteId ?? 0)}_${Number(articleId ?? 0)}`;

/**
 * @returns {string[]}
 */
export function loadExcludedLinkSuggestions(articleId, siteId) {
    const id = Number(articleId ?? 0);
    if (id <= 0) {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(storageKey(id, siteId));
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        const labels = Array.isArray(parsed) ? parsed : parsed?.labels;
        if (!Array.isArray(labels)) {
            return [];
        }

        return labels
            .map((label) => String(label ?? '').trim())
            .filter((label) => label !== '');
    } catch {
        return [];
    }
}

/**
 * @param {string[]} labels
 */
export function saveExcludedLinkSuggestions(articleId, siteId, labels) {
    const id = Number(articleId ?? 0);
    if (id <= 0) {
        return;
    }

    const unique = [...new Set(labels.map((label) => String(label ?? '').trim()).filter(Boolean))];

    try {
        window.localStorage.setItem(
            storageKey(id, siteId),
            JSON.stringify({
                labels: unique,
                updatedAt: Date.now(),
            }),
        );
    } catch (error) {
        console.warn('Không lưu được gợi ý link đã loại vào localStorage', error);
    }
}

export function clearExcludedLinkSuggestions(articleId, siteId) {
    const id = Number(articleId ?? 0);
    if (id <= 0) {
        return;
    }

    try {
        window.localStorage.removeItem(storageKey(id, siteId));
    } catch (error) {
        console.warn('Không xóa được gợi ý link đã loại trong localStorage', error);
    }
}
