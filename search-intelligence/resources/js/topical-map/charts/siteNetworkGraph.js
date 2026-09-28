/**
 * Site Network projection helpers.
 * Nodes come only from the managed-site payload. Edges stay directional.
 */

export function parseSiteRef(ref) {
    const match = String(ref || '').match(/^site:(\d+)$/);
    if (!match) {
        return 0;
    }
    const id = Number(match[1]);
    return Number.isFinite(id) && id > 0 ? id : 0;
}

export function directionalPair(edge) {
    return {
        sourceSiteId: parseSiteRef(edge?.source_site_ref),
        targetSiteId: parseSiteRef(edge?.target_site_ref),
    };
}

export function edgeSelectionKey(edge) {
    return `${String(edge?.source_site_ref || '')}>${String(edge?.target_site_ref || '')}`;
}

/**
 * @param {{ sites?: object[], edges?: object[], accessible_site_count?: number }|null} payload
 * @returns {'no_accessible_sites'|'single_site'|'no_relationships'|null}
 */
export function siteNetworkEmptyReason(payload) {
    const sites = Array.isArray(payload?.sites) ? payload.sites : [];
    const edges = Array.isArray(payload?.edges) ? payload.edges : [];
    if (sites.length > 0 && edges.length > 0) {
        return null;
    }
    const accessible = Number(payload?.accessible_site_count ?? 0);
    if (!Number.isFinite(accessible) || accessible <= 0) {
        return 'no_accessible_sites';
    }
    if (accessible === 1) {
        return 'single_site';
    }
    return 'no_relationships';
}

/**
 * Build a graph from managed sites only.
 * An edge whose endpoint is not a managed site is dropped — never becomes a node.
 */
export function buildSiteNetworkGraph(payload) {
    const sites = Array.isArray(payload?.sites) ? payload.sites : [];
    const edges = Array.isArray(payload?.edges) ? payload.edges : [];
    const byRef = new Map();
    sites.forEach((site) => {
        const ref = String(site?.site_ref || '');
        if (!ref.startsWith('site:') || parseSiteRef(ref) <= 0) {
            return;
        }
        byRef.set(ref, site);
    });

    const nodeList = Array.from(byRef.values());
    const count = nodeList.length;
    const radius = count <= 1 ? 0 : 220;
    const nodes = nodeList.map((site, index) => {
        const angle = (Math.PI * 2 * index) / Math.max(count, 1) - Math.PI / 2;
        return {
            id: String(site.site_ref),
            name: String(site.domain || site.site_ref),
            siteId: Number(site.site_id) || parseSiteRef(site.site_ref),
            x: Math.cos(angle) * radius,
            y: Math.sin(angle) * radius,
            symbolSize: 54,
        };
    });

    let maxLinks = 1;
    edges.forEach((edge) => {
        maxLinks = Math.max(maxLinks, Number(edge?.article_link_count) || 0);
    });

    const links = [];
    edges.forEach((edge) => {
        const source = String(edge?.source_site_ref || '');
        const target = String(edge?.target_site_ref || '');
        if (!byRef.has(source) || !byRef.has(target) || source === target) {
            return;
        }
        const sourceId = parseSiteRef(source);
        const targetId = parseSiteRef(target);
        const articleLinkCount = Number(edge.article_link_count) || 0;
        const width = 1.5 + (articleLinkCount / maxLinks) * 6;
        links.push({
            source,
            target,
            sourceSiteRef: source,
            targetSiteRef: target,
            sourceSiteId: sourceId,
            targetSiteId: targetId,
            article_link_count: articleLinkCount,
            source_article_count: Number(edge.source_article_count) || 0,
            target_article_count: Number(edge.target_article_count) || 0,
            source_keyword_count: Number(edge.source_keyword_count) || 0,
            selectionKey: edgeSelectionKey(edge),
            lineStyle: {
                width,
                curveness: sourceId < targetId ? 0.22 : -0.22,
            },
        });
    });

    return { nodes, links };
}

export function sourceTopicsFromPair(payload) {
    const topics = Array.isArray(payload?.topics) ? payload.topics : [];
    return topics.map((topic) => ({
        topic_id: Number(topic?.topic_id) || 0,
        topic_ref: String(topic?.topic_ref || ''),
        name: String(topic?.name || ''),
        cross_site_link_count: Number(topic?.cross_site_link_count) || 0,
    })).filter((topic) => topic.topic_id > 0);
}
