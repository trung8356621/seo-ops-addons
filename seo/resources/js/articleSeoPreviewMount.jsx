import React from 'react';
import { createRoot } from 'react-dom/client';
import SeoScorePanel from './components/SeoScorePanel';

/** @type {Map<HTMLElement, import('react-dom/client').Root>} */
const mountedRoots = new Map();

export function unmountArticleSeoPreview(rootEl) {
    if (!rootEl) {
        return;
    }

    const existing = mountedRoots.get(rootEl);
    if (existing) {
        existing.unmount();
        mountedRoots.delete(rootEl);
    }
    rootEl.innerHTML = '';
}

/**
 * @param {HTMLElement} rootEl
 * @param {{ focus_keyword?: string|null, analysis?: object|null, extracted_links?: object }} payload
 */
export function mountArticleSeoPreview(rootEl, payload) {
    if (!rootEl || !payload) {
        return;
    }

    unmountArticleSeoPreview(rootEl);

    const root = createRoot(rootEl);
    mountedRoots.set(rootEl, root);

    root.render(
        <SeoScorePanel
            focusKeyword={payload.focus_keyword ?? null}
            analysis={payload.analysis ?? { violations: payload.violations ?? [] }}
            seoScoringRules={payload.seo_scoring_rules ?? []}
            seoRuleMessages={payload.seo_rule_messages ?? payload.seo_scoring_messages ?? {}}
            loading={false}
            analyzing={false}
        />,
    );
}
