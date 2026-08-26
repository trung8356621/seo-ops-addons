{{--
    Shared `.cp-ops-*` CSS for Content Project ops (view-seo-project-operations.blade.php)
    and the Publishing Queue hub (publishing-queue-hub.blade.php). Include once per page via
    <x-seo-content-ai::content-project-ops-styles />. Do not duplicate these rules elsewhere.
--}}
@once
    <style>
        .cp-ops-kpi-grid {
            display: grid;
            gap: 0.5rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .cp-ops-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .cp-ops-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        }
        .cp-ops-kpi-card--exception {
            position: relative;
            margin-left: 0.35rem;
        }
        .cp-ops-kpi-card--exception::before {
            content: '';
            position: absolute;
            left: -0.45rem;
            top: 12%;
            bottom: 12%;
            border-left: 1px dashed rgb(209 213 219);
        }
        .dark .cp-ops-kpi-card--exception::before { border-left-color: rgb(75 85 99); }
        .cp-ops-failed-quick {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .cp-ops-failed-quick__chip {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid rgb(229 231 235);
            background: rgb(249 250 251);
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: rgb(55 65 81);
        }
        .dark .cp-ops-failed-quick__chip {
            border-color: rgb(55 65 81);
            background: rgb(31 41 55);
            color: rgb(209 213 219);
        }
        .cp-ops-failed-quick__chip.is-active {
            border-color: rgb(239 68 68);
            background: rgb(254 242 242);
            color: rgb(185 28 28);
        }
        .dark .cp-ops-failed-quick__chip.is-active {
            border-color: rgb(248 113 113);
            background: rgb(127 29 29 / 0.35);
            color: rgb(254 202 202);
        }

        .cp-ops-kpi-card {
            display: flex;
            min-height: 4.25rem;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid rgb(229 231 235);
            border-left-width: 4px;
            border-radius: 0.75rem;
            background: #fff;
            padding: 0.625rem 0.75rem;
            text-align: left;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
            cursor: pointer;
            transition: background-color .18s ease, border-color .18s ease, box-shadow .18s ease, color .18s ease;
        }
        .dark .cp-ops-kpi-card {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-ops-kpi-card:hover {
            background: rgb(249 250 251);
            box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
        }
        .dark .cp-ops-kpi-card:hover { background: rgb(31 41 55); }
        .cp-ops-kpi-card:focus-visible {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 2px;
        }
        .cp-ops-kpi-card.is-active {
            background: rgb(239 246 255);
            border-color: rgb(147 197 253);
            box-shadow: 0 1px 3px rgb(59 130 246 / 0.12);
        }
        .dark .cp-ops-kpi-card.is-active {
            background: rgb(30 58 138 / 0.28);
            border-color: rgb(59 130 246 / 0.55);
        }
        .cp-ops-kpi-card.is-active .cp-ops-kpi-card__label,
        .cp-ops-kpi-card.is-active .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.is-active .cp-ops-kpi-card__value {
            color: rgb(37 99 235);
        }
        .dark .cp-ops-kpi-card.is-active .cp-ops-kpi-card__label,
        .dark .cp-ops-kpi-card.is-active .cp-ops-kpi-card__icon,
        .dark .cp-ops-kpi-card.is-active .cp-ops-kpi-card__value {
            color: rgb(147 197 253);
        }
        .cp-ops-kpi-card.is-active .cp-ops-kpi-card__label {
            font-weight: 700;
        }
        .cp-ops-kpi-card--pulse {
            border-color: rgb(59 130 246) !important;
            background: rgb(239 246 255) !important;
        }
        .dark .cp-ops-kpi-card--pulse {
            background: rgb(30 58 138 / 0.35) !important;
        }
        .cp-ops-kpi-card__value-wrap {
            display: block;
            margin-top: 0.25rem;
            min-width: 2.25rem;
            overflow: hidden;
        }
        .cp-ops-kpi-card__value-stack {
            position: relative;
            display: block;
            min-height: 1.5rem;
            overflow: hidden;
        }
        .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--out {
            position: absolute;
            inset: 0 auto auto 0;
            animation: cp-ops-count-out .26s ease forwards;
        }
        .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--in {
            animation: cp-ops-count-in .26s ease forwards;
        }
        @keyframes cp-ops-count-out {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-8px); }
        }
        @keyframes cp-ops-count-in {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--out,
            .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--in {
                animation-duration: .12s;
            }
            .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--out {
                transform: none;
            }
            .cp-ops-kpi-card__value-stack.is-animating .cp-ops-kpi-card__value--in {
                transform: none;
            }
        }
        .cp-ops-row--pending {
            opacity: 0.78;
            transition: opacity .18s ease;
        }
        .cp-ops-status-cell,
        .cp-ops-schedule-cell {
            transition: opacity .18s ease;
        }
        @media (prefers-reduced-motion: reduce) {
            .cp-ops-row--pending,
            .cp-ops-status-cell,
            .cp-ops-schedule-cell {
                transition: none;
            }
            .cp-ops-row--pending .animate-spin {
                animation: none;
            }
        }
        .cp-ops-row--highlight {
            background: rgb(239 246 255) !important;
            transition: background-color .14s ease;
        }
        .dark .cp-ops-row--highlight {
            background: rgb(30 58 138 / 0.28) !important;
        }
        .cp-ops-row--exit {
            transition:
                opacity .18s ease,
                transform .18s ease,
                height .22s ease,
                padding .22s ease,
                margin .22s ease,
                border-width .22s ease !important;
        }
        .cp-ops-row--rollback {
            animation: cp-ops-row-in .16s ease;
        }
        @keyframes cp-ops-row-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .cp-ops-row--exit {
                transition: opacity .12s ease, height .14s ease, padding .14s ease, margin .14s ease !important;
                transform: none !important;
            }
        }
        .cp-ops-kpi-card.accent-total { border-left-color: rgb(107 114 128); }
        .cp-ops-kpi-card.accent-draft { border-left-color: rgb(156 163 175); }
        .cp-ops-kpi-card.accent-normal { border-left-color: rgb(156 163 175); }
        .cp-ops-kpi-card.accent-pending { border-left-color: rgb(148 163 184); }
        .cp-ops-kpi-card.accent-recently_completed { border-left-color: rgb(59 130 246); }
        .cp-ops-kpi-card.accent-running { border-left-color: rgb(59 130 246); }
        .cp-ops-kpi-card.accent-failed { border-left-color: rgb(239 68 68); }
        .cp-ops-kpi-card.accent-review { border-left-color: rgb(245 158 11); }
        .cp-ops-kpi-card.accent-approved { border-left-color: rgb(34 197 94); }
        .cp-ops-kpi-card.accent-unscheduled { border-left-color: rgb(148 163 184); }
        .cp-ops-kpi-card.accent-scheduled { border-left-color: rgb(139 92 246); }
        .cp-ops-kpi-card.accent-publishing { border-left-color: rgb(59 130 246); }
        .cp-ops-kpi-card.accent-published { border-left-color: rgb(13 148 136); }

        .cp-ops-kpi-card__top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.25rem;
        }
        .cp-ops-kpi-card__label {
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgb(107 114 128);
        }
        .dark .cp-ops-kpi-card__label { color: rgb(156 163 175); }
        .cp-ops-kpi-card__icon {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
            opacity: 0.85;
        }
        .cp-ops-kpi-card.accent-recently_completed .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-recently_completed .cp-ops-kpi-card__value { color: rgb(37 99 235); }
        .cp-ops-kpi-card.accent-running .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-running .cp-ops-kpi-card__value { color: rgb(37 99 235); }
        .cp-ops-kpi-card.accent-failed .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-failed .cp-ops-kpi-card__value { color: rgb(220 38 38); }
        .cp-ops-kpi-card.accent-review .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-review .cp-ops-kpi-card__value { color: rgb(217 119 6); }
        .cp-ops-kpi-card.accent-approved .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-approved .cp-ops-kpi-card__value { color: rgb(22 163 74); }
        .cp-ops-kpi-card.accent-unscheduled .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-unscheduled .cp-ops-kpi-card__value { color: rgb(71 85 105); }
        .cp-ops-kpi-card.accent-scheduled .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-scheduled .cp-ops-kpi-card__value { color: rgb(124 58 237); }
        .cp-ops-kpi-card.accent-publishing .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-publishing .cp-ops-kpi-card__value { color: rgb(37 99 235); }
        .cp-ops-kpi-card.accent-published .cp-ops-kpi-card__icon,
        .cp-ops-kpi-card.accent-published .cp-ops-kpi-card__value { color: rgb(15 118 110); }
        .cp-ops-kpi-card__value {
            margin-top: 0.25rem;
            font-size: 1.35rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
            color: rgb(17 24 39);
            transition: transform .15s ease, color .15s ease;
        }
        .cp-ops-kpi-card__value.cp-ops-count-tick {
            transform: scale(1.06);
        }
        .dark .cp-ops-kpi-card__value { color: #fff; }
        @media (min-width: 640px) {
            .cp-ops-kpi-card__value { font-size: 1.5rem; }
        }

        .cp-ops-toolbar {
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
            background: #fff;
            padding: 0.75rem;
        }
        .dark .cp-ops-toolbar {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-ops-toolbar__row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }
        .cp-ops-toolbar__search {
            flex: 1 1 14rem;
            min-width: 12rem;
            max-width: 100%;
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }
        .cp-ops-toolbar__filters {
            display: none;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }
        @media (min-width: 768px) {
            .cp-ops-toolbar__filters { display: flex; }
            .cp-ops-toolbar__filters-btn { display: none !important; }
        }
        .cp-ops-toolbar .cp-ops-select,
        .cp-ops-toolbar .x-select-wrap.cp-ops-select {
            display: inline-block;
            width: 9.5rem;
            max-width: 9.5rem;
            flex: 0 0 9.5rem;
        }
        .cp-ops-toolbar .cp-ops-select .x-select,
        .cp-ops-toolbar .cp-ops-select select {
            width: 100%;
        }
        @media (max-width: 1023px) {
            .cp-ops-toolbar .cp-ops-select--wide-only { display: none !important; }
        }
        .cp-ops-toolbar__check {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: rgb(75 85 99);
            white-space: nowrap;
        }
        .dark .cp-ops-toolbar__check { color: rgb(209 213 219); }
        .cp-ops-toolbar__link {
            font-size: 0.75rem;
            font-weight: 600;
            color: rgb(37 99 235);
            background: none;
            border: 0;
            cursor: pointer;
            white-space: nowrap;
        }
        .cp-ops-toolbar__filters-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid rgb(209 213 219);
            background: #fff;
        }
        .cp-ops-toolbar__select-page { margin-left: auto; }

        .cp-ops-filters-drawer {
            position: fixed;
            inset: 0;
            z-index: 50;
        }
        @media (min-width: 768px) {
            .cp-ops-filters-drawer { display: none !important; }
        }
        .cp-ops-filters-drawer__backdrop {
            position: absolute;
            inset: 0;
            background: rgb(0 0 0 / 0.4);
        }
        .cp-ops-filters-drawer__panel {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: 80vh;
            overflow: auto;
            border-radius: 1rem 1rem 0 0;
            background: #fff;
            padding: 1rem;
            box-shadow: 0 -8px 24px rgb(0 0 0 / 0.15);
        }
        .dark .cp-ops-filters-drawer__panel { background: rgb(17 24 39); }
        .cp-ops-filters-drawer__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        .cp-ops-filters-drawer__head h3 {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .cp-ops-filters-drawer__body {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .cp-ops-filters-drawer__actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 0.5rem;
        }
        .cp-ops-filters-drawer__actions .fi-btn { flex: 1; }

        .cp-ops-mobile-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .cp-ops-mobile-card {
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
            background: #fff;
            padding: 0.75rem;
        }
        .dark .cp-ops-mobile-card {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-ops-mobile-card__row { display: flex; align-items: flex-start; gap: 0.5rem; }
        .cp-ops-mobile-card__body { min-width: 0; flex: 1; }
        .cp-ops-mobile-card__badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            margin-top: 0.5rem;
        }
        .cp-ops-mobile-card__meta {
            margin-top: 0.25rem;
            font-size: 0.6875rem;
            color: rgb(107 114 128);
        }

        .cp-ops-table-wrap {
            display: none;
            overflow-x: auto;
            overflow-y: visible;
            max-height: none;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
            background: #fff;
        }
        .dark .cp-ops-table-wrap {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        @media (min-width: 768px) {
            .cp-ops-mobile-list { display: none; }
            .cp-ops-table-wrap { display: block; }
        }
        .cp-ops-table-scroll {
            max-height: none;
            overflow-x: auto;
            overflow-y: visible;
        }
        .cp-ops-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .cp-ops-table thead {
            z-index: 10;
            background: rgb(249 250 251);
        }
        .dark .cp-ops-table thead { background: rgb(31 41 55); }
        .cp-ops-table th {
            padding: 0.5rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgb(107 114 128);
            border-bottom: 1px solid rgb(229 231 235);
        }
        .dark .cp-ops-table th {
            color: rgb(156 163 175);
            border-bottom-color: rgb(55 65 81);
        }
        .cp-ops-table td {
            padding: 0.5rem;
            vertical-align: top;
            border-bottom: 1px solid rgb(243 244 246);
        }
        .dark .cp-ops-table td { border-bottom-color: rgb(31 41 55); }
        .cp-ops-table tbody tr:hover { background: rgb(249 250 251 / 0.9); }
        .dark .cp-ops-table tbody tr:hover { background: rgb(31 41 55 / 0.45); }
        .cp-ops-table tbody tr.is-even { background: rgb(249 250 251 / 0.45); }
        .dark .cp-ops-table tbody tr.is-even { background: rgb(31 41 55 / 0.25); }
        .cp-ops-col-check { width: 2.5rem; }
        .cp-ops-col-thumb { width: 4rem; min-width: 4rem; }
        .cp-ops-col-item { width: 33%; }
        .cp-ops-col-gen, .cp-ops-col-life { width: 11%; }
        .cp-ops-col-keywords { width: 7%; min-width: 4.5rem; }
        .cp-ops-col-activity { width: 18%; }
        .cp-ops-col-actions { width: 10%; }
        .cp-ops-kw-count {
            font-variant-numeric: tabular-nums;
            font-size: 0.8125rem;
            font-weight: 600;
            color: rgb(17 24 39);
        }
        .dark .cp-ops-kw-count { color: rgb(243 244 246); }
        .cp-ops-kw-cell { max-width: 100%; line-height: 1.25; }
        .cp-ops-kw-cell--editable { cursor: text; }
        .cp-ops-kw-display { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.125rem 0.25rem; }
        .cp-ops-kw-original {
            font-size: 0.6875rem;
            color: rgb(107 114 128);
            text-decoration: line-through;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .dark .cp-ops-kw-original { color: rgb(156 163 175); }
        .cp-ops-kw-arrow { font-size: 0.625rem; color: rgb(156 163 175); }
        .cp-ops-kw-effective { font-size: 0.75rem; font-weight: 500; color: rgb(17 24 39); }
        .dark .cp-ops-kw-effective { color: rgb(243 244 246); }
        .cp-ops-kw-text { font-size: 0.75rem; color: rgb(55 65 81); }
        .dark .cp-ops-kw-text { color: rgb(209 213 219); }
        .cp-ops-kw-badge, .cp-ops-kw-dirty {
            font-size: 0.5625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 0.0625rem 0.25rem;
            border-radius: 0.25rem;
            background: rgb(243 244 246);
            color: rgb(75 85 99);
        }
        .cp-ops-kw-dirty { background: rgb(254 243 199); color: rgb(146 64 14); }
        .dark .cp-ops-kw-badge { background: rgb(55 65 81); color: rgb(209 213 219); }
        .dark .cp-ops-kw-dirty { background: rgb(120 53 15 / 0.35); color: rgb(253 230 138); }
        .cp-ops-kw-revert {
            font-size: 0.625rem;
            color: rgb(79 70 229);
            text-decoration: underline;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
        }
        .cp-ops-kw-revert:hover { color: rgb(67 56 202); }
        .cp-ops-kw-input {
            width: 100%;
            min-width: 6rem;
            font-size: 0.75rem;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
            border: 1px solid rgb(209 213 219);
            background: #fff;
        }
        .dark .cp-ops-kw-input { border-color: rgb(75 85 99); background: rgb(17 24 39); color: rgb(243 244 246); }
        .cp-ops-muted {
            font-size: 0.75rem;
            color: rgb(75 85 99);
        }
        .dark .cp-ops-muted { color: rgb(209 213 219); }
        .cp-ops-step {
            margin-top: 0.25rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.6875rem;
            color: rgb(107 114 128);
        }

        /* Row action dropdown — wider, nowrap, flip */
        .cp-ops-menu {
            position: absolute;
            z-index: 80;
            min-width: 240px;
            max-width: min(280px, calc(100vw - 24px));
            max-height: min(70vh, 28rem);
            overflow-y: auto;
            overflow-x: hidden;
            border-radius: 0.5rem;
            border: 1px solid rgb(229 231 235);
            background: #fff;
            padding: 0.25rem 0;
            box-shadow: 0 10px 25px rgb(0 0 0 / 0.12);
        }
        .dark .cp-ops-menu {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-ops-menu--bottom { top: calc(100% + 0.25rem); }
        .cp-ops-menu--top { bottom: calc(100% + 0.25rem); }
        .cp-ops-menu--end { right: 0; left: auto; }
        .cp-ops-menu--start { left: 0; right: auto; }
        /* Teleported to body — collision placement via inline fixed top/left only. */
        .cp-ops-menu--portal {
            position: fixed;
            top: auto;
            right: auto;
            bottom: auto;
            left: auto;
        }
        .cp-ops-menu--portal.cp-ops-menu--bottom,
        .cp-ops-menu--portal.cp-ops-menu--top,
        .cp-ops-menu--portal.cp-ops-menu--end,
        .cp-ops-menu--portal.cp-ops-menu--start {
            top: auto;
            right: auto;
            bottom: auto;
            left: auto;
        }
        .cp-ops-menu__heading {
            margin: 0;
            padding: 0.25rem 0.75rem 0.125rem;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: rgb(156 163 175);
        }
        .cp-ops-menu__heading--nested { padding-top: 0.35rem; }
        .cp-ops-menu__divider {
            margin: 0.25rem 0;
            border-top: 1px solid rgb(243 244 246);
        }
        .dark .cp-ops-menu__divider { border-color: rgb(31 41 55); }
        .cp-ops-menu__item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            min-height: 2.25rem;
            padding: 0.375rem 0.75rem;
            text-align: left;
            font-size: 0.8125rem;
            color: inherit;
            background: transparent;
            border: 0;
            cursor: pointer;
            text-decoration: none;
        }
        .cp-ops-menu__item:hover,
        .cp-ops-menu__item:focus {
            background: rgb(249 250 251);
            outline: none;
        }
        .dark .cp-ops-menu__item:hover,
        .dark .cp-ops-menu__item:focus { background: rgb(31 41 55); }
        .cp-ops-menu__item--danger { color: rgb(220 38 38); }
        .dark .cp-ops-menu__item--danger { color: rgb(248 113 113); }
        .cp-ops-menu__item--disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }
        .cp-ops-menu__item--disabled:hover,
        .cp-ops-menu__item--disabled:focus {
            background: transparent;
        }
        .cp-ops-menu__icon {
            width: 1rem;
            height: 1rem;
            flex: 0 0 1rem;
        }
        .cp-ops-menu__label {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cp-ops-menu__note {
            display: block;
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            color: rgb(156 163 175);
        }

        /* Publishing Queue bulk toolbar — sticky on mobile */
        @media (max-width: 639px) {
            .pq-bulk-toolbar {
                position: sticky;
                bottom: 0.75rem;
                z-index: 30;
                margin-top: 0.75rem;
                box-shadow: 0 -4px 16px rgb(0 0 0 / 0.08);
            }
            .pq-bulk-actions {
                justify-content: stretch;
            }
            .pq-bulk-actions > .relative {
                flex: 1 1 0;
            }
            .pq-bulk-actions .fi-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Content Project dialogs — teleport to body; padding + z-index owned here
           so Filament page stacking / overflow cannot clip or flush text to edges. */
        .cp-ops-dialog-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            box-sizing: border-box;
            background: rgb(3 7 18 / 0.55);
            backdrop-filter: blur(2px);
        }
        .cp-ops-dialog {
            width: 100%;
            max-width: 36rem;
            max-height: min(85vh, 42rem);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-sizing: border-box;
            border-radius: 1rem;
            border: 1px solid rgb(229 231 235);
            background: #fff;
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.35);
        }
        .dark .cp-ops-dialog {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }
        .cp-ops-dialog--sm { max-width: 28rem; }
        .cp-ops-dialog__header,
        .cp-ops-dialog__body {
            padding: 1.5rem 1.5rem 1rem;
            box-sizing: border-box;
        }
        .cp-ops-dialog__body { padding-top: 0; }
        .cp-ops-dialog__footer {
            padding: 1rem 1.5rem;
            box-sizing: border-box;
            border-top: 1px solid rgb(243 244 246);
            background: rgb(249 250 251);
        }
        .dark .cp-ops-dialog__footer {
            border-top-color: rgb(31 41 55);
            background: rgb(17 24 39 / 0.6);
        }
        .cp-ops-dialog__scroll {
            min-height: 0;
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 0.75rem 1.5rem 1rem;
            box-sizing: border-box;
        }

        /* —— Content Planning (Draft Planner) —— */
        .cp-plan {
            --cp-plan-green: #16a34a;
            --cp-plan-green-hover: #15803d;
            --cp-plan-blue: #2563eb;
            --cp-plan-blue-hover: #1d4ed8;
            --cp-plan-border: #e5e7eb;
            --cp-plan-muted: #6b7280;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        .cp-plan-context {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.875rem 1rem;
            border: 1px solid var(--cp-plan-border);
            border-radius: 0.75rem;
            background: #fff;
            padding: 1rem 1.125rem;
        }
        .dark .cp-plan-context {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
        }
        .cp-plan-context__field {
            min-width: 11rem;
        }
        .cp-plan-context__field--draft {
            flex: 1 1 16rem;
            min-width: 16rem;
        }
        .cp-plan-context__label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--cp-plan-muted);
        }
        .cp-plan-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            border-radius: 0.5rem;
            border: 0;
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1.25rem;
            padding: 0.625rem 1.125rem;
            cursor: pointer;
            transition: background-color .15s ease, opacity .15s ease;
            white-space: nowrap;
        }
        .cp-plan-btn:disabled,
        .cp-plan-btn.is-disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
        }
        .cp-plan-btn--publish {
            background: var(--cp-plan-green);
            color: #fff;
            min-height: 2.5rem;
            margin-left: auto;
        }
        .cp-plan-btn--publish:hover { background: var(--cp-plan-green-hover); }
        .cp-plan-btn--improve {
            background: var(--cp-plan-green);
            color: #fff;
            flex: 1 1 12rem;
            min-height: 2.625rem;
            width: 100%;
        }
        .cp-plan-btn--improve:hover { background: var(--cp-plan-green-hover); }
        .cp-plan-btn--create {
            background: var(--cp-plan-blue);
            color: #fff;
            /* Column card child: do not flex-grow or grid equal-height stretches the button. */
            flex: 0 0 auto;
            align-self: stretch;
            min-height: 2.625rem;
            width: 100%;
        }
        .cp-plan-btn--create:hover { background: var(--cp-plan-blue-hover); }
        .cp-plan-grid {
            display: grid;
            gap: 1rem;
            align-items: start;
        }
        @media (min-width: 1024px) {
            .cp-plan-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .cp-plan-card {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            border: 1px solid var(--cp-plan-border);
            border-radius: 0.875rem;
            background: #fff;
            padding: 1.125rem 1.25rem 1rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
        }
        .dark .cp-plan-card {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
        }
        .cp-plan-card--improve { border-top: 3px solid var(--cp-plan-green); }
        .cp-plan-card--create { border-top: 3px solid var(--cp-plan-blue); }
        .cp-plan-card__head {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .cp-plan-card__icon {
            display: inline-flex;
            height: 2.5rem;
            width: 2.5rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
        }
        .cp-plan-card__icon--improve {
            background: #ecfdf5;
            color: var(--cp-plan-green);
        }
        .cp-plan-card__icon--create {
            background: #eff6ff;
            color: var(--cp-plan-blue);
        }
        .dark .cp-plan-card__icon--improve {
            background: rgb(16 185 129 / 0.15);
            color: #6ee7b7;
        }
        .dark .cp-plan-card__icon--create {
            background: rgb(37 99 235 / 0.2);
            color: #93c5fd;
        }
        .cp-plan-card__title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 650;
            color: rgb(17 24 39);
        }
        .dark .cp-plan-card__title { color: rgb(243 244 246); }
        .cp-plan-card__help {
            margin: 0.2rem 0 0;
            font-size: 0.8125rem;
            line-height: 1.35;
            color: var(--cp-plan-muted);
        }
        .cp-plan-warn {
            margin: 0;
            font-size: 0.75rem;
            color: #b45309;
        }
        .dark .cp-plan-warn { color: #fcd34d; }
        .cp-plan-action-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.75rem;
        }
        .cp-plan-qty {
            width: 5.5rem;
            flex-shrink: 0;
        }
        .cp-plan-type {
            min-width: 8rem;
            max-width: 12rem;
            flex: 1 1 8rem;
        }
        .cp-plan-qty__label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--cp-plan-muted);
        }
        .cp-plan-meta {
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
        }
        .cp-plan-chips {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem;
        }
        .cp-plan-chips__label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--cp-plan-muted);
            margin-right: 0.125rem;
        }
        .cp-plan-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid var(--cp-plan-border);
            background: #f9fafb;
            padding: 0.2rem 0.625rem;
            font-size: 0.6875rem;
            font-weight: 500;
            color: #374151;
        }
        .dark .cp-plan-chip {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(31 41 55);
            color: #e5e7eb;
        }
        .cp-plan-link {
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
        }
        .cp-plan-link--improve { color: var(--cp-plan-green); }
        .cp-plan-link--create { color: var(--cp-plan-blue); }
        .cp-plan-link:hover { text-decoration: underline; }
        .cp-plan-filters-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #4b5563;
            cursor: pointer;
        }
        .cp-plan-filters-toggle:hover { color: #111827; }
        .cp-plan-filters-panel {
            border: 1px solid #f3f4f6;
            border-radius: 0.625rem;
            background: #f9fafb;
            padding: 0.875rem;
        }
        .dark .cp-plan-filters-panel {
            border-color: rgb(255 255 255 / 0.08);
            background: rgb(3 7 18 / 0.45);
        }
        .cp-plan-filters-grid {
            display: grid;
            gap: 0.875rem 1rem;
            grid-template-columns: minmax(0, 1fr);
        }
        @media (min-width: 640px) {
            .cp-plan-filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1024px) {
            .cp-plan-filters-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .cp-plan-filters-grid__field { min-width: 0; }
        .cp-plan-filters-grid__actions {
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            min-width: 0;
        }
        @media (min-width: 1024px) {
            .cp-plan-filters-grid__actions {
                grid-column: 3;
                grid-row: 3;
            }
        }
        .cp-plan-draft--full {
            width: 100%;
            max-width: none;
        }
        .cp-plan-draft-table-wrap {
            width: 100%;
            overflow-x: auto;
        }
        .cp-plan-inline-input,
        .cp-plan-inline-textarea {
            width: 100%;
            border-radius: 0.375rem;
            border: 1px solid #86efac;
            background: #fff;
            padding: 0.35rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.35;
            box-shadow: 0 0 0 2px rgb(16 185 129 / 0.15);
        }
        .dark .cp-plan-inline-input,
        .dark .cp-plan-inline-textarea {
            border-color: #34d399;
            background: rgb(17 24 39);
            color: #f3f4f6;
        }
        .cp-plan-inline-textarea { min-height: 4.5rem; resize: vertical; }
        .cp-plan-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 0.125rem 0.5rem;
            font-size: 0.6875rem;
            font-weight: 650;
            letter-spacing: 0.01em;
        }
        .cp-plan-badge--rewrite {
            background: #ecfdf5;
            color: #047857;
        }
        .cp-plan-badge--improve {
            background: #fffbeb;
            color: #b45309;
        }
        .cp-plan-badge--create {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .dark .cp-plan-badge--rewrite { background: rgb(16 185 129 / 0.15); color: #6ee7b7; }
        .dark .cp-plan-badge--improve { background: rgb(245 158 11 / 0.15); color: #fcd34d; }
        .dark .cp-plan-badge--create { background: rgb(37 99 235 / 0.2); color: #93c5fd; }
        .cp-plan-row-actions {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem;
        }
        .cp-plan-row-actions--under {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.15rem 0.65rem;
            margin-top: 0.4rem;
            font-size: 0.75rem;
            line-height: 1.35;
        }
        .cp-plan-row-action {
            display: inline;
            padding: 0;
            border: 0;
            background: transparent;
            color: #2563eb;
            font-size: inherit;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
        }
        .cp-plan-row-action:hover { text-decoration: underline; }
        .cp-plan-row-action--warn { color: #b45309; }
        .cp-plan-row-action--danger { color: #b91c1c; }
        .cp-plan-title-line {
            display: inline;
            font-size: 0.875rem;
            line-height: 1.35;
        }
        .cp-plan-seo-inline {
            font-size: 0.75rem;
            font-weight: 500;
            color: #9ca3af;
            white-space: nowrap;
        }
        .dark .cp-plan-seo-inline { color: #6b7280; }
        .cp-plan-icon-btn {
            display: inline-flex;
            height: 1.75rem;
            width: 1.75rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.375rem;
            color: #4b5563;
            transition: background-color 0.15s, color 0.15s;
        }
        .cp-plan-icon-btn:hover {
            background: #f3f4f6;
            color: #111827;
        }
        .cp-plan-icon-btn--warn:hover { color: #b45309; background: #fffbeb; }
        .cp-plan-icon-btn--danger:hover { color: #b91c1c; background: #fef2f2; }
        .dark .cp-plan-icon-btn { color: #9ca3af; }
        .dark .cp-plan-icon-btn:hover { background: rgb(255 255 255 / 0.08); color: #f3f4f6; }
        @media (max-width: 1100px) {
            .cp-plan-draft-table__col-added { display: none; }
        }
        @media (max-width: 900px) {
            .cp-plan-draft-table__col-post-type { display: none; }
        }
        .cp-plan-draft {
            border: 1px solid var(--cp-plan-border);
            border-radius: 0.875rem;
            background: #fff;
            overflow: hidden;
        }
        .dark .cp-plan-draft {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
        }
        .cp-plan-draft__head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
            padding: 0.875rem 1.125rem;
            border-bottom: 1px solid var(--cp-plan-border);
        }
        .dark .cp-plan-draft__head { border-bottom-color: rgb(255 255 255 / 0.08); }
        .cp-plan-draft__title {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 650;
            color: rgb(17 24 39);
        }
        .dark .cp-plan-draft__title { color: #f3f4f6; }
        .cp-plan-draft__badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            background: #ecfdf5;
            color: #15803d;
            padding: 0.125rem 0.55rem;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .dark .cp-plan-draft__badge {
            background: rgb(16 185 129 / 0.15);
            color: #6ee7b7;
        }
        .cp-plan-draft__body { padding: 0; }
        .cp-plan-draft__empty {
            padding: 1.5rem 1.125rem;
            text-align: left;
        }
        .cp-plan-draft-name {
            min-height: 2.5rem;
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid var(--cp-plan-border);
            background: #f9fafb;
            font-size: 0.875rem;
            font-weight: 500;
            color: #111827;
        }
        .dark .cp-plan-draft-name {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(3 7 18 / 0.4);
            color: #f3f4f6;
        }
        .cp-plan-segment {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            width: 100%;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: #fff;
            padding: 0.25rem;
        }
        .dark .cp-plan-segment {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
        }
        .cp-plan-segment__btn {
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            padding: 0.35rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4b5563;
            cursor: pointer;
        }
        .cp-plan-segment__btn:hover { background: #f3f4f6; }
        .cp-plan-segment__btn.is-active {
            background: #059669;
            color: #fff;
        }
        .dark .cp-plan-segment__btn { color: #9ca3af; }
        .dark .cp-plan-segment__btn:hover { background: rgb(255 255 255 / 0.06); }
        .dark .cp-plan-segment__btn.is-active { background: #059669; color: #fff; }
        .cp-plan-draft__tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            padding: 0.75rem 1.125rem 0;
        }
        .cp-plan-draft__tab {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid transparent;
            border-radius: 9999px;
            background: transparent;
            padding: 0.3rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
        }
        .cp-plan-draft__tab:hover { background: #f3f4f6; }
        .cp-plan-draft__tab.is-active {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .cp-plan-draft__tab-count {
            border-radius: 9999px;
            background: rgb(0 0 0 / 0.06);
            padding: 0 0.35rem;
            font-variant-numeric: tabular-nums;
        }
        .cp-plan-draft__tab.is-active .cp-plan-draft__tab-count { background: rgb(4 120 87 / 0.12); }
        .cp-plan-draft__type-filter {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1.125rem 0.75rem;
        }
        .cp-plan-chip.is-active {
            background: #059669;
            color: #fff;
            border-color: #059669;
        }
        .cp-plan-article-cell {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
        }
        .cp-plan-article-icon {
            display: inline-flex;
            height: 1.75rem;
            width: 1.75rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            margin-top: 0.1rem;
        }
        .cp-plan-article-icon--improve { background: #ecfdf5; color: #059669; }
        .cp-plan-article-icon--create { background: #eff6ff; color: #2563eb; }
        .cp-plan-article-icon--manual { background: #f3f4f6; color: #6b7280; }
        .cp-plan-review-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.375rem;
            border: 1px solid #e5e7eb;
            background: #fff;
            font-size: 0.875rem;
            font-weight: 700;
            color: #9ca3af;
            cursor: pointer;
        }
        .cp-plan-review-toggle.is-unreviewed:hover { border-color: #d1d5db; color: #6b7280; }
        .cp-plan-review-toggle.is-reviewed {
            border-color: #a7f3d0;
            background: #ecfdf5;
            color: #047857;
        }
    </style>
@endonce
