import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    closePanel,
    focusReason,
    getActivePanel,
    openPanel,
    subscribeEditorNavigation,
} from '../runtime/editorRuntimeNavigation';
import {
    getRuntimeNavigatorBadges,
    getRuntimeWidgetHealth,
    subscribeRuntimeWidgetHealth,
} from '../runtime/editorRuntimeHealthStore';
import { isMainColumnOnlyPanel } from '../runtime/mainColumnPanels';
import { SHELL_BOUNDARY_NAV_ITEMS } from '../runtime/editorShellNavItems';
import {
    buildNavChipClassName,
    isNavChipActive,
    resolveNavChipHealthFlags,
    resolveNavChipStatus,
} from './editorSidebarNavChipState';

const CHIP_LABELS = Object.freeze({
    seo: { label: 'SEO', fullLabel: 'SEO Assistant' },
    images: { label: 'Images', fullLabel: 'Image Assistant' },
    featured: { label: 'Featured', fullLabel: 'Featured Image' },
    'product-album': { label: 'Gallery', fullLabel: 'Product Album' },
    links: { label: 'Links', fullLabel: 'Link Assistant' },
    reviews: { label: 'Reviews', fullLabel: 'Reviews Assistant' },
    cta: { label: 'CTA', fullLabel: 'CTA Assistant' },
    faq: { label: 'FAQ', fullLabel: 'FAQ Assistant' },
});

function chipStatus(health) {
    return resolveNavChipStatus(health);
}

function chipIssueCount(chipId, health) {
    const status = chipStatus(health);
    if (status !== 'error' && status !== 'warning') {
        return null;
    }
    if (chipId === 'images') {
        const errors = Number(health?.error_count ?? 0);
        const warnings = Number(health?.warning_count ?? 0);
        const badge = errors > 0 ? errors : warnings;
        return badge > 0 ? badge : null;
    }
    const count = Number(health?.issue_count ?? 0);
    return count > 0 ? count : null;
}

function isRefreshing(health) {
    return String(health?.refresh_status || '') === 'refreshing';
}

function resolveChipHealth(chipId, healthMap) {
    if (chipId === 'cta') {
        // CTA metric is navigator-badge owned. Never inherit Links validation severity.
        return healthMap?.cta || null;
    }
    return healthMap?.[chipId] || null;
}

function chipBadge(chipId, health, badges) {
    if (chipId === 'featured') {
        return null;
    }

    if (health && Number(health.item_count) >= 0 && ['images', 'links', 'gallery', 'cta'].includes(chipId)) {
        const count = Number(health.item_count);
        if (count > 0) {
            return count;
        }
        if (chipId === 'links' && health.status === 'error') {
            return 0;
        }
    }

    const value = badges?.[chipId];
    // NOT_LOADED: null/undefined/''/false must never render as Reviews 0.
    if (value === null || value === undefined || value === '' || value === false) {
        return null;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        if (value === 0) {
            return chipId === 'reviews' ? 0 : null;
        }

        return value;
    }

    const numeric = Number(value);
    if (!Number.isNaN(numeric) && numeric === 0) {
        return chipId === 'reviews' ? 0 : null;
    }

    return value;
}

function chipReasonsTooltip(chipId, health) {
    const reasons = health?.reasons;
    const parts = [];
    if (chipId === 'images') {
        const valid = Number(health?.item_count ?? 0);
        const recommended = Number(health?.recommended_count ?? 0);
        const missing = Number(health?.missing_recommended_count ?? 0);
        if (valid >= 0) {
            parts.push(`Có ${valid} ảnh nội dung hợp lệ`);
        }
        if (recommended > 0) {
            parts.push(`Đề xuất khoảng ${recommended} ảnh`);
        }
        if (missing > 0) {
            parts.push(`Thiếu khoảng ${missing} ảnh`);
        }
    }
    if (Array.isArray(reasons)) {
        reasons.forEach((reason) => {
            if (reason?.severity === 'info' || reason?.code === 'image_recommendation') {
                return;
            }
            if (reason?.message) {
                parts.push(reason.message);
            }
        });
    }
    return parts.join(' · ');
}

function resolveChipMeta(entry) {
    const panelId = String(entry.panelId || entry.id || '');
    const defaults = CHIP_LABELS[panelId] || {};
    return {
        id: panelId,
        panelId,
        label: entry.label || defaults.label || panelId,
        fullLabel: entry.fullLabel || defaults.fullLabel || entry.label || panelId,
        order: Number(entry.order ?? 0),
        shell: Boolean(entry.shell),
        linkSection: entry.linkSection || (panelId === 'cta' ? 'cta' : null),
        keywords: Array.isArray(entry.keywords)
            ? entry.keywords
            : [panelId, defaults.label, defaults.fullLabel].filter(Boolean),
        disabled: Boolean(entry.disabled),
        disabledReason: entry.disabledReason || '',
    };
}

function notifyLinkSection(panelId) {
    if (typeof window === 'undefined') {
        return;
    }
    let section = 'all';
    if (panelId === 'cta') {
        section = 'cta';
    } else if (panelId === 'links') {
        section = 'links';
    }
    window.dispatchEvent(new CustomEvent('seo-assistant-link-section', {
        detail: { section },
    }));
}

/**
 * React dock — chips from runtime sidebar registry + shell boundary items.
 * Active panel + health owned by runtime services (not Alpine).
 */
export function EditorSidebarNavigation({
    runtime,
    rootEl = null,
    shellItems = SHELL_BOUNDARY_NAV_ITEMS,
    contextRevision = 0,
}) {
    const [activePanel, setActivePanel] = useState(() => getActivePanel());
    const [healthMap, setHealthMap] = useState(() => getRuntimeWidgetHealth());
    const [badges, setBadges] = useState(() => getRuntimeNavigatorBadges());

    useEffect(() => subscribeEditorNavigation((panelId) => {
        // FAQ is main-column only — keep dock chip highlight on the last sidebar rail panel.
        if (isMainColumnOnlyPanel(panelId)) {
            return;
        }
        setActivePanel(panelId);
    }), []);

    useEffect(() => subscribeRuntimeWidgetHealth(({ health, badges: nextBadges }) => {
        setHealthMap(health || {});
        setBadges(nextBadges || {});
    }), []);

    const registryChips = useMemo(() => {
        if (!runtime?.getSidebarEntries) {
            return [];
        }
        return runtime.getSidebarEntries()
            .filter((entry) => entry?.navChip !== false)
            .map(resolveChipMeta);
        // contextRevision bumps when runtime.setContext runs (post type / archive).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [runtime, contextRevision]);

    const chips = useMemo(() => {
        const shell = (shellItems || []).map(resolveChipMeta);
        return [...registryChips, ...shell].sort((a, b) => a.order - b.order);
    }, [registryChips, shellItems]);

    const selectChip = (panelId) => {
        const chip = chips.find((entry) => entry.id === panelId);
        if (!chip || chip.disabled) {
            return;
        }

        const health = healthMap?.[panelId] || null;
        const hasIssues = Number(chipIssueCount(panelId, health) ?? 0) > 0
            || ['error', 'warning'].includes(chipStatus(health));

        if (activePanel === panelId && !hasIssues) {
            closePanel({ source: 'react_nav_toggle' });
            return;
        }

        openPanel(panelId, { source: 'react_nav' });
        notifyLinkSection(panelId);

        if (panelId === 'publishing') {
            window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
        }

        if (hasIssues) {
            const reason = Array.isArray(health?.reasons) ? health.reasons[0] : null;
            focusReason(reason?.code || panelId, {
                panel: panelId,
                reason,
                target_id: reason?.target_id ?? null,
                source: 'react_nav',
            });
        }
    };

    const body = (
        <div className="seo-assistant-dock" role="navigation" aria-label="Assistant Dock">
            <div className="seo-assistant-dock__tabs" role="tablist" aria-label="Assistant panels">
                {chips.map((chip) => {
                    const health = resolveChipHealth(chip.id, healthMap);
                    const { hasError, hasWarning, status } = resolveNavChipHealthFlags(health);
                    // Selected state: canonical activePanel only — never from health/error.
                    const isActive = isNavChipActive(activePanel, chip.id);
                    const badge = chipBadge(chip.id, health, badges);
                    const issue = chipIssueCount(chip.id, health);
                    const refreshing = isRefreshing(health);
                    const tooltip = chipReasonsTooltip(chip.id, health) || chip.fullLabel
                        || chip.disabledReason;
                    const className = buildNavChipClassName({
                        isActive,
                        hasError,
                        hasWarning,
                        status,
                        isRefreshing: refreshing,
                        isShell: chip.shell,
                        isDisabled: chip.disabled,
                    });

                    return (
                        <button
                            key={chip.id}
                            type="button"
                            className={className}
                            role="tab"
                            data-widget-id={chip.id}
                            data-active={isActive ? '1' : '0'}
                            data-has-error={hasError ? '1' : '0'}
                            data-has-warning={hasWarning ? '1' : '0'}
                            aria-selected={isActive ? 'true' : 'false'}
                            title={tooltip}
                            aria-label={tooltip !== chip.fullLabel ? `${chip.label}: ${tooltip}` : chip.fullLabel}
                            disabled={chip.disabled}
                            onClick={() => selectChip(chip.id)}
                        >
                            {(hasError || hasWarning) ? (
                                <span className="seo-assistant-dock__tab-dot" aria-hidden="true" />
                            ) : null}
                            <span className="seo-assistant-dock__tab-label">{chip.label}</span>
                            {badge !== null && badge !== '' ? (
                                <span className="seo-assistant-dock__tab-badge">{badge}</span>
                            ) : null}
                            {issue ? (
                                <span className="seo-assistant-dock__tab-issue" aria-hidden="true">{issue}</span>
                            ) : null}
                            {refreshing ? (
                                <span className="seo-assistant-dock__tab-refresh" aria-hidden="true" title="Refreshing">↻</span>
                            ) : null}
                        </button>
                    );
                })}
            </div>
        </div>
    );

    if (rootEl) {
        return createPortal(body, rootEl);
    }

    return body;
}
