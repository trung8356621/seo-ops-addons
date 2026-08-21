/**
 * Phase 4 stabilization — single API boundary for lazy editor payloads.
 * UI must not invent alternate schemas.
 */

/**
 * Unwrap common API envelopes without guessing module-specific fields.
 * Supports: null | undefined | {...} | { data } | { success, data } | Axios-like { data: { success, data } }.
 *
 * @param {unknown} response
 * @returns {unknown}
 */
export function unwrapModuleEnvelope(response) {
    const raw = response?.data ?? response ?? null;
    if (raw == null) {
        return null;
    }

    if (Array.isArray(raw)) {
        return raw;
    }

    if (typeof raw !== 'object') {
        return raw;
    }

    // Envelope { success?, message?, data } — prefer inner data (may be null).
    if (
        'data' in raw
        && !('cached' in raw)
        && !('items' in raw)
        && !('faqs' in raw)
        && !('extracted_links' in raw)
        && !('score' in raw)
        && !('applicable' in raw)
    ) {
        return raw.data ?? null;
    }

    return raw;
}

/**
 * Shared safe defaults for assistant/module payloads that expose cache flags.
 *
 * @param {unknown} response
 * @returns {{
 *   success: boolean,
 *   cached: boolean,
 *   cached_at: string|null,
 *   items: unknown[],
 *   faqs: unknown[],
 *   error: string|null,
 *   message: string|null,
 *   raw: Record<string, unknown>,
 * }}
 */
export function normalizeModulePayload(response) {
    const payload = unwrapModuleEnvelope(response);

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        const items = Array.isArray(payload) ? payload : [];

        return {
            success: false,
            cached: false,
            cached_at: null,
            items,
            faqs: items,
            error: payload == null ? 'EMPTY_PAYLOAD' : null,
            message: payload == null ? 'EMPTY_PAYLOAD' : null,
            raw: {},
        };
    }

    const items = Array.isArray(payload.items)
        ? payload.items
        : Array.isArray(payload.faqs)
          ? payload.faqs
          : [];
    const envelopeSuccess = response?.success ?? response?.data?.success;

    return {
        ...payload,
        success: payload.success ?? envelopeSuccess ?? true,
        cached: Boolean(payload.cached),
        cached_at: payload.cached_at ?? payload.cachedAt ?? null,
        items,
        faqs: Array.isArray(payload.faqs) ? payload.faqs : items,
        error: payload.error != null ? String(payload.error) : null,
        message: payload.message != null ? String(payload.message) : null,
        raw: payload,
    };
}

/**
 * Dev-only structured module error log (no secrets).
 *
 * @param {{ moduleName: string, articleId?: number|null, endpoint?: string|null, error?: unknown }} detail
 */
export function logModuleLoadError(detail) {
    if (typeof window === 'undefined') {
        return;
    }

    const isDev = Boolean(window.__SEO_ARTICLE_EDITOR_PERF_DEBUG__)
        || String(window.location?.hostname ?? '') === 'localhost'
        || String(window.location?.hostname ?? '').endsWith('.test');

    if (!isDev && typeof console !== 'undefined' && typeof console.warn === 'function') {
        console.warn('[seo-module]', detail?.moduleName ?? 'unknown', detail?.error?.message ?? detail?.error);

        return;
    }

    if (typeof console !== 'undefined' && typeof console.error === 'function') {
        console.error('[seo-module-load]', {
            module_name: detail?.moduleName ?? null,
            article_id: detail?.articleId ?? null,
            endpoint: detail?.endpoint ?? null,
            error_message: detail?.error instanceof Error
                ? detail.error.message
                : String(detail?.error ?? ''),
        });
    }
}

/**
 * @param {unknown} payload
 * @returns {{
 *   score: number|null,
 *   focusKeyword: string|null,
 *   seoTitle: string,
 *   metaDescription: string,
 *   contentHash: string,
 *   stale: boolean,
 *   skipSeoScore: boolean,
 *   violations: unknown[],
 *   siteDomain: string,
 *   articleSlug: string,
 *   permalinkBase: string,
 *   permalink: string,
 *   analyzedContentHash: string,
 *   raw: Record<string, unknown>,
 * }}
 */
export function normalizeSeoSummary(payload) {
    const src = unwrapModuleEnvelope(payload);
    const safe = src && typeof src === 'object' && !Array.isArray(src) ? src : {};
    const scoreRaw = safe.score ?? safe.seo_score ?? null;
    const score = scoreRaw == null || scoreRaw === ''
        ? null
        : Number(scoreRaw);

    return {
        score: Number.isFinite(score) ? score : null,
        focusKeyword: safe.focus_keyword ?? safe.focusKeyword ?? null,
        seoTitle: String(safe.seo_title ?? safe.seoTitle ?? '').trim(),
        metaDescription: String(safe.meta_description ?? safe.metaDescription ?? '').trim(),
        contentHash: String(safe.content_hash ?? safe.contentHash ?? '').trim(),
        stale: Boolean(safe.stale),
        skipSeoScore: Boolean(safe.skip_seo_score ?? safe.skipSeoScore),
        violations: Array.isArray(safe.violations) ? safe.violations : [],
        siteDomain: String(safe.site_domain ?? safe.siteDomain ?? '').trim(),
        articleSlug: String(safe.article_slug ?? safe.articleSlug ?? '').trim(),
        permalinkBase: String(safe.permalink_base ?? safe.permalinkBase ?? '').trim(),
        permalink: String(safe.permalink ?? '').trim(),
        analyzedContentHash: String(safe.analyzed_content_hash ?? safe.analyzedContentHash ?? '').trim(),
        raw: safe,
    };
}

/**
 * @param {unknown} responseOrPayload
 * @returns {{
 *   success: boolean,
 *   cached: boolean,
 *   cached_at: string|null,
 *   items: unknown[],
 *   faqs: unknown[],
 *   count: number,
 *   extractDebug: unknown,
 *   canGenerateFaq: boolean,
 *   canImportMarkdownFaq: boolean,
 *   error: string|null,
 * }}
 */
export function normalizeFaqPayload(responseOrPayload) {
    const base = normalizeModulePayload(responseOrPayload);
    const src = base.raw && Object.keys(base.raw).length > 0 ? base.raw : base;
    const canGenerateRaw = src.can_generate ?? src.can_generate_faq ?? src.canGenerateFaq;

    return {
        success: Boolean(base.success),
        cached: Boolean(base.cached),
        cached_at: base.cached_at ?? null,
        items: Array.isArray(base.items) ? base.items : [],
        faqs: Array.isArray(base.faqs) ? base.faqs : [],
        count: Number(src.count ?? base.items.length ?? 0) || 0,
        extractDebug: src.extract_debug ?? src.extractDebug ?? null,
        canGenerateFaq: canGenerateRaw !== false,
        canImportMarkdownFaq: Boolean(src.can_import_markdown_faq ?? src.canImportMarkdownFaq),
        faqSnapshot: src.faq_snapshot && typeof src.faq_snapshot === 'object' ? src.faq_snapshot : null,
        error: base.error,
    };
}

/**
 * @param {unknown} payload
 * @returns {{
 *   extractedLinks: { internal: unknown[], external: unknown[] },
 *   domainLinkList: unknown[],
 *   domainLinkListCatalog: unknown[],
 *   domainCtaList: unknown[],
 *   suggestedInternalLinks: unknown[],
 *   suggestedInternalLinksCatalog: unknown[],
 *   suggestedExternalLinks: unknown[],
 *   suggestedExternalLinksCatalog: unknown[],
 *   mainDomainSuggestions: { mainDomain: string, relationship: string|null, items: unknown[] },
 *   canGenerateSuggestions: boolean,
 * }}
 */
export function normalizeLinksPayload(payload) {
    const unwrapped = unwrapModuleEnvelope(payload);
    const src = unwrapped && typeof unwrapped === 'object' && !Array.isArray(unwrapped) ? unwrapped : {};
    const extracted = src.extracted_links && typeof src.extracted_links === 'object'
        ? src.extracted_links
        : { internal: [], external: [] };

    return {
        extractedLinks: {
            internal: Array.isArray(extracted.internal) ? extracted.internal : [],
            external: Array.isArray(extracted.external) ? extracted.external : [],
        },
        domainLinkList: Array.isArray(src.domain_link_list) ? src.domain_link_list : [],
        domainLinkListCatalog: Array.isArray(src.domain_link_list_catalog)
            ? src.domain_link_list_catalog
            : [],
        domainCtaList: Array.isArray(src.domain_cta_list) ? src.domain_cta_list : [],
        ctaQuickTemplates:
            src.cta_quick_templates && typeof src.cta_quick_templates === 'object'
                ? src.cta_quick_templates
                : src.ctaQuickTemplates && typeof src.ctaQuickTemplates === 'object'
                  ? src.ctaQuickTemplates
                  : null,
        suggestedInternalLinks: Array.isArray(src.suggested_internal_links)
            ? src.suggested_internal_links
            : [],
        suggestedInternalLinksCatalog: Array.isArray(src.suggested_internal_links_catalog)
            ? src.suggested_internal_links_catalog
            : [],
        suggestedExternalLinks: Array.isArray(src.suggested_external_links)
            ? src.suggested_external_links
            : [],
        suggestedExternalLinksCatalog: Array.isArray(src.suggested_external_links_catalog)
            ? src.suggested_external_links_catalog
            : [],
        mainDomainSuggestions: {
            mainDomain: String(src.main_domain_suggestions?.main_domain ?? '').trim(),
            relationship: src.main_domain_suggestions?.relationship
                ? String(src.main_domain_suggestions.relationship)
                : null,
            source: String(src.main_domain_suggestions?.source ?? ''),
            items: Array.isArray(src.main_domain_suggestions?.items)
                ? src.main_domain_suggestions.items
                : [],
        },
        canGenerateSuggestions: src.can_generate_suggestions !== false,
        contentSource: typeof src.content_source === 'string' ? src.content_source : '',
        suggestionDebug: src.suggestion_debug && typeof src.suggestion_debug === 'object'
            ? src.suggestion_debug
            : null,
    };
}

/**
 * CTA catalog lives inside links base payload.
 * @param {unknown} payload
 * @returns {{ items: unknown[], count: number }}
 */
export function normalizeCtaPayload(payload) {
    const links = normalizeLinksPayload(payload);

    return {
        items: links.domainCtaList,
        count: links.domainCtaList.length,
    };
}

/**
 * @param {unknown} payload
 * @returns {{
 *   applicable: boolean,
 *   status: string|null,
 *   count: number,
 *   warning: string|null,
 *   canCreateReviews: boolean,
 *   createBlockReason: string|null,
 *   raw: Record<string, unknown>,
 * }}
 */
export function normalizeReviewStatus(payload) {
    const unwrapped = unwrapModuleEnvelope(payload);
    const src = unwrapped && typeof unwrapped === 'object' && !Array.isArray(unwrapped) ? unwrapped : {};
    const postType = String(src.post_type ?? src.postType ?? '').trim().toLowerCase();
    const applicable = src.applicable != null
        ? Boolean(src.applicable)
        : postType === 'product';
    const wpPostId = Number(src.wp_post_id ?? src.wpPostId ?? 0);
    const count = Number(
        src.count
        ?? src.wordpress_review_count
        ?? src.wordpressReviewCount
        ?? 0,
    ) || 0;

    let status = src.status != null ? String(src.status) : null;
    if (status == null) {
        if (!applicable) {
            status = null;
        } else if (!Number.isFinite(wpPostId) || wpPostId <= 0) {
            status = 'not_synced';
        } else if (src.wordpress_connected === false || src.wordpressConnected === false) {
            status = 'wordpress_unavailable';
        } else {
            status = 'ok';
        }
    }

    return {
        applicable,
        status,
        count,
        warning: src.warning != null ? String(src.warning) : null,
        canCreateReviews: Boolean(src.can_create_reviews ?? src.canCreateReviews),
        createBlockReason: src.create_block_reason ?? src.createBlockReason ?? null,
        raw: src,
    };
}

/**
 * Read Phase 2 core bootstrap JSON.
 * @returns {Record<string, unknown>|null}
 */
export function readCoreBootstrap() {
    try {
        const el = document.getElementById('seo-article-core-bootstrap');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return null;
        }
        const data = JSON.parse(raw);

        return data && typeof data === 'object' ? data : null;
    } catch {
        return null;
    }
}

/**
 * @returns {{ articleId: number, siteId: number, connectionHash: string }}
 */
export function readCoreArticleIdentity() {
    const core = readCoreBootstrap();
    if (!core) {
        return { articleId: 0, siteId: 0, connectionHash: '' };
    }

    return {
        articleId: Number(core.articleId ?? core.id ?? 0) || 0,
        siteId: Number(core.siteId ?? core.site_id ?? 0) || 0,
        connectionHash: String(core.connectionHash ?? core.seo_connection_hash ?? '').trim(),
    };
}
