import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    findSentenceEndOffsets,
    groupSentenceEnds,
    isSafeSentenceBoundary,
} from '../utils/editorCommands/sentenceParagraphSplit.js';

describe('sentenceParagraphSplit boundaries', () => {
    it('splits plain Vietnamese sentences', () => {
        const text = 'Câu một. Câu hai! Câu ba? Câu bốn.';
        const ends = findSentenceEndOffsets(text);
        assert.equal(ends.length, 4);
        assert.deepEqual(groupSentenceEnds(ends, 2), [ends[1], ends[3]]);
    });

    it('does not split decimals', () => {
        const text = 'Giá khoảng 3.5 triệu đồng. Mua ngay.';
        assert.equal(isSafeSentenceBoundary(text, text.indexOf('.')), false);
        const ends = findSentenceEndOffsets(text);
        assert.equal(ends.length, 2);
    });

    it('does not split emails or urls', () => {
        const email = 'Liên hệ a.b@example.com ngay. Cảm ơn.';
        const domainDot = email.indexOf('.', email.indexOf('@'));
        assert.equal(email[domainDot], '.');
        assert.equal(isSafeSentenceBoundary(email, domainDot), false);
        const url = 'Xem https://example.com/path nhé. Xong.';
        const hostDot = url.indexOf('example.com') + 'example'.length;
        assert.equal(url[hostDot], '.');
        assert.equal(isSafeSentenceBoundary(url, hostDot), false);
    });

    it('keeps 1–3 sentences as a single chunk when under target', () => {
        const text = 'Một. Hai. Ba.';
        const ends = findSentenceEndOffsets(text);
        assert.equal(ends.length, 3);
        assert.deepEqual(groupSentenceEnds(ends, 2), [ends[2]]);
    });

    it('second pass on already-short chunks does not invent splits', () => {
        const text = 'Chỉ một câu thôi.';
        assert.deepEqual(findSentenceEndOffsets(text), [text.replace(/\s+$/u, '').length]);
        assert.equal(groupSentenceEnds(findSentenceEndOffsets(text), 2).length, 1);
    });
});
