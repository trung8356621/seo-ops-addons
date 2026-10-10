import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    describeCtaHeadingTarget,
    locateCtaSections,
    matchEditorHeading,
    requestCtaHeadingNavigation,
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

describe('outline jump request', () => {
    const blocks = [
        { id: 'intro-block', type: 'text', content: '<p>Mở đầu về balo.</p>' },
        { id: 'reason-h2', type: 'text', content: '<h2>Lý do chọn balo quà tặng cho Trường Tiến</h2><p>Giới thiệu mục.</p>' },
        { id: 'reason-body', type: 'text', content: '<h3>Thể hiện cá tính, phong cách</h3><p>Nội dung.</p>' },
        { id: 'other-h2', type: 'text', content: '<h2>Mục khác</h2>' },
        { id: 'other-body', type: 'text', content: '<h3>Thể hiện cá tính, phong cách</h3><p>Bản sau.</p>' },
        { id: 'end-a', type: 'text', content: '<h2>Kết luận</h2><p>Một.</p>' },
        { id: 'end-b', type: 'text', content: '<h2>Kết luận</h2><p>Hai.</p>' },
    ];
    const html = blocks.map((block) => block.content).join('\n\n');

    function jumpFor(heading, occurrence = 1) {
        const rows = locateCtaSections(html).filter((row) => row.heading === heading);
        const calls = [];
        const ok = requestCtaHeadingNavigation({
            sectionId: rows[occurrence - 1].sectionId,
            heading,
            html,
            blocks,
            jump: (node) => calls.push(node),
        });

        return { ok, node: calls[0] ?? null };
    }

    it('maps an H2 onto its heading block, not a section_N id', () => {
        const { ok, node } = jumpFor('Lý do chọn balo quà tặng cho Trường Tiến');
        assert.equal(ok, true);
        assert.equal(node.block_id, 'reason-h2');
        assert.equal(node.level, 2);
        assert.equal(node.heading_index, 0);
        assert.equal(node.id.startsWith('section_'), false);
    });

    it('maps a nested H3 inside the parent section body block', () => {
        const { ok, node } = jumpFor('Thể hiện cá tính, phong cách');
        assert.equal(ok, true);
        assert.equal(node.block_id, 'reason-body');
        assert.equal(node.heading_index, 0);
        const later = jumpFor('Thể hiện cá tính, phong cách', 2);
        assert.equal(later.node.block_id, 'other-body');
    });

    it('maps introduction and duplicate conclusion headings', () => {
        const intro = locateCtaSections(html).find((row) => row.heading === '');
        const calls = [];
        const ok = requestCtaHeadingNavigation({
            sectionId: intro.sectionId,
            heading: '',
            html,
            blocks,
            jump: (node) => calls.push(node),
        });
        assert.equal(ok, true);
        assert.equal(calls[0].block_id, 'intro-block');
        assert.equal(calls[0].id, 'section-intro');
        assert.equal(jumpFor('Kết luận', 2).node.block_id, 'end-b');
    });

    it('does not call jump when the heading is absent from editor blocks', () => {
        const calls = [];
        const ok = requestCtaHeadingNavigation({
            sectionId: 'section_2',
            heading: 'Không có heading này',
            html,
            blocks,
            jump: (node) => calls.push(node),
        });
        assert.equal(ok, false);
        assert.equal(calls.length, 0);
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
