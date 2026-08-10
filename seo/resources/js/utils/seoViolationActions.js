/**
 * SEO violation → inline action map (single renderer). Aliases cover legacy keys.
 */

/** @typedef {'open-faq-generator'|'open-featured-snippet-prompt'} SeoViolationActionId */

/**
 * @type {Record<string, { labelKey: string, action: SeoViolationActionId, aliases?: string[] }>}
 */
export const SEO_VIOLATION_ACTIONS = {
    faq_missing: {
        labelKey: 'seo_violation_action_generate_faq',
        action: 'open-faq-generator',
        aliases: ['faq_schema_missing', 'schema_faq_missing', 'seo.faq_schema'],
    },
    featured_snippet_missing: {
        labelKey: 'seo_violation_action_create_prompt',
        action: 'open-featured-snippet-prompt',
        aliases: ['snippet_missing'],
    },
};

/** @type {Map<string, string>} */
const ALIAS_TO_CANONICAL = (() => {
    const map = new Map();
    for (const [canonical, def] of Object.entries(SEO_VIOLATION_ACTIONS)) {
        map.set(canonical, canonical);
        for (const alias of def.aliases ?? []) {
            map.set(String(alias), canonical);
        }
    }

    return map;
})();

/**
 * @param {string} violationKey
 * @returns {{ key: string, labelKey: string, action: SeoViolationActionId }|null}
 */
export function resolveSeoViolationAction(violationKey) {
    const raw = String(violationKey ?? '').trim();
    if (raw === '') {
        return null;
    }

    const canonical = ALIAS_TO_CANONICAL.get(raw) ?? null;
    if (!canonical) {
        return null;
    }

    const def = SEO_VIOLATION_ACTIONS[canonical];
    if (!def) {
        return null;
    }

    return {
        key: canonical,
        labelKey: def.labelKey,
        action: def.action,
    };
}
