function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

export function topicLabel(topic) {
    const mcp = Number(topic.mcp ?? 0);
    const dna = Number(topic.dna_count ?? 0);
    const articles = Number(topic.article_count ?? 0);
    return `${topic.name}\nMCP ${mcp.toFixed(0)}% · DNA ${dna} · Art ${articles}`;
}

export function buildTreeOption(data, childrenCache) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const siteId = Number(data?.site_id ?? 0);
    const children = topics.map((topic) => {
        const cached = childrenCache.get(Number(topic.id));
        const node = {
            name: topicLabel(topic),
            topicId: Number(topic.id),
            nodeType: 'topic',
            value: Math.max(1, Number(topic.article_count ?? 0) || Number(topic.keyword_count ?? 0) || 1),
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
            collapsed: !cached,
        };
        if (cached?.children?.length) {
            node.children = cached.children.map((child) => ({
                name: child.name,
                keywordId: Number(child.id),
                nodeType: 'keyword',
                value: 1,
                article_count: child.article_count ?? 0,
            }));
        } else if (topic.has_children) {
            node.children = [];
        }
        return node;
    });

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'keyword') {
                    return `<strong>${escapeHtml(d.name || '')}</strong><br/>Keyword`
                        + (d.article_count ? `<br/>Articles: ${d.article_count}` : '');
                }
                if (d.nodeType === 'topic') {
                    const tagNames = Array.isArray(d.tags)
                        ? d.tags.map((t) => t.name).filter(Boolean).join(', ')
                        : '';
                    return [
                        `<strong>${escapeHtml(String(d.name || '').split('\n')[0] || '')}</strong>`,
                        `MCP: ${Number(d.mcp ?? 0).toFixed(1)}%`,
                        `DNA: ${d.dna_count ?? 0}`,
                        `Articles: ${d.article_count ?? 0}`,
                        `Keywords: ${d.keyword_count ?? 0}`,
                        d.coverage ? `Coverage: ${escapeHtml(String(d.coverage))}` : '',
                        tagNames ? `Tags: ${escapeHtml(tagNames)}` : '',
                        '<em>Double-click to open Topic</em>',
                    ].filter(Boolean).join('<br/>');
                }
                return escapeHtml(params?.name || 'Site');
            },
        },
        series: [
            {
                type: 'tree',
                id: 'topical-map-tree',
                name: 'Topical Map',
                data: [
                    {
                        name: `Site #${siteId || ''}`,
                        nodeType: 'site',
                        children,
                    },
                ],
                top: '4%',
                left: '8%',
                bottom: '4%',
                right: '18%',
                symbolSize: 10,
                orient: 'LR',
                expandAndCollapse: true,
                initialTreeDepth: 1,
                label: {
                    position: 'left',
                    verticalAlign: 'middle',
                    align: 'right',
                    fontSize: 11,
                    lineHeight: 14,
                },
                leaves: {
                    label: {
                        position: 'right',
                        verticalAlign: 'middle',
                        align: 'left',
                    },
                },
                emphasis: { focus: 'descendant' },
                animationDuration: 350,
                animationDurationUpdate: 450,
                roam: true,
            },
        ],
    };
}

export function buildSunburstOption(data, childrenCache) {
    const topics = Array.isArray(data?.topics) ? data.topics : [];
    const children = topics.map((topic) => {
        const cached = childrenCache.get(Number(topic.id));
        const value = Math.max(1, Number(topic.article_count ?? 0) || Number(topic.keyword_count ?? 0) || 1);
        const node = {
            name: topic.name,
            value,
            topicId: Number(topic.id),
            nodeType: 'topic',
            mcp: topic.mcp,
            dna_count: topic.dna_count,
            article_count: topic.article_count,
            keyword_count: topic.keyword_count,
            coverage: topic.coverage,
            tags: topic.tags,
        };
        if (cached?.children?.length) {
            node.children = cached.children.map((child) => ({
                name: child.name,
                value: 1,
                keywordId: Number(child.id),
                nodeType: 'keyword',
                article_count: child.article_count ?? 0,
            }));
        }
        return node;
    });

    return {
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            formatter(params) {
                const d = params?.data || {};
                if (d.nodeType === 'topic') {
                    return [
                        `<strong>${escapeHtml(d.name || '')}</strong>`,
                        `Articles: ${d.article_count ?? d.value ?? 0}`,
                        `Keywords: ${d.keyword_count ?? 0}`,
                        `MCP: ${Number(d.mcp ?? 0).toFixed(1)}%`,
                        '<em>Double-click to open Topic</em>',
                    ].join('<br/>');
                }
                return `<strong>${escapeHtml(params?.name || '')}</strong>`;
            },
        },
        series: [
            {
                type: 'sunburst',
                id: 'topical-map-sunburst',
                radius: [0, '92%'],
                sort: undefined,
                emphasis: { focus: 'ancestor' },
                data: children,
                label: { rotate: 'radial', minAngle: 4, fontSize: 10 },
                levels: [
                    {},
                    { r0: '15%', r: '55%', label: { rotate: 'tangential' } },
                    { r0: '55%', r: '92%', label: { position: 'outside', padding: 2 } },
                ],
            },
        ],
    };
}

export function buildNetworkOption(neighborhood) {
    const nodes = Array.isArray(neighborhood?.nodes) ? neighborhood.nodes : [];
    const links = Array.isArray(neighborhood?.links) ? neighborhood.links : [];
    const categories = [
        { name: 'site' },
        { name: 'topic' },
        { name: 'keyword' },
    ];
    const categoryIndex = { site: 0, topic: 1, keyword: 2 };

    return {
        backgroundColor: 'transparent',
        legend: [{ data: categories.map((c) => c.name) }],
        tooltip: {
            formatter(params) {
                if (params.dataType === 'edge') {
                    return 'Topic membership';
                }
                const d = params.data || {};
                return `<strong>${escapeHtml(d.name || '')}</strong><br/>${escapeHtml(d.category || '')}`
                    + (d.nodeType === 'topic' ? '<br/><em>Double-click to open Topic</em>' : '');
            },
        },
        series: [
            {
                type: 'graph',
                id: 'topical-map-network',
                layout: 'force',
                roam: true,
                draggable: false,
                categories,
                data: nodes.map((n) => ({
                    id: n.id,
                    name: n.name,
                    category: categoryIndex[n.category] ?? 1,
                    value: n.value ?? 1,
                    symbolSize: n.category === 'site' ? 28 : (n.category === 'topic' ? 18 : 10),
                    nodeType: n.category,
                    topicId: n.category === 'topic' ? Number(String(n.id).replace(/^topic:/, '')) : undefined,
                    keywordId: n.category === 'keyword' ? Number(String(n.id).replace(/^keyword:/, '')) : undefined,
                })),
                links: links.map((l) => ({
                    source: l.source,
                    target: l.target,
                })),
                label: {
                    show: true,
                    position: 'right',
                    formatter: '{b}',
                    fontSize: 10,
                },
                force: {
                    repulsion: 120,
                    edgeLength: [40, 120],
                    gravity: 0.08,
                },
                lineStyle: { color: 'source', curveness: 0.05, opacity: 0.55 },
                emphasis: { focus: 'adjacency' },
            },
        ],
    };
}
