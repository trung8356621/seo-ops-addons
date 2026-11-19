/**
 * CTA Detector — scan article HTML for managed/shortcode CTAs and plain-text CTAs.
 * No AI. Rules mirror LegacyCtaDetector + verified [seo_ops_cta] container syntax.
 */

const CTA_SHORTCODE_OPEN = /\[seo_ops_cta\b([^\]]*)\]/gi;
const CTA_SHORTCODE_CLOSE = /\[\/seo_ops_cta\]/gi;
const CTA_SHORTCODE_PAIR = /\[seo_ops_cta\b([^\]]*)\]([\s\S]*?)\[\/seo_ops_cta\]/gi;
const CTA_SHORTCODE_SELF = /\[seo_ops_cta\b([^\]]*)\s*\/\]/gi;

const PROMO_VERB =
    /liên hệ|contact us|gọi ngay|inbox|đặt hàng|mua ngay|tham quan|showroom|tư vấn|báo giá|send an email|browse our website|visit our/iu;

const CHANNEL_NEEDLES = [
    'zalo',
    'facebook',
    'mailto:',
    'email',
    '@',
    'hotline',
    'điện thoại',
    'phone',
    'địa chỉ',
    'address',
    'website',
    'http',
];

function normalizeText(value) {
    return String(value ?? '')
        .replace(/\s+/gu, ' ')
        .trim();
}

function escapeAttr(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function wordCount(text) {
    return normalizeText(text)
        .split(/\s+/u)
        .filter(Boolean).length;
}

function channelCount(text) {
    const lower = normalizeText(text).toLowerCase();
    let hits = 0;
    for (const needle of CHANNEL_NEEDLES) {
        if (lower.includes(needle)) {
            hits += 1;
        }
    }
    return hits;
}

function looksPromotional(text) {
    const normalized = normalizeText(text);
    if (normalized === '') {
        return false;
    }
    return channelCount(normalized) > 0 && PROMO_VERB.test(normalized);
}

function isManagedElement(element) {
    return Boolean(
        element?.classList?.contains?.('seo-managed-cta')
        || String(element?.getAttribute?.('class') ?? '').includes('seo-managed-cta'),
    );
}

function isManualElement(element) {
    return element?.getAttribute?.('data-cta-manual') === '1';
}

/**
 * True when the element is a whole CTA container (safe to remove without collateral content).
 * @param {Element} node
 */
function isDeletableShortcodeNode(node) {
    if (!node || node.nodeType !== 1) {
        return false;
    }
    if (isManagedElement(node) || isManualElement(node)) {
        return true;
    }
    const html = String(node.innerHTML || '');
    if (!/\[seo_ops_cta\b/i.test(html)) {
        return false;
    }
    const withoutShortcode = html
        .replace(/\[seo_ops_cta\b([^\]]*)\]([\s\S]*?)\[\/seo_ops_cta\]/gi, '')
        .replace(/\[seo_ops_cta\b([^\]]*)\s*\/\]/gi, '')
        .replace(/<[^>]+>/g, ' ');
    return normalizeText(withoutShortcode) === '';
}

function stripNestedSeoOpsCta(html) {
    return String(html ?? '')
        .replace(CTA_SHORTCODE_OPEN, '')
        .replace(CTA_SHORTCODE_CLOSE, '');
}

function alreadyWrapped(html) {
    const source = String(html ?? '');
    return source.includes('seo-managed-cta') || /\[seo_ops_cta\b/i.test(source);
}

function excerpt(text, max = 120) {
    const normalized = normalizeText(text);
    if (normalized.length <= max) {
        return normalized;
    }
    return `${normalized.slice(0, max - 1)}…`;
}

function parseRoot(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '');
    return template.content;
}

/**
 * Walk top-level children into CTA section buckets (same identity as CtaSectionMap).
 * @param {ParentNode} root
 * @returns {Array<{ sectionId: string, heading: string, nodes: Element[] }>}
 */
function bindSections(root) {
    const sections = [];
    let nodes = [];
    let heading = '';
    let content = '';
    let index = 1;

    const push = () => {
        if (nodes.length === 0 && normalizeText(content) === '' && heading === '') {
            return;
        }
        sections.push({
            sectionId: `section_${index}`,
            heading,
            nodes: [...nodes],
        });
    };

    for (const child of root.children) {
        const tag = child.tagName.toLowerCase();
        if ((tag === 'h2' || tag === 'h3') && normalizeText(content) !== '') {
            push();
            index += 1;
            nodes = [child];
            heading = normalizeText(child.textContent);
            content = '';
            continue;
        }
        if (tag === 'h2' || tag === 'h3') {
            nodes.push(child);
            heading = normalizeText(child.textContent);
            continue;
        }
        nodes.push(child);
        if (isManagedElement(child) || isManualElement(child)) {
            continue;
        }
        const text = normalizeText(child.textContent);
        if (text === '') {
            continue;
        }
        content = `${content} ${text}`.trim();
    }
    push();
    return sections;
}

function shortcodeBodyText(raw) {
    return excerpt(
        String(raw ?? '')
            .replace(CTA_SHORTCODE_OPEN, '')
            .replace(CTA_SHORTCODE_CLOSE, '')
            .replace(/<[^>]+>/g, ' '),
    );
}

/**
 * @param {string} html
 * @returns {{ shortcodes: object[], texts: object[] }}
 */
export function detectCtas(html) {
    const root = parseRoot(html);
    const shortcodes = [];
    const texts = [];
    let shortcodeOrdinal = 0;
    let textOrdinal = 0;

    for (const section of bindSections(root)) {
        let textOccurrence = 0;
        let shortcodeOccurrence = 0;
        for (const node of section.nodes) {
            const tag = node.tagName.toLowerCase();
            if (tag === 'h2' || tag === 'h3') {
                continue;
            }

            if (isManagedElement(node) || /\[seo_ops_cta\b/i.test(node.innerHTML || '')) {
                shortcodeOrdinal += 1;
                shortcodeOccurrence += 1;
                const form = /\[seo_ops_cta\b[^\]]*\/\s*\]/i.test(node.innerHTML || '')
                    && !/\[seo_ops_cta\b[^\]]*\][\s\S]*?\[\/seo_ops_cta\]/i.test(node.innerHTML || '')
                    ? 'self_closing'
                    : 'container';
                const canDelete = isDeletableShortcodeNode(node);
                shortcodes.push({
                    id: `shortcode_${shortcodeOrdinal}`,
                    kind: 'shortcode',
                    form,
                    sectionId: section.sectionId,
                    heading: section.heading,
                    occurrence: shortcodeOccurrence,
                    placementId: node.getAttribute?.('data-cta-placement') || '',
                    style: node.getAttribute?.('data-cta-style') || 'soft',
                    text: shortcodeBodyText(node.textContent || node.innerHTML),
                    outerHtml: node.outerHTML,
                    canWrap: false,
                    canDelete,
                    deleteDisabledReason: canDelete ? '' : 'ambiguous_or_mixed',
                });
                continue;
            }

            if (!['p', 'blockquote'].includes(tag)) {
                continue;
            }
            const text = normalizeText(node.textContent);
            if (text === '' || !looksPromotional(text)) {
                continue;
            }
            const words = wordCount(text);
            let structuralSignal = 'standalone_paragraph';
            let confidence = 'uncertain';
            if (tag === 'blockquote') {
                structuralSignal = 'standalone_blockquote';
                confidence = words <= 80 ? 'high' : 'uncertain';
            } else if (words <= 18 && channelCount(text) >= 1) {
                structuralSignal = 'standalone_paragraph';
                confidence = 'high';
            } else {
                structuralSignal = 'mixed_paragraph';
                confidence = 'uncertain';
            }
            textOrdinal += 1;
            textOccurrence += 1;
            texts.push({
                id: `text_${textOrdinal}`,
                kind: 'text',
                tag,
                sectionId: section.sectionId,
                heading: section.heading,
                occurrence: textOccurrence,
                text,
                excerpt: excerpt(text),
                structuralSignal,
                confidence,
                outerHtml: node.outerHTML,
                innerHtml: node.innerHTML,
                canWrap: !alreadyWrapped(node.outerHTML),
                canDelete: structuralSignal !== 'mixed_paragraph',
            });
        }
    }

    // Catch orphan raw shortcodes that sit inside mixed markup not caught as element root.
    const source = String(html ?? '');
    let pair;
    CTA_SHORTCODE_PAIR.lastIndex = 0;
    while ((pair = CTA_SHORTCODE_PAIR.exec(source)) !== null) {
        const snippet = pair[0];
        if (shortcodes.some((item) => item.outerHtml?.includes(snippet) || item.text === shortcodeBodyText(pair[2]))) {
            continue;
        }
        shortcodeOrdinal += 1;
        shortcodes.push({
            id: `shortcode_${shortcodeOrdinal}`,
            kind: 'shortcode',
            form: 'container',
            sectionId: '',
            heading: '',
            occurrence: 1,
            placementId: '',
            style: 'soft',
            text: shortcodeBodyText(pair[2]),
            outerHtml: snippet,
            canWrap: false,
            canDelete: false,
            deleteDisabledReason: 'ambiguous_or_mixed',
        });
    }
    CTA_SHORTCODE_SELF.lastIndex = 0;
    let selfMatch;
    while ((selfMatch = CTA_SHORTCODE_SELF.exec(source)) !== null) {
        const snippet = selfMatch[0];
        if (shortcodes.some((item) => item.outerHtml?.includes(snippet))) {
            continue;
        }
        shortcodeOrdinal += 1;
        shortcodes.push({
            id: `shortcode_${shortcodeOrdinal}`,
            kind: 'shortcode',
            form: 'self_closing',
            sectionId: '',
            heading: '',
            occurrence: 1,
            placementId: '',
            style: 'soft',
            text: excerpt(snippet),
            outerHtml: snippet,
            canWrap: false,
            canDelete: false,
            deleteDisabledReason: 'ambiguous_or_mixed',
        });
    }

    return { shortcodes, texts };
}

/**
 * Build managed CTA HTML using verified WP container shortcode syntax.
 * @param {{ placementId: string, sectionId: string, innerHtml: string, style?: string }} args
 */
export function buildManagedCtaHtml({
    placementId,
    sectionId,
    innerHtml,
    style = 'soft',
    intent = 'consultation',
    origin = 'detector',
    slotId = '',
}) {
    const resolvedStyle = ['soft', 'consultation', 'conversion'].includes(style) ? style : 'soft';
    const safeInner = stripNestedSeoOpsCta(innerHtml);
    const placement = escapeAttr(placementId);
    const section = escapeAttr(sectionId);
    const styleAttr = escapeAttr(resolvedStyle);
    const intentAttr = escapeAttr(String(intent || 'consultation').trim() || 'consultation');
    const originAttr = escapeAttr(String(origin || 'detector').trim() || 'detector');
    const slotAttr = slotId ? ` data-cta-slot-id="${escapeAttr(slotId)}"` : '';
    return (
        `<div class="seo-managed-cta" data-cta-placement="${placement}" data-cta-section="${section}"`
        + ` data-cta-intent="${intentAttr}" data-cta-alias="" data-cta-origin="${originAttr}"${slotAttr}`
        + ` data-cta-style="${styleAttr}" data-cta-manual="0">`
        + `[seo_ops_cta style="${styleAttr}" placement="${placement}" intent="${intentAttr}"]`
        + `${safeInner}[/seo_ops_cta]</div>`
    );
}

function matchCandidateNode(node, candidate) {
    if (!node || !candidate) {
        return false;
    }
    if (candidate.kind === 'shortcode') {
        if (candidate.placementId && node.getAttribute?.('data-cta-placement') === candidate.placementId) {
            return true;
        }
        return alreadyWrapped(node.outerHTML) && normalizeText(node.textContent).includes(normalizeText(candidate.text).slice(0, 40));
    }
    if (!['p', 'blockquote'].includes(node.tagName.toLowerCase())) {
        return false;
    }
    return normalizeText(node.textContent) === normalizeText(candidate.text);
}

/**
 * Replace a plain-text CTA node with a managed shortcode container. Idempotent.
 * @param {string} html
 * @param {object} candidate
 * @returns {{ ok: boolean, html: string, code?: string }}
 */
function shortcodeCoversText(html, text) {
    const needle = normalizeText(text).slice(0, 40);
    if (needle === '') {
        return false;
    }
    return detectCtas(html).shortcodes.some((item) => normalizeText(item.text).includes(needle));
}

export function wrapTextCtaInHtml(html, candidate) {
    if (!candidate || candidate.kind !== 'text') {
        return { ok: false, html, code: 'invalid_candidate' };
    }
    if (!candidate.canWrap || alreadyWrapped(candidate.outerHtml || '') || shortcodeCoversText(html, candidate.text)) {
        return { ok: false, html, code: 'already_wrapped' };
    }
    const root = parseRoot(html);
    const sections = bindSections(root);
    const section = sections.find((row) => row.sectionId === candidate.sectionId);
    if (!section) {
        if (shortcodeCoversText(html, candidate.text)) {
            return { ok: false, html, code: 'already_wrapped' };
        }
        return { ok: false, html, code: 'section_missing' };
    }
    let seen = 0;
    for (const node of section.nodes) {
        if (!matchCandidateNode(node, candidate)) {
            continue;
        }
        seen += 1;
        if (seen !== Number(candidate.occurrence || 1)) {
            continue;
        }
        if (alreadyWrapped(node.outerHTML)) {
            return { ok: false, html, code: 'already_wrapped' };
        }
        const placementId = `cta_detect_${Date.now().toString(36)}_${seen}`;
        const wrapped = buildManagedCtaHtml({
            placementId,
            sectionId: candidate.sectionId,
            innerHtml: node.innerHTML,
            style: 'soft',
        });
        const holder = document.createElement('template');
        holder.innerHTML = wrapped;
        const next = holder.content.firstElementChild;
        if (!next || !node.parentNode) {
            return { ok: false, html, code: 'wrap_failed' };
        }
        node.parentNode.replaceChild(next, node);
        const serializer = document.createElement('div');
        for (const child of root.childNodes) {
            serializer.appendChild(child.cloneNode(true));
        }
        return { ok: true, html: serializer.innerHTML };
    }
    if (shortcodeCoversText(html, candidate.text)) {
        return { ok: false, html, code: 'already_wrapped' };
    }
    return { ok: false, html, code: 'candidate_missing' };
}

function serializeRoot(root) {
    const serializer = document.createElement('div');
    for (const child of root.childNodes) {
        serializer.appendChild(child.cloneNode(true));
    }
    return serializer.innerHTML;
}

/**
 * Delete a plain-text CTA node only when it is a whole paragraph/blockquote CTA.
 * @param {string} html
 * @param {object} candidate
 * @returns {{ ok: boolean, html: string, code?: string }}
 */
export function deleteTextCtaInHtml(html, candidate) {
    if (!candidate || candidate.kind !== 'text') {
        return { ok: false, html, code: 'invalid_candidate' };
    }
    if (!candidate.canDelete || candidate.structuralSignal === 'mixed_paragraph') {
        return { ok: false, html, code: 'mixed_content' };
    }
    const root = parseRoot(html);
    const sections = bindSections(root);
    const section = sections.find((row) => row.sectionId === candidate.sectionId);
    if (!section) {
        return { ok: false, html, code: 'section_missing' };
    }
    let seen = 0;
    for (const node of section.nodes) {
        if (!matchCandidateNode(node, candidate)) {
            continue;
        }
        seen += 1;
        if (seen !== Number(candidate.occurrence || 1)) {
            continue;
        }
        node.parentNode?.removeChild(node);
        return { ok: true, html: serializeRoot(root) };
    }
    return { ok: false, html, code: 'candidate_missing' };
}

/**
 * Delete one shortcode/managed CTA by stable placement id or section occurrence.
 * Never string-replaces shortcode text globally (duplicate bodies stay intact).
 *
 * @param {string} html
 * @param {object} candidate
 * @returns {{ ok: boolean, html: string, code?: string }}
 */
export function deleteShortcodeCtaInHtml(html, candidate) {
    if (!candidate || candidate.kind !== 'shortcode') {
        return { ok: false, html, code: 'invalid_candidate' };
    }
    if (!candidate.canDelete) {
        return { ok: false, html, code: candidate.deleteDisabledReason === 'ambiguous_or_mixed' ? 'ambiguous' : 'mixed_content' };
    }

    const root = parseRoot(html);
    const placementId = String(candidate.placementId || '').trim();

    if (placementId) {
        const matches = [];
        for (const child of root.children) {
            if (child.getAttribute?.('data-cta-placement') === placementId) {
                matches.push(child);
            }
        }
        if (matches.length === 1) {
            matches[0].parentNode?.removeChild(matches[0]);
            return { ok: true, html: serializeRoot(root) };
        }
        if (matches.length > 1) {
            return { ok: false, html, code: 'ambiguous' };
        }
    }

    if (!candidate.sectionId) {
        return { ok: false, html, code: 'ambiguous' };
    }

    const sections = bindSections(root);
    const section = sections.find((row) => row.sectionId === candidate.sectionId);
    if (!section) {
        return { ok: false, html, code: 'section_missing' };
    }

    let seen = 0;
    const matches = [];
    for (const node of section.nodes) {
        const isShortcodeNode = isManagedElement(node)
            || isManualElement(node)
            || /\[seo_ops_cta\b/i.test(node.innerHTML || '');
        if (!isShortcodeNode || !isDeletableShortcodeNode(node)) {
            continue;
        }
        seen += 1;
        if (seen === Number(candidate.occurrence || 1)) {
            matches.push(node);
        }
    }

    if (matches.length !== 1) {
        return { ok: false, html, code: matches.length === 0 ? 'candidate_missing' : 'ambiguous' };
    }

    matches[0].parentNode?.removeChild(matches[0]);
    return { ok: true, html: serializeRoot(root) };
}

/**
 * Delete a detected CTA (shortcode or plain-text) when uniquely identifiable.
 * @param {string} html
 * @param {object} candidate
 * @returns {{ ok: boolean, html: string, code?: string }}
 */
export function deleteCtaInHtml(html, candidate) {
    if (!candidate) {
        return { ok: false, html, code: 'invalid_candidate' };
    }
    if (candidate.kind === 'text') {
        return deleteTextCtaInHtml(html, candidate);
    }
    if (candidate.kind === 'shortcode') {
        return deleteShortcodeCtaInHtml(html, candidate);
    }
    return { ok: false, html, code: 'invalid_candidate' };
}

/**
 * Locate a CTA candidate in the live editor DOM and scroll to it.
 * @param {object} candidate
 * @param {{ highlightClass?: string }} [options]
 * @returns {boolean}
 */
export function locateCtaCandidateInDom(candidate, options = {}) {
    const pane = document.querySelector('.seo-article-editor-mainpane');
    if (!pane || !candidate) {
        return false;
    }
    const highlightClass = options.highlightClass || 'is-cta-jump-highlight';
    let target = null;
    if (candidate.kind === 'shortcode' && candidate.placementId) {
        target = pane.querySelector(`[data-cta-placement="${CSS.escape(candidate.placementId)}"]`);
    }
    if (!target && candidate.kind === 'shortcode') {
        target = [...pane.querySelectorAll('.seo-managed-cta, .seo-managed-cta-view')].find((node) => {
            const text = normalizeText(node.textContent);
            return text.includes(normalizeText(candidate.text).slice(0, 32));
        }) || null;
    }
    if (!target && candidate.kind === 'text') {
        const needle = normalizeText(candidate.text);
        const nodes = [...pane.querySelectorAll('p, blockquote, .ProseMirror p, .ProseMirror blockquote')];
        let seen = 0;
        for (const node of nodes) {
            if (normalizeText(node.textContent) !== needle) {
                continue;
            }
            seen += 1;
            if (seen === Number(candidate.occurrence || 1)) {
                target = node;
                break;
            }
        }
    }
    if (!target) {
        return false;
    }
    const slot = target.closest?.('.seo-editor-block-slot, [data-seo-block-id], section[data-seo-section-id]') || target;
    slot.classList?.add?.(highlightClass);
    window.setTimeout(() => slot.classList?.remove?.(highlightClass), 2400);
    target.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
    return true;
}

export const CTA_DETECTOR_SHORTCODE = 'seo_ops_cta';

export {
    looksPromotional,
    channelCount,
    alreadyWrapped,
    stripNestedSeoOpsCta,
    normalizeText,
};
