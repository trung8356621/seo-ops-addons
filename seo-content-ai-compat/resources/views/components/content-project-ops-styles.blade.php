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
        .cp-ops-col-gen, .cp-ops-col-life { width: 12%; }
        .cp-ops-col-domain { width: 13%; min-width: 7rem; padding-right: 0.75rem; white-space: nowrap; }
        .cp-ops-col-keywords { width: 16%; min-width: 7rem; padding-right: 0.75rem; }
        .cp-ops-col-workflow { width: 14%; min-width: 7rem; padding-left: 0.25rem; }
        .cp-ops-col-activity { width: 16%; }
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
        .cp-ops-dialog--split { max-width: 52rem; } /* ~832px two-pane */
        .cp-ops-dialog--clone { max-width: 32rem; }
        .cp-draft-split-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 1.25rem;
            align-items: start;
        }
        @media (max-width: 640px) {
            .cp-draft-split-layout { grid-template-columns: 1fr; }
        }
        .cp-draft-split-writers {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 18rem;
            overflow-y: auto;
        }
        .cp-draft-split-writer-row {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            padding: 0.625rem 0.75rem;
            background: #fff;
        }
        .dark .cp-draft-split-writer-row {
            border-color: rgb(255 255 255 / 0.1);
            background: transparent;
        }
        .cp-draft-split-writer-row.is-full,
        .cp-draft-split-writer-row.is-excluded {
            opacity: 0.72;
            background: rgb(249 250 251);
        }
        .dark .cp-draft-split-writer-row.is-full,
        .dark .cp-draft-split-writer-row.is-excluded {
            background: rgb(255 255 255 / 0.04);
        }
        .cp-draft-split-writer-metrics {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.375rem 0.5rem;
        }
        .cp-draft-split-metric {
            font-size: 0.6875rem;
            line-height: 1.25;
            font-variant-numeric: tabular-nums;
        }
        .cp-draft-split-metric--existing {
            color: rgb(107 114 128);
        }
        .dark .cp-draft-split-metric--existing {
            color: rgb(156 163 175);
        }
        .cp-draft-split-metric--new {
            color: rgb(37 99 235);
            font-weight: 600;
        }
        .dark .cp-draft-split-metric--new {
            color: rgb(96 165 250);
        }
        .cp-draft-split-metric--result {
            color: rgb(17 24 39);
            font-weight: 600;
        }
        .dark .cp-draft-split-metric--result {
            color: rgb(243 244 246);
        }
        .cp-draft-split-exclude {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.375rem;
            color: rgb(107 114 128);
        }
        .cp-draft-split-exclude:hover {
            background: rgb(243 244 246);
            color: rgb(17 24 39);
        }
        .dark .cp-draft-split-exclude:hover {
            background: rgb(255 255 255 / 0.08);
            color: rgb(243 244 246);
        }
        .cp-draft-split-add-back {
            flex-shrink: 0;
            font-size: 0.6875rem;
            font-weight: 600;
            color: rgb(37 99 235);
            white-space: nowrap;
        }
        .dark .cp-draft-split-add-back {
            color: rgb(96 165 250);
        }
        .cp-draft-split-excluded {
            margin-top: 0.25rem;
            padding-top: 0.5rem;
            border-top: 1px dashed rgb(229 231 235);
        }
        .dark .cp-draft-split-excluded {
            border-top-color: rgb(255 255 255 / 0.1);
        }
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
            --cp-plan-top-offset: 9.5rem;
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .cp-plan-scroll-workspace {
            --cp-plan-top-offset: 7rem;
            --cp-plan-snap-padding: 4.25rem;
        }
        .fi-main-ctn:has(.cp-plan-scroll-workspace) .fi-page {
            row-gap: 0;
            padding-top: 0.25rem;
        }
        .fi-main-ctn:has(.cp-plan-scroll-workspace) > .fi-header {
            padding-block: 0.35rem 0.25rem;
            min-height: 0;
        }
        .fi-main-ctn:has(.cp-plan-scroll-workspace) .fi-header-heading {
            display: none;
        }
        .cp-plan-snap-owner {
            scroll-padding-top: var(--cp-plan-snap-padding);
        }
        @media (min-width: 1024px) {
            .cp-plan-snap-owner {
                scroll-snap-type: y mandatory;
            }
        }
        @media (max-width: 1023px) {
            .cp-plan-snap-owner {
                scroll-snap-type: none;
            }
        }
        .cp-plan-slide {
            scroll-snap-align: start;
            scroll-snap-stop: normal;
            scroll-margin-top: var(--cp-plan-snap-padding);
            padding-block: 0.25rem;
        }
        .cp-plan-slide--create {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            height: calc(100dvh - var(--cp-plan-top-offset));
            max-height: calc(100dvh - var(--cp-plan-top-offset));
            min-height: 0;
            overflow: hidden;
            padding-bottom: 0.25rem;
            box-sizing: border-box;
        }
        @media (max-width: 1023px) {
            .cp-plan-slide--create {
                height: auto;
                max-height: none;
                overflow: visible;
            }
        }
        .cp-plan-slide--draft {
            padding-top: 0;
            padding-bottom: 1.25rem;
        }
        .cp-plan-slide__planner {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .cp-plan-slide__planner > .cp-plan-slide__grid {
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            overflow: hidden;
        }
        .cp-plan-slide__grid.cp-plan-grid,
        .cp-plan-slide--create .cp-plan-grid {
            flex: 1 1 auto;
            min-height: 0;
            height: 100%;
            --cp-plan-panel-max: 100%;
            align-items: stretch;
        }
        .cp-plan-section-jump {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            align-self: center;
            flex: 0 0 auto;
            margin: 0.25rem 0 0;
            padding: 0.35rem 0.75rem;
            border: 1px dashed var(--cp-plan-border);
            border-radius: 9999px;
            background: transparent;
            color: var(--cp-plan-muted);
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .cp-plan-section-jump:hover {
            color: #111827;
            border-color: #cbd5e1;
            background: #f9fafb;
        }
        .cp-plan-section-jump--up {
            align-self: flex-start;
            margin: 0 0 0.5rem;
        }
        .dark .cp-plan-section-jump:hover {
            color: #f3f4f6;
            border-color: rgb(255 255 255 / 0.2);
            background: rgb(255 255 255 / 0.05);
        }
        .cp-plan-draft-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
            justify-content: space-between;
        }
        .cp-plan-draft-header__title {
            min-width: 0;
            flex: 1 1 auto;
        }
        .cp-plan-draft-header__domain {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            flex: 0 1 auto;
            min-width: 0;
            max-width: 100%;
        }
        .cp-plan-draft-header__domain-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--cp-plan-muted);
            white-space: nowrap;
        }
        .dark .cp-plan-draft-header__domain-label { color: #9ca3af; }
        .cp-plan-draft-header__domain .cp-plan-inline-select--domain-filter {
            max-width: 12rem;
            min-width: 8rem;
        }
        .cp-plan-draft-header__publish {
            flex: 0 0 auto;
            margin-left: 0;
        }
        @media (min-width: 640px) {
            .cp-plan-draft-header__publish {
                margin-left: auto;
            }
        }
        .cp-plan-draft__title-count {
            font-weight: 600;
            color: var(--cp-plan-muted);
        }
        .dark .cp-plan-draft__title-count { color: #9ca3af; }
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
        .cp-plan-btn--danger {
            background: #dc2626;
            color: #fff;
            border: 1px solid #b91c1c;
            flex: 0 0 auto;
            min-height: 2.25rem;
        }
        .cp-plan-btn--danger:hover { background: #b91c1c; }
        .cp-plan-grid {
            display: grid;
            gap: 1rem;
            align-items: start;
            --cp-plan-panel-max: min(36rem, 70vh);
        }
        @media (min-width: 1024px) {
            .cp-plan-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                align-items: stretch;
                transition: grid-template-columns 0.2s ease;
            }
            .cp-plan-grid > .cp-plan-card {
                height: 100%;
                max-height: var(--cp-plan-panel-max);
                min-height: 0;
                overflow: hidden;
            }
            .cp-plan-slide--create .cp-plan-grid > .cp-plan-card {
                max-height: 100%;
                min-height: 0;
            }
            .cp-plan-slide--create .cp-plan-card--create {
                display: flex;
                flex-direction: column;
                min-height: 0;
            }
            .cp-plan-slide--create .cp-plan-card--create .cp-plan-tab-panels {
                flex: 1 1 auto;
                min-height: 0;
                overflow: hidden;
            }
            /* Gen AI focused: Improve card hidden; AI workspace full width */
            .cp-plan-grid.is-ai-focused {
                grid-template-columns: minmax(0, 1fr);
            }
            .cp-plan-grid.is-ai-focused > [data-planner-card="improve"] {
                display: none;
            }
        }
        /* Mobile / all breakpoints: hide Improve pane while AI focus */
        .cp-plan-grid.is-ai-focused > [data-planner-card="improve"] {
            display: none;
        }
        .cp-plan-card--with-sticky-cta {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .cp-plan-card--with-sticky-cta .cp-plan-card__scroll {
            flex: 1 1 auto;
        }
        .cp-plan-sticky-cta--improve .cp-plan-btn--improve {
            width: 100%;
            box-shadow: 0 8px 20px rgb(22 163 74 / 0.16);
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
        .cp-plan-card__scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgb(148 163 184 / 0.7) transparent;
        }
        .cp-plan-card__scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .cp-plan-card__scroll::-webkit-scrollbar-thumb {
            background: rgb(148 163 184 / 0.65);
            border-radius: 9999px;
        }
        .cp-plan-card__scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .dark .cp-plan-card__scroll {
            scrollbar-color: rgb(100 116 139 / 0.75) transparent;
        }
        .dark .cp-plan-card__scroll::-webkit-scrollbar-thumb {
            background: rgb(100 116 139 / 0.75);
        }
        .cp-idea-picker--embedded {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-width: 0;
            min-height: 0;
            height: 100%;
        }
        .cp-idea-picker--embedded .cp-idea-picker__scroll {
            flex: 1 1 auto;
        }
        .cp-idea-picker--embedded .cp-plan-btn--create {
            width: auto;
            flex: 0 0 auto;
            padding-inline: 0.875rem;
        }
        .cp-idea-row {
            position: relative;
        }
        .cp-idea-row__delete {
            flex-shrink: 0;
            margin-top: 0.15rem;
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: rgb(156 163 175);
            padding: 0.25rem;
            opacity: 0;
            cursor: pointer;
            transition: opacity 0.12s ease, color 0.12s ease, background 0.12s ease;
        }
        .cp-idea-row:hover .cp-idea-row__delete,
        .cp-idea-row:focus-within .cp-idea-row__delete,
        .cp-idea-row__delete:focus-visible {
            opacity: 1;
        }
        .cp-idea-row__delete:hover {
            color: rgb(220 38 38);
            background: rgb(254 226 226);
        }
        .dark .cp-idea-row__delete:hover {
            color: #fca5a5;
            background: rgb(127 29 29 / 0.35);
        }
        @media (hover: none) {
            .cp-idea-row__delete { opacity: 0.85; }
        }
        .cp-plan-create-body {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-width: 0;
            min-height: 0;
        }
        .cp-plan-create-body--sticky-cta {
            height: 100%;
            gap: 0;
        }
        .cp-plan-create-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
            padding-bottom: 0.5rem;
            scrollbar-width: thin;
        }
        .cp-plan-create-body--sticky-cta > .cp-plan-create-scroll {
            overflow: hidden;
        }
        .cp-plan-ai-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.5rem 0.75rem;
            flex: 0 0 auto;
        }
        .cp-plan-type--compact {
            min-width: 8rem;
        }
        .cp-plan-chips--compact {
            margin: 0;
        }
        .cp-plan-notes {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .cp-plan-notes > .cp-audit-notes,
        .cp-plan-notes > .cp-ai-topic-workspace {
            flex: 1 1 auto;
            min-height: 0;
        }
        .cp-plan-sticky-cta {
            flex: 0 0 auto;
            position: sticky;
            bottom: 0;
            z-index: 6;
            margin-top: auto;
            padding: 0.75rem 0 0.15rem;
            background: linear-gradient(to top, #fff 55%, rgb(255 255 255 / 0.92) 78%, rgb(255 255 255 / 0));
        }
        .dark .cp-plan-sticky-cta {
            background: linear-gradient(to top, rgb(17 24 39) 55%, rgb(17 24 39 / 0.92) 78%, rgb(17 24 39 / 0));
        }
        .cp-plan-sticky-cta .cp-plan-btn--create {
            width: 100%;
            box-shadow: 0 8px 20px rgb(37 99 235 / 0.18);
        }
        /* AI focus: Green · Content type · Blue */
        .cp-plan-sticky-cta__split {
            display: grid;
            grid-template-columns: 3fr 7fr;
            gap: 0.5rem;
            align-items: stretch;
        }
        .cp-plan-sticky-cta__split--with-type {
            grid-template-columns: minmax(0, 3fr) minmax(7.5rem, 2fr) minmax(0, 5fr);
        }
        .cp-plan-sticky-cta__split .cp-plan-btn {
            width: 100%;
            min-height: 2.75rem;
            height: 100%;
            justify-content: center;
            text-align: center;
        }
        .cp-plan-sticky-cta__split .cp-plan-btn--improve {
            box-shadow: 0 8px 20px rgb(22 163 74 / 0.16);
        }
        .cp-plan-sticky-cta__split .cp-plan-btn--create {
            box-shadow: 0 8px 20px rgb(37 99 235 / 0.18);
        }
        .cp-plan-sticky-cta__type {
            display: flex;
            align-items: stretch;
            min-width: 0;
        }
        .cp-plan-sticky-cta__type .cp-ops-select,
        .cp-plan-sticky-cta__type-select {
            width: 100%;
            align-self: stretch;
        }
        .cp-plan-sticky-cta__type-select select,
        .cp-plan-sticky-cta__type .cp-ops-select select {
            height: 100%;
            min-height: 2.75rem;
            width: 100%;
        }
        .cp-plan-sticky-cta__type-readonly {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 2.75rem;
            padding: 0 0.65rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
            white-space: nowrap;
        }
        .dark .cp-plan-sticky-cta__type-readonly {
            border-color: #4b5563;
            background: rgb(17 24 39);
            color: #e5e7eb;
        }
        .cp-plan-sticky-cta__balanced {
            display: grid;
            grid-template-columns: minmax(7.5rem, 1fr) minmax(0, 3fr);
            gap: 0.5rem;
            align-items: stretch;
        }
        .cp-plan-sticky-cta__balanced .cp-plan-btn--create {
            width: 100%;
        }
        @media (max-width: 640px) {
            .cp-plan-sticky-cta__split--with-type {
                grid-template-columns: 1fr;
            }
            .cp-plan-sticky-cta__balanced {
                grid-template-columns: 1fr;
            }
        }
        .cp-plan-tab-panel[data-create-panel="ai"].is-active {
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
        }
        .cp-plan-tab-panel[data-create-panel="ai"] .cp-plan-create-body {
            flex: 1 1 auto;
            min-height: 0;
        }
        .cp-plan-create-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            padding: 0.25rem;
            border-radius: 0.65rem;
            background: rgb(239 246 255);
            border: 1px solid rgb(191 219 254);
        }
        .dark .cp-plan-create-tabs {
            background: rgb(37 99 235 / 0.12);
            border-color: rgb(37 99 235 / 0.35);
        }
        .cp-plan-create-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 0;
            border-radius: 0.5rem;
            background: transparent;
            color: rgb(30 64 175);
            font-size: 0.8125rem;
            font-weight: 600;
            line-height: 1.2;
            padding: 0.45rem 0.7rem;
            cursor: pointer;
        }
        .dark .cp-plan-create-tab { color: #93c5fd; }
        .cp-plan-create-tab.is-active {
            background: #fff;
            color: rgb(29 78 216);
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
        }
        .dark .cp-plan-create-tab.is-active {
            background: rgb(17 24 39);
            color: #bfdbfe;
        }
        .cp-plan-create-tab--external {
            text-decoration: none;
            opacity: 0.88;
            font-weight: 500;
        }
        .cp-plan-create-tab--external:hover {
            opacity: 1;
        }
        .cp-plan-create-tab__badge {
            display: inline-flex;
            min-width: 1.35rem;
            justify-content: center;
            border-radius: 9999px;
            background: rgb(37 99 235 / 0.12);
            color: inherit;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.1rem 0.4rem;
            font-variant-numeric: tabular-nums;
        }
        .cp-plan-tab-panels {
            display: grid;
            flex: 1 1 auto;
            min-height: 0;
        }
        .cp-plan-tab-panel {
            grid-area: 1 / 1;
            min-width: 0;
            min-height: 0;
        }
        .cp-plan-tab-panel.is-inactive {
            visibility: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .cp-plan-tab-panel.is-active {
            visibility: visible;
            pointer-events: auto;
            z-index: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }
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
            line-height: 1.25;
            text-decoration: none;
            box-shadow: none;
            appearance: none;
            -webkit-appearance: none;
        }
        .cp-plan-link--improve { color: var(--cp-plan-green); }
        .cp-plan-link--create { color: var(--cp-plan-blue); }
        button.cp-plan-link.cp-plan-link--create,
        .cp-plan-clone-trigger {
            color: var(--cp-plan-blue);
            border: 0;
            background: transparent;
            box-shadow: none;
        }
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
        .cp-plan-draft-section {
            width: 100%;
            max-width: none;
        }
        .cp-plan-draft-table {
            width: 100%;
            table-layout: auto;
        }
        .cp-plan-draft-table-wrap {
            width: 100%;
            max-width: none;
            overflow-x: auto;
        }
        .cp-plan-draft__filters-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1.25rem;
            padding: 0.5rem 0 0.75rem;
        }
        .cp-plan-draft__bulk {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem 1rem;
            margin: 0 0 0.75rem;
            padding: 0.55rem 0.75rem;
            border: 1px solid #bbf7d0;
            border-radius: 0.5rem;
            background: #f0fdf4;
        }
        .dark .cp-plan-draft__bulk {
            border-color: rgb(6 78 59 / 0.55);
            background: rgb(6 78 59 / 0.2);
        }
        .cp-plan-draft__bulk-count {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #047857;
        }
        .dark .cp-plan-draft__bulk-count {
            color: #6ee7b7;
        }
        .cp-plan-draft__bulk-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }
        .cp-plan-inline-select--domain,
        .cp-plan-inline-select--domain-filter {
            max-width: 14rem;
            min-width: 9rem;
        }
        .cp-plan-draft-table__col-stt {
            width: 3.25rem;
            min-width: 2.75rem;
            max-width: 3.75rem;
            text-align: center;
            white-space: nowrap;
            vertical-align: top;
        }
        .cp-plan-draft-table__col-domain {
            min-width: 8rem;
            white-space: nowrap;
        }
        .cp-plan-draft-table__col-actions {
            min-width: 7rem;
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
        .cp-plan-inline-select {
            max-width: 7.5rem;
            width: 100%;
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            background-color: #fff;
            background-image: none !important;
            background-repeat: no-repeat !important;
            padding: 0.25rem 0.4rem;
            font-size: 0.75rem;
            line-height: 1.25;
            color: #374151;
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
        }
        .dark .cp-plan-inline-input,
        .dark .cp-plan-inline-textarea {
            border-color: #34d399;
            background: rgb(17 24 39);
            color: #f3f4f6;
        }
        .dark .cp-plan-inline-select {
            border-color: #4b5563;
            background: rgb(17 24 39);
            color: #e5e7eb;
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
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.125rem;
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
        .cp-plan-icon-btn {
            display: inline-flex;
            height: 1.75rem;
            width: 1.75rem;
            align-items: center;
            justify-content: center;
            border: 0;
            padding: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: #4b5563;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.15s, color 0.15s;
        }
        .cp-plan-icon-btn:hover {
            background: #f3f4f6;
            color: #111827;
        }
        .cp-plan-icon-btn--warn { color: #b45309; }
        .cp-plan-icon-btn--warn:hover { color: #b45309; background: #fffbeb; }
        .cp-plan-icon-btn--danger { color: #b91c1c; }
        .cp-plan-icon-btn--danger:hover { color: #b91c1c; background: #fef2f2; }
        .dark .cp-plan-icon-btn { color: #9ca3af; }
        .dark .cp-plan-icon-btn:hover { background: rgb(255 255 255 / 0.08); color: #f3f4f6; }
        .dark .cp-plan-icon-btn--warn { color: #fbbf24; }
        .dark .cp-plan-icon-btn--danger { color: #f87171; }
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

        .cp-audit-notes {
            display: flex;
            flex-direction: column;
            gap: 0;
            border: 1px solid var(--cp-plan-border);
            border-radius: 0.75rem;
            padding: 0;
            background: #fafafa;
            min-height: 0;
            flex: 1 1 auto;
            overflow: hidden;
            height: 100%;
        }
        .dark .cp-audit-notes {
            background: rgb(17 24 39 / 0.6);
        }
        .cp-ai-topic-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0;
            min-height: 0;
            height: 100%;
        }
        @media (min-width: 1024px) {
            .cp-ai-topic-workspace {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                align-items: stretch;
                gap: 0;
            }
            .cp-ai-topic-column--available {
                border-right: 1px solid var(--cp-plan-border);
                border-radius: 0.75rem 0 0 0.75rem;
            }
            .cp-ai-topic-column--selected {
                border-radius: 0 0.75rem 0.75rem 0;
            }
            .dark .cp-ai-topic-column--available {
                border-right-color: rgb(255 255 255 / 0.1);
            }
        }
        .cp-ai-topic-column {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            border: 0;
            border-radius: 0;
            background: #fff;
            overflow: hidden;
        }
        .dark .cp-ai-topic-column {
            border-color: transparent;
            background: rgb(3 7 18 / 0.35);
        }
        @media (max-width: 1023px) {
            .cp-ai-topic-column--available {
                border-bottom: 1px solid var(--cp-plan-border);
                max-height: 22rem;
            }
            .dark .cp-ai-topic-column--available {
                border-bottom-color: rgb(255 255 255 / 0.1);
            }
        }
        .cp-ai-topic-column__head {
            flex: 0 0 auto;
            padding: 0.625rem 0.75rem 0.5rem;
            border-bottom: 1px solid var(--cp-plan-border);
            background: #fff;
        }
        .dark .cp-ai-topic-column__head {
            border-bottom-color: rgb(255 255 255 / 0.08);
            background: rgb(17 24 39);
        }
        .cp-ai-topic-column__body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 0.5rem 0.75rem 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            scrollbar-width: thin;
        }
        .cp-ai-topic-column--available .cp-audit-notes__help {
            margin-bottom: 0.4rem;
        }
        .cp-ai-topic-column--available .cp-audit-notes__toolbar {
            margin-top: 0.35rem;
        }
        .cp-audit-notes__title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .dark .cp-audit-notes__title { color: #f3f4f6; }
        .cp-audit-notes__count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.375rem;
            border-radius: 9999px;
            background: #e5e7eb;
            font-size: 0.6875rem;
            font-weight: 600;
            color: #374151;
        }
        .dark .cp-audit-notes__count {
            background: rgb(55 65 81);
            color: #e5e7eb;
        }
        .cp-audit-notes__help {
            margin-top: 0.125rem;
            font-size: 0.6875rem;
            color: var(--cp-plan-muted);
        }
        .cp-audit-notes__toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .cp-audit-notes__search {
            flex: 1 1 10rem;
            min-width: 8rem;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            font-size: 0.8125rem;
            padding: 0.375rem 0.625rem;
            background: #fff;
        }
        .dark .cp-audit-notes__search {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(3 7 18);
            color: #e5e7eb;
        }
        .cp-audit-notes__list-wrap {
            position: relative;
            min-height: 8rem;
        }
        .cp-audit-notes__list-wrap.is-loading .cp-audit-notes__list {
            pointer-events: none;
        }
        .cp-audit-notes__list {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: none;
            overflow: visible;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .cp-audit-notes__row {
            position: relative;
            display: flex;
            align-items: stretch;
            border: 1px solid #e5e7eb;
            border-radius: 0.65rem;
            background: #fff;
            transition: border-color 0.15s ease, background 0.15s ease;
        }
        .dark .cp-audit-notes__row {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(3 7 18);
        }
        .cp-audit-notes__row.is-selected {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .dark .cp-audit-notes__row.is-selected {
            border-color: rgb(59 130 246 / 0.45);
            background: rgb(37 99 235 / 0.12);
        }
        .cp-audit-notes__check {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            flex: 1;
            padding: 0.65rem 0.75rem;
            cursor: pointer;
        }
        .cp-audit-notes__check input { margin-top: 0.15rem; flex: 0 0 auto; }
        .cp-audit-notes__row-body { min-width: 0; flex: 1; }
        .cp-audit-notes__name {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #111827;
            line-height: 1.3;
        }
        .dark .cp-audit-notes__name { color: #f3f4f6; }
        .cp-audit-notes__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.35rem;
        }
        .cp-audit-notes__pill {
            display: inline-flex;
            align-items: center;
            border-radius: 0.375rem;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 0.1rem 0.4rem;
            font-size: 0.6875rem;
            color: #4b5563;
            line-height: 1.3;
        }
        .dark .cp-audit-notes__pill {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(31 41 55);
            color: #d1d5db;
        }
        .cp-audit-notes__row-loading {
            display: none;
            align-items: center;
            padding-right: 0.65rem;
        }
        .cp-audit-notes__empty {
            padding: 0.75rem 0.5rem;
            font-size: 0.75rem;
            color: var(--cp-plan-muted);
            border: 1px dashed #e5e7eb;
            border-radius: 0.65rem;
            background: #fff;
        }
        .cp-audit-notes__skeleton {
            display: none;
            flex-direction: column;
            gap: 0.4rem;
        }
        [wire\:loading] .cp-audit-notes__skeleton,
        .cp-audit-notes__skeleton[style*="display"],
        .cp-audit-notes__list-wrap .cp-audit-notes__skeleton {
            /* Livewire toggles display */
        }
        .cp-audit-notes__skeleton-row {
            display: flex;
            gap: 0.65rem;
            align-items: flex-start;
            border: 1px solid #e5e7eb;
            border-radius: 0.65rem;
            background: #fff;
            padding: 0.65rem 0.75rem;
        }
        .dark .cp-audit-notes__skeleton-row {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(3 7 18);
        }
        .cp-audit-notes__skeleton-check {
            width: 0.9rem;
            height: 0.9rem;
            border-radius: 0.2rem;
            background: #e5e7eb;
            flex: 0 0 auto;
            margin-top: 0.15rem;
        }
        .cp-audit-notes__skeleton-body { flex: 1; min-width: 0; }
        .cp-audit-notes__skeleton-line {
            height: 0.55rem;
            border-radius: 9999px;
            background: #e5e7eb;
        }
        .dark .cp-audit-notes__skeleton-check,
        .dark .cp-audit-notes__skeleton-line { background: rgb(55 65 81); }
        .cp-audit-notes__skeleton-line--title { width: 42%; margin-bottom: 0.45rem; height: 0.7rem; }
        .cp-audit-notes__skeleton-line--meta { width: 68%; }
        .cp-audit-notes__selected-title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
            margin: 0;
        }
        .dark .cp-audit-notes__selected-title { color: #d1d5db; }
        .cp-audit-notes__selected-title-wrap {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.35rem 0.75rem;
            min-width: 0;
            flex: 1 1 auto;
        }
        .cp-audit-notes__ideas-total {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--cp-plan-blue);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .dark .cp-audit-notes__ideas-total { color: #93c5fd; }
        .cp-audit-notes__selected-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0;
        }
        .cp-audit-notes__selected-title-wrap {
            min-width: 0;
            flex: 1 1 auto;
        }
        .cp-audit-notes__selected-title {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.25rem 0.35rem;
            margin: 0;
            font-size: 0.8125rem;
            font-weight: 700;
            color: #111827;
        }
        .dark .cp-audit-notes__selected-title { color: #f3f4f6; }
        .cp-audit-notes__selected-sep {
            color: #9ca3af;
            font-weight: 500;
        }
        .cp-audit-notes__selected-actions {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 0.35rem 0.5rem;
            flex: 0 0 auto;
        }
        .cp-audit-notes__icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            background: #fff;
            color: #4b5563;
            cursor: pointer;
        }
        .cp-audit-notes__icon-btn:hover {
            border-color: #bfdbfe;
            color: #1d4ed8;
            background: #eff6ff;
        }
        .dark .cp-audit-notes__icon-btn {
            border-color: #4b5563;
            background: rgb(17 24 39);
            color: #d1d5db;
        }
        .cp-audit-notes__clear-all {
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 0.75rem;
            font-weight: 600;
            color: #dc2626;
            cursor: pointer;
            white-space: nowrap;
        }
        .cp-audit-notes__clear-all:hover { color: #b91c1c; text-decoration: underline; }
        .cp-audit-notes__selected-empty {
            margin: 0.25rem 0 0;
            font-size: 0.75rem;
            color: var(--cp-plan-muted);
        }
        .cp-audit-notes__add-topic {
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--cp-plan-blue);
            cursor: pointer;
            white-space: nowrap;
        }
        .cp-audit-notes__manual-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            align-items: flex-end;
            margin-bottom: 0.65rem;
        }
        .cp-audit-notes__manual-seed-label {
            flex: 1 1 12rem;
            min-width: 0;
        }
        .cp-audit-notes__manual-seed-label .cp-audit-notes__dna-input {
            width: 100%;
            margin-top: 0.2rem;
        }
        .cp-audit-notes__manual-cancel {
            border: 0;
            background: transparent;
            padding: 0.15rem 0.35rem;
            font-size: 1.15rem;
            line-height: 1;
            color: #6b7280;
            cursor: pointer;
        }
        .cp-audit-notes__manual-cancel:hover {
            color: #111827;
        }
        .dark .cp-audit-notes__manual-cancel:hover {
            color: #f3f4f6;
        }
        .cp-audit-notes__item {
            border: 1px solid var(--cp-plan-border);
            border-radius: 0.65rem;
            padding: 0.55rem 0.65rem 0.6rem;
            margin-bottom: 0;
            background: #fff;
            transition: opacity 0.15s ease;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }
        .dark .cp-audit-notes__item {
            background: rgb(3 7 18);
        }
        .cp-audit-notes__item-head {
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            align-items: flex-start;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid #f3f4f6;
            margin-bottom: 0;
        }
        .dark .cp-audit-notes__item-head { border-bottom-color: rgb(255 255 255 / 0.08); }
        .cp-audit-notes__remove,
        .cp-audit-notes__dna-remove {
            border: 0;
            background: transparent;
            color: #9ca3af;
            font-size: 1rem;
            line-height: 1;
            cursor: pointer;
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
        }
        .cp-audit-notes__remove:hover,
        .cp-audit-notes__dna-remove:hover {
            color: #dc2626;
            background: #fef2f2;
        }
        .cp-audit-notes__dna {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .cp-audit-notes__dna-row {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            padding: 0.3rem 0.45rem;
            border: 1px solid #f3f4f6;
            border-radius: 0.5rem;
            background: #f9fafb;
        }
        .dark .cp-audit-notes__dna-row {
            border-color: rgb(255 255 255 / 0.08);
            background: rgb(17 24 39);
        }
        .cp-audit-notes__dna-weight {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: #1d4ed8;
            background: #dbeafe;
            border-radius: 0.3rem;
            padding: 0.05rem 0.35rem;
            min-width: 2.1rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.6875rem;
        }
        .dark .cp-audit-notes__dna-weight {
            color: #93c5fd;
            background: rgb(37 99 235 / 0.25);
        }
        .cp-audit-notes__dna-phrase { flex: 1; color: #111827; min-width: 0; }
        .dark .cp-audit-notes__dna-phrase { color: #e5e7eb; }
        .cp-audit-notes__dna-placement {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            flex-shrink: 0;
            font-size: 0.6875rem;
            font-weight: 600;
            color: #4b5563;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        .dark .cp-audit-notes__dna-placement { color: #9ca3af; }
        .cp-audit-notes__dna-placement-check {
            width: 0.85rem;
            height: 0.85rem;
            margin: 0;
            accent-color: var(--cp-plan-blue);
            cursor: pointer;
        }
        .cp-audit-notes__add-dna {
            margin-top: 0.55rem;
            border: 0;
            background: transparent;
            padding: 0;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--cp-plan-blue);
            cursor: pointer;
        }
        .cp-audit-notes__dna-dup {
            border: 0;
            background: transparent;
            color: #6b7280;
            font-size: 0.85rem;
            line-height: 1;
            padding: 0 0.2rem;
            cursor: pointer;
        }
        .cp-audit-notes__dna-dup:hover { color: var(--cp-plan-blue); }
        .cp-audit-notes__target-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.4rem;
        }
        .cp-audit-notes__target-mode {
            font-size: 0.7rem;
            font-weight: 600;
            color: #6b7280;
            padding: 0.15rem 0.4rem;
            border-radius: 0.3rem;
            background: rgb(243 244 246);
        }
        .cp-audit-notes__target-mode.is-manual {
            color: #1d4ed8;
            background: rgb(219 234 254);
        }
        .dark .cp-audit-notes__target-mode {
            background: rgb(31 41 55);
            color: #9ca3af;
        }
        .dark .cp-audit-notes__target-mode.is-manual {
            background: rgb(30 58 138 / 0.35);
            color: #93c5fd;
        }
        .cp-audit-notes__target-reset {
            border: 0;
            background: transparent;
            color: var(--cp-plan-blue);
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
        }
        .cp-audit-notes__alloc-warn {
            margin: 0.4rem 0 0.55rem;
            font-size: 0.75rem;
            color: #b45309;
        }
        .dark .cp-audit-notes__alloc-warn { color: #fbbf24; }
        .cp-audit-notes__target-row {
            margin-top: 0.45rem;
        }
        .cp-audit-notes__slot-summary {
            margin: 0.3rem 0 0.45rem;
            font-size: 0.72rem;
            color: #6b7280;
        }
        .dark .cp-audit-notes__slot-summary { color: #9ca3af; }
        .cp-audit-notes__mode-toggle {
            display: inline-flex;
            gap: 0.25rem;
            margin: 0.55rem 0 0.35rem;
            padding: 0.15rem;
            border-radius: 0.45rem;
            background: rgb(243 244 246);
        }
        .dark .cp-audit-notes__mode-toggle {
            background: rgb(31 41 55);
        }
        .cp-audit-notes__mode-btn {
            border: 0;
            background: transparent;
            color: #4b5563;
            font-size: 0.72rem;
            font-weight: 600;
            line-height: 1.2;
            padding: 0.3rem 0.55rem;
            border-radius: 0.35rem;
            cursor: pointer;
        }
        .dark .cp-audit-notes__mode-btn { color: #9ca3af; }
        .cp-audit-notes__mode-btn.is-active {
            background: #fff;
            color: #111827;
            box-shadow: 0 0 0 1px rgb(0 0 0 / 0.06);
        }
        .dark .cp-audit-notes__mode-btn.is-active {
            background: rgb(55 65 81);
            color: #f3f4f6;
            box-shadow: none;
        }
        .cp-audit-notes__auto-panel,
        .cp-audit-notes__manual-panel {
            margin-top: 0.25rem;
        }
        .cp-audit-notes__field-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.72rem;
            font-weight: 600;
            color: #4b5563;
        }
        .dark .cp-audit-notes__field-label { color: #9ca3af; }
        .cp-audit-notes__auto-help {
            margin: 0.35rem 0 0;
            font-size: 0.72rem;
            line-height: 1.35;
            color: #6b7280;
        }
        .cp-audit-notes__auto-help--muted { opacity: 0.9; }
        .dark .cp-audit-notes__auto-help { color: #9ca3af; }
        .cp-audit-notes__dna--hints { margin-top: 0.4rem; opacity: 0.85; }
        .cp-audit-notes__dna-row--hint { border-style: dashed; }
        .cp-audit-notes__dna-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            margin-top: 0.55rem;
        }
        .cp-audit-notes__dna-input {
            flex: 1 1 8rem;
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            font-size: 0.75rem;
            padding: 0.35rem 0.5rem;
        }
        .cp-audit-notes__dna-weight-input {
            width: 4.5rem;
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            font-size: 0.75rem;
            padding: 0.35rem 0.5rem;
        }
        .dark .cp-audit-notes__dna-input,
        .dark .cp-audit-notes__dna-weight-input {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
            color: #e5e7eb;
        }
        .cp-audit-notes__pager {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.75rem;
        }
        .cp-audit-notes__page-btn {
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 0.375rem;
            width: 1.75rem;
            height: 1.75rem;
            line-height: 1;
            cursor: pointer;
        }
        .cp-audit-notes__page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .dark .cp-audit-notes__page-btn {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(3 7 18);
            color: #e5e7eb;
        }
        .cp-audit-notes__page-label { color: var(--cp-plan-muted); }
    </style>
@endonce
