import assert from 'node:assert/strict';
import { test, describe } from 'node:test';
import settingsTagsInputFormComponent, { normalizeTag } from '../components/settingsTagsInput.js';

describe('SettingsTagsInput frontend normalizer', () => {
    test('trusted domain tag normalization', () => {
        // http://seo-ops.test/ -> seo-ops.test
        assert.equal(normalizeTag('http://seo-ops.test/', 'trusted_domain'), 'seo-ops.test');

        // HTTPS://WWW.Example.COM/a -> example.com
        assert.equal(normalizeTag('HTTPS://WWW.Example.COM/a', 'trusted_domain'), 'example.com');

        // *.GOV -> *.gov
        assert.equal(normalizeTag('*.GOV', 'trusted_domain'), '*.gov');

        // *.Example.COM -> *.example.com
        assert.equal(normalizeTag('*.Example.COM', 'trusted_domain'), '*.example.com');

        // //sub.example.com/page -> sub.example.com
        assert.equal(normalizeTag('//sub.example.com/page', 'trusted_domain'), 'sub.example.com');

        // Invalid trusted domains rejected
        assert.equal(normalizeTag('http://', 'trusted_domain'), null);
        assert.equal(normalizeTag('foo bar.com', 'trusted_domain'), null);
        assert.equal(normalizeTag('javascript:alert(1)', 'trusted_domain'), null);
        assert.equal(normalizeTag('mailto:test@example.com', 'trusted_domain'), null);
        assert.equal(normalizeTag('tel:123', 'trusted_domain'), null);
        assert.equal(normalizeTag('@', 'trusted_domain'), null);
        assert.equal(normalizeTag('://bad', 'trusted_domain'), null);
        assert.equal(normalizeTag('*.', 'trusted_domain'), null);
        assert.equal(normalizeTag('*.*', 'trusted_domain'), null);
        assert.equal(normalizeTag('*gov', 'trusted_domain'), null);
        assert.equal(normalizeTag('foo.*', 'trusted_domain'), null);
        assert.equal(normalizeTag(null, 'trusted_domain'), null);
    });

    test('strict domain tag normalization', () => {
        // Normal URLs
        assert.equal(normalizeTag('https://www.facebook.com/foo', 'strict_domain'), 'facebook.com');
        assert.equal(normalizeTag('HTTPS://WWW.Facebook.COM/x', 'strict_domain'), 'facebook.com');
        assert.equal(normalizeTag('https://mastodon.social/@user', 'strict_domain'), 'mastodon.social');

        // Wildcards rejected in strict mode
        assert.equal(normalizeTag('*.facebook.com', 'strict_domain'), null);
        assert.equal(normalizeTag('*.gov', 'strict_domain'), null);
        assert.equal(normalizeTag('foo bar.com', 'strict_domain'), null);
    });

    test('extension tag normalization', () => {
        assert.equal(normalizeTag('.JPG', 'extension'), 'jpg');
        assert.equal(normalizeTag(' PDF', 'extension'), 'pdf');
        assert.equal(normalizeTag('.png', 'extension'), 'png');

        // Invalid extensions rejected
        assert.equal(normalizeTag('invalid.ext', 'extension'), null);
        assert.equal(normalizeTag('bad*ext', 'extension'), null);
        assert.equal(normalizeTag('toolongextensionname', 'extension'), null);
        assert.equal(normalizeTag('..', 'extension'), null);
    });

    test('phrase tag normalization', () => {
        assert.equal(normalizeTag(' FAQ Questions ', 'phrase'), 'faq questions');
        assert.equal(normalizeTag('Frequently Asked Questions', 'phrase'), 'frequently asked questions');
        assert.equal(normalizeTag('   ', 'phrase'), null);
    });
});

describe('SettingsTagsInput Alpine Component', () => {
    test('createTag() immediately normalizes raw input before pushing to state', () => {
        const state = [];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'trusted_domain',
        });

        component.newTag = 'http://seo-ops.test/';
        component.createTag();

        // Visible state immediately contains canonical tag, NOT raw URL
        assert.deepEqual(component.state, ['seo-ops.test']);
        assert.equal(component.newTag, '');
    });

    test('createTag() rejects invalid values without pushing to state', () => {
        const state = ['seo-ops.test'];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'trusted_domain',
        });

        component.newTag = 'foo bar.com';
        component.createTag();

        // State unchanged, invalid tag rejected
        assert.deepEqual(component.state, ['seo-ops.test']);
        assert.equal(component.newTag, '');

        component.newTag = 'javascript:alert(1)';
        component.createTag();
        assert.deepEqual(component.state, ['seo-ops.test']);
        assert.equal(component.newTag, '');
    });

    test('createTag() dedupes on canonical value', () => {
        const state = [];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'trusted_domain',
        });

        component.newTag = 'example.com';
        component.createTag();
        assert.deepEqual(component.state, ['example.com']);

        // Variant that normalizes to example.com
        component.newTag = 'https://www.example.com/path';
        component.createTag();
        assert.deepEqual(component.state, ['example.com'], 'Duplicate canonical tag must not be pushed');

        // Uppercase variant
        component.newTag = 'EXAMPLE.COM';
        component.createTag();
        assert.deepEqual(component.state, ['example.com'], 'Case variant must not produce duplicate tag');
    });

    test('extension createTag() and canonical dedupe', () => {
        const state = [];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'extension',
        });

        component.newTag = '.JPG';
        component.createTag();
        assert.deepEqual(component.state, ['jpg']);

        component.newTag = 'jpg';
        component.createTag();
        assert.deepEqual(component.state, ['jpg']);

        component.newTag = 'JPG';
        component.createTag();
        assert.deepEqual(component.state, ['jpg']);

        component.newTag = 'invalid.ext';
        component.createTag();
        assert.deepEqual(component.state, ['jpg']);
    });

    test('phrase createTag() preserves multi-word semantics and dedupes', () => {
        const state = [];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'phrase',
        });

        component.newTag = ' FAQ Questions ';
        component.createTag();
        assert.deepEqual(component.state, ['faq questions']);

        component.newTag = 'faq questions';
        component.createTag();
        assert.deepEqual(component.state, ['faq questions']);

        component.newTag = 'FAQ QUESTIONS';
        component.createTag();
        assert.deepEqual(component.state, ['faq questions']);
    });

    test('paste handler splits and normalizes each tag', async () => {
        const state = [];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [','],
            normalizerType: 'trusted_domain',
        });

        component.$nextTick = (cb) => cb();

        // Simulate pasting multiple raw items
        component.newTag = 'http://seo-ops.test/, HTTPS://WWW.Example.COM/path, *.GOV, foo bar.com';
        component.input['x-on:paste'].call(component);

        assert.deepEqual(component.state, ['seo-ops.test', 'example.com', '*.gov']);
    });

    test('init() canonicalizes and dedupes existing state', () => {
        const state = [
            'http://seo-ops.test/',
            'seo-ops.test',
            'HTTPS://WWW.Example.COM/path',
            'example.com',
            '*.GOV',
            'invalid tag',
        ];
        const component = settingsTagsInputFormComponent({
            state,
            splitKeys: [],
            normalizerType: 'trusted_domain',
        });

        component.init();

        assert.deepEqual(component.state, ['seo-ops.test', 'example.com', '*.gov']);
    });
});
