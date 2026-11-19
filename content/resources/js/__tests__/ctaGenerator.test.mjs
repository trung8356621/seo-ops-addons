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

function manyH3(prefix, count) {
    const parts = [];
    for (let i = 1; i <= count; i += 1) {
        parts.push(`<h3>${prefix}.${i}</h3><p>Nội dung ${prefix}.${i}.</p>`);
    }
    return parts.join('');
}

describe('ctaGenerator H2-only density', () => {
    let analyzeArticleForCta;
    let planCtaGeneration;
    let applyCtaGenerationPlan;
    let detectCtas;

    before(async () => {
        installDom();
        ({ analyzeArticleForCta, planCtaGeneration, applyCtaGenerationPlan } = await import('../utils/ctaGenerator.js'));
        ({ detectCtas } = await import('../utils/ctaDetector.js'));
    });

    it('3 H2 + many H3 => max 1 CTA at end of first H2 group', () => {
        const html = [
            '<h2>Phần 1</h2><p>Nội dung phần 1.</p>',
            manyH3('1', 5),
            '<h2>Phần 2</h2><p>Nội dung phần 2.</p>',
            manyH3('2', 5),
            '<h2>Phần 3</h2><p>Nội dung phần 3.</p>',
            manyH3('3', 5),
        ].join('');

        const plan = planCtaGeneration(html, makePool(10));
        assert.equal(plan.analysis.eligibleHeadingCount, 3);
        assert.equal(plan.analysis.maxAllowed, 1);
        assert.equal(plan.proposals.length, 1);
        assert.equal(plan.proposals[0].heading, 'Phần 3');

        const applied = applyCtaGenerationPlan(html, plan);
        assert.equal(applied.inserted, 1);
        assert.equal(detectCtas(applied.html).shortcodes.length, 1);

        const second = planCtaGeneration(applied.html, makePool(10));
        assert.equal(second.canInsert, false);
        assert.equal(second.reason, 'density_reached');
    });

    it('6 H2 + many H3 => max 2 CTAs; 9 H2 => max 3; 1 H2 => 0', () => {
        const six = [];
        for (let i = 1; i <= 6; i += 1) {
            six.push(`<h2>Phần ${i}</h2><p>Nội dung ${i}.</p>${manyH3(String(i), 3)}`);
        }
        const sixPlan = planCtaGeneration(six.join(''), makePool(10));
        assert.equal(sixPlan.analysis.eligibleHeadingCount, 6);
        assert.equal(sixPlan.analysis.maxAllowed, 2);
        assert.equal(sixPlan.proposals.length, 2);
        assert.equal(sixPlan.proposals[0].heading, 'Phần 3');
        assert.equal(sixPlan.proposals[1].heading, 'Phần 6');

        const nine = [];
        for (let i = 1; i <= 9; i += 1) {
            nine.push(`<h2>Phần ${i}</h2><p>Nội dung ${i}.</p>`);
        }
        const ninePlan = planCtaGeneration(nine.join(''), makePool(20));
        assert.equal(ninePlan.analysis.eligibleHeadingCount, 9);
        assert.equal(ninePlan.proposals.length, 3);

        const one = planCtaGeneration(`<h2>Only</h2><p>Body.</p>${manyH3('1', 10)}`, makePool(10));
        assert.equal(one.analysis.eligibleHeadingCount, 1);
        assert.equal(one.analysis.maxAllowed, 0);
        assert.equal(one.canInsert, false);
        assert.equal(one.reason, 'density_reached');
    });

    it('skips H2 subtree that already has a CTA and does not move legacy CTAs', () => {
        const legacy = '<div class="seo-managed-cta" data-cta-placement="cta_old">[seo_ops_cta style="soft"]CTA cũ[/seo_ops_cta]</div>';
        const html = [
            '<h2>Phần 1</h2><p>Nội dung 1.</p>',
            '<h2>Phần 2</h2><p>Nội dung 2.</p>',
            legacy,
            '<h3>2.1</h3><p>Con của phần 2.</p>',
            '<h2>Phần 3</h2><p>Nội dung 3.</p>',
            '<h2>Phần 4</h2><p>Nội dung 4.</p>',
            '<h2>Phần 5</h2><p>Nội dung 5.</p>',
            '<h2>Phần 6</h2><p>Nội dung 6.</p>',
        ].join('');

        const plan = planCtaGeneration(html, makePool(10));
        assert.equal(plan.analysis.eligibleHeadingCount, 6);
        assert.equal(plan.analysis.existingCount, 1);
        assert.equal(plan.analysis.maxAllowed, 2);
        // Group 1 (H2 1-3) already has CTA in H2#2 → skip; group 2 (H2 4-6) gets one at end.
        assert.equal(plan.proposals.length, 1);
        assert.equal(plan.proposals[0].heading, 'Phần 6');

        const applied = applyCtaGenerationPlan(html, plan);
        assert.ok(applied.html.includes(legacy));
        assert.equal(detectCtas(applied.html).shortcodes.length, 2);
    });

    it('9 H2 with 2 existing CTAs can add at most 1 more in a free group', () => {
        const cta = (id) => `<div class="seo-managed-cta" data-cta-placement="${id}">[seo_ops_cta style="soft"]Body ${id}[/seo_ops_cta]</div>`;
        const parts = [];
        for (let i = 1; i <= 9; i += 1) {
            parts.push(`<h2>Phần ${i}</h2><p>Nội dung ${i}.</p>`);
            if (i === 2 || i === 5) {
                parts.push(cta(`cta_${i}`));
            }
        }
        const plan = planCtaGeneration(parts.join(''), makePool(10));
        assert.equal(plan.analysis.existingCount, 2);
        assert.equal(plan.analysis.maxAllowed, 3);
        assert.equal(plan.proposals.length, 1);
        assert.equal(plan.proposals[0].heading, 'Phần 9');
    });

    it('excludes H1/H3/H4/FAQ and never inserts into FAQ or empty H2', () => {
        const html = [
            '<h1>H1 title</h1>',
            '<h2>Phần 1</h2>',
            '<h2>Phần 2</h2><p>Nội dung 2.</p>',
            '<h4>H4 sub</h4><p>Nội dung H4.</p>',
            '<h2>Câu hỏi thường gặp</h2>',
            '<h3>Câu hỏi 1?</h3><p>Trả lời 1.</p>',
            '<p class="omi-faq-placeholder" data-omi-faq="1">[omi_faq]</p>',
        ].join('');

        const analysis = analyzeArticleForCta(html);
        assert.equal(analysis.eligibleHeadingCount, 2);
        assert.equal(analysis.maxAllowed, 0);

        const oneSafe = planCtaGeneration(
            '<h2>A</h2><h2>B</h2><h2>C</h2><p>Only C has body.</p>',
            makePool(5),
        );
        assert.equal(oneSafe.analysis.safePoints.length, 1);
        assert.equal(oneSafe.proposals[0].heading, 'C');
    });

    it('returns empty_pool and preserves shortcodes byte-for-byte when adding remaining quota', () => {
        assert.equal(planCtaGeneration('<h2>A</h2><p>1</p><h2>B</h2><p>2</p><h2>C</h2><p>3</p>', []).reason, 'empty_pool');

        const legacy = '<p>[seo_ops_cta style="soft" placement="cta_001" intent="product_discovery"]Xem mẫu cũ [website][/seo_ops_cta]</p>';
        const html = [
            '<h2>Phần 1</h2><p>Nội dung 1.</p>',
            legacy,
            '<h2>Phần 2</h2><p>Nội dung 2.</p>',
            '<h2>Phần 3</h2><p>Nội dung 3.</p>',
            '<h2>Phần 4</h2><p>Nội dung 4.</p>',
            '<h2>Phần 5</h2><p>Nội dung 5.</p>',
            '<h2>Phần 6</h2><p>Nội dung 6.</p>',
        ].join('');
        const plan = planCtaGeneration(html, makePool(20));
        assert.equal(plan.proposals.length, 1);
        const applied = applyCtaGenerationPlan(html, plan);
        assert.ok(applied.html.includes(legacy));
    });
});
