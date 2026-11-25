/**
 * CTA Generator — manual insertion from Site CTA Pool.
 *
 * Density = floor(wordCount / 650). Prefer end of H2 sections; fill with H3 ends.
 * Existing CTAs are preserved and counted toward quota. Templates are chosen at random.
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
const SHORTCODE_RE = /\[[^\]]+\]/g;
const WORDS_PER_CTA = 650;
/** Soft preference so nearby H2 beats a slightly closer H3. */
const H2_DISTANCE_BONUS_RATIO = 0.08;
/** Minimum cumulative-word gap between selected insert points. */
const MIN_WORD_GAP = 120;

export { WORDS_PER_CTA };

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

/**
 * Count words in content text: Unicode letters (VN-safe), ignore HTML/shortcodes.
 * @param {string} text
 * @returns {number}
 */
export function countCtaContentWords(text) {
    const cleaned = String(text ?? '')
        .replace(SHORTCODE_RE, ' ')
        .replace(/\s+/gu, ' ')
        .trim();
    if (!cleaned) {
        return 0;
    }
    const matches = cleaned.match(/[\p{L}][\p{L}\p{N}\-]*/gu);
    return matches ? matches.length : 0;
}

function elementPlainText(element) {
    if (!element || element.nodeType !== 1) {
        return '';
    }
    // Prefer innerHTML→strip so inline tags do not glue words (e.g. <em>a</em>b → "a b").
    const html = typeof element.innerHTML === 'string' ? element.innerHTML : '';
    if (html !== '' && /<[a-z!/]/i.test(html)) {
        return html
            .replace(/<script[\s\S]*?<\/script>/gi, ' ')
            .replace(/<style[\s\S]*?<\/style>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/&nbsp;/gi, ' ')
            .replace(/&amp;/gi, '&')
            .replace(/&lt;/gi, '<')
            .replace(/&gt;/gi, '>')
            .replace(/&quot;/gi, '"');
    }
    return element.textContent || '';
}

function nodeContentWords(element, detectedTextSet) {
    if (!element || element.nodeType !== 1) {
        return 0;
    }
    if (isFaqElement(element) || isCtaNode(element, detectedTextSet)) {
        return 0;
    }
    return countCtaContentWords(elementPlainText(element));
}

function createSection(headingEntry) {
    return {
        sectionId: headingEntry.sectionId,
        heading: headingEntry.text,
        headingTag: headingEntry.tag,
        headingChildIndex: headingEntry.childIndex,
        h2Index: headingEntry.h2Index ?? null,
        h3Index: headingEntry.h3Index ?? null,
        parentH2SectionId: headingEntry.parentH2SectionId ?? null,
        special: headingEntry.special,
        hasContent: false,
        hasCta: false,
        lastContentIndex: -1,
        lastNonCtaContentIndex: -1,
        text: headingEntry.text,
        startWord: headingEntry.startWord ?? 0,
        endWord: headingEntry.startWord ?? 0,
        wordCount: 0,
    };
}

function finalizeSection(section, children, detectedTextSet, safePoints) {
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
    const tag = anchorNode.tagName.toLowerCase();
    if (NON_MAIN_CONTAINER_TAGS.has(tag) && ['table', 'ul', 'ol', 'pre', 'form'].includes(tag)) {
        // Anchor is the container itself (top-level) — OK to insert after it, not inside.
    }
    safePoints.push({
        sectionId: section.sectionId,
        heading: section.heading,
        headingTag: section.headingTag,
        h2Index: section.h2Index,
        h3Index: section.h3Index,
        parentH2SectionId: section.parentH2SectionId,
        anchorChildIndex: section.lastNonCtaContentIndex,
        sectionText: section.text,
        hasCta: section.hasCta,
        endWord: section.endWord,
        startWord: section.startWord,
        wordCount: section.wordCount,
    });
}

/**
 * Analyze article HTML for word-based CTA density and H2/H3 end insert points.
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
    const h3Sections = [];
    const eligibleHeadings = [];
    const safePoints = [];

    let inFaqSection = false;
    let currentH2 = null;
    let currentH3 = null;
    let sectionCounter = 0;
    let h2Counter = 0;
    let h3Counter = 0;
    let cumulativeWords = 0;

    const closeH3 = () => {
        if (currentH3) {
            finalizeSection(currentH3, children, detectedTextSet, safePoints);
            currentH3 = null;
        }
    };

    const closeH2 = () => {
        closeH3();
        if (currentH2) {
            finalizeSection(currentH2, children, detectedTextSet, safePoints);
            currentH2 = null;
        }
    };

    const absorbContent = (section, index, words) => {
        if (!section) {
            return;
        }
        section.hasContent = true;
        section.lastContentIndex = index;
        section.lastNonCtaContentIndex = index;
        section.wordCount += words;
        section.endWord = cumulativeWords;
        section.text = `${section.text} ${normalizeText(children[index].textContent || '')}`.trim();
    };

    for (let i = 0; i < children.length; i += 1) {
        const node = children[i];
        const tag = node.tagName.toLowerCase();

        if (isFaqElement(node)) {
            closeH2();
            inFaqSection = true;
            continue;
        }

        if (tag === 'h2') {
            const headingText = normalizeText(node.textContent || '');
            if (FAQ_HEADING_RE.test(headingText)) {
                closeH2();
                inFaqSection = true;
                continue;
            }

            inFaqSection = false;
            closeH2();

            sectionCounter += 1;
            h2Counter += 1;
            const headingEntry = {
                tag: 'h2',
                text: headingText,
                childIndex: i,
                sectionId: `h2_${sectionCounter}`,
                h2Index: h2Counter - 1,
                special: SPECIAL_H2_RE.test(headingText),
                startWord: cumulativeWords,
            };
            eligibleHeadings.push(headingEntry);
            currentH2 = createSection(headingEntry);
            h2Sections.push(currentH2);
            continue;
        }

        if (inFaqSection) {
            continue;
        }

        if (tag === 'h3') {
            if (!currentH2 || currentH2.special) {
                continue;
            }
            closeH3();
            sectionCounter += 1;
            h3Counter += 1;
            const headingText = normalizeText(node.textContent || '');
            const headingEntry = {
                tag: 'h3',
                text: headingText,
                childIndex: i,
                sectionId: `h3_${sectionCounter}`,
                h2Index: currentH2.h2Index,
                h3Index: h3Counter - 1,
                parentH2SectionId: currentH2.sectionId,
                special: false,
                startWord: cumulativeWords,
            };
            eligibleHeadings.push(headingEntry);
            currentH3 = createSection(headingEntry);
            h3Sections.push(currentH3);
            // Nested heading text contributes lightly to parent H2 text for context only.
            currentH2.text = `${currentH2.text} ${headingText}`.trim();
            continue;
        }

        if (tag === 'h1' || /^h[4-6]$/i.test(tag)) {
            if (currentH2 && hasMeaningfulContent(node) && !isCtaNode(node, detectedTextSet)) {
                const words = nodeContentWords(node, detectedTextSet);
                cumulativeWords += words;
                currentH2.text = `${currentH2.text} ${normalizeText(node.textContent || '')}`.trim();
                if (currentH3) {
                    currentH3.text = `${currentH3.text} ${normalizeText(node.textContent || '')}`.trim();
                }
            }
            continue;
        }

        if (!currentH2) {
            // Pre-H2 content — count toward article words for density, never insert here.
            if (hasMeaningfulContent(node) && !isCtaNode(node, detectedTextSet) && !isFaqElement(node)) {
                cumulativeWords += nodeContentWords(node, detectedTextSet);
            }
            continue;
        }

        if (!hasMeaningfulContent(node)) {
            continue;
        }

        if (isCtaNode(node, detectedTextSet)) {
            if (currentH3) {
                currentH3.hasCta = true;
                currentH3.lastContentIndex = i;
            }
            currentH2.hasCta = true;
            currentH2.lastContentIndex = i;
            continue;
        }

        if (NON_MAIN_CONTAINER_TAGS.has(tag) || ['p', 'div', 'section', 'article'].includes(tag)) {
            const words = nodeContentWords(node, detectedTextSet);
            cumulativeWords += words;
            if (currentH3) {
                absorbContent(currentH3, i, words);
                // H2 end tracks last content including nested H3 body.
                currentH2.hasContent = true;
                currentH2.lastContentIndex = i;
                currentH2.lastNonCtaContentIndex = i;
                currentH2.wordCount += words;
                currentH2.endWord = cumulativeWords;
                currentH2.text = `${currentH2.text} ${normalizeText(node.textContent || '')}`.trim();
            } else {
                absorbContent(currentH2, i, words);
            }
        }
    }

    closeH2();

    const existingCount = (ctaScan.shortcodes?.length || 0) + (ctaScan.texts?.length || 0);
    const wordCount = cumulativeWords;
    const targetCtas = Math.floor(wordCount / WORDS_PER_CTA);
    const maxAllowed = targetCtas;
    const remainingQuota = Math.max(0, targetCtas - existingCount);

    const safeH2Points = safePoints.filter((p) => p.headingTag === 'h2');
    const safeH3Points = safePoints.filter((p) => p.headingTag === 'h3');

    return {
        eligibleHeadings,
        eligibleHeadingCount: eligibleHeadings.filter((h) => h.tag === 'h2').length,
        eligibleH2Count: h2Sections.length,
        eligibleH3Count: h3Sections.length,
        h2Sections,
        h3Sections,
        existingCtas: ctaScan,
        existingCount,
        wordCount,
        targetCtas,
        maxAllowed,
        remainingQuota,
        wordsPerCta: WORDS_PER_CTA,
        safePoints,
        safeH2Points,
        safeH3Points,
        safeH2Count: safeH2Points.length,
        safeH3Count: safeH3Points.length,
    };
}

/**
 * Evenly pick insert points by cumulative-word milestones.
 * Prefers H2 ends when comparable; falls back to H3. Never requires filling quota.
 *
 * @param {ReturnType<typeof analyzeArticleForCta>} analysis
 * @param {number} count
 */
export function selectEvenSafePoints(analysis, count) {
    if (count <= 0) {
        return [];
    }

    const totalWords = Math.max(1, analysis.wordCount || 1);
    const h2Bonus = Math.max(40, Math.round(totalWords * H2_DISTANCE_BONUS_RATIO));
    const available = [...(analysis.safePoints || [])].sort((a, b) => a.endWord - b.endWord);
    if (available.length === 0) {
        return [];
    }

    const n = Math.min(count, available.length);
    const milestones = [];
    for (let i = 0; i < n; i += 1) {
        milestones.push(((i + 1) / (n + 1)) * totalWords);
    }

    const selected = [];
    const usedSectionIds = new Set();
    const usedAnchors = new Set();

    for (const milestone of milestones) {
        let best = null;
        let bestScore = Number.POSITIVE_INFINITY;

        for (const point of available) {
            if (usedSectionIds.has(point.sectionId) || usedAnchors.has(point.anchorChildIndex)) {
                continue;
            }
            const tooClose = selected.some(
                (picked) => Math.abs(picked.endWord - point.endWord) < MIN_WORD_GAP
                    || Math.abs(picked.anchorChildIndex - point.anchorChildIndex) <= 1,
            );
            if (tooClose) {
                continue;
            }

            const dist = Math.abs(point.endWord - milestone);
            const score = dist - (point.headingTag === 'h2' ? h2Bonus : 0);
            if (score < bestScore) {
                bestScore = score;
                best = point;
            }
        }

        if (!best) {
            continue;
        }
        selected.push(best);
        usedSectionIds.add(best.sectionId);
        usedAnchors.add(best.anchorChildIndex);
    }

    return selected.sort((a, b) => a.anchorChildIndex - b.anchorChildIndex);
}

function pickRandomIndex(length, rng) {
    if (length <= 0) {
        return -1;
    }
    const r = typeof rng === 'function' ? rng() : Math.random();
    const unit = Math.min(Math.max(Number.isFinite(r) ? r : 0, 0), 0.999999999);
    return Math.floor(unit * length);
}

/**
 * Random pool assignment: cycle without replacement first; avoid consecutive repeats.
 *
 * @param {Array<{ slot?: number, slot_id?: string, content: string, intent?: string }>} poolTemplates
 * @param {object[]} points
 * @param {{ shortcodes?: object[], texts?: object[] }} existingCtas
 * @param {() => number} [rng]
 */
export function selectPoolTemplatesForPoints(poolTemplates, points, existingCtas, rng = Math.random) {
    const existingBodies = new Set(
        [...(existingCtas?.shortcodes || []), ...(existingCtas?.texts || [])]
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

    if (pool.length === 0) {
        return [];
    }

    const assignments = [];
    let bag = [];
    let lastKey = null;

    const refillBag = () => {
        bag = pool.map((_, index) => index);
    };

    refillBag();

    for (const point of points) {
        if (bag.length === 0) {
            refillBag();
        }

        let chosenBagIndex = -1;
        if (bag.length === 1) {
            chosenBagIndex = 0;
        } else {
            // Prefer non-consecutive when possible.
            const candidates = [];
            for (let i = 0; i < bag.length; i += 1) {
                const key = String(pool[bag[i]].slot_id || pool[bag[i]].slot);
                if (key !== lastKey) {
                    candidates.push(i);
                }
            }
            const pickFrom = candidates.length > 0 ? candidates : bag.map((_, i) => i);
            const pick = pickRandomIndex(pickFrom.length, rng);
            chosenBagIndex = pickFrom[pick];
        }

        const poolIndex = bag.splice(chosenBagIndex, 1)[0];
        const template = pool[poolIndex];
        const key = String(template.slot_id || template.slot);
        lastKey = key;
        assignments.push({ point, template });
    }

    return assignments;
}

/**
 * Plan manual CTA insertions without mutating HTML.
 *
 * @param {string} html
 * @param {Array<{ slot: number, slot_id?: string, intent?: string, content: string }>} poolTemplates
 * @param {{ style?: string, rng?: () => number }} [options]
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

    const selectedPoints = selectEvenSafePoints(analysis, analysis.remainingQuota);
    if (selectedPoints.length === 0) {
        return {
            ok: true,
            canInsert: false,
            reason: 'no_safe_position',
            analysis,
            proposals: [],
        };
    }

    const rng = typeof options.rng === 'function' ? options.rng : Math.random;
    const assignments = selectPoolTemplatesForPoints(validPool, selectedPoints, analysis.existingCtas, rng);
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
            headingTag: point.headingTag,
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
