/**
 * Collapsed-section baseline offsets for Outline section mapping / resize restore.
 * Baseline is NOT the final Outline scroll destination — jump measures live DOM after expand.
 */

import {
    editorScrollOffset,
    scrollEditorTargetIntoViewAsync,
    selectEditorScroller,
} from './ctaSectionNavigator.js';

export function waitForNextPaint() {
    return new Promise((resolve) => {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => resolve());
        });
    });
}

function mainPane() {
    return document.querySelector('.seo-article-editor-mainpane');
}

function sectionElements() {
    const root = mainPane();
    if (!root) {
        return [];
    }

    return [...root.querySelectorAll('section[data-seo-section-id]')];
}

export function sectionStructureKey(sections) {
    if (!Array.isArray(sections) || sections.length === 0) {
        return '';
    }

    return sections.map((section) => String(section?.id ?? '')).join('|');
}

export function buildCollapsedBaselineMap() {
    const sections = sectionElements();
    if (sections.length === 0) {
        return new Map();
    }

    const container = selectEditorScroller(sections[0]) ?? document.scrollingElement;
    if (!container) {
        return new Map();
    }

    const containerTop = container.getBoundingClientRect().top;
    const scrollTop = container.scrollTop;
    const map = new Map();

    for (const section of sections) {
        const id = String(section.getAttribute('data-seo-section-id') ?? '').trim();
        if (id === '') {
            continue;
        }
        const top = section.getBoundingClientRect().top - containerTop + scrollTop;
        map.set(id, top);
    }

    return map;
}

/**
 * Scroll scroller so the collapsed baseline of `sectionId` sits under the sticky header.
 * @param {string} sectionId
 * @param {Map<string, number>} baselineMap
 * @param {{ behavior?: ScrollBehavior, highlightClass?: string }} [options]
 */
export function scrollToCollapsedSectionBaseline(sectionId, baselineMap, options = {}) {
    const id = String(sectionId ?? '').trim();
    if (id === '' || !(baselineMap instanceof Map) || !baselineMap.has(id)) {
        return false;
    }

    const section = document.querySelector(`section[data-seo-section-id="${CSS.escape(id)}"]`);
    if (!(section instanceof Element)) {
        return false;
    }

    const container = selectEditorScroller(section) ?? document.scrollingElement;
    if (!container) {
        return false;
    }

    const top = baselineMap.get(id) - editorScrollOffset();
    container.scrollTo({
        top: Math.max(0, top),
        behavior: options.behavior === 'auto' ? 'auto' : 'smooth',
    });

    const highlightClass = String(options.highlightClass ?? '').trim();
    if (highlightClass !== '') {
        section.classList.add(highlightClass);
        window.setTimeout(() => {
            section.classList.remove(highlightClass);
        }, 2400);
    }

    return true;
}

/**
 * Wait until `readElement` returns a node whose viewport top is stable across paints.
 * Uses rAF only — no fixed setTimeout render guesses.
 * @param {() => Element|null|undefined} readElement
 * @param {{ maxFrames?: number, isCurrent?: () => boolean }} [options]
 * @returns {Promise<Element|null>}
 */
export async function waitForStableElement(readElement, options = {}) {
    const maxFrames = Number.isFinite(options.maxFrames) ? Number(options.maxFrames) : 24;
    const isCurrent = typeof options.isCurrent === 'function' ? options.isCurrent : () => true;
    let lastTop = null;
    let stableCount = 0;

    for (let frames = 0; frames < maxFrames; frames += 1) {
        if (!isCurrent()) {
            return null;
        }
        await waitForNextPaint();
        if (!isCurrent()) {
            return null;
        }
        const element = typeof readElement === 'function' ? readElement() : null;
        if (!(element instanceof Element)) {
            lastTop = null;
            stableCount = 0;
            continue;
        }
        const top = element.getBoundingClientRect().top;
        if (lastTop !== null && Math.abs(top - lastTop) < 1) {
            stableCount += 1;
            if (stableCount >= 1) {
                return element;
            }
        } else {
            stableCount = 0;
        }
        lastTop = top;
    }

    const fallback = typeof readElement === 'function' ? readElement() : null;
    return fallback instanceof Element ? fallback : null;
}

/**
 * After a section expands, wait for stable layout then scroll the live heading into view.
 * @param {() => Element|null|undefined} readElement
 * @param {{ highlightClass?: string, maxFrames?: number, isCurrent?: () => boolean, behavior?: ScrollBehavior }} [options]
 */
export async function waitAndScrollToHeading(readElement, options = {}) {
    const element = await waitForStableElement(readElement, options);
    if (!(element instanceof Element)) {
        return { ok: false, reason: 'heading_missing' };
    }
    if (typeof options.isCurrent === 'function' && !options.isCurrent()) {
        return { ok: false, reason: 'cancelled' };
    }

    return scrollEditorTargetIntoViewAsync(element, {
        highlightClass: options.highlightClass,
        behavior: options.behavior === 'smooth' ? 'smooth' : 'auto',
    });
}
