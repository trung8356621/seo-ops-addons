/**
 * Soft content-image recommendation.
 * target_words_per_image = 200 → ~6 ảnh cho bài ~1.150 từ (không dùng ~120–140).
 */

export const TARGET_WORDS_PER_IMAGE = 200;

/**
 * @param {string} html
 * @param {{
 *   countWordsForImageRatio?: Function,
 *   queryImages?: Function,
 *   wordsPerImage?: number,
 *   validImageCountOverride?: number|null,
 *   missingAltOverride?: number|null,
 * }} [options]
 */
export function computeImageRatioMetrics(html, {
    countWordsForImageRatio,
    queryImages,
    wordsPerImage = TARGET_WORDS_PER_IMAGE,
    validImageCountOverride = null,
    missingAltOverride = null,
} = {}) {
    const source = String(html ?? '');
    const targetWords = Math.max(1, Number(wordsPerImage) || TARGET_WORDS_PER_IMAGE);
    let images = [];
    let missingAlt = 0;

    if (typeof queryImages === 'function') {
        images = queryImages(source);
    } else if (typeof document !== 'undefined' && validImageCountOverride == null) {
        const container = document.createElement('div');
        container.innerHTML = source;
        images = Array.from(container.querySelectorAll('img')).filter((img) => {
            const src = String(img.getAttribute('src') ?? '').trim();
            return src !== '' && !/placeholder/i.test(src);
        });
    }

    if (validImageCountOverride == null) {
        images.forEach((img) => {
            const alt = typeof img?.getAttribute === 'function'
                ? String(img.getAttribute('alt') ?? '').trim()
                : String(img?.alt ?? '').trim();
            if (alt === '') {
                missingAlt += 1;
            }
        });
    } else if (missingAltOverride != null) {
        missingAlt = Math.max(0, Number(missingAltOverride) || 0);
    }

    const validImageCount = validImageCountOverride != null
        ? Math.max(0, Number(validImageCountOverride) || 0)
        : images.length;
    const wordCount = typeof countWordsForImageRatio === 'function'
        ? Number(countWordsForImageRatio(source)) || 0
        : 0;

    const recommended = wordCount > 0
        ? Math.max(1, Math.ceil(wordCount / targetWords))
        : 0;
    const missing = Math.max(0, recommended - validImageCount);

    // Soft score aligned to target words/image (ideal ~0.75x–1.5x band).
    let baseScore = 0;
    if (wordCount >= 10 && validImageCount > 0) {
        const wordsPerImageActual = Math.round(wordCount / validImageCount);
        const idealLow = Math.round(targetWords * 0.75);
        const idealHigh = Math.round(targetWords * 1.5);
        if (missing === 0 && wordsPerImageActual >= idealLow && wordsPerImageActual <= idealHigh) {
            baseScore = 15;
        } else if (missing === 0) {
            baseScore = 15;
        } else if (missing === 1) {
            baseScore = 12;
        } else if (missing === 2) {
            baseScore = 8;
        } else if (missing >= 3) {
            baseScore = 3;
        } else if (wordsPerImageActual > idealHigh && wordsPerImageActual <= idealHigh + 200) {
            baseScore = 10;
        } else {
            baseScore = 3;
        }
    }

    return {
        current_image_count: validImageCount,
        valid_image_count: validImageCount,
        block_image_count: validImageCount,
        current_word_count: wordCount,
        recommended_image_count: recommended,
        missing_image_count: missing,
        target_words_per_image: targetWords,
        baseScore,
        missingAlt,
    };
}

/**
 * @param {number} currentWordCount
 * @param {number} recommendedWordCount
 */
export function computeContentLengthMetrics(currentWordCount, recommendedWordCount) {
    const current = Math.max(0, Number(currentWordCount) || 0);
    const recommended = Math.max(1, Number(recommendedWordCount) || 2000);

    return {
        current_word_count: current,
        recommended_word_count: recommended,
        missing_word_count: Math.max(0, recommended - current),
    };
}

/**
 * Interpolate `:key` placeholders. Never leave snake_case codes as UI text.
 *
 * @param {string} template
 * @param {Record<string, string|number>} vars
 */
export function interpolateSeoReasonTemplate(template, vars = {}) {
    return String(template ?? '').replace(/:([a-zA-Z0-9_]+)/g, (_, key) => {
        if (Object.prototype.hasOwnProperty.call(vars, key)) {
            return String(vars[key]);
        }
        return '';
    });
}

/**
 * @param {string} locale
 * @param {number} value
 */
export function formatSeoCount(value, locale = 'vi') {
    const number = Number(value) || 0;
    try {
        return new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'vi-VN').format(number);
    } catch {
        return String(number);
    }
}

// Client defaults (parity with lang/{locale}/seo_rules.php) — used before lazy seo_rule_messages load.
const DEFAULT_SEO_RULE_TEMPLATES = {
    vi: {
        missing_focus_keyword: 'Chưa gán từ khóa chính cho bài viết.',
        h2_missing: 'Cần ít nhất 2 thẻ H2 trong bài viết.',
        content_length_low: 'Thiếu khoảng :missing từ.',
        content_length_low_label: 'Nội dung còn ngắn',
        content_length_low_detail: 'Bài viết hiện có :current từ; đề xuất tối thiểu khoảng :recommended từ.',
        image_ratio_missing: 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
        image_ratio_missing_label: 'Chưa có ảnh nội dung',
        image_ratio_missing_detail: 'Hiện có :current ảnh; đề xuất khoảng :recommended ảnh cho bài viết :words từ.',
        image_ratio_poor: 'Thiếu khoảng :missing ảnh.',
        image_ratio_poor_label: 'Tỷ lệ hình ảnh còn kém',
        image_ratio_poor_detail: 'Bài viết :words từ hiện có :current ảnh; đề xuất khoảng :recommended ảnh.',
        image_ratio_low: 'Thiếu khoảng :missing ảnh trong nội dung.',
        image_ratio_low_label: 'Bài viết nên bổ sung thêm ảnh',
        image_ratio_low_detail: 'Bài viết có :words từ và :current ảnh nội dung; đề xuất khoảng :recommended ảnh.',
        image_ratio_suboptimal: 'Nên thêm khoảng :missing ảnh để đạt tỷ lệ lý tưởng.',
        image_ratio_suboptimal_label: 'Tỷ lệ hình ảnh chưa lý tưởng',
        image_ratio_suboptimal_detail: 'Bài viết :words từ hiện có :current ảnh; đề xuất khoảng :recommended ảnh.',
        image_alt_missing: 'Một hoặc nhiều ảnh thiếu thuộc tính ALT.',
        wiki_trust_missing: 'Thiếu liên kết ngoài wiki-trust.',
        faq_missing: 'Thiếu FAQ schema (chưa có dữ liệu FAQ).',
        keyword_missing_in_title: 'Từ khóa chính chưa có trong tiêu đề SEO.',
        keyword_missing_in_meta: 'Từ khóa chính chưa có trong meta description.',
        keyword_missing_in_slug: 'Từ khóa chính chưa có trong slug URL.',
        keyword_missing_in_intro: 'Từ khóa chính chưa có trong 100 từ đầu.',
        featured_snippet_missing: 'Thiếu bảng Featured Snippet hoặc chưa đạt ngưỡng tối thiểu.',
        featured_snippet_below_good: 'Bảng Featured Snippet đạt mức trung bình nhưng chưa đạt mức tốt.',
        featured_snippet_below_excellent: 'Bảng Featured Snippet đạt mức tốt nhưng chưa đạt mức rất tốt.',
        all_passed: 'Bài viết đạt tất cả quy tắc SEO.',
    },
    en: {
        missing_focus_keyword: 'No focus keyword assigned for this article.',
        h2_missing: 'Need at least 2 H2 headings in the article.',
        content_length_low: 'Add about :missing more words.',
        content_length_low_label: 'Content is too short',
        content_length_low_detail: 'Current length: :current words; recommended minimum: about :recommended words.',
        image_ratio_missing: 'Currently :current images; about :recommended recommended for a :words-word article.',
        image_ratio_missing_label: 'No content images yet',
        image_ratio_missing_detail: 'Currently :current images; about :recommended recommended for a :words-word article.',
        image_ratio_poor: 'Add about :missing more images.',
        image_ratio_poor_label: 'Image ratio is poor',
        image_ratio_poor_detail: 'This :words-word article has :current images; about :recommended are recommended.',
        image_ratio_low: 'Add about :missing more images.',
        image_ratio_low_label: 'Image ratio is too low',
        image_ratio_low_detail: 'This :words-word article has :current images; about :recommended are recommended.',
        image_ratio_suboptimal: 'Add about :missing more images to reach the ideal ratio.',
        image_ratio_suboptimal_label: 'Image ratio is not ideal',
        image_ratio_suboptimal_detail: 'This :words-word article has :current images; about :recommended are recommended.',
        image_alt_missing: 'One or more images are missing ALT text.',
        wiki_trust_missing: 'Missing at least one outbound wiki-trust link.',
        faq_missing: 'FAQ schema is missing (no FAQ data saved).',
        keyword_missing_in_title: 'Focus keyword is missing from the SEO title.',
        keyword_missing_in_meta: 'Focus keyword is missing from the meta description.',
        keyword_missing_in_slug: 'Focus keyword is missing from the URL slug.',
        keyword_missing_in_intro: 'Focus keyword is missing from the first 100 words.',
        featured_snippet_missing: 'Featured Snippet table is missing or does not meet minimum thresholds.',
        featured_snippet_below_good: 'Featured Snippet table meets average tier but not good tier.',
        featured_snippet_below_excellent: 'Featured Snippet table meets good tier but not excellent tier.',
        all_passed: 'All SEO rules passed.',
    },
};

const SAFE_FALLBACKS = {
    vi: {
        content_length_low: 'Nội dung chưa đạt độ dài đề xuất',
        image_ratio_low: 'Bài viết nên bổ sung thêm ảnh',
        image_ratio_poor: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        image_ratio_missing: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        image_ratio_suboptimal: 'Tỷ lệ hình ảnh chưa đạt đề xuất',
        missing_focus_keyword: 'Thiếu từ khóa chính',
        wiki_trust_missing: 'Thiếu liên kết ngoài wiki-trust',
        keyword_missing_in_intro: 'Từ khóa chính chưa có trong 100 từ đầu',
        keyword_missing_in_title: 'Từ khóa chính chưa có trong tiêu đề SEO',
        keyword_missing_in_meta: 'Từ khóa chính chưa có trong meta description',
        keyword_missing_in_slug: 'Từ khóa chính chưa có trong slug URL',
        h2_missing: 'Cần ít nhất 2 thẻ H2 trong bài viết',
        faq_missing: 'Thiếu FAQ schema',
        image_alt_missing: 'Một hoặc nhiều ảnh thiếu ALT',
    },
    en: {
        content_length_low: 'Content is below the recommended length',
        image_ratio_low: 'Image ratio is below recommendation',
        image_ratio_poor: 'Image ratio is below recommendation',
        image_ratio_missing: 'Image ratio is below recommendation',
        image_ratio_suboptimal: 'Image ratio is below recommendation',
        missing_focus_keyword: 'Focus keyword is missing',
        wiki_trust_missing: 'Missing at least one outbound wiki-trust link',
        keyword_missing_in_intro: 'Focus keyword is missing from the first 100 words',
        keyword_missing_in_title: 'Focus keyword is missing from the SEO title',
        keyword_missing_in_meta: 'Focus keyword is missing from the meta description',
        keyword_missing_in_slug: 'Focus keyword is missing from the URL slug',
        h2_missing: 'Need at least 2 H2 headings in the article',
        faq_missing: 'FAQ schema is missing',
        image_alt_missing: 'One or more images are missing ALT text',
    },
};

function resolveSeoReasonMessageBag(optionsMessages = {}, locale = 'vi') {
    const fromWindow = (typeof window !== 'undefined'
        && window.__SEO_RULE_MESSAGES__
        && typeof window.__SEO_RULE_MESSAGES__ === 'object')
        ? window.__SEO_RULE_MESSAGES__
        : {};
    const defaults = DEFAULT_SEO_RULE_TEMPLATES[locale] || DEFAULT_SEO_RULE_TEMPLATES.vi;
    const prefixedDefaults = {};
    Object.entries(defaults).forEach(([key, value]) => {
        prefixedDefaults[`seo_rules.${key}`] = value;
        prefixedDefaults[key] = value;
    });
    return {
        ...prefixedDefaults,
        ...fromWindow,
        ...(optionsMessages && typeof optionsMessages === 'object' ? optionsMessages : {}),
    };
}

/**
 * @param {string} key
 * @param {string} locale
 */
export function safeSeoReasonFallback(key, locale = 'vi') {
    const normalized = String(key ?? '').replace(/^seo_rules\./, '').trim();
    const pack = SAFE_FALLBACKS[locale] || SAFE_FALLBACKS.vi;
    if (pack[normalized]) {
        return pack[normalized];
    }

    return locale === 'en'
        ? 'SEO check needs attention'
        : 'Cần kiểm tra tiêu chí SEO';
}

/**
 * Build display label/summary/detail for a violation key.
 *
 * @param {string} key
 * @param {{
 *   messages?: Record<string, string>,
 *   metrics?: Record<string, number>,
 *   locale?: string,
 * }} options
 */
export function presentSeoReason(key, options = {}) {
    const normalized = String(key ?? '').replace(/^seo_rules\./, '').trim();
    const locale = options.locale === 'en' ? 'en' : 'vi';
    const messages = resolveSeoReasonMessageBag(options.messages, locale);
    const metrics = options.metrics && typeof options.metrics === 'object' ? options.metrics : {};

    const summaryKey = `seo_rules.${normalized}`;
    const detailKey = `seo_rules.${normalized}_detail`;
    const labelKey = `seo_rules.${normalized}_label`;

    const vars = {};
    Object.entries(metrics).forEach(([name, value]) => {
        vars[name] = typeof value === 'number' ? formatSeoCount(value, locale) : String(value ?? '');
    });
    // short aliases used in lang files
    if (metrics.missing_image_count != null) {
        vars.missing = formatSeoCount(metrics.missing_image_count, locale);
    }
    if (metrics.current_image_count != null) {
        vars.current = formatSeoCount(metrics.current_image_count, locale);
    }
    if (metrics.recommended_image_count != null) {
        vars.recommended = formatSeoCount(metrics.recommended_image_count, locale);
    }
    if (metrics.current_word_count != null) {
        vars.words = formatSeoCount(metrics.current_word_count, locale);
        if (vars.current == null) {
            vars.current = vars.words;
        }
    }
    if (metrics.recommended_word_count != null && vars.recommended == null) {
        vars.recommended = formatSeoCount(metrics.recommended_word_count, locale);
    }
    if (metrics.missing_word_count != null && vars.missing == null) {
        vars.missing = formatSeoCount(metrics.missing_word_count, locale);
    }

    const looksLikeCode = (value) => /^[a-z0-9]+(?:_[a-z0-9]+)+$/i.test(String(value ?? '').trim());
    const isImageRatioKey = normalized.startsWith('image_ratio_');
    const missingImages = Number(metrics.missing_image_count);
    const useGenericImageRatioCopy = isImageRatioKey
        && Number.isFinite(missingImages)
        && missingImages <= 0;

    const rawSummary = useGenericImageRatioCopy
        ? ''
        : (messages[summaryKey] || messages[normalized] || '');
    const rawDetail = messages[detailKey] || '';
    const rawLabel = messages[labelKey] || '';

    let summary = rawSummary ? interpolateSeoReasonTemplate(rawSummary, vars) : '';
    let detail = rawDetail ? interpolateSeoReasonTemplate(rawDetail, vars) : '';
    let label = rawLabel ? interpolateSeoReasonTemplate(rawLabel, vars) : '';

    if (useGenericImageRatioCopy) {
        summary = safeSeoReasonFallback(normalized, locale);
        if (!detail || looksLikeCode(detail)) {
            detail = summary;
        }
    }

    if (!summary || looksLikeCode(summary) || summary === normalized) {
        summary = safeSeoReasonFallback(normalized, locale);
        const pack = SAFE_FALLBACKS[locale] || SAFE_FALLBACKS.vi;
        // Known keys already have client defaults — only warn for truly unknown codes.
        if (
            !pack[normalized]
            && !(DEFAULT_SEO_RULE_TEMPLATES[locale] || DEFAULT_SEO_RULE_TEMPLATES.vi)[normalized]
            && typeof console !== 'undefined'
            && typeof console.warn === 'function'
        ) {
            console.warn('[seo-reason] missing translation', normalized);
        }
    }
    if (!label || looksLikeCode(label)) {
        label = summary;
    }
    if (!detail || looksLikeCode(detail)) {
        detail = summary;
    }

    return {
        code: normalized,
        label,
        summary,
        detail,
        metrics,
    };
}
