/**
 * Canonical FAQ content vs schema selectors for Article Editor + SEO analysis.
 * Keep FAQ widget and SEO analyzer on the same semantic model.
 */

/**
 * @param {unknown} row
 * @returns {boolean}
 */
export function isValidFaqQuestionRow(row) {
    if (!row || typeof row !== 'object') {
        return false;
    }
    const question = String(row.question ?? '').trim();
    const answer = String(row.answer ?? '').trim();
    if (question === '' || answer === '') {
        return false;
    }
    // Shortcode / placeholder markers are content signals, not schema-ready rows.
    if (question === '[omi_faq]' || answer === 'shortcode' || answer === 'detected') {
        return false;
    }
    return true;
}

/**
 * @param {unknown} faqs
 * @returns {Array<{question: string, answer: string}>}
 */
export function normalizeCanonicalFaqRows(faqs) {
    if (!Array.isArray(faqs)) {
        return [];
    }
    return faqs.filter(isValidFaqQuestionRow).map((row) => ({
        question: String(row.question ?? '').trim(),
        answer: String(row.answer ?? '').trim(),
    }));
}

/**
 * @param {string|null|undefined} html
 * @returns {boolean}
 */
export function htmlHasFaqContentSignal(html) {
    const source = String(html ?? '');
    if (source === '') {
        return false;
    }
    return /omi-faq-placeholder|\[omi_faq\]|omi-faq-item/i.test(source);
}

/**
 * @param {{
 *   faqs?: unknown,
 *   faqCountHint?: number|null,
 *   html?: string|null,
 *   documentHasFaqPlaceholder?: boolean,
 * }} input
 * @returns {{
 *   faq_question_count: number,
 *   has_faq_content: boolean,
 *   has_faq_schema: boolean,
 *   faqs_known: boolean,
 *   faqs_for_scoring: Array|null,
 * }}
 */
export function selectCanonicalFaqState(input = {}) {
    const faqsKnown = Array.isArray(input.faqs);
    const schemaRows = faqsKnown ? normalizeCanonicalFaqRows(input.faqs) : [];
    const hint = Math.max(0, Number(input.faqCountHint ?? 0) || 0);
    // HTML / placeholder signals apply only while FAQ owner rows are unhydrated.
    const htmlSignal = !faqsKnown && (
        htmlHasFaqContentSignal(input.html)
        || input.documentHasFaqPlaceholder === true
    );

    const faq_question_count = faqsKnown
        ? Math.max(schemaRows.length, hint)
        : Math.max(hint, htmlSignal ? 1 : 0);

    // Bootstrap faqCount comes from DB faqs()->count() — those rows ARE the FAQ schema.
    // Only HTML/shortcode-without-count is "content without schema".
    const has_faq_schema = faqsKnown
        ? schemaRows.length > 0
        : hint > 0;
    const has_faq_content = has_faq_schema || faq_question_count > 0 || htmlSignal;

    return {
        faq_question_count,
        has_faq_content,
        has_faq_schema,
        faqs_known: faqsKnown,
        // null = unhydrated owner state (analyzer may use HTML fallback)
        faqs_for_scoring: faqsKnown ? schemaRows : null,
    };
}

/**
 * Build SEO FAQ violation codes from canonical state.
 *
 * @param {ReturnType<typeof selectCanonicalFaqState>} state
 * @returns {string[]}
 */
export function resolveFaqSeoViolationCodes(state) {
    if (!state?.has_faq_content) {
        return ['faq_missing'];
    }
    if (!state.has_faq_schema) {
        return ['faq_schema_missing'];
    }
    return [];
}

/**
 * Locale-aware FAQ SEO messages (Editor/React). Prefer project message bags when present.
 *
 * @param {string} code
 * @param {{ faq_question_count?: number, locale?: string }} [opts]
 * @returns {string}
 */
export function formatFaqSeoViolationMessage(code, opts = {}) {
    const locale = String(opts.locale || 'vi').toLowerCase().startsWith('en') ? 'en' : 'vi';
    const count = Math.max(0, Number(opts.faq_question_count ?? 0) || 0);

    if (code === 'faq_schema_missing') {
        if (locale === 'en') {
            return count > 0
                ? `There are ${count} FAQ questions but FAQ schema is missing.`
                : 'FAQ content exists but FAQ schema is missing.';
        }
        return count > 0
            ? `Đã có ${count} câu hỏi FAQ nhưng chưa có FAQ schema.`
            : 'Đã có nội dung FAQ nhưng chưa có FAQ schema.';
    }

    if (locale === 'en') {
        return 'FAQ content is missing.';
    }
    return 'Thiếu dữ liệu FAQ.';
}
