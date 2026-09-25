/**
 * Client-side filter helpers for Topical Map.
 * Tags OR + Untagged; MCP range AND; facet counts stay canonical.
 */

export function clampMcp(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.max(0, Math.min(100, n));
}

export function normalizeMcpRange(min, max) {
    let mcpMin = clampMcp(min);
    let mcpMax = clampMcp(max);
    if (mcpMin > mcpMax) {
        const tmp = mcpMin;
        mcpMin = mcpMax;
        mcpMax = tmp;
    }
    return { mcpMin, mcpMax };
}

/**
 * @param {object[]} topics
 * @param {{ selectedTagIds: number[], showUntagged: boolean, tagFilterAll: boolean, mcpMin: number, mcpMax: number }} filters
 */
export function filterTopics(topics, filters) {
    const list = Array.isArray(topics) ? topics : [];
    const { mcpMin, mcpMax } = normalizeMcpRange(filters.mcpMin, filters.mcpMax);

    return list.filter((topic) => {
        const mcp = Number(topic.mcp ?? 0);
        if (mcp < mcpMin || mcp > mcpMax) {
            return false;
        }

        if (filters.tagFilterAll) {
            return true;
        }

        const tags = Array.isArray(topic.tags) ? topic.tags : [];
        if (tags.length === 0) {
            return Boolean(filters.showUntagged);
        }

        const selected = Array.isArray(filters.selectedTagIds) ? filters.selectedTagIds : [];
        if (selected.length === 0) {
            return false;
        }

        return tags.some((tag) => selected.includes(Number(tag.id)));
    });
}

/**
 * Client-side Network prune against allowed Topic ids.
 * Keeps Site; Topics in allow-set; DNA linked to a kept Topic; edges with both ends kept.
 * No orphan DNA nodes.
 *
 * @param {object|null|undefined} neighborhood
 * @param {Iterable<number>|Set<number>} allowedTopicIds
 */
export function pruneNeighborhoodByAllowedTopics(neighborhood, allowedTopicIds) {
    const allowed = allowedTopicIds instanceof Set
        ? allowedTopicIds
        : new Set(Array.from(allowedTopicIds || []).map(Number).filter((n) => n > 0));

    const nodes = Array.isArray(neighborhood?.nodes) ? neighborhood.nodes : [];
    const links = Array.isArray(neighborhood?.links) ? neighborhood.links : [];

    if (nodes.length === 0) {
        return {
            nodes: [],
            links: [],
            truncated: Boolean(neighborhood?.truncated),
            dna_truncated: Boolean(neighborhood?.dna_truncated),
            showing_topics: 0,
            total_topics: Number(neighborhood?.total_topics ?? 0),
            showing_dna: 0,
            total_dna: Number(neighborhood?.total_dna ?? 0),
            max_mcp: Number(neighborhood?.max_mcp ?? 0),
        };
    }

    const keep = new Set();
    for (const node of nodes) {
        const id = String(node.id ?? '');
        const category = String(node.category || node.nodeType || '');
        if (category === 'site' || id.startsWith('site:')) {
            keep.add(id);
            continue;
        }
        if (category === 'topic' || id.startsWith('topic:')) {
            const tid = Number(id.replace(/^topic:/, ''));
            if (allowed.has(tid)) {
                keep.add(id);
            }
        }
    }

    for (const link of links) {
        const source = String(link.source ?? '');
        const target = String(link.target ?? '');
        // Topic → DNA (and legacy keyword: if any residual payload)
        if (keep.has(source) && (target.startsWith('dna:') || target.startsWith('keyword:'))) {
            keep.add(target);
        }
        if (keep.has(target) && (source.startsWith('dna:') || source.startsWith('keyword:'))) {
            keep.add(source);
        }
    }

    const filteredNodes = nodes.filter((n) => keep.has(String(n.id)));
    const filteredLinks = links.filter(
        (l) => keep.has(String(l.source)) && keep.has(String(l.target)),
    );
    const showingTopics = filteredNodes.filter((n) => {
        const id = String(n.id ?? '');
        return String(n.category || '') === 'topic' || id.startsWith('topic:');
    }).length;
    const showingDna = filteredNodes.filter((n) => {
        const id = String(n.id ?? '');
        return String(n.category || '') === 'dna' || id.startsWith('dna:');
    }).length;

    let maxMcp = 0;
    for (const n of filteredNodes) {
        if (String(n.category || '') === 'topic' || String(n.id || '').startsWith('topic:')) {
            maxMcp = Math.max(maxMcp, clampMcp(n.mcp));
        }
    }

    return {
        ...neighborhood,
        nodes: filteredNodes,
        links: filteredLinks,
        showing_topics: showingTopics,
        total_topics: Number(neighborhood?.total_topics ?? showingTopics),
        showing_dna: showingDna,
        total_dna: Number(neighborhood?.total_dna ?? showingDna),
        max_mcp: maxMcp,
    };
}

export function readFilterQuery(search = window.location.search) {
    const params = new URLSearchParams(search);
    const tagsRaw = params.get('tags') || '';
    const selectedTagIds = tagsRaw
        .split(',')
        .map((v) => Number(v.trim()))
        .filter((n) => Number.isFinite(n) && n > 0);
    const showUntagged = params.get('untagged') === '1';
    const tagFilterAll = selectedTagIds.length === 0 && !showUntagged;
    const viewRaw = String(params.get('view') || 'tree').toLowerCase();
    // Legacy bookmarks: sunburst → treemap; structure → tree (route key stays tree).
    let view = viewRaw;
    if (viewRaw === 'sunburst') {
        view = 'treemap';
    } else if (viewRaw === 'structure') {
        view = 'tree';
    }
    const renderer = ['tree', 'network', 'treemap'].includes(view) ? view : 'tree';
    const { mcpMin, mcpMax } = normalizeMcpRange(
        params.has('mcp_min') ? params.get('mcp_min') : 0,
        params.has('mcp_max') ? params.get('mcp_max') : 100,
    );

    return {
        selectedTagIds,
        showUntagged,
        tagFilterAll,
        mcpMin,
        mcpMax,
        renderer,
    };
}

export function writeFilterQuery(filters) {
    const params = new URLSearchParams(window.location.search);
    // Network no longer stores drill/focus state in the URL.
    params.delete('focused_topic');
    params.delete('root');
    params.delete('drill');

    if (filters.tagFilterAll) {
        params.delete('tags');
        params.delete('untagged');
    } else {
        if (filters.selectedTagIds.length) {
            params.set('tags', filters.selectedTagIds.join(','));
        } else {
            params.delete('tags');
        }
        if (filters.showUntagged) {
            params.set('untagged', '1');
        } else {
            params.delete('untagged');
        }
    }

    if (filters.mcpMin === 0) {
        params.delete('mcp_min');
    } else {
        params.set('mcp_min', String(Math.round(filters.mcpMin)));
    }
    if (filters.mcpMax === 100) {
        params.delete('mcp_max');
    } else {
        params.set('mcp_max', String(Math.round(filters.mcpMax)));
    }

    if (filters.renderer === 'tree') {
        params.delete('view');
    } else {
        params.set('view', filters.renderer);
    }

    const qs = params.toString();
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash || ''}`;
    window.history.replaceState(null, '', next);
}
