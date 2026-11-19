/**
 * CTA Generator — manual, deterministic insertion from Site CTA Pool.
 *
 * Density & placement use top-level H2 sections only (not H1/H3/H4).
 * Existing CTAs are preserved and counted; never moved or deleted by the generator.
 */

import { buildManagedCtaHtml, detectCtas, normalizeText } from './ctaDetector.js';

const FAQ_HEADING_RE = /^(câu hỏi thường gặp|những câu hỏi thường gặp|hỏi đáp|faq|frequently asked questions)\b/iu;
const SPECIAL_H2_RE = /^(giới thiệu|introduction|kết luận|conclusion|tóm tắt|summary)\b/iu;
const NON_MAIN_CONTAINER_TAGS = new Set([
    'table',
    'ul',
    'ol',
    'blockquote',
    'aside',
    'nav',
    'footer',
    'header',
    'form',
    'pre',
    'figure',
]);
const FAQ_SELECTOR = '.omi-faq-accordion, .seo-faq-shortcode-block, [data-omi-faq], .omi-faq-placeholder, .rank-math-faq-block, .schema-faq';
const CTA_SHORTCODE_RE = /\[seo_ops_cta\b/i;
const H2_GROUP_SIZE = 3;

function escapeHtmlText(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function isFaqElement(element) {
    if (!element || element.nodeType !== 1) {
        return false;
    }
    if (element.matches?.(FAQ_SELECTOR) || element.querySelector?.(FAQ_SELECTOR)) {
        return true;
    }
    if (/\[omi_faq\]/i.test(element.textContent || '')) {
        return true;
    }
    return false;
}

function isCtaNode(element, detectedTextSet) {
    if (!element || element.nodeType !== 1) {
        return false;
    }
    if (
        element.classList?.contains('seo-managed-cta')
        || String(element.getAttribute?.('class') ?? '').includes('seo-managed-cta')
        || element.getAttribute?.('data-cta-manual') === '1'
        || CTA_SHORTCODE_RE.test(element.innerHTML || '')
        || CTA_SHORTCODE_RE.test(element.textContent || '')
    ) {
        return true;
    }
    const norm = normalizeText(element.textContent || '');
    return norm !== '' && detectedTextSet.has(norm);
}

function parseTemplateRoot(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '');
    return template.content;
}

function hasMeaningfulContent(element) {
    if (!element || element.nodeType !== 1) {
        return false;
    }
    const tag = element.tagName.toLowerCase();
    if (['img', 'figure', 'table', 'ul', 'ol', 'blockquote', 'hr'].includes(tag)) {
        return true;
    }
    return normalizeText(element.textContent || '') !== '';
}

function nextMeaningfulElement(children, startIndex) {
    for (let i = startIndex + 1; i < children.length; i += 1) {
        if (hasMeaningfulContent(children[i])) {
            return children[i];
        }
    }
    return null;
}

function createH2Section(headingEntry) {
    return {
        sectionId: headingEntry.sectionId,
        heading: headingEntry.text,
        headingTag: 'h2',
        headingChildIndex: headingEntry.childIndex,
        h2Index: headingEntry.h2Index,
        special: headingEntry.special,
        hasContent: false,
        hasCta: false,
        lastContentIndex: -1,
        lastNonCtaContentIndex: -1,
        text: headingEntry.text,
    };
}

function finalizeH2Section(section, children, detectedTextSet, safePoints) {
    if (!section || section.special) {
        return;
    }
    if (!section.hasContent || section.lastNonCtaContentIndex < 0 || section.hasCta) {
        return;
    }
    const anchorNode = children[section.lastNonCtaContentIndex];
    const nextNode = nextMeaningfulElement(children, section.lastNonCtaContentIndex);
    if (isCtaNode(anchorNode, detectedTextSet)) {
        return;
    }
    if (nextNode && isCtaNode(nextNode, detectedTextSet)) {
        return;
    }
    if (isFaqElement(anchorNode) || (nextNode && isFaqElement(nextNode))) {
        return;
    }
    safePoints.push({
        sectionId: section.sectionId,
        heading: section.heading,
        headingTag: 'h2',
        h2Index: section.h2Index,
        anchorChildIndex: section.lastNonCtaContentIndex,
        sectionText: section.text,
        hasCta: section.hasCta,
    });
}

/**
 * Analyze article HTML using top-level H2 sections only.
 *
 * @param {string} html
 * @param {{ shortcodes?: object[], texts?: object[] }} [detected]
 */
export function analyzeArticleForCta(html, detected = null) {
    const root = parseTemplateRoot(html);
    const ctaScan = detected ?? detectCtas(html);
    const detectedTextSet = new Set(
        (ctaScan.texts || []).map((row) => normalizeText(row.text || '')).filter(Boolean),
    );

    const children = Array.from(root.children);
    const h2Sections = [];
    const eligibleHeadings = [];
    const safePoints = [];

    let inFaqSection = false;
    let currentSection = null;
    let sectionCounter = 0;
    let h2Counter = 0;

    for (let i = 0; i < children.length; i += 1) {
        const node = children[i];
        const tag = node.tagName.toLowerCase();

        if (isFaqElement(node)) {
            if (currentSection) {
                finalizeH2Section(currentSection, children, detectedTextSet, safePoints);
                currentSection = null;
            }
            inFaqSection = true;
            continue;
        }

        if (tag === 'h2') {
            const headingText = normalizeText(node.textContent || '');
            if (FAQ_HEADING_RE.test(headingText)) {
                if (currentSection) {
                    finalizeH2Section(currentSection, children, detectedTextSet, safePoints);
                    currentSection = null;
                }
                inFaqSection = true;
                continue;
            }

            inFaqSection = false;
            if (currentSection) {
                finalizeH2Section(currentSection, children, detectedTextSet, safePoints);
            }

            sectionCounter += 1;
            h2Counter += 1;
            const headingEntry = {
                tag: 'h2',
                text: headingText,
                childIndex: i,
                sectionId: `h2_${sectionCounter}`,
                h2Index: h2Counter - 1,
                special: SPECIAL_H2_RE.test(headingText),
            };
            eligibleHeadings.push(headingEntry);
            currentSection = createH2Section(headingEntry);
            h2Sections.push(currentSection);
            continue;
        }

        if (inFaqSection) {
            continue;
        }

        // H1 / H3+ never open sections and never count toward density.
        if (tag === 'h1' || /^h[3-6]$/i.test(tag)) {
            if (currentSection && hasMeaningfulContent(node) && !isCtaNode(node, detectedTextSet)) {
                currentSection.text = `${currentSection.text} ${normalizeText(node.textContent || '')}`.trim();
                // Nested headings alone do not qualify the H2 as having insertable body content.
            }
            continue;
        }

        if (!currentSection) {
            // Introduction / pre-H2 content — never insert here.
            continue;
        }

        if (!hasMeaningfulContent(node)) {
            continue;
        }

        if (isCtaNode(node, detectedTextSet)) {
            currentSection.hasCta = true;
            currentSection.lastContentIndex = i;
            continue;
        }

        if (NON_MAIN_CONTAINER_TAGS.has(tag) || ['p', 'div', 'section', 'article'].includes(tag)) {
            currentSection.hasContent = true;
            currentSection.lastContentIndex = i;
            currentSection.lastNonCtaContentIndex = i;
            currentSection.text = `${currentSection.text} ${normalizeText(node.textContent || '')}`.trim();
        }
    }

    if (currentSection) {
        finalizeH2Section(currentSection, children, detectedTextSet, safePoints);
    }

    const existingCount = (ctaScan.shortcodes?.length || 0) + (ctaScan.texts?.length || 0);
    const maxAllowed = Math.floor(eligibleHeadings.length / H2_GROUP_SIZE);
    const remainingQuota = Math.max(0, maxAllowed - existingCount);

    return {
        eligibleHeadings,
        eligibleHeadingCount: eligibleHeadings.length,
        h2Sections,
        existingCtas: ctaScan,
        existingCount,
        maxAllowed,
        remainingQuota,
        safePoints,
    };
}

/**
 * Pick at most one safe H2 end per consecutive group of ~3 H2s.
 * Skip groups that already contain a CTA. Prefer the last safe H2 in the group.
 *
 * @param {ReturnType<typeof analyzeArticleForCta>} analysis
 * @param {number} count
 */
function selectGroupedSafePoints(analysis, count) {
    if (count <= 0) {
        return [];
    }
    const sections = analysis.h2Sections || [];
    const safeBySectionId = new Map((analysis.safePoints || []).map((point) => [point.sectionId, point]));
    const selected = [];

    for (let start = 0; start < sections.length && selected.length < count; start += H2_GROUP_SIZE) {
        const group = sections.slice(start, start + H2_GROUP_SIZE);
        if (group.length === 0) {
            continue;
        }
        if (group.some((section) => section.hasCta)) {
            continue;
        }
        let picked = null;
        for (let i = group.length - 1; i >= 0; i -= 1) {
            const candidate = safeBySectionId.get(group[i].sectionId);
            if (candidate) {
                picked = candidate;
                break;
            }
        }
        if (picked) {
            selected.push(picked);
        }
    }

    return selected;
}

const INTENT_KEYWORDS = {
    quotation: ['báo giá', 'giá', 'chi phí', 'bảng giá', 'ngân sách', 'số lượng'],
    consultation: ['tư vấn', 'chọn', 'hướng dẫn', 'kinh nghiệm', 'lưu ý', 'cách'],
    product: ['mẫu', 'chất liệu', 'vải', 'thiết kế', 'sản phẩm', 'loại', 'kích thước'],
    service: ['xưởng', 'quy trình', 'sản xuất', 'gia công', 'in ấn', 'thêu', 'đặt may'],
    contact: ['liên hệ', 'đặt hàng', 'kết nối', 'hỗ trợ'],
};

function scoreTemplateForPoint(template, point) {
    const intent = normalizeText(template.intent || '').toLowerCase();
    const sectionText = normalizeText(`${point.heading} ${point.sectionText}`).toLowerCase();
    let score = 0;
    for (const [group, keywords] of Object.entries(INTENT_KEYWORDS)) {
        if (!intent.includes(group)) {
            continue;
        }
        for (const kw of keywords) {
            if (sectionText.includes(kw)) {
                score += 2;
            }
        }
    }
    return score;
}

function selectPoolTemplatesForPoints(poolTemplates, points, existingCtas) {
    const existingBodies = new Set(
        [...(existingCtas.shortcodes || []), ...(existingCtas.texts || [])]
            .map((item) => normalizeText(item.text || '').toLowerCase())
            .filter(Boolean),
    );

    const eligible = (Array.isArray(poolTemplates) ? poolTemplates : []).filter((tpl) => {
        const content = String(tpl?.content ?? '').trim();
        if (!content) {
            return false;
        }
        const norm = normalizeText(content).toLowerCase();
        return !existingBodies.has(norm);
    });

    const pool = eligible.length > 0
        ? eligible
        : (Array.isArray(poolTemplates) ? poolTemplates.filter((t) => String(t?.content ?? '').trim() !== '') : []);

    const usedSlotIds = new Set();
    const assignments = [];

    for (const point of points) {
        const available = pool.filter((tpl) => !usedSlotIds.has(String(tpl.slot_id || tpl.slot)));
        if (available.length === 0) {
            break;
        }
        let best = available[0];
        let bestScore = scoreTemplateForPoint(best, point);
        for (let i = 1; i < available.length; i += 1) {
            const candidate = available[i];
            const candidateScore = scoreTemplateForPoint(candidate, point);
            if (candidateScore > bestScore) {
                best = candidate;
                bestScore = candidateScore;
            }
        }
        const key = String(best.slot_id || best.slot);
        usedSlotIds.add(key);
        assignments.push({
            point,
            template: best,
        });
    }

    return assignments;
}

/**
 * Plan manual CTA insertions without mutating HTML.
 *
 * @param {string} html
 * @param {Array<{ slot: number, slot_id?: string, intent?: string, content: string }>} poolTemplates
 * @param {{ style?: string }} [options]
 */
export function planCtaGeneration(html, poolTemplates, options = {}) {
    const analysis = analyzeArticleForCta(html);
    const validPool = (Array.isArray(poolTemplates) ? poolTemplates : []).filter(
        (row) => String(row?.content ?? '').trim() !== '',
    );

    if (analysis.remainingQuota <= 0) {
        return {
            ok: true,
            canInsert: false,
            reason: 'density_reached',
            analysis,
            proposals: [],
        };
    }

    if (validPool.length === 0) {
        return {
            ok: true,
            canInsert: false,
            reason: 'empty_pool',
            analysis,
            proposals: [],
        };
    }

    const selectedPoints = selectGroupedSafePoints(analysis, analysis.remainingQuota);
    if (selectedPoints.length === 0) {
        return {
            ok: true,
            canInsert: false,
            reason: 'no_safe_position',
            analysis,
            proposals: [],
        };
    }

    const assignments = selectPoolTemplatesForPoints(validPool, selectedPoints, analysis.existingCtas);
    const style = ['soft', 'consultation', 'conversion'].includes(options.style) ? options.style : 'soft';
    const stamp = Date.now().toString(36);

    const proposals = assignments.map(({ point, template }, index) => {
        const slotId = String(template.slot_id || `CTA_${Number(template.slot || index + 1)}`);
        const intent = String(template.intent || 'consultation').trim() || 'consultation';
        const content = String(template.content || '').trim();
        const placementId = `cta_gen_${stamp}_${index + 1}`;
        const managedHtml = buildManagedCtaHtml({
            placementId,
            sectionId: point.sectionId,
            innerHtml: escapeHtmlText(content),
            style,
            intent,
            origin: 'pool_generator',
            slotId,
        });

        return {
            id: placementId,
            slotId,
            intent,
            content,
            sectionId: point.sectionId,
            heading: point.heading,
            anchorChildIndex: point.anchorChildIndex,
            managedHtml,
        };
    });

    return {
        ok: true,
        canInsert: proposals.length > 0,
        reason: proposals.length > 0 ? 'ready' : 'no_safe_position',
        analysis,
        proposals,
    };
}

/**
 * Insert planned managed CTA blocks into article HTML without altering existing nodes/CTAs.
 *
 * @param {string} html
 * @param {ReturnType<typeof planCtaGeneration>} plan
 * @returns {{ ok: boolean, html: string, inserted: number }}
 */
export function applyCtaGenerationPlan(html, plan) {
    const proposals = Array.isArray(plan?.proposals) ? plan.proposals : [];
    if (proposals.length === 0) {
        return { ok: false, html: String(html ?? ''), inserted: 0 };
    }

    const root = parseTemplateRoot(html);
    const children = Array.from(root.children);
    const sorted = [...proposals].sort((a, b) => b.anchorChildIndex - a.anchorChildIndex);
    let inserted = 0;

    for (const item of sorted) {
        const anchor = children[item.anchorChildIndex];
        if (!anchor || !anchor.parentNode) {
            continue;
        }
        const holder = document.createElement('template');
        holder.innerHTML = item.managedHtml;
        const block = holder.content.firstElementChild;
        if (!block) {
            continue;
        }
        anchor.parentNode.insertBefore(block, anchor.nextSibling);
        inserted += 1;
    }

    const serializer = document.createElement('div');
    for (const child of root.childNodes) {
        serializer.appendChild(child.cloneNode(true));
    }

    return {
        ok: inserted > 0,
        html: serializer.innerHTML,
        inserted,
    };
}
