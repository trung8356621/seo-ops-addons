import { useEffect, useRef } from 'react';
import { ensureLinkPreviews, hydrateLinksFromCache } from '../services/linkPreviewPipeline';

/**
 * Shared hook: hydrate from document cache then fetch missing previews (deduped).
 *
 * @param {Array<Record<string, unknown>>} links
 * @param {{
 *   cache?: Record<string, Record<string, unknown>>,
 *   onLinksChange?: (next: Array<Record<string, unknown>>) => void,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 *   enabled?: boolean,
 * }} options
 */
export default function useEnsureLinkPreviews(links, options = {}) {
    const {
        cache = {},
        onLinksChange,
        onCacheUpdate,
        enabled = true,
    } = options;

    const linksKey = (Array.isArray(links) ? links : [])
        .map((l) => `${l?.normalized_url || l?.url || ''}:${l?.preview_fetched_at || ''}`)
        .join('|');
    const runIdRef = useRef(0);

    useEffect(() => {
        if (!enabled || typeof onLinksChange !== 'function') return undefined;
        const list = Array.isArray(links) ? links : [];
        if (list.length === 0) return undefined;

        const hydrated = hydrateLinksFromCache(list, cache);
        const pending = hydrated.filter((l) => l.url && !l.preview_fetched_at);
        const hydratedOnly = pending.length === 0
            && hydrated.some((l, i) => Boolean(l.preview_fetched_at) && !list[i]?.preview_fetched_at);

        if (pending.length === 0) {
            if (hydratedOnly) onLinksChange(hydrated);
            return undefined;
        }

        const runId = runIdRef.current + 1;
        runIdRef.current = runId;
        let cancelled = false;

        (async () => {
            const next = await ensureLinkPreviews(hydrated, {
                cache,
                onCacheUpdate,
            });
            if (!cancelled && runIdRef.current === runId) {
                onLinksChange(next);
            }
        })();

        return () => {
            cancelled = true;
        };
        // linksKey captures URL + fetch state; cache object identity is intentional.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, linksKey]);
}
