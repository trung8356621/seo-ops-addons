import { normalizeArticleSlug } from '@content-addon/utils/articleSlugUtils.js';
import { isFaqPlaceholderHtml } from '@content-addon/utils/editorHtmlUtils.js';
import {
    containsKeywordPhrase,
    normalizePhrase,
} from './keywordPhraseMatcher';
import {
    buildViolationLines,
    isRuleEnabled,
    resolveRuleMessage,
    sanitizeViolations,
    scoreFromViolations,
} from './seoScoreCalculator';
import { resolveFeaturedSnippetViolationFromTables } from './seoContentBonus';
import {
    TARGET_WORDS_PER_IMAGE,
    computeContentLengthMetrics,
    computeImageRatioMetrics,
} from './seoReasonMetrics';
import {
    getAnalysisPolicy,
    normalizeViolationList,
    wordsPerImageFromPolicy,
} from './articleAnalysisOwnership';
import { DEFAULT_WIKI_TRUST_DOMAINS, isWikiTrustUrl, normalizeDomainHost, resolveLinkHost } from './wikiTrustDomains';
import {
    createDocumentModel,
    sliceFirstWordsFromModel,
} from '@content-addon/utils/documentModel.js';
import { selectFaqPlaceholders, selectLinks } from '@content-addon/utils/documentSelectors.js';
import { blocksToDocumentJson, htmlToDocumentJson } from '@content-addon/utils/htmlDocumentCompat.js';
import { createCurrentDraftAnalysisSnapshot } from '@content-addon/utils/currentDraftAnalysisSnapshot.js';

const RULE_KEYS = {
    missingFocusKeyword: 'missing_focus_keyword',
    h2Missing: 'h2_missing',
    contentLengthLow: 'content_length_low',
    imageRatioMissing: 'image_ratio_missing',
    imageRatioPoor: 'image_ratio_poor',
    imageRatioLow: 'image_ratio_low',
    imageRatioSuboptimal: 'image_ratio_suboptimal',
    imageAltMissing: 'image_alt_missing',
    wikiTrustMissing: 'wiki_trust_missing',
    faqMissing: 'faq_missing',
    keywordMissingInTitle: 'keyword_missing_in_title',
    keywordMissingInMeta: 'keyword_missing_in_meta',
    keywordMissingInSlug: 'keyword_missing_in_slug',
    keywordMissingInIntro: 'keyword_missing_in_intro',
};

export function resolveScoringMessage(key, messages = {}, params = {}) {
    let template = resolveRuleMessage(key, [], messages);
    if (template === key && String(key).startsWith('seo_rules.')) {
        template = String(messages?.[key] ?? key);
    }
    Object.entries(params).forEach(([name, value]) => {
        template = template.replaceAll(`:${name}`, String(value));
    });

    return template;
}

function normalizeFocusKeyword(raw) {
    let source = raw;
    if (source && typeof source === 'object') {
        source = source.phrase ?? source.focus_keyword ?? source.keyword ?? source.label ?? '';
    }

    const value = String(source ?? '').trim();
    if (value === '') {
        return '';
    }

    // Matching always case-insensitive — lowercase early for meta/title/intro checks.
    const primary = value.includes(',')
        ? (value.split(',')[0]?.trim() ?? '')
        : value;

    return primary.toLocaleLowerCase();
}

/**
 * Phase 3: resolve TipTap/PM DocumentModel. HTML / blocks are compat adapters only.
 *
 * @param {{
 *   document?: object|null,
 *   documentModel?: object|null,
 *   blocks?: array|null,
 *   html?: string,
 * }} input
 */
export function resolveAnalysisDocumentModel(input = {}) {
    if (input.documentModel && typeof input.documentModel.wordCount === 'function') {
        return input.documentModel;
    }
    if (input.document && typeof input.document === 'object') {
        return createDocumentModel(input.document);
    }
    if (Array.isArray(input.blocks) && input.blocks.length > 0) {
        return createDocumentModel(blocksToDocumentJson(input.blocks));
    }

    return createDocumentModel(htmlToDocumentJson(input.html ?? ''));
}

/**
 * Links from DocumentModel (canonical). Falls back to HTML regex only if model empty + html provided.
 */
export function extractLinksFromDocument(docOrModel, domain, htmlFallback = '') {
    const model = docOrModel && typeof docOrModel.links === 'function'
        ? docOrModel
        : createDocumentModel(docOrModel);
    const result = { internal: [], external: [] };
    const rows = selectLinks(model);

    rows.forEach((row) => {
        const href = String(row.href ?? '').trim();
        if (href === '' || href.startsWith('#') || isSpecialSchemeLink(href)) {
            return;
        }
        const text = String(row.text ?? '').replace(/\s+/g, ' ').trim();
        const item = { href, text, is_nofollow: false, offset: 0 };
        if (isInternalLink(href, domain)) {
            result.internal.push(item);
        } else {
            result.external.push(item);
        }
    });

    result.internal = deduplicateLinksByHrefAndText(result.internal);
    result.external = deduplicateLinksByHrefAndText(result.external);

    if (result.internal.length === 0 && result.external.length === 0 && String(htmlFallback ?? '').trim() !== '') {
        return extractLinks(htmlFallback, domain);
    }

    return result;
}

function slugContainsFocusKeyword(slug, focusKeyword) {
    const keywordSlug = normalizeArticleSlug(normalizeFocusKeyword(focusKeyword));
    const articleSlug = normalizeArticleSlug(slug);

    if (keywordSlug === '' || articleSlug === '') {
        return false;
    }

    return articleSlug.includes(keywordSlug);
}

function countWords(html) {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return 0;
    }

    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu);

    return matches ? matches.length : 0;
}

function countWordsForImageRatio(html) {
    // Eligible prose only — never ALT/caption/filename/slug/hidden media chrome.
    const withoutMediaChrome = String(html ?? '')
        .replace(/<figcaption\b[^>]*>[\s\S]*?<\/figcaption>/giu, ' ')
        .replace(/<figure\b[^>]*>[\s\S]*?<\/figure>/giu, ' ')
        .replace(/<img\b[^>]*>/giu, ' ')
        .replace(/\s(?:alt|title|aria-label|data-filename|data-slug)\s*=\s*(["'])[\s\S]*?\1/giu, ' ');
    const text = withoutMediaChrome.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return 0;
    }

    // Same tokenizer as body word count (Unicode letters) — avoid whitespace split drift.
    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu);

    return matches ? matches.length : 0;
}

function sliceFirstWords(html, wordLimit) {
    const text = String(html ?? '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return '';
    }

    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu) ?? [];

    return matches.slice(0, Math.max(1, wordLimit)).join(' ');
}

function countH2Tags(html) {
    if (typeof document === 'undefined') {
        return (String(html ?? '').match(/<h2\b[^>]*>/gi) ?? []).length;
    }

    const container = document.createElement('div');
    container.innerHTML = String(html ?? '');

    return container.querySelectorAll('h2').length;
}

function isSpecialSchemeLink(href) {
    const lower = String(href ?? '').trim().toLowerCase();
    if (lower === '') {
        return false;
    }

    if (lower.startsWith('javascript:')) {
        return true;
    }

    const match = lower.match(/^([a-z][a-z0-9+.-]*):/i);
    if (match) {
        return ['tel', 'mailto', 'sms', 'fax', 'callto', 'geo', 'skype', 'whatsapp', 'viber', 'data', 'cid'].includes(
            match[1].toLowerCase(),
        );
    }

    const raw = String(href ?? '').trim();
    if (/^[+]?[\d\s().-]{6,}$/u.test(raw)) {
        return true;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(raw);
}

function isInternalLink(href, domain) {
    const value = String(href ?? '').trim();
    if (value.startsWith('/')) {
        return true;
    }

    const host = resolveLinkHost(value.startsWith('//') ? `https:${value}` : value);
    const normalizedDomain = normalizeDomainHost(domain);

    return host !== '' && normalizedDomain !== '' && host === normalizedDomain;
}

function normalizeLinkHrefForDedup(href) {
    return String(href ?? '').trim().toLowerCase().replace(/\/+$/, '');
}

function deduplicateLinksByHrefAndText(links) {
    const seen = new Set();
    const unique = [];

    links.forEach((link) => {
        const href = normalizeLinkHrefForDedup(link.href);
        const text = normalizePhrase(link.text);
        const key = `${href}\0${text}`;

        if (href === '' || seen.has(key)) {
            return;
        }

        seen.add(key);
        unique.push(link);
    });

    return unique;
}

export function extractLinks(content, domain) {
    const result = {
        internal: [],
        external: [],
    };

    const source = String(content ?? '').trim();
    if (source === '') {
        return result;
    }

    const pattern = /<a\b([^>]*\bhref\s*=\s*(["'])([^"']+)\2[^>]*)>([\s\S]*?)<\/a>/giu;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        const attrs = match[1] ?? '';
        const href = String(match[3] ?? '').trim();
        if (href === '' || href.startsWith('#') || isSpecialSchemeLink(href)) {
            continue;
        }

        const innerHtml = match[4] ?? '';
        const text = String(innerHtml).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        const relMatch = attrs.match(/\brel\s*=\s*(["'])([^"']*)\1/i);
        const rel = relMatch ? relMatch[2].toLowerCase() : '';
        const isNofollow = rel.includes('nofollow');
        const offset = match.index ?? 0;

        const item = {
            href,
            text,
            is_nofollow: isNofollow,
            offset,
        };

        if (isInternalLink(href, domain)) {
            result.internal.push(item);
        } else {
            result.external.push(item);
        }
    }

    result.internal = deduplicateLinksByHrefAndText(result.internal);
    result.external = deduplicateLinksByHrefAndText(result.external);

    return result;
}

function queryValidContentImages(htmlContent) {
    if (typeof document === 'undefined') {
        const matches = String(htmlContent ?? '').match(/<img\b[^>]*>/gi) ?? [];
        return matches
            .map((tag) => {
                const srcMatch = tag.match(/\bsrc\s*=\s*(["'])([^"']*)\1/i);
                const altMatch = tag.match(/\balt\s*=\s*(["'])([^"']*)\1/i);
                return {
                    getAttribute: (name) => {
                        if (name === 'src') {
                            return srcMatch?.[2] ?? '';
                        }
                        if (name === 'alt') {
                            return altMatch?.[2] ?? '';
                        }
                        return '';
                    },
                };
            })
            .filter((img) => {
                const src = String(img.getAttribute('src') ?? '').trim();
                return src !== '' && !/placeholder/i.test(src);
            });
    }

    const container = document.createElement('div');
    container.innerHTML = String(htmlContent ?? '');

    return Array.from(container.querySelectorAll('img')).filter((img) => {
        const src = String(img.getAttribute('src') ?? '').trim();
        return src !== '' && !/placeholder/i.test(src);
    });
}

function calculateTextToImageMetrics(htmlContent, {
    wordsPerImage = TARGET_WORDS_PER_IMAGE,
    validImageCountOverride = null,
} = {}) {
    return computeImageRatioMetrics(htmlContent, {
        countWordsForImageRatio,
        queryImages: queryValidContentImages,
        wordsPerImage,
        validImageCountOverride,
    });
}

function hasWikiTrustExternalLink(extractedLinks, wikiTrustDomains) {
    return (extractedLinks.external ?? []).some((link) => isWikiTrustUrl(link.href, wikiTrustDomains));
}

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

function parseFaqHeadingPairs(container) {
    const faqs = [];

    container.querySelectorAll('h3').forEach((heading) => {
        const question = String(heading.textContent ?? '').trim();
        if (question === '') {
            return;
        }

        let answer = '';
        let sibling = heading.nextElementSibling;

        while (sibling) {
            const tag = sibling.tagName.toLowerCase();
            if (['h1', 'h2', 'h3'].includes(tag)) {
                break;
            }

            if (tag === 'p') {
                const text = String(sibling.textContent ?? '').trim();
                if (text !== '') {
                    answer = answer === '' ? text : `${answer} ${text}`;
                }
            }

            sibling = sibling.nextElementSibling;
        }

        if (answer !== '') {
            faqs.push({ question, answer });
        }
    });

    return faqs;
}

function parseFaqsFromHtmlForScoring(html) {
    const source = String(html ?? '').trim();
    if (source === '') {
        return [];
    }

    if (typeof document === 'undefined') {
        if (isFaqPlaceholderHtml(source) || /omi-faq-item/i.test(source)) {
            return [{ question: 'FAQ', answer: 'detected' }];
        }

        return [];
    }

    const container = document.createElement('div');
    container.innerHTML = source;

    const fromAccordion = [];
    container.querySelectorAll('.omi-faq-item').forEach((item) => {
        const question = String(item.querySelector('.omi-faq-item__question')?.textContent ?? '').trim();
        const answer = String(item.querySelector('.omi-faq-item__answer')?.textContent ?? '').trim();

        if (question !== '' && answer !== '') {
            fromAccordion.push({ question, answer });
        }
    });

    if (fromAccordion.length > 0) {
        return fromAccordion;
    }

    const fromHeadings = parseFaqHeadingPairs(container);
    if (fromHeadings.length > 0) {
        return fromHeadings;
    }

    if (isFaqPlaceholderHtml(source)) {
        return [{ question: '[omi_faq]', answer: 'shortcode' }];
    }

    return [];
}

export function resolveFaqsForScoring(html, faqs, documentModel = null) {
    // Array (including []) = canonical/known FAQ owner state.
    // null/undefined = unknown/unhydrated — DocumentModel/HTML fallback allowed.
    if (Array.isArray(faqs)) {
        return normalizeFaqs(faqs);
    }

    // Prefer DocumentModel placeholder nodes over HTML parse.
    if (documentModel && selectFaqPlaceholders(documentModel).length > 0) {
        return [{ question: '[omi_faq]', answer: 'shortcode' }];
    }

    return parseFaqsFromHtmlForScoring(html);
}

function resolveArticleLengthTarget(postType, settings = {}) {
    const normalized = String(postType ?? '').trim();
    const isProduct = normalized === 'product';
    const raw = isProduct ? settings.article_length_product : settings.article_length_default;
    const fallback = isProduct ? 1000 : 2000;
    const parsed = Number.parseInt(String(raw ?? ''), 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function resolveFeaturedSnippetViolation(snapshot, thresholds = {}) {
    return resolveFeaturedSnippetViolationFromTables(snapshot?.tables ?? [], {
        rows_min: Number(thresholds?.rows_min ?? 6),
        rows_range: Number(thresholds?.rows_range ?? 8),
        rows_max: Number(thresholds?.rows_max ?? 10),
        min_columns: Number(thresholds?.min_columns ?? 2),
        max_columns: Number(thresholds?.max_columns ?? 5),
    });
}

function resolveImageRatioViolations(html, imageMetricsOverride = null, wordsPerImage = TARGET_WORDS_PER_IMAGE) {
    const metrics = imageMetricsOverride || calculateTextToImageMetrics(html, { wordsPerImage });
    const missingAlt = metrics.missingAlt;
    const missing = Math.max(0, Number(metrics.missing_image_count) || 0);
    const validCount = Math.max(0, Number(metrics.valid_image_count) || 0);
    const wordCount = Math.max(0, Number(metrics.current_word_count) || 0);
    const violations = [];

    // Soft optimization warnings from missing vs recommended (policy words/image).
    if (wordCount >= 10 && validCount === 0) {
        violations.push(RULE_KEYS.imageRatioMissing);
    } else if (missing >= 3) {
        violations.push(RULE_KEYS.imageRatioPoor);
    } else if (missing >= 2) {
        violations.push(RULE_KEYS.imageRatioLow);
    } else if (missing === 1) {
        violations.push(RULE_KEYS.imageRatioSuboptimal);
    }

    if (missingAlt > 0) {
        violations.push(RULE_KEYS.imageAltMissing);
    }

    return violations;
}

function resolveKeywordViolations({ html, keyword, seoTitle, metaDescription, slug, introText = null }) {
    const violations = [];
    // Explicit lowercase before meta compare (defense in depth; normalizePhrase also lowercases).
    const keywordForMatch = String(keyword ?? '').toLocaleLowerCase();

    if (!containsKeywordPhrase(seoTitle, keywordForMatch)) {
        violations.push(RULE_KEYS.keywordMissingInTitle);
    }
    if (!containsKeywordPhrase(metaDescription, keywordForMatch)) {
        violations.push(RULE_KEYS.keywordMissingInMeta);
    }
    if (!slugContainsFocusKeyword(slug, keywordForMatch)) {
        violations.push(RULE_KEYS.keywordMissingInSlug);
    }
    const intro = introText != null ? String(introText) : sliceFirstWords(html, 100);
    if (!containsKeywordPhrase(intro, keywordForMatch)) {
        violations.push(RULE_KEYS.keywordMissingInIntro);
    }

    return violations;
}

function sanitizeViolationList(violations, seoScoringRules = []) {
    return sanitizeViolations(violations, seoScoringRules).filter((key) => isRuleEnabled(key, seoScoringRules));
}

function computeViolations({
    focusKeyword,
    seoTitle,
    content,
    slug,
    metaDescription,
    siteDomain,
    faqs,
    wikiTrustDomains,
    articleLengthTarget = 2000,
    featuredSnippetThresholds = {},
    seoScoringRules = [],
    imageMetricsOverride = null,
    wordsPerImage = TARGET_WORDS_PER_IMAGE,
    snapshot = null,
}) {
    const keyword = normalizeFocusKeyword(focusKeyword);
    const violations = [];
    const currentDraft = snapshot || createCurrentDraftAnalysisSnapshot({ html: content });
    const model = currentDraft.documentModel;
    const wordCount = currentDraft.wordCount;
    const h2Count = currentDraft.h2Count;
    const extractedLinks = extractLinksFromDocument(model, siteDomain, content);
    const introText = sliceFirstWordsFromModel(model, 100);

    if (h2Count < 2) {
        violations.push(RULE_KEYS.h2Missing);
    }

    if (wordCount < Math.max(1, Number(articleLengthTarget) || 2000)) {
        violations.push(RULE_KEYS.contentLengthLow);
    }

    violations.push(...resolveImageRatioViolations(content, imageMetricsOverride, wordsPerImage));

    if (!hasWikiTrustExternalLink(extractedLinks, wikiTrustDomains)) {
        violations.push(RULE_KEYS.wikiTrustMissing);
    }

    if (resolveFaqsForScoring(content, faqs, model).length === 0) {
        violations.push(RULE_KEYS.faqMissing);
    }

    violations.push(
        ...resolveKeywordViolations({
            html: content,
            keyword,
            seoTitle,
            metaDescription,
            slug,
            introText,
        }),
    );

    const snippetViolation = resolveFeaturedSnippetViolation(currentDraft, featuredSnippetThresholds);
    if (snippetViolation) {
        violations.push(snippetViolation);
    }

    const lengthTarget = Math.max(1, Number(articleLengthTarget) || 2000);
    const imageMetrics = imageMetricsOverride || calculateTextToImageMetrics(content, { wordsPerImage });
    // Prefer DocumentModel word count for content_length metrics.
    const contentLengthMetrics = computeContentLengthMetrics(wordCount, lengthTarget);
    if (imageMetrics && typeof imageMetrics === 'object') {
        imageMetrics.current_word_count = wordCount;
    }

    return {
        violations: sanitizeViolationList(violations, seoScoringRules),
        extracted_links: extractedLinks,
        metrics: {
            image_ratio: imageMetrics,
            content_length: contentLengthMetrics,
            target_words_per_image: wordsPerImage,
            document_owner: 'tiptap_json',
        },
    };
}

export function computeSeoAnalysis({
    html = '',
    document: documentJson = null,
    documentModel: incomingModel = null,
    blocks = null,
    focusKeyword = '',
    seoTitle = '',
    metaDescription = '',
    slug = '',
    siteDomain = '',
    faqs = undefined,
    wikiTrustDomains = DEFAULT_WIKI_TRUST_DOMAINS,
    scoringMessages = {},
    seoScoringRules = [],
    postType = 'article',
    articleLengthSettings = {},
    featuredSnippetThresholds = {},
    analysisPolicy = null,
    articleId = null,
    mediaSnapshot = null,
    externalFacts = null,
} = {}) {
    const policy = analysisPolicy || getAnalysisPolicy();
    const keyword = normalizeFocusKeyword(focusKeyword);
    const content = String(html ?? '');
    const wordsPerImage = wordsPerImageFromPolicy(policy);
    const snapshot = incomingModel && typeof incomingModel.wordCount === 'function'
        ? createCurrentDraftAnalysisSnapshot({
            html: content,
            document: incomingModel.json(),
        })
        : createCurrentDraftAnalysisSnapshot({
            html: content,
            document: documentJson,
            blocks,
        });
    const documentModel = snapshot.documentModel;
    const modelWordCount = snapshot.wordCount;

    const lengthSettings = {
        article_length_product: policy?.content?.article_length_product
            ?? articleLengthSettings?.article_length_product,
        article_length_default: policy?.content?.article_length_default
            ?? articleLengthSettings?.article_length_default,
    };
    const lengthTarget = resolveArticleLengthTarget(postType || policy?.article_type || 'article', lengthSettings);

    if (mediaSnapshot && articleId) {
        // Ensure snapshot counts available to resolveContentImageCounts via store.
    }

    const imageMetrics = computeImageRatioMetrics('', {
        countWordsForImageRatio: () => modelWordCount,
        wordsPerImage,
        validImageCountOverride: snapshot.imageCount,
        missingAltOverride: snapshot.missingImageAltCount,
    });
    imageMetrics.count_source = snapshot.source;
    const contentLengthMetrics = computeContentLengthMetrics(modelWordCount, lengthTarget);
    const rules = Array.isArray(policy?.seo_scoring_rules) && policy.seo_scoring_rules.length > 0
        ? policy.seo_scoring_rules
        : seoScoringRules;
    const snippetThresholds = policy?.featured_snippet_thresholds && typeof policy.featured_snippet_thresholds === 'object'
        ? policy.featured_snippet_thresholds
        : featuredSnippetThresholds;

    if (keyword === '') {
        const extractedLinks = extractLinksFromDocument(documentModel, siteDomain, content);
        const violations = isRuleEnabled(RULE_KEYS.missingFocusKeyword, rules)
            ? [RULE_KEYS.missingFocusKeyword]
            : [];

        return {
            violations: normalizeViolationList(violations, policy),
            score: scoreFromViolations(violations, rules),
            seo_score: scoreFromViolations(violations, rules),
            errors: buildViolationLines(violations, rules, scoringMessages, {
                content_length: contentLengthMetrics,
                image_ratio: imageMetrics,
            }),
            good: [],
            warnings: [],
            extracted_links: extractedLinks,
            metrics: {
                image_ratio: imageMetrics,
                content_length: contentLengthMetrics,
                target_words_per_image: wordsPerImage,
                draft_structure: {
                    source: snapshot.source,
                    heading_count: snapshot.headings.length,
                    h2_count: snapshot.h2Count,
                    image_count: snapshot.imageCount,
                    table_count: snapshot.tables.length,
                    list_count: snapshot.lists.length,
                    link_count: snapshot.links.length,
                },
                document_owner: 'tiptap_json',
            },
            policy_version: Number(policy?.version) || 1,
            analysis_owner: 'react_immediate',
        };
    }

    const result = computeViolations({
        focusKeyword: keyword,
        seoTitle,
        content,
        slug,
        metaDescription,
        siteDomain,
        faqs,
        wikiTrustDomains,
        articleLengthTarget: lengthTarget,
        featuredSnippetThresholds: snippetThresholds,
        seoScoringRules: rules,
        imageMetricsOverride: imageMetrics,
        wordsPerImage,
        snapshot,
    });

    let violations = normalizeViolationList(result.violations, policy);

    // External fact: wiki trust — only when server says refresh not required / checked false.
    if (
        externalFacts?.trust
        && externalFacts.trust.refresh_required === false
        && externalFacts.trust.has_trusted_source === false
        && isRuleEnabled(RULE_KEYS.wikiTrustMissing, rules)
        && !violations.includes(RULE_KEYS.wikiTrustMissing)
    ) {
        // Keep client HTML wiki check as primary; external fact only reinforces when present.
    }

    const score = scoreFromViolations(violations, rules);
    const metrics = {
        ...(result.metrics ?? {}),
        image_ratio: imageMetrics,
        content_length: contentLengthMetrics,
        target_words_per_image: wordsPerImage,
        draft_structure: {
            source: snapshot.source,
            heading_count: snapshot.headings.length,
            h2_count: snapshot.h2Count,
            image_count: snapshot.imageCount,
            table_count: snapshot.tables.length,
            list_count: snapshot.lists.length,
            link_count: snapshot.links.length,
        },
    };
    const errors = buildViolationLines(violations, rules, scoringMessages, metrics);

    return {
        violations,
        score,
        seo_score: score,
        errors,
        good: violations.length === 0 ? [resolveScoringMessage('seo_rules.all_passed', scoringMessages)] : [],
        warnings: [],
        extracted_links: result.extracted_links,
        metrics,
        policy_version: Number(policy?.version) || 1,
        analysis_owner: 'react_immediate',
    };
}

export function buildSeoAnalysisPayload(analysis) {
    if (!analysis || typeof analysis !== 'object') {
        return null;
    }

    return {
        violations: Array.isArray(analysis.violations) ? analysis.violations : [],
        extracted_links: analysis.extracted_links ?? { internal: [], external: [] },
        metrics: analysis.metrics ?? null,
    };
}

// Backward compat for tests importing calculateTextToImageScore
export function calculateTextToImageScore(htmlContent) {
    const { baseScore, missingAlt } = calculateTextToImageMetrics(htmlContent);
    let score = baseScore;
    if (missingAlt > 0) {
        score = Math.max(0, score - 5);
    }

    return { score, ratio: 0 };
}
