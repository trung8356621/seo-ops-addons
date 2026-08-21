/**
 * Focused FAQ scoring ownership semantics (mirrors seoAnalyzer.resolveFaqsForScoring).
 * Full analyzer import needs Vite aliases — keep this pure for Node.
 */

function normalizeFaqs(faqs) {
    if (!Array.isArray(faqs)) {
        return [];
    }

    return faqs.filter((item) => {
        const question = String(item?.question ?? '').trim();
        const answer = String(item?.answer ?? '').trim();

        return question !== '' && answer !== '';
    });
}

function isFaqPlaceholderHtml(html) {
    return /omi-faq-placeholder|\[omi_faq\]/i.test(String(html ?? ''));
}

function parseFaqsFromHtmlForScoring(html) {
    const source = String(html ?? '').trim();
    if (source === '') {
        return [];
    }
    if (isFaqPlaceholderHtml(source)) {
        return [{ question: '[omi_faq]', answer: 'shortcode' }];
    }

    return [];
}

function resolveFaqsForScoring(html, faqs, documentModelPlaceholders = 0) {
    if (Array.isArray(faqs)) {
        return normalizeFaqs(faqs);
    }
    if (documentModelPlaceholders > 0) {
        return [{ question: '[omi_faq]', answer: 'shortcode' }];
    }

    return parseFaqsFromHtmlForScoring(html);
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

assert(
    resolveFaqsForScoring('<p>[omi_faq]</p>', []).length === 0,
    'known empty [] must ignore stale shortcode',
);
assert(
    resolveFaqsForScoring('<p>[omi_faq]</p>', null).length === 1,
    'unknown null may use shortcode fallback',
);
assert(
    resolveFaqsForScoring('<p>[omi_faq]</p>', undefined).length === 1,
    'unknown undefined may use shortcode fallback',
);
assert(
    resolveFaqsForScoring('<p>no faq</p>', [{ question: 'Q?', answer: '<p>A</p>' }]).length === 1,
    'valid FAQ counts',
);
assert(
    resolveFaqsForScoring('<p>[omi_faq]</p>', [{ question: '', answer: '' }]).length === 0,
    'empty row in known array still empty after normalize',
);

console.log('seoAnalyzer.faqScoring.selftest: ok');
