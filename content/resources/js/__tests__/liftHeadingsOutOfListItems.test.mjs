import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { liftHeadingsOutOfListItems } from '../utils/listItemHeadingSanitize.js';

describe('liftHeadingsOutOfListItems', () => {
    it('lifts heading+paragraph out of listItem before TipTap hydrate', () => {
        const input = '<ul><li><h3><strong>Herschel Supply Co</strong></h3><p>mô tả brand</p></li></ul>';
        const out = liftHeadingsOutOfListItems(input);
        assert.match(out, /^<h3><strong>Herschel Supply Co<\/strong><\/h3>/);
        assert.match(out, /<ul><li><p>mô tả brand<\/p><\/li><\/ul>/);
        assert.equal(out.includes('<li><h3>'), false);
    });

    it('leaves normal list items unchanged', () => {
        const input = '<ul><li><p>Herschel Supply Co</p></li></ul>';
        assert.equal(liftHeadingsOutOfListItems(input), input);
    });
});
