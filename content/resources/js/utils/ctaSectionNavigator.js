/**
 * Scroll the article editor to the section identified by CTA `section_N`.
 * Identity follows the same top-level h2/h3 walk as CtaArticleSections.
 */

const SCROLL_OFFSET = 88;

function normalizeHeading(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function isSkippedCtaNode(element) {
    return element.classList.contains('seo-managed-cta') || element.getAttribute('data-cta-manual') === '1';
}

/**
 * @param {string} html
 * @returns {Array<{ sectionId: string, heading: string, tag: ''|'h2'|'h3', h2Index: number, h3Index: number }>}
 */
export function locateCtaSections(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '');
    const sections = [];
    let index = 1;
    let heading = '';
    let content = '';
    let tag = '';
    let h2Index = -1;
    let h3Index = -1;
    let seenH2 = -1;

    const push = () => {
        sections.push({
            sectionId: `section_${index}`,
            heading,
            tag,
            h2Index,
            h3Index,
        });
    };

    for (const child of template.content.children) {
        const name = child.tagName.toLowerCase();
        if (name === 'h2' || name === 'h3') {
            if (content.trim() !== '') {
                push();
                index += 1;
                content = '';
            }
            heading = normalizeHeading(child.textContent);
            tag = name;
            if (name === 'h2') {
                seenH2 += 1;
                h2Index = seenH2;
                h3Index = -1;
            } else {
                h2Index = seenH2;
                h3Index = (h3Index < 0 ? 0 : h3Index + 1);
            }
            continue;
        }
        if (isSkippedCtaNode(child)) {
            continue;
        }
        const text = normalizeHeading(child.textContent);
        if (text === '') {
            continue;
        }
        content = `${content} ${text}`.trim();
    }

    if (content.trim() !== '' || heading !== '') {
        push();
    }

    return sections;
}

function mainPane() {
    return document.querySelector('.seo-article-editor-mainpane');
}

function h2SectionElements() {
    const root = mainPane();
    if (!root) {
        return [];
    }

    return [...root.querySelectorAll('section[data-seo-section-id]')].filter(
        (element) => element.getAttribute('data-seo-section-id') !== 'section-intro',
    );
}

function sectionTitle(section) {
    const text = section.querySelector('.seo-section-header-title__text');
    if (text) {
        return normalizeHeading(text.textContent);
    }
    const input = section.querySelector('.seo-section-header-title__input');
    return normalizeHeading(input?.value ?? '');
}

function isCollapsed(section) {
    return Boolean(section.querySelector('header button .lucide-chevron-right'));
}

function expandSection(section) {
    if (!isCollapsed(section)) {
        return Promise.resolve();
    }
    section.querySelector('header button')?.click();
    return new Promise((resolve) => {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => resolve());
        });
    });
}

function h3Targets(section) {
    return [...section.querySelectorAll('.seo-editor-block-slot')].filter((slot) => {
        const heading = slot.querySelector('h3');
        return heading && !slot.querySelector('h2');
    });
}

function scrollTarget(element) {
    const highlight = element.matches('section[data-seo-section-id]')
        ? element
        : element.closest('.seo-editor-block-slot') || element;
    let node = highlight.parentElement;
    /** @type {HTMLElement|null} */
    let scroller = null;
    while (node && node !== document.documentElement) {
        if (node.matches('.ProseMirror, .tiptap-editor-content, .seo-editor-block-slot, .seo-block-editor-body, section[data-seo-section-id]')) {
            node = node.parentElement;
            continue;
        }
        const overflow = window.getComputedStyle(node).overflowY;
        if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
            scroller = node;
            break;
        }
        node = node.parentElement;
    }
    const container = scroller || document.scrollingElement;
    if (!container) {
        return;
    }
    const top = highlight.getBoundingClientRect().top - container.getBoundingClientRect().top + container.scrollTop - SCROLL_OFFSET;
    container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    highlight.classList.add(highlight.matches('section[data-seo-section-id]') ? 'is-outline-jump-highlight' : 'is-cta-jump-highlight');
    window.setTimeout(() => {
        highlight.classList.remove('is-outline-jump-highlight', 'is-cta-jump-highlight');
    }, 2400);
}

/**
 * @param {{ sectionId?: string, heading?: string, html?: string }} target
 * @returns {Promise<boolean>}
 */
export async function scrollToCtaSection(target) {
    const sectionId = String(target?.sectionId ?? '');
    const expected = normalizeHeading(target?.heading);
    const located = locateCtaSections(target?.html ?? '').find((row) => row.sectionId === sectionId);
    if (!located || normalizeHeading(located.heading) !== expected) {
        return false;
    }

    if (located.tag === '') {
        const intro = mainPane()?.querySelector('section[data-seo-section-id="section-intro"]');
        if (!intro || expected !== '') {
            return false;
        }
        scrollTarget(intro);
        return true;
    }

    if (located.tag === 'h2') {
        const section = h2SectionElements()[located.h2Index];
        if (!section || sectionTitle(section) !== expected) {
            return false;
        }
        scrollTarget(section);
        return true;
    }

    const parent = located.h2Index < 0
        ? mainPane()?.querySelector('section[data-seo-section-id="section-intro"]')
        : h2SectionElements()[located.h2Index];
    if (!parent || located.h3Index < 0) {
        return false;
    }
    await expandSection(parent);
    const slot = h3Targets(parent)[located.h3Index];
    const heading = slot?.querySelector('h3');
    if (!heading || normalizeHeading(heading.textContent) !== expected) {
        return false;
    }
    scrollTarget(heading);
    return true;
}
