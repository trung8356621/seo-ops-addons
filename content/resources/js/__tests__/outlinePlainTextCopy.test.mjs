import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatOutlineSubtreePlainText,
    stripOutlineNumberPrefix,
} from '../utils/outlinePlainTextCopy.js';

describe('outlinePlainTextCopy', () => {
    it('copies H2 with nested H3/H4 and indentation', () => {
        const text = formatOutlineSubtreePlainText({
            level: 2,
            heading_text: 'H2 🌟 Tiếng Việt',
            children: [
                {
                    level: 3,
                    heading_text: 'H3 con',
                    children: [{ level: 4, heading_text: 'H4 cháu', children: [] }],
                },
                { level: 3, heading_text: 'H3 khác', children: [] },
            ],
        });
        assert.equal(text, 'H2 🌟 Tiếng Việt\n  H3 con\n    H4 cháu\n  H3 khác');
    });

    it('copies lone H4', () => {
        assert.equal(
            formatOutlineSubtreePlainText({ level: 4, heading_text: 'Chỉ H4', children: [] }),
            'Chỉ H4',
        );
    });

    it('strips hierarchy number prefixes only', () => {
        assert.equal(stripOutlineNumberPrefix('2. Mở đầu'), 'Mở đầu');
        assert.equal(stripOutlineNumberPrefix('2.1. H3 con'), 'H3 con');
        assert.equal(stripOutlineNumberPrefix('2.1.3. Chi tiết 🌟'), 'Chi tiết 🌟');
        assert.equal(stripOutlineNumberPrefix('2024 Xu hướng'), '2024 Xu hướng');
        assert.equal(stripOutlineNumberPrefix('3.5 triệu sản phẩm'), '3.5 triệu sản phẩm');
        assert.equal(stripOutlineNumberPrefix('Không số'), 'Không số');
    });

    it('copy output drops numbering but keeps indent and Vietnamese/emoji', () => {
        const text = formatOutlineSubtreePlainText({
            level: 2,
            heading_text: '2. Tổng quan 🌟',
            children: [
                {
                    level: 3,
                    heading_text: '2.1. Chi tiết tiếng Việt',
                    children: [{ level: 4, heading_text: '2.1.3. Mục nhỏ', children: [] }],
                },
            ],
        });
        assert.equal(text, 'Tổng quan 🌟\n  Chi tiết tiếng Việt\n    Mục nhỏ');
    });
});
