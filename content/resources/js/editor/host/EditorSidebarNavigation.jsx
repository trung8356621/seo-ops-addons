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
import { SHELL_BOUNDARY_NAV_ITEMS } from '../runtime/editorShellNavItems';

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

function normalizeSearchText(value) {
    return String(value ?? '').trim().toLowerCase();
}

function chipStatus(health) {
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
    if (value === null || value === undefined || value === '') {
        return chipId === 'reviews' ? 0 : null;
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
    const [searchQuery, setSearchQuery] = useState('');
    const [searchOpen, setSearchOpen] = useState(false);
    const [searchHighlightIndex, setSearchHighlightIndex] = useState(0);

    useEffect(() => subscribeEditorNavigation((panelId) => {
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

    const searchCatalog = useMemo(() => {
        const items = [];
        chips.forEach((chip) => {
            items.push({
                label: chip.fullLabel,
                panelId: chip.id,
                keywords: [chip.id, chip.label, chip.fullLabel, ...(chip.keywords || [])]
                    .map(normalizeSearchText),
            });
            (chip.keywords || []).forEach((keyword) => {
                items.push({
                    label: `${chip.fullLabel} — ${keyword}`,
                    panelId: chip.id,
                    keywords: [normalizeSearchText(keyword)],
                });
            });
        });
        return items;
    }, [chips]);

    const filteredSearchResults = useMemo(() => {
        const q = normalizeSearchText(searchQuery);
        if (q === '') {
            return [];
        }
        const chipIds = new Set(chips.map((chip) => chip.id));
        return searchCatalog
            .filter((item) => chipIds.has(item.panelId))
            .filter((item) => {
                if (normalizeSearchText(item.label).includes(q)) return true;
                return (item.keywords || []).some((keyword) => keyword.includes(q) || q.includes(keyword));
            })
            .slice(0, 14);
    }, [chips, searchCatalog, searchQuery]);

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

    const onSearchKeydown = (event) => {
        const results = filteredSearchResults;
        if (event.key === 'Escape') {
            event.preventDefault();
            setSearchQuery('');
            setSearchOpen(false);
            event.target.blur();
            return;
        }
        if (!searchOpen || results.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setSearchHighlightIndex((index) => (index + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setSearchHighlightIndex((index) => (index - 1 + results.length) % results.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const item = results[searchHighlightIndex];
            if (item) {
                selectChip(item.panelId);
                setSearchQuery('');
                setSearchOpen(false);
            }
        }
    };

    const body = (
        <div className="seo-assistant-dock" role="navigation" aria-label="Assistant Dock">
            <div className="seo-assistant-dock__search-wrap">
                <label className="sr-only" htmlFor="seo-assistant-dock-search">Search assistants</label>
                <div className="seo-assistant-dock__search">
                    <svg className="seo-assistant-dock__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m21 21-4.35-4.35M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z" />
                    </svg>
                    <input
                        id="seo-assistant-dock-search"
                        type="search"
                        className="seo-assistant-dock__search-input"
                        placeholder="Search assistants..."
                        autoComplete="off"
                        value={searchQuery}
                        onChange={(event) => {
                            const next = event.target.value;
                            setSearchQuery(next);
                            setSearchOpen(normalizeSearchText(next) !== '');
                            setSearchHighlightIndex(0);
                        }}
                        onFocus={() => {
                            if (normalizeSearchText(searchQuery) !== '') {
                                setSearchOpen(true);
                            }
                        }}
                        onKeyDown={onSearchKeydown}
                    />
                </div>
                {searchOpen && filteredSearchResults.length > 0 ? (
                    <div className="seo-assistant-dock__dropdown">
                        {filteredSearchResults.map((item, index) => (
                            <button
                                key={`${item.label}-${index}`}
                                type="button"
                                className={`seo-assistant-dock__dropdown-item${searchHighlightIndex === index ? ' is-active' : ''}`}
                                onClick={() => {
                                    selectChip(item.panelId);
                                    setSearchQuery('');
                                    setSearchOpen(false);
                                }}
                            >
                                <span className="seo-assistant-dock__dropdown-label">{item.label}</span>
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            <div className="seo-assistant-dock__tabs" role="tablist" aria-label="Assistant panels">
                {chips.map((chip) => {
                    const health = resolveChipHealth(chip.id, healthMap);
                    const status = chipStatus(health);
                    const active = activePanel === chip.id;
                    const badge = chipBadge(chip.id, health, badges);
                    const issue = chipIssueCount(chip.id, health);
                    const refreshing = isRefreshing(health);
                    const tooltip = chipReasonsTooltip(chip.id, health) || chip.fullLabel
                        || chip.disabledReason;
                    const className = [
                        'seo-assistant-dock__tab',
                        active ? 'is-active' : '',
                        status === 'error' ? 'is-status-error' : '',
                        status === 'warning' ? 'is-status-warning' : '',
                        status === 'success' ? 'is-status-success' : '',
                        refreshing ? 'is-refreshing' : '',
                        chip.shell ? 'is-shell-boundary' : '',
                        chip.disabled ? 'is-disabled' : '',
                    ].filter(Boolean).join(' ');

                    return (
                        <button
                            key={chip.id}
                            type="button"
                            className={className}
                            role="tab"
                            aria-selected={active ? 'true' : 'false'}
                            title={tooltip}
                            aria-label={tooltip !== chip.fullLabel ? `${chip.label}: ${tooltip}` : chip.fullLabel}
                            disabled={chip.disabled}
                            onClick={() => selectChip(chip.id)}
                        >
                            {(status === 'error' || status === 'warning') ? (
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
