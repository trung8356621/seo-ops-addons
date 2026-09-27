/**
 * Agent-local project list. All Sites is a global scope, not a fake site id.
 * @param {Array<{id:number, domain:string}>} sites
 */
export function buildProjectItems(sites) {
    const items = [
        {
            type: 'global',
            key: 'global',
            label: 'All Sites',
            retrieval: 'unsupported',
        },
    ];

    for (const site of sites) {
        const id = Number(site.id);
        if (!Number.isInteger(id) || id <= 0) {
            continue;
        }
        items.push({
            type: 'site',
            key: `site:${id}`,
            siteId: id,
            siteRef: `site:${id}`,
            label: String(site.domain || ''),
            retrieval: 'supported',
        });
    }

    return items;
}

export const PROJECT_SIDEBAR_ACTIONS = [];

/**
 * Switch the selected project without mutating the site catalog.
 * @param {ReturnType<typeof buildProjectItems>} items
 * @param {string} key
 */
export function switchProject(items, key) {
    const next = items.find((item) => item.key === key) ?? items[0];
    return {
        type: next.type,
        key: next.key,
        siteId: next.siteId,
        siteRef: next.siteRef,
        label: next.label,
        retrieval: next.retrieval,
    };
}

export function scopePayload(item) {
    if (!item || item.type === 'global') {
        return { type: 'global' };
    }
    return {
        type: 'site',
        siteId: item.siteId,
        siteRef: item.siteRef,
    };
}
