import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    ALL_KEY,
    buildUrlWithDomain,
    isAllDomains,
    isDomainNeutralPanelPath,
    normalizeDomainKey,
    readDomainFromUrl,
    resolveDomainContext,
    resolveDomainKeyFromSiteId,
    sanitizeDomainKey,
} from '../domainContextStore.js';

describe('normalizeDomainKey', () => {
    it('maps empty and legacy sentinels to all', () => {
        assert.equal(normalizeDomainKey(''), ALL_KEY);
        assert.equal(normalizeDomainKey('0'), ALL_KEY);
        assert.equal(normalizeDomainKey(-1), ALL_KEY);
        assert.equal(normalizeDomainKey(null), ALL_KEY);
        assert.equal(normalizeDomainKey('ALL'), ALL_KEY);
    });

    it('lowercases hostnames', () => {
        assert.equal(normalizeDomainKey('BaloQuatang.net'), 'baloquatang.net');
    });
});

describe('resolveDomainContext priority', () => {
    const accessible = ['baloquatang.net', 'congtybalo.com', 'maybalotuixachgiare.com'];

    it('A. default → first accessible domain', () => {
        assert.equal(resolveDomainContext({ accessible }), 'baloquatang.net');
        assert.equal(isAllDomains(ALL_KEY), true);
    });

    it('B. last-used localStorage when no URL/tab', () => {
        assert.equal(resolveDomainContext({
            lastDomain: 'baloquatang.net',
            accessible,
        }), 'baloquatang.net');
    });

    it('C. tab sessionStorage wins over last-used', () => {
        assert.equal(resolveDomainContext({
            sessionDomain: 'congtybalo.com',
            lastDomain: 'baloquatang.net',
            accessible,
        }), 'congtybalo.com');
    });

    it('D. URL wins over tab and last-used', () => {
        assert.equal(resolveDomainContext({
            urlDomain: 'maybalotuixachgiare.com',
            sessionDomain: 'congtybalo.com',
            lastDomain: 'baloquatang.net',
            accessible,
        }), 'maybalotuixachgiare.com');
    });

    it('E. tab stores are independent inputs (no cross-tab sync)', () => {
        const tabA = resolveDomainContext({ sessionDomain: 'baloquatang.net', lastDomain: 'congtybalo.com', accessible });
        const tabB = resolveDomainContext({ sessionDomain: 'congtybalo.com', lastDomain: 'congtybalo.com', accessible });
        assert.equal(tabA, 'baloquatang.net');
        assert.equal(tabB, 'congtybalo.com');
    });

    it('stale / unauthorized stored key falls back to first accessible', () => {
        assert.equal(sanitizeDomainKey('gone.test', accessible), ALL_KEY);
        assert.equal(resolveDomainContext({
            sessionDomain: 'gone.test',
            lastDomain: 'gone.test',
            accessible,
        }), 'baloquatang.net');
    });

    it('explicit URL all is honored', () => {
        assert.equal(resolveDomainContext({
            urlDomain: 'all',
            sessionDomain: 'congtybalo.com',
            accessible,
        }), ALL_KEY);
    });
});

describe('site_id URL ↔ domain key', () => {
    const accessible = ['baloquatang.net', 'mayhopphat.com'];
    const previous = globalThis.window;

    beforeEach(() => {
        globalThis.window = {
            __SEO_SITE_IDS_BY_DOMAIN__: {
                'baloquatang.net': 3,
                'mayhopphat.com': 6,
            },
        };
    });

    afterEach(() => {
        if (previous === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = previous;
        }
    });

    it('maps site_id to domain key', () => {
        assert.equal(resolveDomainKeyFromSiteId(6), 'mayhopphat.com');
        assert.equal(resolveDomainKeyFromSiteId(3), 'baloquatang.net');
        assert.equal(resolveDomainKeyFromSiteId(99), null);
    });

    it('readDomainFromUrl resolves ?site_id= to hostname', () => {
        assert.equal(
            readDomainFromUrl('/seo/keywords/clusters?site_id=6'),
            'mayhopphat.com',
        );
    });

    it('sanitizeDomainKey accepts numeric site_id against hostname allow-list', () => {
        assert.equal(sanitizeDomainKey('6', accessible), 'mayhopphat.com');
        assert.equal(sanitizeDomainKey(6, accessible), 'mayhopphat.com');
    });

    it('URL site_id wins over stale session/last storage (F5)', () => {
        assert.equal(resolveDomainContext({
            urlDomain: readDomainFromUrl('/seo/keywords/clusters?site_id=6'),
            sessionDomain: 'baloquatang.net',
            lastDomain: 'baloquatang.net',
            accessible,
        }), 'mayhopphat.com');
    });

    it('buildUrlWithDomain writes site_id for known hostnames', () => {
        assert.equal(
            buildUrlWithDomain('/seo/keywords/clusters', 'mayhopphat.com'),
            '/seo/keywords/clusters?site_id=6',
        );
    });
});

describe('URL representation', () => {
    it('reads leftover ?domain= but does not write it onto Projects URLs', () => {
        assert.equal(readDomainFromUrl('/seo/abc/content-projects?domain=baloquatang.net'), 'baloquatang.net');
        assert.equal(
            buildUrlWithDomain('/seo/abc/content-projects?month=2026-07', 'baloquatang.net'),
            '/seo/abc/content-projects?month=2026-07',
        );
        const allUrl = new URL(
            buildUrlWithDomain('/seo/abc/content-projects?domain=congtybalo.com&month=2026-07', 'all'),
            'http://local.test',
        );
        assert.equal(allUrl.searchParams.get('domain'), null);
        assert.equal(allUrl.searchParams.get('site_id'), null);
        assert.equal(allUrl.searchParams.get('month'), '2026-07');
    });

    it('treats Projects and publishing-queue as domain-neutral paths', () => {
        assert.equal(isDomainNeutralPanelPath('/seo/abc/content-projects'), true);
        assert.equal(isDomainNeutralPanelPath('/seo/abc/content-projects/12/edit'), true);
        assert.equal(isDomainNeutralPanelPath('/seo/abc/publishing-queue'), true);
        assert.equal(isDomainNeutralPanelPath('/seo/abc/articles'), false);
        assert.equal(isDomainNeutralPanelPath('/seo/abc/keywords'), false);
    });
});
