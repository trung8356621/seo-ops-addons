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

export function readFilterQuery(search = window.location.search) {
    const params = new URLSearchParams(search);
    const tagsRaw = params.get('tags') || '';
    const selectedTagIds = tagsRaw
        .split(',')
        .map((v) => Number(v.trim()))
        .filter((n) => Number.isFinite(n) && n > 0);
    const showUntagged = params.get('untagged') === '1';
    const tagFilterAll = selectedTagIds.length === 0 && !showUntagged;
    const view = String(params.get('view') || 'tree').toLowerCase();
    const renderer = ['tree', 'network', 'sunburst'].includes(view) ? view : 'tree';
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

    // Preserve site=
    const qs = params.toString();
    const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash || ''}`;
    window.history.replaceState(null, '', next);
}
