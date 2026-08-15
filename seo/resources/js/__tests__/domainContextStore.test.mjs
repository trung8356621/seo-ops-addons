import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    ALL_KEY,
    buildUrlWithDomain,
    isAllDomains,
    normalizeDomainKey,
    readDomainFromUrl,
    resolveDomainContext,
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

    it('A. default → all', () => {
        assert.equal(resolveDomainContext({ accessible }), ALL_KEY);
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

    it('stale / unauthorized stored key falls back to all', () => {
        assert.equal(sanitizeDomainKey('gone.test', accessible), ALL_KEY);
        assert.equal(resolveDomainContext({
            sessionDomain: 'gone.test',
            lastDomain: 'gone.test',
            accessible,
        }), ALL_KEY);
    });
});

describe('URL representation', () => {
    it('reads and writes ?domain=', () => {
        assert.equal(readDomainFromUrl('/seo/abc/content-projects?domain=baloquatang.net'), 'baloquatang.net');
        assert.equal(
            buildUrlWithDomain('/seo/abc/content-projects?month=2026-07', 'baloquatang.net'),
            '/seo/abc/content-projects?month=2026-07&domain=baloquatang.net',
        );
        assert.equal(
            buildUrlWithDomain('/seo/abc/content-projects?domain=congtybalo.com&month=2026-07', 'all'),
            '/seo/abc/content-projects?domain=all&month=2026-07',
        );
    });
});
