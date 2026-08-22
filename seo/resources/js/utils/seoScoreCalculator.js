import { presentSeoReason, safeSeoReasonFallback } from './seoReasonMetrics';

const BASE_SCORE = 100;

const LEGACY_VIOLATION_KEY_MAP = {
    'seo.missing_focus_keyword': 'missing_focus_keyword',
    'seo.heading': 'h2_missing',
    'seo.length': 'content_length_low',
    'seo.image_ratio': 'image_ratio_missing',
    'seo.wiki_trust': 'wiki_trust_missing',
    'seo.faq_schema': 'faq_missing',
    'seo.keyword_density': 'keyword_missing_in_title',
};

/** Mirror SeoScoringRulesRegistry::defaultRules() keys when client rules bootstrap is missing. */
const DEFAULT_KNOWN_VIOLATION_KEYS = new Set([
    'missing_focus_keyword',
    'h2_missing',
    'content_length_low',
    'image_ratio_missing',
    'image_ratio_poor',
    'image_ratio_low',
    'image_ratio_suboptimal',
    'image_alt_missing',
    'wiki_trust_missing',
    'faq_missing',
    'faq_schema_missing',
    'keyword_missing_in_title',
    'keyword_missing_in_meta',
    'keyword_missing_in_slug',
    'keyword_missing_in_intro',
    'featured_snippet_missing',
    'featured_snippet_below_good',
    'featured_snippet_below_excellent',
]);

function resolveKnownViolationKeys(rules = []) {
    const map = rulesMap(rules);
    if (map.size > 0) {
        const keys = new Set([...map.keys()]);
        // Semantic split of FAQ content vs schema — keep visible when FAQ rule exists.
        if (keys.has('faq_missing')) {
            keys.add('faq_schema_missing');
        }
        return keys;
    }

    const fromWindow = rulesMap(window.__SEO_SCORING_RULES__ ?? []);
    if (fromWindow.size > 0) {
        const keys = new Set([...fromWindow.keys()]);
        if (keys.has('faq_missing')) {
            keys.add('faq_schema_missing');
        }
        return keys;
    }

    return DEFAULT_KNOWN_VIOLATION_KEYS;
}

function rulesMap(rules = []) {
    const map = new Map();
    (Array.isArray(rules) ? rules : []).forEach((rule) => {
        if (rule?.key) {
            map.set(String(rule.key), rule);
        }
    });

    return map;
}

export function normalizeViolationKey(key) {
    const raw = String(key ?? '').trim();
    if (raw === '' || raw.endsWith('.pass')) {
        return null;
    }

    if (LEGACY_VIOLATION_KEY_MAP[raw]) {
        return LEGACY_VIOLATION_KEY_MAP[raw];
    }

    if (DEFAULT_KNOWN_VIOLATION_KEYS.has(raw)) {
        return raw;
    }

    const map = rulesMap(window.__SEO_SCORING_RULES__ ?? []);
    if (map.has(raw)) {
        return raw;
    }

    return null;
}

/**
 * When FAQ questions/content exist, never keep a bare `faq_missing` key —
 * upgrade to `faq_schema_missing` so UI does not say "no FAQ data".
 *
 * @param {string[]} keys
 * @param {Record<string, unknown>} [metrics]
 * @returns {string[]}
 */
export function refineFaqViolationKeys(keys = [], metrics = {}) {
    const faq = metrics?.faq && typeof metrics.faq === 'object' ? metrics.faq : {};
    const count = Number(
        faq.faq_question_count
        ?? metrics?.faq_question_count
        ?? metrics?.count
        ?? 0,
    );
    const hasContent = faq.has_faq_content === true
        || (Number.isFinite(count) && count > 0);

    if (!hasContent) {
        return keys;
    }

    return [...new Set(
        keys.map((key) => (key === 'faq_missing' ? 'faq_schema_missing' : key)),
    )];
}

export function sanitizeViolations(violations = [], rules = [], metrics = {}) {
    const knownKeys = resolveKnownViolationKeys(rules);

    const normalized = [...new Set(
        (Array.isArray(violations) ? violations : [])
            .map((key) => normalizeViolationKey(key))
            .filter((key) => key !== null && knownKeys.has(key)),
    )];

    return refineFaqViolationKeys(normalized, metrics);
}

export function isRuleEnabled(key, rules = []) {
    const normalized = String(key);
    if (normalized === 'faq_schema_missing') {
        // Enabled whenever the FAQ rule is enabled (semantic sibling).
        return isRuleEnabled('faq_missing', rules);
    }
    const rule = rulesMap(rules).get(normalized);

    return rule?.enabled !== false;
}

export function deductionFor(key, rules = []) {
    const normalized = String(key);
    if (normalized === 'faq_schema_missing') {
        if (!isRuleEnabled('faq_missing', rules)) {
            return 0;
        }
        const map = rulesMap(rules);
        const schemaRule = map.get('faq_schema_missing');
        if (schemaRule) {
            return Number(schemaRule?.deduction ?? 0);
        }
        return Number(map.get('faq_missing')?.deduction ?? 10);
    }

    if (!isRuleEnabled(normalized, rules)) {
        return 0;
    }

    const map = rulesMap(rules);
    const rule = map.get(normalized);

    return Number(rule?.deduction ?? 0);
}

export function scoreFromViolations(violations = [], rules = [], metrics = {}) {
    const list = sanitizeViolations(violations, rules, metrics);

    if (
        list.includes('missing_focus_keyword')
        && isRuleEnabled('missing_focus_keyword', rules)
    ) {
        return 0;
    }

    let deduction = 0;
    list.forEach((key) => {
        deduction += deductionFor(key, rules);
    });

    return Math.max(0, Math.min(BASE_SCORE, BASE_SCORE - deduction));
}

function metricsForViolationKey(key, metrics = {}) {
    const normalized = String(key ?? '').replace(/^seo_rules\./, '');
    if (normalized === 'content_length_low') {
        return metrics?.content_length ?? metrics?.contentLength ?? {};
    }
    if (
        normalized === 'image_ratio_low'
        || normalized === 'image_ratio_poor'
        || normalized === 'image_ratio_missing'
        || normalized === 'image_ratio_suboptimal'
    ) {
        return metrics?.image_ratio ?? metrics?.imageRatio ?? {};
    }
    if (normalized === 'faq_missing' || normalized === 'faq_schema_missing') {
        const faq = metrics?.faq && typeof metrics.faq === 'object' ? metrics.faq : {};
        return {
            ...faq,
            faq_question_count: faq.faq_question_count ?? metrics?.faq_question_count ?? 0,
            count: faq.faq_question_count ?? metrics?.faq_question_count ?? 0,
        };
    }

    return {};
}

export function resolveRuleMessage(key, rules = [], messages = {}, metrics = {}, locale = 'vi') {
    const normalized = normalizeViolationKey(key) ?? String(key);
    const presented = presentSeoReason(normalized, {
        messages,
        metrics: metricsForViolationKey(normalized, metrics),
        locale,
    });

    // Never leak snake_case technical keys into UI.
    if (/^[a-z0-9]+(?:_[a-z0-9]+)+$/i.test(presented.summary)) {
        return safeSeoReasonFallback(normalized, locale);
    }

    return presented.summary;
}

export function formatViolationLine(key, rules = [], messages = {}, metrics = {}, locale = 'vi') {
    const normalized = normalizeViolationKey(key);
    if (normalized === null || !isRuleEnabled(normalized, rules)) {
        return null;
    }

    const deduction = deductionFor(normalized, rules);
    if (deduction <= 0) {
        return null;
    }

    const message = resolveRuleMessage(normalized, rules, messages, metrics, locale);

    return `-${deduction}đ: ${message}`;
}

export function buildViolationLines(violations = [], rules = [], messages = {}, metrics = {}, locale = 'vi') {
    return sanitizeViolations(violations, rules, metrics)
        .map((key) => formatViolationLine(key, rules, messages, metrics, locale))
        .filter((line) => line !== null);
}

export function buildFailedViolationItems(violations = [], rules = [], messages = {}, metrics = {}, locale = 'vi') {
    return sanitizeViolations(violations, rules, metrics)
        .map((key) => {
            if (!isRuleEnabled(key, rules)) {
                return null;
            }

            const deduction = deductionFor(key, rules);
            if (deduction <= 0) {
                return null;
            }

            const presented = presentSeoReason(key, {
                messages,
                metrics: metricsForViolationKey(key, metrics),
                locale,
            });

            return {
                key,
                label: presented.summary,
                summary: presented.summary,
                detail: presented.detail,
                metrics: presented.metrics,
                deduction,
            };
        })
        .filter((item) => item !== null);
}

export function buildPassedRuleItems(violations = [], rules = [], messages = {}, metrics = {}) {
    const failedKeys = new Set(sanitizeViolations(violations, rules, metrics));
    const list = Array.isArray(rules) ? rules : [];
    const enabledRules = list.filter((rule) => rule?.enabled !== false && rule?.key);
    const enabledKeySet = new Set(enabledRules.map((rule) => String(rule.key)));
    const passed = [];
    const consumedKeys = new Set();

    PASSED_RULE_DISPLAY_GROUPS.forEach((group) => {
        const activeKeys = group.keys.filter((key) => enabledKeySet.has(key));
        if (activeKeys.length === 0) {
            return;
        }

        if (activeKeys.some((key) => failedKeys.has(key))) {
            activeKeys.forEach((key) => consumedKeys.add(key));

            return;
        }

        passed.push({
            key: activeKeys[0],
            label: group.label,
        });
        activeKeys.forEach((key) => consumedKeys.add(key));
    });

    enabledRules.forEach((rule) => {
        const key = String(rule.key);
        if (consumedKeys.has(key) || failedKeys.has(key)) {
            return;
        }

        passed.push({
            key,
            label: passedRuleDisplayName(key),
        });
    });

    return passed;
}

const PASSED_RULE_DISPLAY_GROUPS = [
    {
        label: 'Image Ratio',
        keys: ['image_ratio_missing', 'image_ratio_poor', 'image_ratio_low', 'image_ratio_suboptimal'],
    },
    {
        label: 'Featured Snippet',
        keys: ['featured_snippet_missing', 'featured_snippet_below_good', 'featured_snippet_below_excellent'],
    },
];

const PASSED_RULE_DISPLAY_NAMES = {
    missing_focus_keyword: 'Focus Keyword',
    h2_missing: 'Heading Structure',
    content_length_low: 'Content Length',
    image_ratio_missing: 'Image Ratio',
    image_ratio_poor: 'Image Ratio',
    image_ratio_low: 'Image Ratio',
    image_ratio_suboptimal: 'Image Ratio',
    image_alt_missing: 'Image ALT',
    wiki_trust_missing: 'Wiki Trust Links',
    faq_missing: 'FAQ Schema',
    keyword_missing_in_title: 'Keyword in Title',
    keyword_missing_in_meta: 'Meta Description',
    keyword_missing_in_slug: 'Slug',
    keyword_missing_in_intro: 'Keyword in Intro',
    featured_snippet_missing: 'Featured Snippet',
    featured_snippet_below_good: 'Featured Snippet',
    featured_snippet_below_excellent: 'Featured Snippet',
};

export function passedRuleDisplayName(key) {
    const normalized = String(key ?? '').trim();
    if (normalized === '') {
        return '';
    }

    if (PASSED_RULE_DISPLAY_NAMES[normalized]) {
        return PASSED_RULE_DISPLAY_NAMES[normalized];
    }

    return normalized
        .split('_')
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function scoreQualityLabel(score) {
    const value = typeof score === 'number' ? score : 0;

    if (value >= 70) {
        return 'Good';
    }

    if (value >= 50) {
        return 'Fair';
    }

    return 'Needs work';
}
