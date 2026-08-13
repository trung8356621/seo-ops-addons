/**
 * Post-type-aware Publishing category taxonomy (mirrors PHP PublishingTaxonomyResolver).
 * Laravel-only articles: evaluate from type + selected IDs — no wp_post_id required.
 */

/**
 * @param {unknown} postType
 * @param {unknown} [recordType]
 * @returns {{
 *   taxonomy: string|null,
 *   wp_taxonomy: string|null,
 *   required: boolean,
 *   reason: string
 * }}
 */
export function resolvePublishingCategoryTaxonomy(postType, recordType = null) {
    const candidates = [normalizeRawType(postType), normalizeRawType(recordType)]
        .filter((value) => value !== '');
    const list = candidates.length > 0 ? candidates : [''];

    // Prefer specific identity when Livewire normalizes page → article.
    for (const specific of ['page', 'product', 'e-commerce', 'product_category', 'product_cat', 'category']) {
        if (list.includes(specific)) {
            return resolveRaw(specific);
        }
    }

    return resolveRaw(list[0]);
}

/**
 * @param {string} raw
 */
function resolveRaw(raw) {
    if (['category', 'product_category', 'product_cat'].includes(raw)) {
        return {
            taxonomy: null,
            wp_taxonomy: null,
            required: false,
            reason: 'taxonomy_entity',
        };
    }

    if (raw === 'page') {
        return {
            taxonomy: null,
            wp_taxonomy: null,
            required: false,
            reason: 'page',
        };
    }

    if (raw === 'product' || raw === 'e-commerce') {
        return {
            taxonomy: 'product_category',
            wp_taxonomy: 'product_cat',
            required: true,
            reason: 'product',
        };
    }

    if (raw === 'article' || raw === 'post' || raw === '') {
        return {
            taxonomy: 'category',
            wp_taxonomy: 'category',
            required: true,
            reason: 'post',
        };
    }

    return {
        taxonomy: null,
        wp_taxonomy: null,
        required: false,
        reason: 'custom_or_unknown',
    };
}

/**
 * @param {unknown} postType
 * @param {unknown} [recordType]
 */
export function publishingRequiresCategory(postType, recordType = null) {
    return resolvePublishingCategoryTaxonomy(postType, recordType).required === true;
}

/**
 * @param {unknown} value
 */
function normalizeRawType(value) {
    return String(value ?? '').trim().toLowerCase();
}

if (typeof window !== 'undefined') {
    window.__seoResolvePublishCategoryRequirement = resolvePublishingCategoryTaxonomy;
}
