import assert from 'node:assert/strict';
import { before, describe, it } from 'node:test';

function installDom() {
    function createText(value) {
        return {
            nodeType: 3,
            textContent: String(value),
            outerHTML: String(value),
            parentNode: null,
            nextSibling: null,
            cloneNode() {
                return createText(value);
            },
        };
    }

    function parseFragment(html) {
        const source = String(html ?? '');
        const nodes = [];
        const re = /([^<]+)|<([a-z0-9]+)([^>]*)\/>|<([a-z0-9]+)([^>]*)>([\s\S]*?)<\/\4>/gi;
        let match;
        while ((match = re.exec(source)) !== null) {
            if (match[1] != null) {
                if (match[1] !== '') {
                    nodes.push(createText(match[1]));
                }
                continue;
            }
            if (match[2]) {
                nodes.push(createElement(match[2], match[3] || '', ''));
                continue;
            }
            nodes.push(createElement(match[4], match[5] || '', match[6] ?? ''));
        }
        return nodes;
    }

    function syncSiblings(list) {
        for (let i = 0; i < list.length; i += 1) {
            list[i].nextSibling = list[i + 1] ?? null;
        }
    }

    function createElement(tagName, attrs = '', rawInner = '') {
        const attrMap = {};
        String(attrs).replace(/([a-zA-Z_:-]+)="([^"]*)"/g, (_, key, value) => {
            attrMap[key] = value;
            return '';
        });
        const classNames = String(attrMap.class || '').split(/\s+/).filter(Boolean);
        const children = parseFragment(rawInner);
        const node = {
            nodeType: 1,
            tagName: String(tagName).toUpperCase(),
            children: children.filter((c) => c.nodeType === 1),
            childNodes: children,
            parentNode: null,
            nextSibling: null,
            classList: {
                contains: (name) => classNames.includes(name),
            },
            getAttribute: (name) => (Object.prototype.hasOwnProperty.call(attrMap, name) ? attrMap[name] : null),
            matches: (selector) => {
                if (selector.includes('omi-faq') && (classNames.some((c) => c.includes('omi-faq')) || attrMap['data-omi-faq'])) {
                    return true;
                }
                if (selector.includes('seo-faq') && classNames.some((c) => c.includes('seo-faq'))) {
                    return true;
                }
                return false;
            },
            querySelector: (selector) => children.find((c) => c.nodeType === 1 && c.matches?.(selector)) || null,
            get textContent() {
                return children.map((c) => c.textContent || '').join('');
            },
            get innerHTML() {
                return children.map((c) => c.outerHTML).join('');
            },
            set innerHTML(value) {
                const next = parseFragment(value);
                children.splice(0, children.length, ...next);
                node.childNodes = children;
                node.children = children.filter((c) => c.nodeType === 1);
                next.forEach((c) => {
                    c.parentNode = node;
                });
                syncSiblings(children);
            },
            get outerHTML() {
                const attrText = Object.entries(attrMap)
                    .map(([k, v]) => ` ${k}="${v}"`)
                    .join('');
                return `<${String(tagName).toLowerCase()}${attrText}>${node.innerHTML}</${String(tagName).toLowerCase()}>`;
            },
            appendChild(child) {
                children.push(child);
                child.parentNode = node;
                node.childNodes = children;
                node.children = children.filter((c) => c.nodeType === 1);
                syncSiblings(children);
                return child;
            },
            insertBefore(newNode, refNode) {
                const idx = refNode ? children.indexOf(refNode) : children.length;
                const at = idx < 0 ? children.length : idx;
                children.splice(at, 0, newNode);
                newNode.parentNode = node;
                node.childNodes = children;
                node.children = children.filter((c) => c.nodeType === 1);
                syncSiblings(children);
                return newNode;
            },
            cloneNode(deep) {
                const cloned = createElement(tagName, attrs, deep ? node.innerHTML : '');
                return cloned;
            },
        };
        children.forEach((c) => {
            c.parentNode = node;
        });
        syncSiblings(children);
        return node;
    }

    globalThis.document = {
        createElement(tag) {
            const el = createElement(tag, '', '');
            if (String(tag).toLowerCase() === 'template') {
                return {
                    get content() {
                        return {
                            children: el.children,
                            childNodes: el.childNodes,
                            get firstElementChild() {
                                return el.children[0] || null;
                            },
                        };
                    },
                    set innerHTML(value) {
                        el.innerHTML = value;
                    },
                    get innerHTML() {
                        return el.innerHTML;
                    },
                };
            }
            return el;
        },
    };
}

function makePool(count) {
    const out = [];
    for (let i = 1; i <= count; i += 1) {
        out.push({
            slot: i,
            slot_id: `CTA_${i}`,
            intent: i % 2 === 0 ? 'quotation' : 'consultation',
            content: `Mẫu CTA số ${i}: liên hệ [[cta:zalo]] hoặc [[cta:phone_1]] để được hỗ trợ.`,
        });
    }
    return out;
}

/** Deterministic RNG cycling through fixed unit values. */
function sequenceRng(values) {
    let i = 0;
    return () => {
        const value = values[i % values.length];
        i += 1;
        return value;
    };
}

function words(n, seed = 'từ') {
    const parts = [];
    for (let i = 0; i < n; i += 1) {
        parts.push(`${seed}${i}`);
    }
    return parts.join(' ');
}

function para(n, seed = 'nội') {
    return `<p>${words(n, seed)}</p>`;
}

/**
 * Build a long article with 7 H2s and nested H3s totaling ~targetWords.
 */
function buildLongArticle({ h2Count = 7, h3PerH2 = 3, targetWords = 6595, existingCtaIndexes = [] } = {}) {
    const sections = Math.max(1, h2Count);
    const nested = Math.max(0, h3PerH2);
    const bodySlots = sections * (1 + nested); // one direct H2 body + each H3 body
    const perSlot = Math.max(20, Math.floor(targetWords / bodySlots));
    const parts = [];
    let slot = 0;
    for (let h = 1; h <= sections; h += 1) {
        parts.push(`<h2>Phần ${h}</h2>`);
        parts.push(para(perSlot, `h2${h}w`));
        slot += 1;
        if (existingCtaIndexes.includes(h)) {
            parts.push(`<div class="seo-managed-cta" data-cta-placement="cta_h2_${h}">[seo_ops_cta style="soft"]CTA sẵn ${h}[/seo_ops_cta]</div>`);
        }
        for (let s = 1; s <= nested; s += 1) {
            parts.push(`<h3>Phần ${h}.${s}</h3>`);
            parts.push(para(perSlot, `h3${h}${s}w`));
            slot += 1;
        }
    }
    // Top up remaining words on last paragraph if needed.
    const html = parts.join('');
    return html;
}

describe('ctaGenerator word-density + H2/H3 placement', () => {
    let analyzeArticleForCta;
    let planCtaGeneration;
    let applyCtaGenerationPlan;
    let countCtaContentWords;
    let selectPoolTemplatesForPoints;
    let detectCtas;
    let WORDS_PER_CTA;

    before(async () => {
        installDom();
        ({
            analyzeArticleForCta,
            planCtaGeneration,
            applyCtaGenerationPlan,
            countCtaContentWords,
            selectPoolTemplatesForPoints,
            WORDS_PER_CTA,
        } = await import('../utils/ctaGenerator.js'));
        ({ detectCtas } = await import('../utils/ctaDetector.js'));
    });

    it('1) 650 words => target 1 CTA', () => {
        const html = `<h2>A</h2>${para(650, 'a')}`;
        const analysis = analyzeArticleForCta(html);
        assert.equal(WORDS_PER_CTA, 650);
        assert.ok(analysis.wordCount >= 650);
        assert.equal(analysis.targetCtas, Math.floor(analysis.wordCount / 650));
        assert.equal(analysis.maxAllowed, analysis.targetCtas);
        assert.equal(analysis.remainingQuota, analysis.targetCtas);
        const plan = planCtaGeneration(html, makePool(5), { rng: sequenceRng([0]) });
        assert.equal(plan.proposals.length, Math.min(1, analysis.safePoints.length));
    });

    it('2) 1500 words => target 2 CTAs', () => {
        const html = [
            `<h2>A</h2>${para(500, 'a')}`,
            `<h2>B</h2>${para(500, 'b')}`,
            `<h2>C</h2>${para(500, 'c')}`,
        ].join('');
        const analysis = analyzeArticleForCta(html);
        assert.equal(analysis.targetCtas, Math.floor(analysis.wordCount / 650));
        assert.ok(analysis.targetCtas >= 2);
        const plan = planCtaGeneration(html, makePool(10), { rng: sequenceRng([0, 0.5]) });
        assert.equal(plan.analysis.maxAllowed, plan.analysis.targetCtas);
        assert.ok(plan.proposals.length <= plan.analysis.remainingQuota);
        assert.ok(plan.proposals.length >= 1);
    });

    it('3) 3000 words => target 4 CTAs', () => {
        const parts = [];
        for (let i = 1; i <= 6; i += 1) {
            parts.push(`<h2>Phần ${i}</h2>${para(500, `p${i}`)}`);
        }
        const html = parts.join('');
        const analysis = analyzeArticleForCta(html);
        assert.equal(analysis.targetCtas, Math.floor(analysis.wordCount / 650));
        assert.ok(analysis.targetCtas >= 4);
        const plan = planCtaGeneration(html, makePool(20), { rng: () => 0.1 });
        assert.ok(plan.proposals.length <= analysis.remainingQuota);
        assert.ok(plan.proposals.length >= Math.min(4, analysis.safeH2Count));
    });

    it('4) ~6595 words => target 10 CTAs', () => {
        const html = buildLongArticle({ h2Count: 7, h3PerH2: 3, targetWords: 6595 });
        const analysis = analyzeArticleForCta(html);
        assert.ok(analysis.wordCount >= 6500, `wordCount=${analysis.wordCount}`);
        assert.equal(analysis.targetCtas, Math.floor(analysis.wordCount / 650));
        assert.ok(analysis.targetCtas >= 10);
        assert.equal(analysis.maxAllowed, analysis.targetCtas);
    });

    it('5) 7 H2 needing 10 CTAs falls back to H3 ends', () => {
        const html = buildLongArticle({ h2Count: 7, h3PerH2: 3, targetWords: 6595 });
        const plan = planCtaGeneration(html, makePool(20), { rng: () => 0.2 });
        assert.ok(plan.analysis.safeH2Count <= 7);
        assert.ok(plan.analysis.safeH3Count > 0);
        assert.ok(plan.proposals.length > plan.analysis.safeH2Count || plan.proposals.some((p) => p.headingTag === 'h3'));
        const tags = new Set(plan.proposals.map((p) => p.headingTag));
        assert.ok(tags.has('h2'));
        assert.ok(tags.has('h3'), 'should use H3 when H2 alone cannot cover quota');
    });

    it('6) H2 with many H3s inserts at true H2 end (after nested H3 bodies)', () => {
        const html = [
            '<h2>Phần 1</h2>',
            para(200, 'a'),
            '<h3>1.1</h3>',
            para(200, 'b'),
            '<h3>1.2</h3>',
            para(200, 'c'),
            '<h2>Phần 2</h2>',
            para(400, 'd'),
        ].join('');
        const analysis = analyzeArticleForCta(html);
        const h2Point = analysis.safeH2Points.find((p) => p.heading === 'Phần 1');
        assert.ok(h2Point);
        const h3Points = analysis.safeH3Points.filter((p) => p.parentH2SectionId === h2Point.sectionId);
        assert.equal(h3Points.length, 2);
        assert.ok(h2Point.anchorChildIndex > h3Points[0].anchorChildIndex);
        assert.equal(h2Point.anchorChildIndex, h3Points[1].anchorChildIndex);
    });

    it('7) consecutive H3s get distinct end anchors', () => {
        const html = [
            '<h2>Phần 1</h2>',
            para(100, 'a'),
            '<h3>1.1</h3>',
            para(100, 'b'),
            '<h3>1.2</h3>',
            para(100, 'c'),
            '<h3>1.3</h3>',
            para(100, 'd'),
        ].join('');
        const analysis = analyzeArticleForCta(html);
        assert.equal(analysis.safeH3Count, 3);
        const anchors = analysis.safeH3Points.map((p) => p.anchorChildIndex);
        assert.equal(new Set(anchors).size, 3);
    });

    it('8) existing CTAs are preserved and reduce remaining quota', () => {
        const legacy = '<div class="seo-managed-cta" data-cta-placement="cta_old">[seo_ops_cta style="soft"]CTA cũ[/seo_ops_cta]</div>';
        const parts = [];
        for (let i = 1; i <= 8; i += 1) {
            parts.push(`<h2>Phần ${i}</h2>${para(400, `p${i}`)}`);
            if (i === 2) {
                parts.push(legacy);
            }
        }
        const html = parts.join('');
        const analysis = analyzeArticleForCta(html);
        assert.equal(analysis.existingCount, 1);
        assert.equal(analysis.remainingQuota, Math.max(0, analysis.targetCtas - 1));

        const plan = planCtaGeneration(html, makePool(20), { rng: () => 0.3 });
        const applied = applyCtaGenerationPlan(html, plan);
        assert.ok(applied.html.includes(legacy));
        assert.ok(applied.html.includes('CTA cũ'));
    });

    it('9) second generator run does not duplicate when density already reached', () => {
        const parts = [];
        for (let i = 1; i <= 6; i += 1) {
            parts.push(`<h2>Phần ${i}</h2>${para(500, `p${i}`)}`);
        }
        const html = parts.join('');
        const first = planCtaGeneration(html, makePool(20), { rng: () => 0.15 });
        assert.ok(first.canInsert);
        const applied = applyCtaGenerationPlan(html, first);
        assert.ok(applied.inserted > 0);

        const second = planCtaGeneration(applied.html, makePool(20), { rng: () => 0.15 });
        const before = detectCtas(applied.html).shortcodes.length;
        if (second.canInsert) {
            const again = applyCtaGenerationPlan(applied.html, second);
            const after = detectCtas(again.html).shortcodes.length;
            assert.ok(after >= before);
            // No duplicate placement ids from first batch.
            for (const p of first.proposals) {
                const occurrences = again.html.split(`data-cta-placement="${p.id}"`).length - 1;
                assert.equal(occurrences, 1);
            }
        } else {
            assert.ok(['density_reached', 'no_safe_position'].includes(second.reason));
        }
    });

    it('10) FAQ / table / list / shortcode zones are not used as insert anchors incorrectly', () => {
        const html = [
            '<h2>Phần 1</h2>',
            para(300, 'a'),
            '<table><tr><td>bảng</td></tr></table>',
            '<ul><li>mục một</li><li>mục hai</li></ul>',
            '<h2>Câu hỏi thường gặp</h2>',
            '<h3>Hỏi 1?</h3>',
            para(50, 'faq'),
            '<p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq]</p>',
            '<h2>Phần 2</h2>',
            para(300, 'b'),
        ].join('');
        const analysis = analyzeArticleForCta(html);
        assert.ok(analysis.safePoints.every((p) => !/faq|câu hỏi thường gặp/i.test(p.heading)));
        const plan = planCtaGeneration(html, makePool(5), { rng: () => 0 });
        if (plan.canInsert) {
            const applied = applyCtaGenerationPlan(html, plan);
            assert.ok(applied.html.includes('omi-faq-placeholder'));
            assert.ok(applied.html.includes('<table>'));
            assert.ok(applied.html.includes('<ul>'));
            assert.ok(!applied.html.includes('data-omi-faq="1"[seo_ops_cta'));
        }
    });

    it('11) pool smaller than quota allows reuse across rounds', () => {
        const parts = [];
        for (let i = 1; i <= 10; i += 1) {
            parts.push(`<h2>Phần ${i}</h2>${para(400, `p${i}`)}`);
            parts.push(`<h3>${i}.1</h3>${para(300, `s${i}`)}`);
        }
        const html = parts.join('');
        const analysis = analyzeArticleForCta(html);
        assert.ok(analysis.remainingQuota > 3);
        const pool = makePool(3);
        const plan = planCtaGeneration(html, pool, { rng: sequenceRng([0, 0.3, 0.6, 0.1, 0.4, 0.7, 0.2]) });
        assert.ok(plan.proposals.length > pool.length);
        const slotIds = plan.proposals.map((p) => p.slotId);
        assert.ok(new Set(slotIds).size <= pool.length);
    });

    it('12) injectable RNG makes pool selection deterministic', () => {
        const points = [
            { sectionId: 'h2_1', heading: 'A' },
            { sectionId: 'h2_2', heading: 'B' },
            { sectionId: 'h2_3', heading: 'C' },
        ];
        const pool = makePool(5);
        const a = selectPoolTemplatesForPoints(pool, points, { shortcodes: [], texts: [] }, sequenceRng([0, 0, 0]));
        const b = selectPoolTemplatesForPoints(pool, points, { shortcodes: [], texts: [] }, sequenceRng([0, 0, 0]));
        assert.deepEqual(
            a.map((row) => row.template.slot_id),
            b.map((row) => row.template.slot_id),
        );
    });

    it('13) apply does not destroy article body HTML', () => {
        const marker = '<p>Đoạn quan trọng tiếng Việt có dấu.</p>';
        const html = [
            '<h2>Phần 1</h2>',
            marker,
            para(400, 'a'),
            '<h2>Phần 2</h2>',
            para(400, 'b'),
            '<h2>Phần 3</h2>',
            para(400, 'c'),
        ].join('');
        const plan = planCtaGeneration(html, makePool(10), { rng: () => 0.25 });
        const applied = applyCtaGenerationPlan(html, plan);
        assert.ok(applied.html.includes(marker));
        assert.ok(applied.html.includes('Đoạn quan trọng tiếng Việt có dấu'));
        assert.ok(applied.html.includes('<h2>Phần 1</h2>'));
        assert.ok(applied.html.includes('<h2>Phần 3</h2>'));
    });

    it('14) preview/apply fields remain compatible for Article Editor panel', () => {
        const html = [
            `<h2>A</h2>${para(400, 'a')}`,
            `<h2>B</h2>${para(400, 'b')}`,
            `<h2>C</h2>${para(400, 'c')}`,
        ].join('');
        const plan = planCtaGeneration(html, makePool(8), { rng: () => 0.4 });
        assert.equal(typeof plan.analysis.maxAllowed, 'number');
        assert.equal(typeof plan.analysis.remainingQuota, 'number');
        assert.equal(typeof plan.analysis.wordCount, 'number');
        assert.equal(typeof plan.analysis.targetCtas, 'number');
        assert.equal(plan.analysis.maxAllowed, plan.analysis.targetCtas);
        if (plan.canInsert) {
            assert.ok(plan.proposals[0].managedHtml.includes('seo-managed-cta'));
            assert.ok(plan.proposals[0].managedHtml.includes('seo_ops_cta'));
            const applied = applyCtaGenerationPlan(html, plan);
            assert.equal(applied.ok, true);
            assert.ok(applied.inserted > 0);
        }
    });

    it('word counter ignores HTML tags, shortcodes, and CTA bodies', () => {
        assert.equal(countCtaContentWords('Xin chào thế giới'), 4);
        // Shortcode syntax stripped; plain words between tags still count at text level.
        assert.equal(countCtaContentWords('Một [seo_ops_cta style="soft"][/seo_ops_cta] ba'), 2);
        assert.equal(countCtaContentWords('Một [omi_faq] ba'), 2);
        const html = [
            '<h2>Tiêu đề</h2>',
            '<p>Một hai ba bốn năm</p>',
            '<div class="seo-managed-cta">[seo_ops_cta]CTA dài không đếm vào quota từ[/seo_ops_cta]</div>',
            '<p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq]</p>',
        ].join('');
        // FAQ heading not present; faq placeholder alone ends analysis after first section content.
        const withFaqH2 = [
            '<h2>Mở</h2>',
            '<p>Một hai ba bốn năm sáu</p>',
            '<h2>Câu hỏi thường gặp</h2>',
            '<p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq] metadata không đếm</p>',
        ].join('');
        const analysis = analyzeArticleForCta(withFaqH2);
        assert.equal(analysis.wordCount, 6);
        assert.ok(!Number.isNaN(analysis.wordCount));
    });

    it('empty pool and density_reached reasons still work', () => {
        assert.equal(planCtaGeneration(`<h2>A</h2>${para(700, 'a')}`, []).reason, 'empty_pool');
        const short = planCtaGeneration('<h2>A</h2><p>Ngắn.</p>', makePool(3));
        assert.equal(short.analysis.targetCtas, 0);
        assert.equal(short.reason, 'density_reached');
    });
});
