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

test('isolated managed sites are included with no fake edges', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 5,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'a.test', is_main: true },
            { site_ref: 'site:2', site_id: 2, domain: 'b.test', is_main: false },
            { site_ref: 'site:3', site_id: 3, domain: 'c.test', is_main: false },
            { site_ref: 'site:4', site_id: 4, domain: 'd.test', is_main: false },
            { site_ref: 'site:5', site_id: 5, domain: 'e.test', is_main: false },
        ],
        edges: [
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:2',
                article_link_count: 5,
                source_article_count: 3,
                target_article_count: 2,
                source_keyword_count: 3,
            },
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:3',
                article_link_count: 2,
                source_article_count: 1,
                target_article_count: 1,
                source_keyword_count: 1,
            },
        ],
    });

    // All 5 sites must appear as nodes
    assert.equal(graph.nodes.length, 5);
    const nodeNames = graph.nodes.map((n) => n.name);
    assert.ok(nodeNames.includes('d.test'));
    assert.ok(nodeNames.includes('e.test'));

    // Only 2 real edges — absolutely no fake edges for d.test or e.test
    assert.equal(graph.links.length, 2);
    assert.ok(!graph.links.some((l) => l.sourceSiteRef === 'site:4' || l.targetSiteRef === 'site:4'));
    assert.ok(!graph.links.some((l) => l.sourceSiteRef === 'site:5' || l.targetSiteRef === 'site:5'));

    // Check isolation flags
    const dNode = graph.nodes.find((n) => n.id === 'site:4');
    const aNode = graph.nodes.find((n) => n.id === 'site:1');
    assert.equal(dNode.isIsolated, true);
    assert.equal(aNode.isIsolated, false);
});

test('main node receives main data and distinct size, and selection does not dictate main', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 3,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'main-site.test', is_main: true },
            { site_ref: 'site:2', site_id: 2, domain: 'other-site.test', is_main: false },
        ],
        edges: [],
    });

    const mainNode = graph.nodes.find((n) => n.id === 'site:1');
    const otherNode = graph.nodes.find((n) => n.id === 'site:2');

    assert.equal(mainNode.isMain, true);
    assert.equal(mainNode.is_main, true);
    assert.equal(otherNode.isMain, false);

    // Main node has larger symbolSize
    assert.ok(mainNode.symbolSize > otherNode.symbolSize);
});

test('connected and isolated nodes layout separately to remain readable', () => {
    const graph = buildSiteNetworkGraph({
        accessible_site_count: 3,
        sites: [
            { site_ref: 'site:1', site_id: 1, domain: 'connected-1.test' },
            { site_ref: 'site:2', site_id: 2, domain: 'connected-2.test' },
            { site_ref: 'site:3', site_id: 3, domain: 'isolated.test' },
        ],
        edges: [
            {
                source_site_ref: 'site:1',
                target_site_ref: 'site:2',
                article_link_count: 1,
                source_article_count: 1,
                target_article_count: 1,
                source_keyword_count: 1,
            },
        ],
    });

    const conn1 = graph.nodes.find((n) => n.id === 'site:1');
    const iso = graph.nodes.find((n) => n.id === 'site:3');

    // Isolated node is placed in lower cluster area (Y position significantly lower than connected cluster)
    assert.ok(iso.y > conn1.y + 50, `Expected iso.y (${iso.y}) > conn1.y (${conn1.y}) + 50`);
    assert.equal(iso.isIsolated, true);
    assert.equal(conn1.isIsolated, false);
});

