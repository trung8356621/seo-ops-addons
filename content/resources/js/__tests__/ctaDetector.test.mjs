import assert from 'node:assert/strict';
import { describe, it, before } from 'node:test';

/**
 * Minimal DOM with element + text nodes for CTA detector tests.
 */
function installDom() {
    function createText(value) {
        return {
            nodeType: 3,
            textContent: String(value),
            outerHTML: String(value),
            parentNode: null,
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

    function createElement(tagName, attrs = '', rawInner = '') {
        const attrMap = {};
        String(attrs).replace(/([a-zA-Z_:-]+)="([^"]*)"/g, (_, key, value) => {
            attrMap[key] = value;
            return '';
        });
        const classNames = String(attrMap.class || '')
            .split(/\s+/)
            .filter(Boolean);
        const children = parseFragment(rawInner);
        const node = {
            nodeType: 1,
            tagName: String(tagName).toUpperCase(),
            children: children.filter((child) => child.nodeType === 1),
            childNodes: children,
            parentNode: null,
            classList: {
                contains: (name) => classNames.includes(name),
                add() {},
                remove() {},
            },
            getAttribute: (name) => (Object.prototype.hasOwnProperty.call(attrMap, name) ? attrMap[name] : null),
            get textContent() {
                return children.map((child) => child.textContent || '').join('');
            },
            get innerHTML() {
                return children.map((child) => child.outerHTML).join('');
            },
            set innerHTML(value) {
                const next = parseFragment(value);
                children.splice(0, children.length, ...next);
                node.childNodes = children;
                node.children = children.filter((child) => child.nodeType === 1);
                next.forEach((child) => {
                    child.parentNode = node;
                });
            },
            get outerHTML() {
                const attrText = Object.entries(attrMap)
                    .map(([key, value]) => ` ${key}="${value}"`)
                    .join('');
                return `<${String(tagName).toLowerCase()}${attrText}>${node.innerHTML}</${String(tagName).toLowerCase()}>`;
            },
            replaceChild(next, old) {
                const index = children.indexOf(old);
                if (index < 0) {
                    return;
                }
                children[index] = next;
                next.parentNode = node;
                old.parentNode = null;
                node.childNodes = children;
                node.children = children.filter((child) => child.nodeType === 1);
            },
            removeChild(old) {
                const index = children.indexOf(old);
                if (index < 0) {
                    return;
                }
                children.splice(index, 1);
                old.parentNode = null;
                node.childNodes = children;
                node.children = children.filter((child) => child.nodeType === 1);
            },
            appendChild(child) {
                children.push(child);
                child.parentNode = node;
                node.childNodes = children;
                node.children = children.filter((item) => item.nodeType === 1);
                return child;
            },
            cloneNode() {
                return createElement(tagName, Object.entries(attrMap).map(([k, v]) => `${k}="${v}"`).join(' '), node.innerHTML);
            },
            querySelector() {
                return null;
            },
            querySelectorAll() {
                return [];
            },
            closest() {
                return null;
            },
            scrollIntoView() {},
        };
        children.forEach((child) => {
            child.parentNode = node;
        });
        return node;
    }

    const document = {
        createElement(tag) {
            if (tag === 'template') {
                const content = {
                    children: [],
                    childNodes: [],
                    get firstElementChild() {
                        return this.children[0] || null;
                    },
                    replaceChild(next, old) {
                        const index = this.childNodes.indexOf(old);
                        if (index < 0) {
                            return;
                        }
                        this.childNodes[index] = next;
                        this.children = this.childNodes.filter((child) => child.nodeType === 1);
                        next.parentNode = this;
                    },
                    removeChild(old) {
                        const index = this.childNodes.indexOf(old);
                        if (index < 0) {
                            return;
                        }
                        this.childNodes.splice(index, 1);
                        this.children = this.childNodes.filter((child) => child.nodeType === 1);
                    },
                };
                const element = { content };
                Object.defineProperty(element, 'innerHTML', {
                    set(value) {
                        const nodes = parseFragment(value).filter((child) => child.nodeType === 1);
                        content.childNodes = nodes;
                        content.children = nodes;
                        nodes.forEach((node) => {
                            node.parentNode = content;
                        });
                    },
                });
                return element;
            }
            return createElement(tag);
        },
        querySelector() {
            return null;
        },
        querySelectorAll() {
            return [];
        },
    };

    globalThis.document = document;
    globalThis.CSS = { escape: (value) => String(value) };
    globalThis.window = { setTimeout, document, CSS: globalThis.CSS };
}

describe('ctaDetector', () => {
    /** @type {typeof import('../utils/ctaDetector.js')} */
    let detector;

    before(async () => {
        installDom();
        detector = await import('../utils/ctaDetector.js');
    });

    it('detects managed container shortcode and plain-text CTA', () => {
        const html = [
            '<p>Nylon resists water when the weave is tight.</p>',
            '<div class="seo-managed-cta" data-cta-placement="cta_001" data-cta-style="soft">',
            '[seo_ops_cta style="soft"]Nhắn [zalo] để tư vấn.[/seo_ops_cta]</div>',
            '<blockquote>Liên hệ Công ty may ba lô túi xách Hợp Phát qua Zalo hoặc email để nhận tư vấn.</blockquote>',
            '<p>Choose a size that matches the torso length.</p>',
        ].join('');
        const result = detector.detectCtas(html);
        assert.equal(result.shortcodes.length, 1);
        assert.equal(result.shortcodes[0].form, 'container');
        assert.equal(result.texts.length, 1);
        assert.equal(result.texts[0].canWrap, true);
        assert.equal(result.texts[0].canDelete, true);
    });

    it('ignores lone email or phone without CTA verbs', () => {
        const html = '<p>Email support@shop.test for records.</p><p>Hotline 0901234567 is listed on the invoice.</p>';
        const result = detector.detectCtas(html);
        assert.equal(result.texts.length, 0);
        assert.equal(result.shortcodes.length, 0);
    });

    it('marks mixed promotional paragraphs as uncertain and blocks delete', () => {
        const html = '<p>The fabric stays dry in light rain and lasts for years of school use, then contact us via Zalo if you want a catalog.</p>';
        const result = detector.detectCtas(html);
        assert.equal(result.texts.length, 1);
        assert.equal(result.texts[0].confidence, 'uncertain');
        assert.equal(result.texts[0].canDelete, false);
    });

    it('wraps plain-text CTA with seo_ops_cta and is idempotent', () => {
        const html = '<p>Keep.</p><blockquote>Liên hệ qua Zalo https://zalo.me/1 để được tư vấn.</blockquote><p>After.</p>';
        const first = detector.detectCtas(html);
        assert.equal(first.texts.length, 1);
        const wrapped = detector.wrapTextCtaInHtml(html, first.texts[0]);
        assert.equal(wrapped.ok, true);
        assert.match(wrapped.html, /\[seo_ops_cta style="soft"/);
        assert.match(wrapped.html, /\[\/seo_ops_cta\]/);
        assert.match(wrapped.html, /seo-managed-cta/);
        assert.match(wrapped.html, /Keep\./);
        assert.match(wrapped.html, /After\./);
        const again = detector.detectCtas(wrapped.html);
        assert.equal(again.texts.length, 0);
        assert.equal(again.shortcodes.length, 1);
        const second = detector.wrapTextCtaInHtml(wrapped.html, first.texts[0]);
        assert.equal(second.ok, false);
        assert.equal(second.code, 'already_wrapped');
    });

    it('deletes only the selected plain-text CTA node', () => {
        const html = '<p>Before.</p><p>Liên hệ qua Zalo https://zalo.me/1 để được tư vấn.</p><p>After.</p>';
        const found = detector.detectCtas(html);
        assert.equal(found.texts.length, 1);
        const deleted = detector.deleteTextCtaInHtml(html, found.texts[0]);
        assert.equal(deleted.ok, true);
        assert.match(deleted.html, /Before\./);
        assert.match(deleted.html, /After\./);
        assert.doesNotMatch(deleted.html, /Zalo/);
        assert.equal(detector.detectCtas(deleted.html).texts.length, 0);
    });

    it('preserves bold formatting when wrapping', () => {
        const html = '<p>Liên hệ <strong>Hợp Phát</strong> qua Zalo để nhận tư vấn.</p>';
        const found = detector.detectCtas(html);
        assert.equal(found.texts.length, 1);
        const wrapped = detector.wrapTextCtaInHtml(html, found.texts[0]);
        assert.equal(wrapped.ok, true);
        assert.match(wrapped.html, /<strong>Hợp Phát<\/strong>/);
    });

    it('detects multiple CTAs across sections', () => {
        const html = [
            '<h2>One</h2><p>Liên hệ qua Zalo https://zalo.me/1 để được tư vấn.</p>',
            '<h2>Two</h2><p>Liên hệ qua email a@b.com để nhận báo giá.</p>',
        ].join('');
        const result = detector.detectCtas(html);
        assert.equal(result.texts.length, 2);
        assert.notEqual(result.texts[0].sectionId, result.texts[1].sectionId);
    });

    it('builds the verified shortcode contract', () => {
        const html = detector.buildManagedCtaHtml({
            placementId: 'cta_1',
            sectionId: 'section_1',
            innerHtml: 'Liên hệ <em>ngay</em>',
        });
        assert.match(html, /\[seo_ops_cta style="soft" placement="cta_1" intent="consultation"]/);
        assert.match(html, /Liên hệ <em>ngay<\/em>\[\/seo_ops_cta]/);
    });
});
