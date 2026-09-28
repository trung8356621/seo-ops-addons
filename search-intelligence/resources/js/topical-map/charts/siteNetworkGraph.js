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
    if (sites.length > 0) {
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
 * ALL accessible managed sites appear as nodes, even with 0 edges.
 * Main domain node is flagged as isMain.
 * Isolated sites are placed intentionally in an isolated cluster.
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

    let maxLinks = 1;
    edges.forEach((edge) => {
        maxLinks = Math.max(maxLinks, Number(edge?.article_link_count) || 0);
    });

    const links = [];
    const participatingRefs = new Set();
    edges.forEach((edge) => {
        const source = String(edge?.source_site_ref || '');
        const target = String(edge?.target_site_ref || '');
        if (!byRef.has(source) || !byRef.has(target) || source === target) {
            return;
        }
        participatingRefs.add(source);
        participatingRefs.add(target);

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

    const nodeList = Array.from(byRef.values());
    const connectedSites = nodeList.filter((s) => participatingRefs.has(String(s.site_ref)));
    const isolatedSites = nodeList.filter((s) => !participatingRefs.has(String(s.site_ref)));

    const nodes = [];

    // Position connected nodes in a central circular cluster
    if (connectedSites.length > 0) {
        const connCount = connectedSites.length;
        const connRadius = connCount <= 1 ? 0 : Math.max(160, connCount * 32);
        const centerY = isolatedSites.length > 0 ? -40 : 0;

        connectedSites.forEach((site, index) => {
            const angle = (Math.PI * 2 * index) / Math.max(connCount, 1) - Math.PI / 2;
            const isMain = Boolean(site.is_main);
            nodes.push({
                id: String(site.site_ref),
                name: String(site.domain || site.site_ref),
                domain: String(site.domain || site.site_ref),
                siteId: Number(site.site_id) || parseSiteRef(site.site_ref),
                isMain,
                is_main: isMain,
                isIsolated: false,
                x: Math.round(Math.cos(angle) * connRadius),
                y: Math.round(centerY + Math.sin(angle) * connRadius),
                symbolSize: isMain ? 68 : 54,
            });
        });

        // Position isolated nodes in an intentional lower area
        if (isolatedSites.length > 0) {
            const isoBaseY = Math.max(180, connRadius + 70);
            const cols = Math.min(isolatedSites.length, 5);
            const spacingX = 130;
            const startX = -((cols - 1) * spacingX) / 2;

            isolatedSites.forEach((site, index) => {
                const col = index % cols;
                const row = Math.floor(index / cols);
                const isMain = Boolean(site.is_main);
                nodes.push({
                    id: String(site.site_ref),
                    name: String(site.domain || site.site_ref),
                    domain: String(site.domain || site.site_ref),
                    siteId: Number(site.site_id) || parseSiteRef(site.site_ref),
                    isMain,
                    is_main: isMain,
                    isIsolated: true,
                    x: Math.round(startX + col * spacingX),
                    y: Math.round(isoBaseY + row * 90),
                    symbolSize: isMain ? 68 : 52,
                });
            });
        }
    } else {
        // All sites are isolated — arrange in a clean grid centered at (0, 0)
        const isoCount = isolatedSites.length;
        const cols = Math.min(Math.max(isoCount, 1), 4);
        const spacingX = 140;
        const spacingY = 100;
        const totalRows = Math.ceil(isoCount / cols);
        const startX = -((cols - 1) * spacingX) / 2;
        const startY = -((totalRows - 1) * spacingY) / 2;

        isolatedSites.forEach((site, index) => {
            const col = index % cols;
            const row = Math.floor(index / cols);
            const isMain = Boolean(site.is_main);
            nodes.push({
                id: String(site.site_ref),
                name: String(site.domain || site.site_ref),
                domain: String(site.domain || site.site_ref),
                siteId: Number(site.site_id) || parseSiteRef(site.site_ref),
                isMain,
                is_main: isMain,
                isIsolated: true,
                x: Math.round(startX + col * spacingX),
                y: Math.round(startY + row * spacingY),
                symbolSize: isMain ? 68 : 52,
            });
        });
    }

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
