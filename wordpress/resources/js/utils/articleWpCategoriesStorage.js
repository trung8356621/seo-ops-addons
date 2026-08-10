const storageKey = (articleId) => `seo_wp_category_ids_${articleId}`;

/**
 * @returns {{ categoryIds: number[], fetchedAt: string }|null}
 */
export function loadWpCategoryIds(articleId) {
    const id = Number.parseInt(String(articleId ?? ''), 10);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(storageKey(id));
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }

        const categoryIds = Array.isArray(parsed.categoryIds)
            ? parsed.categoryIds
                .map((value) => Number.parseInt(String(value), 10))
                .filter((value) => Number.isFinite(value) && value > 0)
            : [];

        return {
            categoryIds: [...new Set(categoryIds)],
            fetchedAt: typeof parsed.fetchedAt === 'string' ? parsed.fetchedAt : '',
        };
    } catch {
        return null;
    }
}

/**
 * @param {number} articleId
 * @param {number[]} categoryIds
 * @param {string} [fetchedAt]
 */
export function saveWpCategoryIds(articleId, categoryIds, fetchedAt = '') {
    const id = Number.parseInt(String(articleId ?? ''), 10);
    if (!Number.isFinite(id) || id <= 0) {
        return false;
    }

    const normalized = [...new Set(
        (Array.isArray(categoryIds) ? categoryIds : [])
            .map((value) => Number.parseInt(String(value), 10))
            .filter((value) => Number.isFinite(value) && value > 0),
    )];

    try {
        window.localStorage.setItem(storageKey(id), JSON.stringify({
            categoryIds: normalized,
            fetchedAt: fetchedAt || new Date().toISOString(),
        }));

        return true;
    } catch (error) {
        console.warn('Không lưu được danh mục WordPress vào localStorage', error);

        return false;
    }
}

/**
 * @param {{ articleId?: number, categoryIds?: number[], fetchedAt?: string }} detail
 */
export function applyFetchedWpCategories(detail) {
    const articleId = Number.parseInt(String(detail?.articleId ?? ''), 10);
    const categoryIds = Array.isArray(detail?.categoryIds) ? detail.categoryIds : [];
    if (!Number.isFinite(articleId) || articleId <= 0 || categoryIds.length === 0) {
        return null;
    }

    saveWpCategoryIds(articleId, categoryIds, detail?.fetchedAt ?? '');

    return loadWpCategoryIds(articleId);
}
