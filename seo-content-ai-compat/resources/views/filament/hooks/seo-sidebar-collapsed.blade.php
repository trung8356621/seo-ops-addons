<style>
    .fi-sidebar-item[data-nav-tone="emerald"] { --nav-tone: #10B981; }
    .fi-sidebar-item[data-nav-tone="green"] { --nav-tone: #22C55E; }
    .fi-sidebar-item[data-nav-tone="amber"] { --nav-tone: #F59E0B; }
    .fi-sidebar-item[data-nav-tone="cyan"] { --nav-tone: #06B6D4; }
    .fi-sidebar-item[data-nav-tone="pink"] { --nav-tone: #EC4899; }
    .fi-sidebar-item[data-nav-tone="blue"] { --nav-tone: #3B82F6; }
    .fi-sidebar-item[data-nav-tone="red"] { --nav-tone: #EF4444; }
    .fi-sidebar-item[data-nav-tone="indigo"] { --nav-tone: #6366F1; }
    .fi-sidebar-item[data-nav-tone="violet"] { --nav-tone: #8B5CF6; }
    .fi-sidebar-item[data-nav-tone="slate"] { --nav-tone: #64748B; }

    .fi-sidebar-item[data-nav-tone] .fi-sidebar-item-icon {
        color: var(--nav-tone);
    }

    .fi-sidebar-item[data-nav-tone] .fi-sidebar-item-grouped-border > .rounded-full {
        background-color: var(--nav-tone);
    }

    .fi-sidebar-item[data-nav-tone] .fi-sidebar-item-label {
        color: #374151;
        font-weight: 500;
    }

    .dark .fi-sidebar-item[data-nav-tone] .fi-sidebar-item-label {
        color: #e5e7eb;
    }

    .fi-sidebar-item[data-nav-tone] > div > .fi-sidebar-item-button:hover,
    .fi-sidebar-item[data-nav-tone] > a.fi-sidebar-item-button:hover {
        background: color-mix(in srgb, var(--nav-tone) 8%, transparent);
    }

    .fi-sidebar-item[data-nav-tone].nav-self-active > div > .fi-sidebar-item-button,
    .fi-sidebar-item[data-nav-tone].nav-self-active > a.fi-sidebar-item-button {
        background: color-mix(in srgb, var(--nav-tone) 12%, transparent);
        box-shadow: inset 3px 0 0 var(--nav-tone);
    }

    .fi-sidebar-item[data-nav-tone].nav-self-active .fi-sidebar-item-label {
        font-weight: 600;
        color: #111827;
    }

    .dark .fi-sidebar-item[data-nav-tone].nav-self-active .fi-sidebar-item-label {
        color: #f8fafc;
    }

    .fi-sidebar-item[data-nav-tone] .fi-sidebar-item-button:focus-visible {
        outline: 2px solid var(--nav-tone);
        outline-offset: 2px;
    }

    @media (min-width: 1024px) {
        .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-sub-group-items,
        .fi-sidebar:not(.fi-sidebar-open) [data-fi-sidebar-inline-children],
        .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-group-has-collapsed-flyout > .fi-sidebar-group-items {
            display: none !important;
            height: 0 !important;
            overflow: hidden !important;
            visibility: hidden !important;
        }
    }
</style>
