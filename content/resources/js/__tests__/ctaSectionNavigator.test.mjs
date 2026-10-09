import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    describeCtaHeadingTarget,
    locateCtaSections,
    matchEditorHeading,
    selectEditorScroller,
} from '../utils/ctaSectionNavigator.js';

function parseChildren(html) {
    const parts = String(html).match(/<(\w+)[^>]*>[\s\S]*?<\/\1>/g) ?? [];
    return parts.map((chunk) => {
        const tag = chunk.match(/^<(\w+)/)[1];
        return {
            tagName: tag.toUpperCase(),
            textContent: chunk.replace(/<[^>]+>/g, ''),
            classList: { contains: () => false },
            getAttribute: () => null,
        };
    });
}

globalThis.document = {
    createElement() {
        const element = { content: { children: [] } };
        Object.defineProperty(element, 'innerHTML', {
            set(value) {
                element.content = { children: parseChildren(value) };
            },
        });
        return element;
    },
    documentElement: { id: 'html' },
    scrollingElement: { id: 'page' },
};

const article = [
    '<p>Mở đầu</p>',
    '<h2>1. Chuẩn bị</h2><p>Vật liệu.</p>',
    '<h3>2.3. Kích thước, bleed và margin</h3><p>Bleed.</p>',
    '<h3>2.4. Khác</h3><p>Khác.</p>',
    '<h2>6. Ứng dụng</h2><p>Dùng.</p>',
    '<h3>6.3. Trường hợp sử dụng phổ biến</h3><p>Case.</p>',
    '<h2>Kết luận</h2><p>Xong.</p>',
    '<h2>Phụ lục</h2><p>Một.</p>',
    '<h2>Kết luận</h2><p>Lặp.</p>',
].join('');

function sectionIdFor(heading) {
    return locateCtaSections(article).find((row) => row.heading === heading).sectionId;
}

describe('CTA heading identity', () => {
    it('resolves an H2 by heading occurrence, not editor index', () => {
        const plan = describeCtaHeadingTarget(article, sectionIdFor('Kết luận'), 'Kết luận');
        assert.equal(plan.kind, 'h2');
        assert.equal(plan.occurrence, 1);
        const later = locateCtaSections(article).filter((row) => row.heading === 'Kết luận')[1];
        const secondPlan = describeCtaHeadingTarget(article, later.sectionId, 'Kết luận');
        assert.equal(secondPlan.occurrence, 2);
        const editor = [
            { id: 'section-intro', isIntro: true, title: '', h3s: [] },
            { id: 'decoy', title: 'Mục chèn thêm', h3s: [] },
            { id: 'section-a', title: '1. Chuẩn bị', h3s: [] },
            { id: 'section-b', title: '6. Ứng dụng', h3s: [] },
            { id: 'section-c', title: 'Kết luận', h3s: [] },
            { id: 'section-d', title: 'Phụ lục', h3s: [] },
            { id: 'section-e', title: 'Kết luận', h3s: [] },
        ];
        assert.equal(matchEditorHeading(plan, editor).sectionId, 'section-c');
        assert.equal(matchEditorHeading(secondPlan, editor).sectionId, 'section-e');
    });

    it('resolves H3 inside the parent H2, including a collapsed empty list as a miss', () => {
        const plan = describeCtaHeadingTarget(article, sectionIdFor('6.3. Trường hợp sử dụng phổ biến'), '6.3. Trường hợp sử dụng phổ biến');
        assert.equal(plan.kind, 'h3');
        assert.equal(plan.parentHeading, '6. Ứng dụng');
        const open = matchEditorHeading(plan, [
            { id: 'section-b', title: '6. Ứng dụng', h3s: ['6.3. Trường hợp sử dụng phổ biến'] },
        ]);
        assert.equal(open.sectionId, 'section-b');
        assert.equal(open.h3Index, 0);
        const collapsed = matchEditorHeading(plan, [
            { id: 'section-b', title: '6. Ứng dụng', h3s: [] },
        ]);
        assert.equal(collapsed.ok, false);
        assert.equal(collapsed.sectionId, 'section-b');
    });

    it('resolves the sized H3 without jumping to a later H3', () => {
        const plan = describeCtaHeadingTarget(article, sectionIdFor('2.3. Kích thước, bleed và margin'), '2.3. Kích thước, bleed và margin');
        const match = matchEditorHeading(plan, [{
            id: 'section-a',
            title: '1. Chuẩn bị',
            h3s: ['2.3. Kích thước, bleed và margin', '2.4. Khác'],
        }]);
        assert.equal(match.h3Index, 0);
    });

    it('resolves the introduction only for an empty heading', () => {
        const intro = locateCtaSections(article).find((row) => row.heading === '');
        const plan = describeCtaHeadingTarget(article, intro.sectionId, '');
        assert.equal(plan.kind, 'intro');
        assert.equal(matchEditorHeading(plan, [{ id: 'section-intro', isIntro: true }]).sectionId, 'section-intro');
        assert.equal(describeCtaHeadingTarget(article, intro.sectionId, 'Kết luận').ok, false);
    });

    it('keeps consecutive headings on the last heading identity', () => {
        const html = '<h2>Một</h2><h2>Hai</h2><p>Nội dung.</p>';
        const rows = locateCtaSections(html);
        assert.equal(rows.length, 1);
        assert.equal(rows[0].heading, 'Hai');
        assert.equal(describeCtaHeadingTarget(html, 'section_1', 'Hai').kind, 'h2');
        assert.equal(describeCtaHeadingTarget(html, 'section_1', 'Một').ok, false);
    });

    it('repeats the same resolution', () => {
        const id = sectionIdFor('2.3. Kích thước, bleed và margin');
        const first = describeCtaHeadingTarget(article, id, '2.3. Kích thước, bleed và margin');
        const second = describeCtaHeadingTarget(article, id, '2.3. Kích thước, bleed và margin');
        assert.deepEqual(first, second);
    });
});

describe('editor scroller', () => {
    it('uses the central pane scroller and ignores a sidebar scroller', () => {
        const page = { id: 'page' };
        document.scrollingElement = page;
        globalThis.window = {
            getComputedStyle(node) {
                return { overflowY: node.scrollable ? 'auto' : 'visible' };
            },
        };
        const sidebar = make('seo-article-editor-left-rail', { scrollable: true });
        const pane = make('seo-article-editor-mainpane', { scrollable: false });
        const inner = make('seo-editor-canvas', { scrollable: true, parent: pane });
        pane.contains = (node) => node === inner || node === pane;
        const slot = make('seo-editor-block-slot', { parent: inner });
        sidebar.parentElement = pane.parentElement;
        assert.equal(selectEditorScroller(slot), inner);
        const escaped = make('seo-editor-block-slot', { parent: sidebar });
        assert.equal(selectEditorScroller(escaped), page);
    });
});

function make(className, { scrollable = false, parent = null } = {}) {
    const node = {
        className,
        scrollable,
        parentElement: parent,
        scrollHeight: scrollable ? 400 : 10,
        clientHeight: 100,
        matches(selector) {
            return selector.split(',').some((part) => part.trim() === `.${className}` || part.trim() === className);
        },
        closest(selector) {
            let current = this;
            while (current) {
                if (current.matches(selector)) {
                    return current;
                }
                current = current.parentElement;
            }
            return null;
        },
        contains() {
            return false;
        },
    };
    return node;
}
