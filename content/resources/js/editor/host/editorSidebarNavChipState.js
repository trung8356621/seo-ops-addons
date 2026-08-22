/**
 * Shared dock-chip visual state — selected vs health must stay independent.
 * Active/selected comes ONLY from the canonical activePanelId.
 */

/**
 * @param {string|null|undefined} activePanelId
 * @param {string} chipId
 * @returns {boolean}
 */
export function isNavChipActive(activePanelId, chipId) {
    return String(activePanelId || '') === String(chipId || '');
}

/**
 * @param {object|null|undefined} health
 * @returns {'error'|'warning'|'success'|'info'|'neutral'}
 */
export function resolveNavChipStatus(health) {
    const status = String(health?.status || 'neutral');
    const issues = Number(health?.issue_count ?? health?.error_count ?? 0);
    // Red/warning only from canonical diagnostics — never from refresh/loading.
    if (status === 'error' && issues > 0) {
        return 'error';
    }
    if (status === 'warning' && issues > 0) {
        return 'warning';
    }
    if (status === 'success' || status === 'info') {
        return status;
    }
    return 'neutral';
}

/**
 * @param {object|null|undefined} health
 * @returns {{ hasError: boolean, hasWarning: boolean, status: string }}
 */
export function resolveNavChipHealthFlags(health) {
    const status = resolveNavChipStatus(health);
    return {
        hasError: status === 'error',
        hasWarning: status === 'warning',
        status,
    };
}

/**
 * @param {object} params
 * @param {boolean} params.isActive
 * @param {boolean} params.hasError
 * @param {boolean} params.hasWarning
 * @param {boolean} [params.isRefreshing]
 * @param {boolean} [params.isShell]
 * @param {boolean} [params.isDisabled]
 * @param {string} [params.status]
 * @returns {string}
 */
export function buildNavChipClassName({
    isActive,
    hasError,
    hasWarning,
    isRefreshing = false,
    isShell = false,
    isDisabled = false,
    status = 'neutral',
}) {
    const resolvedStatus = status || (hasError ? 'error' : (hasWarning ? 'warning' : 'neutral'));
    return [
        'seo-assistant-dock__tab',
        isActive ? 'is-active' : '',
        // Health classes never imply selection — CSS must treat these as badge-only.
        resolvedStatus === 'error' || hasError ? 'is-status-error' : '',
        resolvedStatus === 'warning' || hasWarning ? 'is-status-warning' : '',
        resolvedStatus === 'success' ? 'is-status-success' : '',
        isRefreshing ? 'is-refreshing' : '',
        isShell ? 'is-shell-boundary' : '',
        isDisabled ? 'is-disabled' : '',
    ].filter(Boolean).join(' ');
}

/**
 * Exactly one selected chip for a given activePanelId (0 if closed/null).
 *
 * @param {string[]} chipIds
 * @param {string|null|undefined} activePanelId
 * @returns {string[]}
 */
export function collectActiveNavChipIds(chipIds, activePanelId) {
    if (activePanelId == null || activePanelId === '') {
        return [];
    }
    return (chipIds || []).filter((id) => isNavChipActive(activePanelId, id));
}

/**
 * Regression scenario: Images selected while SEO still has errors.
 *
 * @param {object} [options]
 * @param {string} [options.activePanelId]
 * @param {number} [options.seoErrorCount]
 * @returns {{ images: object, seo: object, activeChipIds: string[] }}
 */
export function resolveImagesActiveSeoErrorScenario({
    activePanelId = 'images',
    seoErrorCount = 4,
} = {}) {
    const chipIds = ['seo', 'images', 'reviews', 'publishing'];
    const seoHealth = {
        status: 'error',
        issue_count: seoErrorCount,
        error_count: seoErrorCount,
    };
    const imagesHealth = { status: 'neutral', issue_count: 0 };

    const seoFlags = resolveNavChipHealthFlags(seoHealth);
    const imagesFlags = resolveNavChipHealthFlags(imagesHealth);

    const seo = {
        id: 'seo',
        isActive: isNavChipActive(activePanelId, 'seo'),
        hasError: seoFlags.hasError,
        hasWarning: seoFlags.hasWarning,
        className: buildNavChipClassName({
            isActive: isNavChipActive(activePanelId, 'seo'),
            ...seoFlags,
        }),
    };
    const images = {
        id: 'images',
        isActive: isNavChipActive(activePanelId, 'images'),
        hasError: imagesFlags.hasError,
        hasWarning: imagesFlags.hasWarning,
        className: buildNavChipClassName({
            isActive: isNavChipActive(activePanelId, 'images'),
            ...imagesFlags,
        }),
    };

    return {
        images,
        seo,
        activeChipIds: collectActiveNavChipIds(chipIds, activePanelId),
    };
}
