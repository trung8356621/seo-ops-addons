import { helpRegistry } from './helpRegistry';

/**
 * Resolve help context from Filament route name + URL path/query.
 * Falls back to `system` (overview) — never throws.
 *
 * @param {{ routeName?: string|null, path?: string|null, search?: string|null }} [input]
 * @returns {import('./helpRegistry').HelpContext}
 */
export function resolveHelpContext(input = {}) {
    const fromPayload = resolvePayloadHelpContext(input);
    if (fromPayload) {
        return fromPayload;
    }

    const routeName = String(
        input.routeName
        ?? document.body?.dataset?.helpRouteName
        ?? window.__SEO_HELP_ROUTE_NAME__
        ?? '',
    ).trim();

    const path = String(input.path ?? window.location?.pathname ?? '');
    const search = String(input.search ?? window.location?.search ?? '');
    const fullPath = `${path}${search}`;

    // Tab-specific contexts on Articles list — check before generic articles.
    if (matchesContext(helpRegistry.syncQueue, routeName, fullPath) && /[?&]tab=queue\b/.test(search)) {
        return helpRegistry.syncQueue;
    }
    if (matchesContext(helpRegistry.categories, routeName, fullPath) && /[?&]tab=categories\b/.test(search)) {
        return helpRegistry.categories;
    }
    if (matchesContext(helpRegistry.articleEditor, routeName, fullPath)) {
        return helpRegistry.articleEditor;
    }
    if (matchesContext(helpRegistry.media, routeName, fullPath)) {
        return helpRegistry.media;
    }
    if (matchesContext(helpRegistry.seo, routeName, fullPath)) {
        return helpRegistry.seo;
    }
    if (matchesContext(helpRegistry.settings, routeName, fullPath)) {
        return helpRegistry.settings;
    }
    if (matchesContext(helpRegistry.articles, routeName, fullPath)) {
        return helpRegistry.articles;
    }
    if (matchesContext(helpRegistry.dashboard, routeName, fullPath)) {
        return helpRegistry.dashboard;
    }
    if (document.body?.classList?.contains('article-editor-page')) {
        return helpRegistry.articleEditor;
    }

    return helpRegistry.system;
}

/**
 * Prefer the client payload step list (Admin / Seeding / SEO) when the shell has booted.
 * @param {{ routeName?: string|null, path?: string|null, search?: string|null, panelId?: string|null, articleEditor?: boolean }} input
 */
function resolvePayloadHelpContext(input) {
    const payload = window.__SEO_HELP_PAYLOAD__;
    const contexts = payload?.contexts;
    const steps = payload?.context_resolution;
    if (!contexts || !Array.isArray(steps)) {
        return null;
    }

    const routeName = String(
        input.routeName
        ?? document.body?.dataset?.helpRouteName
        ?? window.__SEO_HELP_ROUTE_NAME__
        ?? '',
    ).trim();
    const path = String(input.path ?? window.location?.pathname ?? '');
    const search = String(input.search ?? window.location?.search ?? '');
    const fullPath = `${path}${search}`;
    const panelId = String(
        input.panelId
        ?? document.body?.dataset?.helpPanel
        ?? window.__HELP_PANEL_ID__
        ?? '',
    ).trim();
    const articleEditor = input.articleEditor === true
        || document.body?.classList?.contains('article-editor-page') === true;

    for (const step of steps) {
        if (!step?.id || !contexts[step.id]) {
            continue;
        }
        const context = contexts[step.id];
        if (step.bodyClass) {
            if (articleEditor && step.bodyClass === 'article-editor-page') {
                return context;
            }
            continue;
        }
        if (step.requiresSearch) {
            try {
                if (!new RegExp(step.requiresSearch).test(search)) {
                    continue;
                }
            } catch {
                continue;
            }
        }
        if (Array.isArray(step.panelIds)) {
            const panelHit = step.panelIds.includes(panelId);
            if (panelHit || matchesContext(normalizePayloadContext(context), routeName, fullPath)) {
                return context;
            }
            continue;
        }
        if (matchesContext(normalizePayloadContext(context), routeName, fullPath)) {
            return context;
        }
    }

    if (articleEditor && contexts.articleEditor) {
        return contexts.articleEditor;
    }

    return contexts.system || null;
}

/**
 * Payload contexts store path patterns as strings. The static registry uses RegExp.
 * @param {Record<string, any>} context
 */
function normalizePayloadContext(context) {
    const paths = Array.isArray(context.pathPatterns) ? context.pathPatterns : [];
    return {
        ...context,
        pathPatterns: paths.map((pattern) => {
            if (pattern instanceof RegExp) {
                return pattern;
            }
            try {
                return new RegExp(String(pattern));
            } catch {
                return /$^/;
            }
        }),
    };
}

/**
 * @param {import('./helpRegistry').HelpContext} context
 * @param {string} routeName
 * @param {string} fullPath
 */
function matchesContext(context, routeName, fullPath) {
    if (!context) {
        return false;
    }

    const names = Array.isArray(context.routeNames) ? context.routeNames : [];
    for (const pattern of names) {
        if (routeNameMatches(routeName, pattern)) {
            return true;
        }
    }

    const paths = Array.isArray(context.pathPatterns) ? context.pathPatterns : [];
    for (const re of paths) {
        if (re instanceof RegExp && re.test(fullPath)) {
            return true;
        }
    }

    return false;
}

/**
 * @param {string} routeName
 * @param {string} pattern  exact or trailing *
 */
function routeNameMatches(routeName, pattern) {
    if (!routeName || !pattern) {
        return false;
    }
    if (pattern.endsWith('*')) {
        const prefix = pattern.slice(0, -1);
        return routeName === prefix || routeName.startsWith(prefix);
    }

    return routeName === pattern;
}

/** Hook-style alias for consumers. */
export function useHelpContext(input) {
    return resolveHelpContext(input);
}
