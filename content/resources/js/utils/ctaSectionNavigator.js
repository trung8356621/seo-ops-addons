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

const SIDEBAR_SCROLL = '.seo-article-editor-left-rail, .seo-article-editor-outline-rail, .wp-article-edit-sidebar-scroll, .seo-article-edit-sidebar-scroll';

function isScrollable(node) {
    const overflow = window.getComputedStyle(node).overflowY;
    return (overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight + 1;
}

/**
 * Scroll only a container inside the central editor pane.
 * Sidebar scrollers are never selected, even when they are in the ancestor chain.
 * @param {HTMLElement} element
 * @returns {HTMLElement|null}
 */
export function selectEditorScroller(element) {
    const pane = element.closest?.('.seo-article-editor-mainpane') ?? null;
    let node = element.parentElement;
    while (node && node !== document.documentElement) {
        if (node.matches?.(SIDEBAR_SCROLL)) {
            return document.scrollingElement;
        }
        if (pane && !pane.contains(node)) {
            break;
        }
        if (node.matches?.('.ProseMirror, .tiptap-editor-content, .seo-editor-block-slot, .seo-block-editor-body, section[data-seo-section-id]')) {
            node = node.parentElement;
            continue;
        }
        if (isScrollable(node)) {
            return node;
        }
        node = node.parentElement;
    }

    return document.scrollingElement;
}

function stickyOffset(pane) {
    const toolbar = pane?.querySelector?.('.seo-editor-toolbar');
    const height = toolbar ? toolbar.getBoundingClientRect().height : 0;
    return Math.max(SCROLL_OFFSET, Math.ceil(height) + 12);
}

function scrollTarget(element) {
    const highlight = element.matches('section[data-seo-section-id]')
        ? element
        : element.closest('.seo-editor-block-slot') || element;
    const pane = highlight.closest?.('.seo-article-editor-mainpane') ?? mainPane();
    const container = selectEditorScroller(highlight);
    if (!container || !pane) {
        return;
    }
    const top = highlight.getBoundingClientRect().top - container.getBoundingClientRect().top + container.scrollTop - stickyOffset(pane);
    container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    highlight.classList.add(highlight.matches('section[data-seo-section-id]') ? 'is-outline-jump-highlight' : 'is-cta-jump-highlight');
    window.setTimeout(() => {
        highlight.classList.remove('is-outline-jump-highlight', 'is-cta-jump-highlight');
    }, 2400);
}

/**
 * Identity of a CTA section inside the canonical heading walk.
 * Occurrence distinguishes duplicate headings. Index into the editor is not an identity.
 * @param {string} html
 * @param {string} sectionId
 * @param {string} heading
 */
export function describeCtaHeadingTarget(html, sectionId, heading) {
    const rows = locateCtaSections(html);
    const row = rows.find((item) => item.sectionId === sectionId);
    const expected = normalizeHeading(heading);
    if (!row || normalizeHeading(row.heading) !== expected) {
        return { ok: false, reason: 'section_mismatch' };
    }
    if (row.tag === '') {
        return expected === '' ? { ok: true, kind: 'intro' } : { ok: false, reason: 'section_mismatch' };
    }
    const same = rows.filter((item) => item.tag === row.tag && normalizeHeading(item.heading) === expected);
    const occurrence = same.findIndex((item) => item.sectionId === row.sectionId) + 1;
    if (row.tag === 'h2') {
        return { ok: true, kind: 'h2', heading: expected, occurrence };
    }
    let parent = null;
    for (const item of rows) {
        if (item.sectionId === row.sectionId) {
            break;
        }
        if (item.tag === 'h2' && item.h2Index === row.h2Index) {
            parent = item;
        }
    }
    if (!parent && row.h2Index < 0) {
        const introH3 = rows.filter((item) => item.tag === 'h3' && item.h2Index < 0 && normalizeHeading(item.heading) === expected);
        return {
            ok: true,
            kind: 'h3',
            heading: expected,
            occurrence: introH3.findIndex((item) => item.sectionId === row.sectionId) + 1,
            parentKind: 'intro',
        };
    }
    if (!parent) {
        return { ok: false, reason: 'parent_missing' };
    }
    const parentHeading = normalizeHeading(parent.heading);
    const parentOccurrence = rows.filter((item) => item.tag === 'h2' && normalizeHeading(item.heading) === parentHeading)
        .findIndex((item) => item.sectionId === parent.sectionId) + 1;
    const withinParent = rows.filter((item) => item.tag === 'h3' && item.h2Index === row.h2Index && normalizeHeading(item.heading) === expected);

    return {
        ok: true,
        kind: 'h3',
        heading: expected,
        occurrence: withinParent.findIndex((item) => item.sectionId === row.sectionId) + 1,
        parentKind: 'h2',
        parentHeading,
        parentOccurrence,
        documentOccurrence: occurrence,
    };
}

/**
 * @param {ReturnType<typeof describeCtaHeadingTarget>} plan
 * @param {Array<{ id: string, isIntro?: boolean, title?: string, h3s?: string[] }>} sections
 */
export function matchEditorHeading(plan, sections) {
    if (!plan?.ok) {
        return { ok: false, reason: plan?.reason || 'section_mismatch' };
    }
    if (plan.kind === 'intro') {
        const intro = sections.find((section) => section.isIntro || section.id === 'section-intro');
        return intro ? { ok: true, sectionId: intro.id, h3Index: null } : { ok: false, reason: 'intro_missing' };
    }
    if (plan.kind === 'h2') {
        const matches = sections.filter((section) => !section.isIntro && normalizeHeading(section.title) === plan.heading);
        const hit = matches[plan.occurrence - 1];
        return hit ? { ok: true, sectionId: hit.id, h3Index: null } : { ok: false, reason: 'heading_missing' };
    }
    const parents = plan.parentKind === 'intro'
        ? sections.filter((section) => section.isIntro || section.id === 'section-intro')
        : sections.filter((section) => !section.isIntro && normalizeHeading(section.title) === plan.parentHeading);
    const parent = parents[plan.parentKind === 'intro' ? 0 : plan.parentOccurrence - 1];
    if (!parent) {
        return { ok: false, reason: 'parent_missing' };
    }
    const h3s = (parent.h3s ?? []).map((text, index) => ({ text: normalizeHeading(text), index }))
        .filter((item) => item.text === plan.heading);
    const hit = h3s[plan.occurrence - 1];
    if (!hit) {
        return { ok: false, reason: 'heading_missing', sectionId: parent.id };
    }

    return { ok: true, sectionId: parent.id, h3Index: hit.index };
}

function readEditorSections(pane) {
    return [...pane.querySelectorAll('section[data-seo-section-id]')].map((section) => {
        const id = section.getAttribute('data-seo-section-id') || '';
        const isIntro = id === 'section-intro';
        const h3s = [...section.querySelectorAll('[data-seo-block-id] h3')]
            .filter((heading) => !heading.closest('.seo-section-header-title'))
            .map((heading) => normalizeHeading(heading.textContent));
        return {
            id,
            isIntro,
            title: isIntro ? '' : sectionTitle(section),
            h3s,
            element: section,
        };
    });
}

function waitForHeading(read) {
    return new Promise((resolve) => {
        let frames = 0;
        const tick = () => {
            const found = read();
            if (found || frames >= 12) {
                resolve(found);
                return;
            }
            frames += 1;
            window.requestAnimationFrame(tick);
        };
        tick();
    });
}

/**
 * @param {{ sectionId?: string, heading?: string, html?: string }} target
 * @returns {Promise<boolean>}
 */
export async function scrollToCtaSection(target) {
    const plan = describeCtaHeadingTarget(target?.html ?? '', String(target?.sectionId ?? ''), target?.heading ?? '');
    const pane = mainPane();
    if (!plan.ok || !pane) {
        return false;
    }
    const sections = () => readEditorSections(pane);
    if (plan.kind === 'intro' || plan.kind === 'h2') {
        const match = matchEditorHeading(plan, sections());
        const element = match.ok ? pane.querySelector(`section[data-seo-section-id="${match.sectionId}"]`) : null;
        if (!element) {
            return false;
        }
        scrollTarget(element);
        return true;
    }
    const parentMatch = sections().find((section) => {
        if (plan.parentKind === 'intro') {
            return section.isIntro;
        }
        const parents = sections().filter((item) => !item.isIntro && item.title === plan.parentHeading);
        return section.id === parents[plan.parentOccurrence - 1]?.id;
    });
    if (!parentMatch?.element) {
        return false;
    }
    await expandSection(parentMatch.element);
    const heading = await waitForHeading(() => {
        const nodes = [...parentMatch.element.querySelectorAll('[data-seo-block-id] h3')]
            .filter((node) => !node.closest('.seo-section-header-title'));
        const matches = nodes.filter((node) => normalizeHeading(node.textContent) === plan.heading);
        return matches[plan.occurrence - 1] ?? null;
    });
    if (!heading) {
        return false;
    }
    scrollTarget(heading);
    return true;
}
