import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildSiteNetworkGraph,
    directionalPair,
    siteNetworkEmptyReason,
    sourceTopicsFromPair,
} from '../charts/siteNetworkGraph.js';

test('A to B and B to A stay independent edges', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 2,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'a.test' },
            { site_ref: 'site:2', site_id: 2, domain: 'b.test' },
        ],
        edges: [
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:2',
                article_link_count: 3,
                source_article_count: 2,
                target_article_count: 0,
                source_keyword_count: 2,
            },
            {
                source_site_ref: 'site:2',
                target_site_ref: 'site:1',
                article_link_count: 1,
                source_article_count: 1,
                target_article_count: 1,
                source_keyword_count: 1,
            },
        ],
    });

    assert.equal(graph.links.length, 2);
    assert.deepEqual(
        directionalPair({
            source_site_ref: graph.links[0].sourceSiteRef,
            target_site_ref: graph.links[0].targetSiteRef,
        }),
        { sourceSiteId: 1, targetSiteId: 2 },
    );
    assert.deepEqual(
        directionalPair({
            source_site_ref: graph.links[1].sourceSiteRef,
            target_site_ref: graph.links[1].targetSiteRef,
        }),
        { sourceSiteId: 2, targetSiteId: 1 },
    );
    assert.equal(graph.links[0].article_link_count, 3);
    assert.equal(graph.links[0].target_article_count, 0);
    assert.notEqual(graph.links[0].lineStyle.curveness, graph.links[1].lineStyle.curveness);
});

test('unresolved target article still contributes via article_link_count', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 2,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'a.test' },
            { site_ref: 'site:2', site_id: 2, domain: 'b.test' },
        ],
        edges: [
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:2',
                article_link_count: 4,
                source_article_count: 2,
                target_article_count: 0,
                source_keyword_count: 3,
            },
        ],
    });

    assert.equal(graph.links.length, 1);
    assert.equal(graph.links[0].article_link_count, 4);
    assert.equal(graph.links[0].target_article_count, 0);
    assert.ok(graph.links[0].lineStyle.width > 1);
});

test('pair identity is directional source then target', () => {
    const pair = directionalPair({
        source_site_ref: 'site:8',
        target_site_ref: 'site:3',
    });
    assert.deepEqual(pair, { sourceSiteId: 8, targetSiteId: 3 });
});

test('topic drill uses the source topic list only', () => {
    const topics = sourceTopicsFromPair({
        source_site_ref: 'site:1',
        target_site_ref: 'site:2',
        topics: [
            { topic_id: 11, topic_ref: 'topic:11', name: 'Alpha', cross_site_link_count: 2 },
        ],
    });
    assert.equal(topics.length, 1);
    assert.equal(topics[0].name, 'Alpha');
    assert.equal(Object.hasOwn(topics[0], 'target_topic_id'), false);
});

test('unmanaged domains never become site nodes', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 1,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'a.test' },
        ],
        edges: [
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:99',
                article_link_count: 2,
                source_article_count: 1,
                target_article_count: 0,
                source_keyword_count: 1,
            },
        ],
    });

    assert.deepEqual(graph.nodes.map((node) => node.name), ['a.test']);
    assert.equal(graph.links.length, 0);
    assert.equal(graph.nodes.some((node) => String(node.name).includes('wikipedia')), false);
});

test('zero accessible sites is an empty state', () => {
    assert.equal(siteNetworkEmptyReason({
        sites: [],
        edges: [],
        accessible_site_count: 0,
    }), 'no_accessible_sites');
    assert.equal(siteNetworkEmptyReason({
        sites: [],
        edges: [],
        accessible_site_count: 1,
    }), 'single_site');
    assert.equal(siteNetworkEmptyReason({
        sites: [],
        edges: [],
        accessible_site_count: 3,
    }), 'no_relationships');
});
