/**
 * Normalizes host context into a canonical shape:
 * {
 *   appKey: string,
 *   scope: { type: 'global' | 'site', ref: string, siteId?: number, label?: string } | null,
 *   capabilities: string[]
 * }
 */
export function normalizeHostContext(raw = {}) {
    const appKey = String(raw?.appKey || raw?.app_key || 'standalone');
    const rawScope = raw?.scope;
    let scope = null;

    if (rawScope && typeof rawScope === 'object') {
        const type = String(rawScope.type || 'global').toLowerCase();
        if (type === 'site') {
            let siteId = Number(rawScope.siteId ?? rawScope.site_id ?? 0);
            const ref = String(
                rawScope.ref || rawScope.siteRef || rawScope.site_ref || (siteId > 0 ? `site:${siteId}` : '')
            );
            if ((!siteId || siteId <= 0) && ref.startsWith('site:')) {
                const parsed = Number(ref.slice(5));
                if (Number.isInteger(parsed) && parsed > 0) {
                    siteId = parsed;
                }
            }
            scope = {
                type: 'site',
                ref: ref || (siteId > 0 ? `site:${siteId}` : ''),
                siteId: siteId > 0 ? siteId : undefined,
                label: rawScope.label || rawScope.domain || ref || (siteId > 0 ? `Site #${siteId}` : 'Site'),
            };
        } else {
            scope = {
                type: 'global',
                ref: 'global',
                label: rawScope.label || 'All Sites',
            };
        }
    }

    const capabilities = Array.isArray(raw?.capabilities)
        ? raw.capabilities.filter((item) => typeof item === 'string')
        : ['turn', 'model-input'];

    return {
        appKey,
        scope,
        capabilities,
    };
}

/**
 * Builds generic scope payload conforming to { type, ref, siteId, site_id }.
 */
export function buildScopePayload(scope) {
    if (!scope || scope.type === 'global') {
        return { type: 'global', ref: 'global' };
    }
    const siteId = scope.siteId ? Number(scope.siteId) : undefined;
    const ref = scope.ref || (siteId ? `site:${siteId}` : '');
    const payload = {
        type: 'site',
        ref,
    };
    if (siteId && siteId > 0) {
        payload.siteId = siteId;
        payload.site_id = siteId;
    }
    return payload;
}
