import { createApi } from '../api/client';

/**
 * Category filter defaults — match KeywordRelationshipGraphPresenter.
 */
export const DEFAULT_REL_FILTERS = {
    topic: true,
    article: true,
    dna: true,
    related_keyword: true,
    gsc: false,
    internal_link: false,
    planning: false,
};

/** Map UI filter key → available_sections entry. */
export const SECTION_KEYS = {
    topic: 'topics',
    article: 'focus_articles',
    dna: 'dna',
    related_keyword: 'related_keywords',
    gsc: 'gsc',
    internal_link: 'internal_links',
    planning: 'planning',
};

export const FILTER_ORDER = [
    'topic',
    'article',
    'dna',
    'related_keyword',
    'gsc',
    'internal_link',
    'planning',
];

export function readRelQuery() {
    try {
        const params = new URLSearchParams(window.location.search);
        const raw = params.get('rel');
        if (!raw) {
            return { ...DEFAULT_REL_FILTERS };
        }
        const enabled = new Set(
            raw
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
        );
        const next = { ...DEFAULT_REL_FILTERS };
        Object.keys(next).forEach((key) => {
            next[key] = enabled.has(key);
        });
        return next;
    } catch {
        return { ...DEFAULT_REL_FILTERS };
    }
}

export function writeRelQuery(filters) {
    try {
        const url = new URL(window.location.href);
        const on = FILTER_ORDER.filter((k) => filters[k]);
        const defaultsOn = FILTER_ORDER.filter((k) => DEFAULT_REL_FILTERS[k]);
        const same =
            on.length === defaultsOn.length && on.every((k, i) => k === defaultsOn[i]);
        if (same) {
            url.searchParams.delete('rel');
        } else {
            url.searchParams.set('rel', on.join(','));
        }
        window.history.replaceState({}, '', url.toString());
    } catch {
        // ignore
    }
}

export function isSectionAvailable(availableSections, filterKey) {
    const section = SECTION_KEYS[filterKey];
    if (!section) {
        return true;
    }
    // Core structural sections always considered available for toggle UX;
    // GSC / links / planning need explicit availability.
    if (filterKey === 'gsc' || filterKey === 'internal_link' || filterKey === 'planning') {
        return Array.isArray(availableSections) && availableSections.includes(section);
    }
    return true;
}

/**
 * Client-side graph filter from presenter payload (all categories present).
 */
export function filterGraphByCategories(graph, filters) {
    if (!graph || typeof graph !== 'object') {
        return { nodes: [], edges: [], categories: [], side_panels: {}, center: null };
    }

    const allowedKinds = new Set(['keyword']);
    if (filters.topic) {
        allowedKinds.add('topic');
    }
    if (filters.article) {
        allowedKinds.add('article');
    }
    if (filters.dna) {
        allowedKinds.add('dna');
    }
    if (filters.related_keyword) {
        allowedKinds.add('related_keyword');
    }
    if (filters.gsc) {
        allowedKinds.add('gsc');
    }
    if (filters.planning) {
        allowedKinds.add('planning');
    }
    if (filters.internal_link) {
        allowedKinds.add('internal_link');
        // Presenter may attach Focus Article solely for link neighborhood.
        allowedKinds.add('article');
    }

    const nodes = (Array.isArray(graph.nodes) ? graph.nodes : []).filter((n) =>
        allowedKinds.has(String(n.kind || '')),
    );
    const ids = new Set(nodes.map((n) => n.id));
    const edges = (Array.isArray(graph.edges) ? graph.edges : []).filter(
        (e) => ids.has(e.source) && ids.has(e.target),
    );

    const catNames = new Set();
    nodes.forEach((n) => {
        const cats = Array.isArray(graph.categories) ? graph.categories : [];
        const cat = cats[n.category];
        if (cat?.name) {
            catNames.add(cat.name);
        }
    });
    const categories = Array.from(catNames).map((name) => ({ name }));

    // Remap category indices after filter.
    const catIndex = {};
    categories.forEach((c, i) => {
        catIndex[c.name] = i;
    });
    const remappedNodes = nodes.map((n) => {
        const cats = Array.isArray(graph.categories) ? graph.categories : [];
        const name = cats[n.category]?.name;
        return {
            ...n,
            category: name && catIndex[name] !== undefined ? catIndex[name] : 0,
        };
    });

    const sidePanels = {};
    const allPanels = graph.side_panels || {};
    remappedNodes.forEach((n) => {
        if (allPanels[n.id]) {
            sidePanels[n.id] = allPanels[n.id];
        }
    });

    return {
        ...graph,
        nodes: remappedNodes,
        edges,
        categories,
        side_panels: sidePanels,
        filters: { ...filters },
    };
}

export function createRelationshipApi(config) {
    const base = createApi(config);
    const endpoints = config.endpoints || {};
    const siteId = Number(config.siteId || 0);
    const csrfToken = config.csrfToken || '';
    const keywordId = Number(config.keywordId || 0);

    return {
        ...base,
        async fetchRelationship(id = keywordId) {
            const path = String(endpoints.relationship || '').replace('{keyword}', String(id));
            const u = new URL(path, window.location.origin);
            u.searchParams.set('site_id', String(siteId));
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken;
                headers['X-XSRF-TOKEN'] = csrfToken;
            }
            const response = await fetch(u.toString(), {
                credentials: 'same-origin',
                headers,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = data?.message || `HTTP ${response.status}`;
                throw new Error(message);
            }
            return data;
        },
    };
}

export function relationshipUrl(template, keywordId) {
    return String(template || '').replace('{keyword}', String(keywordId));
}

export function articleEditUrl(template, articleId) {
    return String(template || '').replace('{article}', String(articleId));
}

export function issueLabel(code, labels = {}) {
    if (code === 'focus_article_missing') {
        return labels.issueFocusMissing || 'Missing Focus Article';
    }
    return String(code || '').replace(/_/g, ' ');
}
