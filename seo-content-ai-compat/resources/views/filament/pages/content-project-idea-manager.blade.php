<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />
    @once
        <style>
            .im-root { display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
            .im-stepbar {
                position: sticky; top: 0.5rem; z-index: 10;
                display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
                gap: 0.65rem; padding: 0.55rem 0.75rem;
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .dark .im-stepbar { border-color: #374151; background: #111827; }
            .im-stepbar__left { display: flex; flex-wrap: wrap; align-items: center; gap: 0.55rem; min-width: 0; }
            .im-stepbar__label { font-size: 0.75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .02em; }
            .im-stepbar__hint { font-size: 0.72rem; color: #6b7280; }
            .im-status {
                display: inline-flex; align-items: center; border-radius: 9999px;
                padding: 0.2rem 0.55rem; font-size: 0.7rem; font-weight: 600;
            }
            .im-status--editing { background: #fef3c7; color: #92400e; }
            .im-status--saving { background: #e0f2fe; color: #075985; }
            .im-status--synced { background: #dcfce7; color: #166534; }
            .im-status--error { background: #ffe4e6; color: #9f1239; }
            .dark .im-status--editing { background: rgba(146,64,14,.35); color: #fde68a; }
            .dark .im-status--saving { background: rgba(7,89,133,.35); color: #bae6fd; }
            .dark .im-status--synced { background: rgba(22,101,52,.35); color: #bbf7d0; }
            .dark .im-status--error { background: rgba(159,18,57,.35); color: #fecdd3; }

            .im-layout {
                display: grid;
                grid-template-columns: minmax(14rem, 18rem) minmax(0, 1.15fr) minmax(0, 1fr);
                gap: 0.75rem;
                align-items: stretch;
                min-width: 0;
            }
            .im-layout.is-collapsed {
                grid-template-columns: 2.75rem minmax(0, 1.15fr) minmax(0, 1fr);
            }
            .im-side, .im-main, .im-right {
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff;
                min-width: 0; min-height: 36rem; display: flex; flex-direction: column;
                height: calc(100vh - 6.5rem); overflow: hidden;
            }
            .dark .im-side, .dark .im-main, .dark .im-right { border-color: #374151; background: #111827; }
            .im-side__head, .im-main__head, .im-right__head {
                display: flex; align-items: center; justify-content: space-between; gap: 0.4rem;
                padding: 0.5rem 0.7rem; border-bottom: 1px solid #e5e7eb; flex: 0 0 auto;
            }
            .dark .im-side__head, .dark .im-main__head, .dark .im-right__head { border-color: #374151; }
            .im-side__title, .im-main__title, .im-right__title { font-size: 0.8125rem; font-weight: 700; color: #111827; }
            .dark .im-side__title, .dark .im-main__title, .dark .im-right__title { color: #f9fafb; }
            .im-side__body { padding: 0; overflow: hidden; flex: 1 1 0; min-height: 0; display: flex; flex-direction: column; }
            .im-side__controls { flex: 0 0 auto; padding: 0.5rem 0.65rem; border-bottom: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 0.4rem; }
            .dark .im-side__controls { border-color: #374151; }
            .im-side__list { flex: 1 1 0; min-height: 0; overflow-y: auto; padding: 0.65rem; }
            .im-main__body { padding: 0; overflow: hidden; flex: 1 1 0; min-height: 0; display: flex; flex-direction: column; }
            .im-main__shell { display: flex; flex-direction: column; flex: 1 1 0; min-height: 0; overflow: hidden; }
            .im-main__sticky { flex: 0 0 auto; padding: 0.45rem 0.65rem; border-bottom: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 0.35rem; background: #fff; }
            .dark .im-main__sticky { border-color: #374151; background: #111827; }
            .im-main__list { flex: 1 1 0; min-height: 0; overflow-y: auto; padding: 0.65rem; }
            .im-right__body { padding: 0.55rem 0.65rem; overflow-y: auto; flex: 0 1 44%; min-height: 9rem; }
            .im-right__body thead th { position: sticky; top: 0; z-index: 2; background: #fff; }
            .dark .im-right__body thead th { background: #111827; }
            .im-right__foot {
                flex: 1 1 56%;
                min-height: 12rem;
                padding: 0;
                border-top: 1px solid #e5e7eb;
                background: #fff;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            .dark .im-right__foot { border-color: #374151; background: #111827; }
            .im-dist { display: flex; flex-direction: column; flex: 1 1 0; min-height: 0; overflow: hidden; }
            .im-dist__head { flex: 0 0 auto; padding: 0.55rem 0.65rem 0.45rem; border-bottom: 1px solid #f3f4f6; display: flex; flex-direction: column; gap: 0.35rem; }
            .dark .im-dist__head { border-color: #1f2937; }
            .im-dist__scroll { flex: 1 1 0; min-height: 0; overflow-y: auto; padding: 0.4rem 0.65rem; }
            .im-dist__actions { flex: 0 0 auto; padding: 0.5rem 0.65rem; border-top: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 0.35rem; background: #fff; }
            .dark .im-dist__actions { border-color: #374151; background: #111827; }
            .im-side.is-collapsed .im-side__body, .im-side.is-collapsed .im-side__title { display: none; }

            .im-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; }
            .im-input {
                border: 1px solid #d1d5db; border-radius: 0.55rem; padding: 0.4rem 0.6rem;
                font-size: 0.8125rem; background-color: #fff; color: #111827; min-width: 8rem;
            }
            .dark .im-input { border-color: #4b5563; background-color: #0f172a; color: #f8fafc; }
            /* Kill stacked Filament/native select arrows (repeating chevrons). */
            select.im-input {
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M6 8l4 4 4-4' stroke='%236b7280' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
                background-repeat: no-repeat !important;
                background-position: right 0.55rem center !important;
                background-size: 0.9rem 0.9rem !important;
                padding-right: 2rem !important;
            }
            .dark select.im-input {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M6 8l4 4 4-4' stroke='%239ca3af' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
            }
            .im-btn {
                display: inline-flex; align-items: center; justify-content: center; gap: 0.3rem;
                border-radius: 0.55rem; border: 1px solid transparent; padding: 0.4rem 0.7rem;
                font-size: 0.78rem; font-weight: 600; cursor: pointer; line-height: 1.2;
            }
            .im-btn:disabled { opacity: .55; cursor: not-allowed; }
            .im-btn--primary { background: #2563eb; color: #fff; }
            .im-btn--primary:hover { background: #1d4ed8; }
            .im-btn--success { background: #16a34a; color: #fff; }
            .im-btn--ghost { background: #f3f4f6; color: #111827; border-color: #e5e7eb; }
            .im-btn--danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
            .dark .im-btn--ghost { background: #1f2937; color: #e5e7eb; border-color: #374151; }
            .dark .im-btn--danger { background: rgba(190,18,60,.2); color: #fecdd3; border-color: #9f1239; }

            .im-banner {
                border-radius: 0.65rem; padding: 0.65rem 0.75rem; border: 1px solid #bfdbfe;
                background: #eff6ff; display: flex; flex-wrap: wrap; gap: 0.55rem; align-items: center; justify-content: space-between;
                margin-bottom: 0.55rem;
            }
            .dark .im-banner { border-color: #1e3a8a; background: rgba(30,58,138,.35); }
            .im-banner__text { font-size: 0.8125rem; color: #1e3a8a; font-weight: 600; }
            .dark .im-banner__text { color: #bfdbfe; }
            .im-banner__sub { font-size: 0.72rem; color: #64748b; font-weight: 400; margin-top: 0.1rem; }

            .im-empty {
                border: 1px dashed #d1d5db; border-radius: 0.65rem; padding: 1.25rem 0.85rem;
                text-align: center; color: #6b7280; font-size: 0.8125rem; background: #fafafa;
            }
            .dark .im-empty { border-color: #4b5563; background: #0b1220; color: #9ca3af; }

            .im-debug {
                border: 1px solid #f59e0b; border-radius: 0.65rem; background: #fffbeb;
                padding: 0.55rem 0.7rem; margin-bottom: 0.65rem; font-size: 0.72rem; color: #78350f;
            }
            .dark .im-debug { border-color: #b45309; background: rgba(120,53,15,.25); color: #fde68a; }
            .im-debug summary { cursor: pointer; font-weight: 700; }
            .im-debug__grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
                gap: 0.35rem 0.75rem; margin-top: 0.45rem;
            }
            .im-debug__k { opacity: 0.75; }
            .im-debug__v { font-weight: 650; font-variant-numeric: tabular-nums; }
            .im-debug__hist { margin-top: 0.45rem; max-height: 9rem; overflow: auto; font-family: ui-monospace, monospace; }
            .im-debug__hist div { white-space: nowrap; }

            .im-card {
                border: 1px solid #e5e7eb; border-radius: 0.65rem; background: #fff; padding: 0.7rem 0.8rem;
                display: flex; flex-direction: column; gap: 0.45rem;
            }
            .dark .im-card { border-color: #374151; background: #0f172a; }
            .im-card.is-selected { border-color: #93c5fd; box-shadow: 0 0 0 1px #93c5fd inset; }
            .im-card__top { display: flex; flex-wrap: wrap; gap: 0.55rem; justify-content: space-between; align-items: flex-start; }
            .im-card__title { font-size: 0.875rem; font-weight: 650; color: #111827; word-break: break-word; }
            .dark .im-card__title { color: #f8fafc; }
            .im-card__meta { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.25rem; }
            .im-pill {
                display: inline-flex; align-items: center; border-radius: 9999px; padding: 0.12rem 0.45rem;
                font-size: 0.68rem; font-weight: 600; background: #f3f4f6; color: #374151;
            }
            .dark .im-pill { background: #1f2937; color: #d1d5db; }
            .im-pill--blue { background: #eff6ff; color: #1d4ed8; }
            .im-pill--green { background: #ecfdf5; color: #047857; }
            .dark .im-pill--blue { background: rgba(29,78,216,.25); color: #93c5fd; }
            .dark .im-pill--green { background: rgba(4,120,87,.25); color: #6ee7b7; }
            .im-card__controls { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
            .im-target { width: 4rem; }
            .im-dna-row { display: flex; flex-wrap: wrap; gap: 0.3rem; align-items: center; }
            .im-dna-chip {
                display: inline-flex; align-items: center; gap: 0.25rem; border-radius: 9999px;
                background: #f3f4f6; padding: 0.12rem 0.4rem; font-size: 0.7rem; color: #111827;
            }
            .dark .im-dna-chip { background: #1f2937; color: #e5e7eb; }
            .im-dna-chip button { border: 0; background: transparent; color: #be123c; cursor: pointer; font-weight: 700; }

            .im-cluster-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.35rem; }
            .im-cluster-row {
                display: flex; gap: 0.5rem; align-items: center; justify-content: space-between;
                border: 1px solid #e5e7eb; border-radius: 0.55rem; padding: 0.5rem 0.55rem; background: #fff;
            }
            .dark .im-cluster-row { border-color: #374151; background: #0f172a; }
            .im-cluster-row__body { min-width: 0; flex: 1; }
            .im-cluster-row__name { font-size: 0.8rem; font-weight: 650; color: #111827; }
            .dark .im-cluster-row__name { color: #f8fafc; }
            .im-cluster-row__meta { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.2rem; }

            .im-conflict { border-color: #fcd34d; background: #fffbeb; padding: 0.65rem; border-radius: 0.65rem; margin-bottom: 0.55rem; }
            .dark .im-conflict { border-color: #92400e; background: rgba(146,64,14,.25); }

            .im-dist {
                display: flex; flex-direction: column; gap: 0.45rem;
            }
            .im-dist__blocked {
                border: 1px solid #fcd34d; background: #fffbeb; color: #92400e;
                border-radius: 0.55rem; padding: 0.55rem 0.65rem; font-size: 0.75rem; line-height: 1.35;
            }
            .dark .im-dist__blocked { border-color: #92400e; background: rgba(146,64,14,.25); color: #fde68a; }
            .im-dist__meta { font-size: 0.78rem; color: #374151; display: grid; gap: 0.2rem; }
            .dark .im-dist__meta { color: #d1d5db; }
            .im-dist__preview { font-size: 0.75rem; list-style: none; margin: 0; padding: 0; }
            .im-dist__preview li { padding: 0.25rem 0; border-bottom: 1px solid #f3f4f6; display: flex; gap: 0.4rem; align-items: flex-start; justify-content: space-between; }
            .dark .im-dist__preview li { border-color: #1f2937; }
            .im-dist__preview li.is-dup { color: #b45309; }
            .im-dist__preview li.is-warn { color: #a16207; }
            .im-dist__dup { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.68rem; font-weight: 700; color: #b45309; }
            .im-dist__warn { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.68rem; font-weight: 600; color: #a16207; }
            .im-dist__result { font-size: 0.75rem; border-radius: 0.45rem; padding: 0.45rem 0.55rem; background: #ecfdf5; color: #047857; }
            .dark .im-dist__result { background: rgba(4,120,87,.2); color: #6ee7b7; }
            .im-dist__result.is-error { background: #fff7ed; color: #c2410c; }
            .dark .im-dist__result.is-error { background: rgba(194,65,12,.2); color: #fdba74; }
            .im-dist__hint { font-size: 0.7rem; color: #6b7280; }

            @media (max-width: 1200px), (max-height: 680px) {
                .im-layout, .im-layout.is-collapsed {
                    grid-template-columns: minmax(12rem, 15rem) minmax(0, 1fr);
                }
                .im-side, .im-main { height: calc(100vh - 6.5rem); min-height: 34rem; overflow: hidden; }
                .im-right { grid-column: 1 / -1; height: auto; min-height: 0; max-height: 44rem; overflow: hidden; }
                .im-right__body { flex: 0 0 auto; max-height: 18rem; }
                .im-right__foot { flex: 1 1 0; min-height: 12rem; }
            }
            @media (max-width: 720px) {
                .im-layout, .im-layout.is-collapsed { grid-template-columns: 1fr; }
                .im-side, .im-main { height: 70vh; min-height: 22rem; overflow: hidden; }
                .im-right { height: auto; min-height: 0; max-height: none; overflow: hidden; }
                .im-input { min-width: 100%; width: 100%; }
            }
        </style>
    @endonce

    @php
        $site = $this->workingSitePayload;
        $sourceFilter = $this->sourceFilterPayload;
        $server = is_array($this->workspaceSnapshot) ? $this->workspaceSnapshot : null;
        $plannerUrl = \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl();
        $dist = $this->distributionContext();
        $monthOptions = $this->activeMonthOptions;
        $sourceOptions = $this->sourceSiteOptions;
    @endphp

    <div class="im-root">
        {{-- Step 1 toolbar: Active Month (Livewire) --}}
        <div class="im-stepbar" data-im-step1="1">
            <div class="im-stepbar__left">
                <span class="im-stepbar__label">{{ __('seo-content-ai::filament.projects.idea_manager_step1') }}</span>
                <label class="text-xs font-semibold text-gray-600 dark:text-gray-300" for="im-active-month">
                    {{ __('seo-content-ai::filament.projects.idea_manager_active_month') }}
                </label>
                <select
                    id="im-active-month"
                    class="im-input"
                    style="min-width:8.5rem"
                    wire:model.live="activeMonth"
                >
                    @foreach ($monthOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <span class="im-stepbar__hint">{{ __('seo-content-ai::filament.projects.idea_manager_active_month_hint') }}</span>
            </div>
            <div class="im-actions" id="im-sync-slot"></div>
        </div>

        <div
            class="im-layout"
            :class="{ 'is-collapsed': sidebarCollapsed }"
            x-data="{
                sidebarCollapsed: @js((bool) $this->sidebarCollapsed),
                selectedCount: 0,
                selectedItems: [],
                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    $wire.toggleSidebar();
                },
            }"
            x-on:im-selection-changed.window="
                selectedCount = Number(($event.detail && $event.detail.count) || 0);
                selectedItems = ($event.detail && $event.detail.items) || [];
            "
        >
            {{-- Sidebar: Cluster sources --}}
            <aside class="im-side" :class="{ 'is-collapsed': sidebarCollapsed }" data-im-sidebar="1">
                <div class="im-side__head">
                    <div class="im-side__title">{{ __('seo-content-ai::filament.projects.idea_manager_sidebar_title') }}</div>
                    <button type="button" class="im-btn im-btn--ghost" x-on:click="toggleSidebar()" title="{{ __('seo-content-ai::filament.projects.idea_manager_sidebar_toggle') }}">
                        <span x-text="sidebarCollapsed ? '»' : '«'"></span>
                    </button>
                </div>
                <div class="im-side__body" x-show="!sidebarCollapsed">
                    <div class="im-side__controls">
                        <label class="text-xs font-semibold text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_source_domain') }}</label>
                        <select
                            class="im-input w-full"
                            style="width:100%;min-width:0"
                            wire:change="setSourceSite(Number($event.target.value))"
                        >
                            @foreach ($sourceOptions as $opt)
                                <option value="{{ $opt['id'] }}" @selected((int) ($sourceFilter['site_id'] ?? 0) === (int) $opt['id'])>
                                    {{ $opt['domain'] }}
                                </option>
                            @endforeach
                        </select>

                        <div class="im-actions" style="width:100%">
                            <input type="search" class="im-input" style="flex:1;min-width:0;width:100%" wire:model="clusterSearch" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_cluster_search') }}" />
                            <button type="button" class="im-btn im-btn--ghost" wire:click="applyClusterSearch">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_apply') }}</button>
                        </div>
                        <select class="im-input w-full" style="width:100%;min-width:0" wire:model.live="clusterFilter">
                            <option value="all">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_all') }}</option>
                            <option value="mcp_low">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_mcp_low') }}</option>
                            <option value="has_focus">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_has_focus') }}</option>
                            <option value="no_focus">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_no_focus') }}</option>
                        </select>
                        <div wire:loading.flex wire:target="loadClusterSuggestions,applyClusterSearch,gotoClusterPage,updatedClusterFilter,setSourceSite" class="text-xs text-gray-500">
                            {{ __('seo-content-ai::filament.projects.idea_manager_cluster_loading') }}
                        </div>
                    </div>

                    <div class="im-side__list">
                        {{-- Cluster list driven by Alpine warehouse root via window events; mirror Livewire rows for first paint --}}
                    <div
                        wire:ignore
                        x-data="ideaManagerClusterBridge({
                            rows: @js($this->clusterRows),
                            total: {{ (int) $this->clusterTotal }},
                        })"
                        x-on:idea-manager-cluster-rows.window="onRows($event.detail)"
                    >
                        <template x-if="(rows || []).length === 0">
                            <div class="im-empty" style="padding:0.85rem">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_empty') }}</div>
                        </template>
                        <ul class="im-cluster-list">
                            <template x-for="row in rows" :key="row.cluster_ref">
                                <li class="im-cluster-row">
                                    <div class="im-cluster-row__body">
                                        <div class="im-cluster-row__name" x-text="row.cluster_name"></div>
                                        <div class="im-cluster-row__meta">
                                            <span class="im-pill" x-text="'MCP ' + Number(row.mcp_share || 0).toFixed(1) + '%'"></span>
                                            <span class="im-pill im-pill--blue" x-text="'DNA ' + Number(row.dna_count || 0)"></span>
                                        </div>
                                    </div>
                                    <button type="button" class="im-btn im-btn--primary" x-on:click="window.dispatchEvent(new CustomEvent('im-add-cluster', { detail: row }))">
                                        {{ __('seo-content-ai::filament.projects.idea_manager_cluster_add') }}
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <div class="im-actions text-xs mt-2" x-show="total > 25">
                            <button type="button" class="im-btn im-btn--ghost" wire:click="gotoClusterPage({{ max(1, (int) $this->clusterPage - 1) }})">Prev</button>
                            <span class="text-gray-500" x-text="'Total: ' + total"></span>
                            <button type="button" class="im-btn im-btn--ghost" wire:click="gotoClusterPage({{ (int) $this->clusterPage + 1 }})">Next</button>
                        </div>
                    </div>
                        <p class="text-[11px] text-gray-400 mt-2">{{ __('seo-content-ai::filament.projects.idea_manager_published_note') }}</p>
                    </div>
                </div>
            </aside>

            {{-- Kho Idea (Alpine, wire:ignore — preserves unsynced draft) --}}
            <section class="im-main" data-im-warehouse="1">
                <div class="im-main__head">
                    <div class="im-main__title">{{ __('seo-content-ai::filament.projects.idea_manager_warehouse_title') }}</div>
                </div>
                <div
                    class="im-main__body"
                    wire:ignore
                    x-data="ideaManagerRoot({
                        siteId: {{ (int) ($site['site_id'] ?? 0) }},
                        siteDomain: @js((string) ($site['domain'] ?? '')),
                        sourceFilterSiteId: {{ (int) ($sourceFilter['site_id'] ?? 0) }},
                        sourceFilterDomain: @js((string) ($sourceFilter['domain'] ?? '')),
                        server: @js($server),
                        debugEnabled: @js((bool) $this->ideaDebug),
                        probeNeedle: @js((bool) $this->ideaDebug ? 'phong cách capsule wardrobe' : ''),
                        clusterRows: @js($this->clusterRows),
                        clusterTotal: {{ (int) $this->clusterTotal }},
                        activeTab: 'ideas',
                        plannerUrl: @js($plannerUrl),
                        labels: {
                            editing: @js(__('seo-content-ai::filament.projects.idea_manager_status_editing')),
                            saving: @js(__('seo-content-ai::filament.projects.idea_manager_status_saving')),
                            synced: @js(__('seo-content-ai::filament.projects.idea_manager_status_synced')),
                            error: @js(__('seo-content-ai::filament.projects.idea_manager_status_error')),
                            keepMineConfirm: @js(__('seo-content-ai::filament.projects.idea_manager_keep_mine_confirm')),
                            loadServerConfirm: @js(__('seo-content-ai::filament.projects.idea_manager_load_server_confirm')),
                            deleteConfirm: @js(__('seo-content-ai::filament.projects.idea_manager_delete_confirm')),
                            bulkValid: @js(__('seo-content-ai::filament.projects.idea_manager_bulk_valid')),
                            bulkDups: @js(__('seo-content-ai::filament.projects.idea_manager_bulk_dups')),
                            bulkInvalid: @js(__('seo-content-ai::filament.projects.idea_manager_bulk_invalid')),
                            conflictBody: @js(__('seo-content-ai::filament.projects.idea_manager_conflict_body')),
                            conflictLocal: @js(__('seo-content-ai::filament.projects.idea_manager_conflict_local')),
                            conflictServer: @js(__('seo-content-ai::filament.projects.idea_manager_conflict_server')),
                            legacyBanner: @js(__('seo-content-ai::filament.projects.idea_manager_legacy_banner')),
                            expandDna: @js(__('seo-content-ai::filament.projects.idea_manager_expand_dna')),
                            collapseDna: @js(__('seo-content-ai::filament.projects.idea_manager_collapse_dna')),
                        }
                    })"
                    x-on:idea-manager-site-changed.window="onSiteChanged(Number(($event.detail && ($event.detail.siteId ?? $event.detail[0])) || 0))"
                    x-on:idea-manager-source-filter-changed.window="onSourceFilterChanged($event.detail)"
                    x-on:idea-manager-cluster-rows.window="onClusterRows($event.detail)"
                    x-on:im-add-cluster.window="addCluster($event.detail)"
                    x-on:im-untick.window="untickFromDistribution(($event.detail && ($event.detail.cluster_ref ?? $event.detail[0])) || '')"
                    x-init="
                        $watch('syncStatus', () => {
                            const slot = document.getElementById('im-sync-slot');
                            if (!slot) return;
                            slot.innerHTML = '';
                            const span = document.createElement('span');
                            span.className = 'im-status im-status--' + (syncStatus === 'unsynced_error' ? 'error' : syncStatus);
                            span.textContent = statusLabel();
                            slot.appendChild(span);
                        });
                        const slot = document.getElementById('im-sync-slot');
                        if (slot) {
                            const span = document.createElement('span');
                            span.className = 'im-status im-status--' + (syncStatus === 'unsynced_error' ? 'error' : syncStatus);
                            span.textContent = statusLabel();
                            slot.appendChild(span);
                        }
                        emitSelection();
                    "
                >
                    <template x-if="siteId <= 0">
                        <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_pick_site') }}</div>
                    </template>

                    <template x-if="siteId > 0">
                        <div class="im-main__shell">
                            <div class="im-main__sticky">
                                <details x-show="debugEnabled" x-cloak class="im-debug" data-im-debug="1" open>
                                <summary>Idea Manager runtime diagnostics (temporary)</summary>
                                <div class="im-debug__grid">
                                    <div><span class="im-debug__k">server_snapshot</span> <span class="im-debug__v" x-text="debugServerCount()"></span></div>
                                    <div><span class="im-debug__k">livewire_delivered</span> <span class="im-debug__v" x-text="debugLivewireCount"></span></div>
                                    <div><span class="im-debug__k">alpine_working_set</span> <span class="im-debug__v" x-text="(items || []).length"></span></div>
                                    <div><span class="im-debug__k">filtered_visible</span> <span class="im-debug__v" x-text="filteredItems().length"></span></div>
                                    <div><span class="im-debug__k">search</span> <span class="im-debug__v" x-text="JSON.stringify(search || '')"></span></div>
                                    <div><span class="im-debug__k">sourceTypeFilter</span> <span class="im-debug__v" x-text="sourceTypeFilter"></span></div>
                                    <div><span class="im-debug__k">sourceFilterSiteId</span> <span class="im-debug__v" x-text="sourceFilterSiteId + ' (cluster only)'"></span></div>
                                    <div><span class="im-debug__k">working_siteId</span> <span class="im-debug__v" x-text="siteId"></span></div>
                                    <div><span class="im-debug__k">dirty</span> <span class="im-debug__v" x-text="String(!!dirty)"></span></div>
                                    <div><span class="im-debug__k">dirty_owners</span> <span class="im-debug__v" x-text="debugDirtyOwnerIds()"></span></div>
                                    <div><span class="im-debug__k">site_revisions</span> <span class="im-debug__v" x-text="debugSiteRevisions()"></span></div>
                                    <div><span class="im-debug__k">last_bootstrap_seq</span> <span class="im-debug__v" x-text="debugSeq"></span></div>
                                    <div><span class="im-debug__k">last_event</span> <span class="im-debug__v" x-text="debugLastEvent || '—'"></span></div>
                                    <div><span class="im-debug__k">last_at_ms</span> <span class="im-debug__v" x-text="debugLastAt || 0"></span></div>
                                    <div><span class="im-debug__k">probe_in_server</span> <span class="im-debug__v" x-text="String(probePresentIn(serverMeta.note_items))"></span></div>
                                    <div><span class="im-debug__k">probe_in_working</span> <span class="im-debug__v" x-text="String(probePresentIn(items))"></span></div>
                                    <div><span class="im-debug__k">probe_in_filtered</span> <span class="im-debug__v" x-text="String(probePresentIn(filteredItems()))"></span></div>
                                    <div><span class="im-debug__k">hydration_error</span> <span class="im-debug__v" x-text="debugHydrationError || 'none'"></span></div>
                                </div>
                                <div class="im-debug__hist">
                                    <template x-for="(ev, i) in debugEvents" :key="'dbg-'+i+'-'+ev.seq">
                                        <div x-text="ev.seq + ' ' + ev.name + ' ' + ev.before + '→' + ev.after + ' src=' + ev.source + (ev.owner ? (' owner=' + ev.owner) : '')"></div>
                                    </template>
                                </div>
                            </details>

                            <div x-show="conflict" x-cloak class="im-conflict">
                                <div class="font-semibold text-amber-900 dark:text-amber-100">{{ __('seo-content-ai::filament.projects.idea_manager_conflict_title') }}</div>
                                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200" x-text="conflictBodyText()"></p>
                                <div class="im-actions mt-3">
                                    <button type="button" class="im-btn im-btn--primary" x-on:click="confirmKeepMine()">{{ __('seo-content-ai::filament.projects.idea_manager_keep_mine') }}</button>
                                    <button type="button" class="im-btn im-btn--ghost" x-on:click="confirmLoadServer()">{{ __('seo-content-ai::filament.projects.idea_manager_load_server') }}</button>
                                    <button type="button" class="im-btn im-btn--ghost" x-on:click="dismissConflict()">{{ __('seo-content-ai::filament.projects.idea_manager_conflict_dismiss') }}</button>
                                </div>
                            </div>

                            <div x-show="legacyImportableCount() > 0 && !legacyDismissed" x-cloak class="im-banner" data-im-legacy-banner="1">
                                <div>
                                    <div class="im-banner__text" x-text="legacyBannerText()"></div>
                                    <div class="im-banner__sub">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_banner_hint') }}</div>
                                </div>
                                <div class="im-actions">
                                    <button type="button" class="im-btn im-btn--primary" x-on:click="openLegacyPreview()">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_restore') }}</button>
                                    <button type="button" class="im-btn im-btn--ghost" x-on:click="legacyDismissed = true">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_dismiss') }}</button>
                                </div>
                            </div>

                            <div class="im-actions">
                                <input type="search" class="im-input" style="flex:1;min-width:10rem" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_search') }}" x-model="search" />
                                <select class="im-input" style="min-width:8rem" x-model="sourceTypeFilter">
                                    <option value="all">{{ __('seo-content-ai::filament.projects.idea_manager_source_filter_all') }}</option>
                                    <option value="cluster">Cluster</option>
                                    <option value="manual">Manual</option>
                                    <option value="generated">Generated</option>
                                </select>
                                <span class="im-pill im-pill--blue" x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_selected_count', ['count' => '__N__']) }}'.replace('__N__', String(selectedCount()))"></span>
                                <button type="button" class="im-btn im-btn--ghost" x-on:click="selectAllVisible()">{{ __('seo-content-ai::filament.projects.idea_manager_select_all') }}</button>
                                <button type="button" class="im-btn im-btn--ghost" x-on:click="clearSelection()">{{ __('seo-content-ai::filament.projects.idea_manager_clear_selection') }}</button>
                            </div>

                            <div class="im-actions">
                                <input type="text" class="im-input" style="flex:1;min-width:10rem" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_add_placeholder') }}" x-model="manualDraft" x-on:keydown.enter.prevent="addManual()" />
                                <button type="button" class="im-btn im-btn--success" x-on:click="addManual()">{{ __('seo-content-ai::filament.projects.idea_manager_add_manual') }}</button>
                                <button type="button" class="im-btn im-btn--primary" x-on:click="showBulk = !showBulk">{{ __('seo-content-ai::filament.projects.idea_manager_bulk_add') }}</button>
                                <button type="button" class="im-btn im-btn--ghost" x-on:click="previewAuditImport()">{{ __('seo-content-ai::filament.projects.idea_manager_import_audit') }}</button>
                            </div>
                            <p class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_ai_fill_hint') }}
                                <a class="underline" :href="plannerUrl" target="_blank" rel="noopener">Content Planning</a>
                            </p>

                            <div x-show="showBulk" x-cloak class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <textarea class="im-input w-full min-h-[7rem] font-mono text-sm" style="width:100%" x-model="bulkText" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_bulk_placeholder') }}"></textarea>
                                <div class="im-actions">
                                    <button type="button" class="im-btn im-btn--ghost" x-on:click="previewBulk()">{{ __('seo-content-ai::filament.projects.idea_manager_bulk_preview') }}</button>
                                    <button type="button" class="im-btn im-btn--primary" x-show="bulkPreview" x-on:click="confirmBulk()">{{ __('seo-content-ai::filament.projects.idea_manager_bulk_confirm') }}</button>
                                    <template x-if="bulkPreview">
                                        <span class="text-xs text-gray-600 dark:text-gray-300">
                                            <span x-text="bulkLabel('valid', bulkPreview.valid.length)"></span> ·
                                            <span x-text="bulkLabel('dups', bulkPreview.duplicates.length)"></span> ·
                                            <span x-text="bulkLabel('invalid', bulkPreview.invalid.length)"></span>
                                        </span>
                                    </template>
                                </div>
                            </div>

                            <div x-show="importPreview" x-cloak class="space-y-2 rounded-lg border border-blue-200 bg-blue-50/50 p-3 dark:border-blue-900 dark:bg-blue-950/30">
                                <div class="font-medium text-sm">{{ __('seo-content-ai::filament.projects.idea_manager_import_preview') }}</div>
                                <template x-if="importPreview && importPreview.importable.length === 0">
                                    <p class="text-sm text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_import_none') }}</p>
                                </template>
                                <template x-if="importPreview && importPreview.importable.length > 0">
                                    <div class="space-y-2">
                                        <p class="text-sm" x-text="importSummaryText()"></p>
                                        <button type="button" class="im-btn im-btn--primary" x-on:click="confirmAuditImport()">{{ __('seo-content-ai::filament.projects.idea_manager_import_confirm') }}</button>
                                    </div>
                                </template>
                            </div>
                            </div>

                            <div class="im-main__list">
                                <template x-if="filteredItems().length === 0">
                                    <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_empty') }}</div>
                                </template>

                                <div class="space-y-2">
                                <template x-for="item in filteredItems()" :key="itemKey(item)">
                                    <div class="im-card" :class="{ 'is-selected': isSelected(itemKey(item)) }">
                                        <div class="im-card__top">
                                            <div class="min-w-0 flex-1 flex gap-2">
                                                <input type="checkbox" class="mt-1" :checked="isSelected(itemKey(item))" x-on:change="toggleSelect(itemKey(item))" />
                                                <div class="min-w-0 flex-1">
                                                    <div class="im-card__title" x-text="item.cluster_name_snapshot || item.seed_text"></div>
                                                    <div class="im-card__meta">
                                                        <span class="im-pill" x-text="sourceLabel(item)"></span>
                                                        <span class="im-pill" x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_source_domain_badge', ['domain' => '__D__']) }}'.replace('__D__', ideaProvenanceDomain(item))"></span>
                                                        <span class="im-pill im-pill--blue" x-text="'DNA ' + specifiedCount(item) + '/' + item.target_dna_count"></span>
                                                        <span class="im-pill im-pill--green" x-show="Number(item.target_dna_count || 0) > specifiedCount(item)" x-text="'AI +' + (Number(item.target_dna_count || 0) - specifiedCount(item))"></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="im-card__controls">
                                                <label class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_target_dna') }}</label>
                                                <input type="number" min="1" max="100" class="im-input im-target"
                                                       :value="item.target_dna_count"
                                                       x-on:change="setTarget(item.cluster_ref, $event.target.value)" />
                                                <button type="button" class="im-btn im-btn--ghost" x-on:click="toggleExpand(item.cluster_ref)"
                                                        x-text="isExpanded(item.cluster_ref) ? (labels.collapseDna || 'Collapse') : (labels.expandDna || 'DNA')"></button>
                                                <button type="button" class="im-btn im-btn--danger" x-on:click="removeItem(item.cluster_ref)">{{ __('seo-content-ai::filament.projects.idea_manager_delete') }}</button>
                                            </div>
                                        </div>
                                        <div x-show="isExpanded(item.cluster_ref)" x-cloak class="space-y-2 border-t border-gray-100 pt-2 dark:border-gray-800">
                                            <div class="text-xs font-semibold text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_specified_dna') }}</div>
                                            <div class="im-dna-row">
                                                <template x-for="(dna, di) in (item.dna || [])" :key="item.cluster_ref + '-dna-' + di">
                                                    <span class="im-dna-chip">
                                                        <span x-text="dna.phrase"></span>
                                                        <button type="button" x-on:click="removeDna(item.cluster_ref, di)" aria-label="remove">×</button>
                                                    </span>
                                                </template>
                                                <template x-if="!(item.dna || []).length">
                                                    <span class="text-xs text-gray-400">{{ __('seo-content-ai::filament.projects.idea_manager_no_dna_yet') }}</span>
                                                </template>
                                            </div>
                                            <div class="im-actions">
                                                <input type="text" class="im-input" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_add_dna') }}"
                                                       x-model="dnaDrafts[item.cluster_ref]"
                                                       x-on:keydown.enter.prevent="addDna(item.cluster_ref)" />
                                                <button type="button" class="im-btn im-btn--ghost" x-on:click="addDna(item.cluster_ref)">{{ __('seo-content-ai::filament.projects.idea_manager_add_dna') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            {{-- Site Planning + Distribution (Livewire-owned; updates with Active Month / target) --}}
            <section class="im-right" data-im-planning="1">
                <div class="im-right__head">
                    <div>
                        <div class="im-right__title">{{ __('seo-content-ai::filament.projects.idea_manager_step2') }} · {{ __('seo-content-ai::filament.projects.idea_manager_planning_title') }}</div>
                        <div class="text-[11px] text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_planning_hint') }}</div>
                    </div>
                </div>
                <div class="im-right__body">
                    <div wire:key="im-site-planning-{{ $this->activeMonth }}-{{ (int) ($this->targetSiteId ?? 0) }}">
                        <x-seo-content-ai::content-project-site-planning />
                    </div>
                </div>
                {{-- wire:ignore: keep selection + analysis Alpine alive across Livewire target remorphs --}}
                <div class="im-right__foot" data-im-distribution-foot="1" wire:ignore>
                    <div class="im-dist" data-im-distribution="1"
                         x-data="ideaManagerDistribution({
                            targetSiteId: {{ (int) ($dist['target_site_id'] ?? 0) }},
                            targetDomain: @js((string) ($dist['target_domain'] ?? '')),
                            activeMonthLabel: @js(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($dist['active_month'])),
                            capacityLabel: @js($dist['planned'] !== null ? (((int) $dist['planned']).' / '.((int) $dist['target'])) : ''),
                         })"
                         x-on:im-selection-changed.window="onSelection($event.detail)"
                         x-on:idea-manager-target-changed.window="onTargetChanged($event.detail)"
                         x-on:idea-manager-active-month-changed.window="onActiveMonthChanged($event.detail)">
                        <div class="im-dist__head">
                            <div class="font-semibold text-sm">{{ __('seo-content-ai::filament.projects.idea_manager_distribution_title') }}</div>
                            <div class="im-dist__meta">
                                <div><strong>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_month') }}:</strong> <span x-text="activeMonthLabel"></span></div>
                                <div>
                                    <strong>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_target') }}:</strong>
                                    <span x-show="targetDomain" x-text="targetDomain"></span>
                                    <span class="text-gray-500" x-show="!targetDomain">{{ __('seo-content-ai::filament.projects.idea_manager_distribution_pick_target') }}</span>
                                </div>
                                <div x-show="capacityLabel" x-cloak>
                                    <strong>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_capacity') }}:</strong>
                                    <span x-text="capacityLabel"></span>
                                </div>
                                <div>
                                    <strong>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_selected') }}:</strong>
                                    <span x-text="count"></span>
                                    <span class="text-gray-400" x-show="count === 0"> — {{ __('seo-content-ai::filament.projects.idea_manager_distribution_pick_ideas') }}</span>
                                </div>
                                <div x-show="count > 0" x-cloak>
                                    <span x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_distribution_exact_count', ['count' => '__N__']) }}'.replace('__N__', String(exactCount))"></span>
                                    ·
                                    <span x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_distribution_warn_count', ['count' => '__N__']) }}'.replace('__N__', String(warningCount))"></span>
                                    ·
                                    <span x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_distribution_unknown_count', ['count' => '__N__']) }}'.replace('__N__', String(unknownCount))"></span>
                                </div>
                            </div>
                            <div class="text-xs text-gray-500" x-show="checking" x-cloak>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_checking') }}</div>
                        </div>
                        <div class="im-dist__scroll">
                            <p class="im-dist__hint mb-1">{{ __('seo-content-ai::filament.projects.idea_manager_distribution_contract_hint') }}</p>
                            <ul class="im-dist__preview" x-show="displayItems.length > 0" x-cloak>
                                <template x-for="(it, i) in displayItems" :key="(it.warehouse_key || it.cluster_ref || 'item') + '-' + i">
                                    <li :class="{ 'is-dup': it.exact_match, 'is-warn': it.warning && !it.exact_match }">
                                        <div class="min-w-0 flex-1 space-y-0.5">
                                            <div class="font-medium" x-text="it.name"></div>
                                            <div class="im-dist__dup" x-show="it.exact_match" x-cloak>
                                                <span>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_exact_focus') }}</span>
                                            </div>
                                            <div class="im-dist__warn" x-show="it.warning && !it.exact_match && it.check_status !== 'unavailable'" x-cloak>
                                                <span>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_warn') }}</span>
                                                <span x-show="it.similarity_score != null" x-text="'· ' + Number(it.similarity_score).toFixed(3)"></span>
                                            </div>
                                            <div class="text-[11px] text-amber-700 dark:text-amber-300" x-show="it.check_status === 'unavailable'" x-cloak>
                                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_unknown_semantic') }}
                                            </div>
                                            <div class="text-[11px] text-gray-500" x-show="it.check_status === 'no_focus_keywords'" x-cloak>
                                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_no_focus_keywords') }}
                                            </div>
                                            <div class="text-[11px] text-gray-500" x-show="it.check_status === 'analyzed' && !it.exact_match && !it.warning" x-cloak>
                                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_no_match') }}
                                            </div>
                                            <div class="text-[11px] text-gray-600 dark:text-gray-300" x-show="it.focus_keyword" x-cloak>
                                                <span>Focus Keyword: </span><strong x-text="it.focus_keyword"></strong>
                                            </div>
                                            <div class="text-[11px] text-gray-600 dark:text-gray-300 flex flex-wrap items-center gap-1.5" x-show="it.article_title" x-cloak>
                                                <span>Article: <span x-text="it.article_title"></span></span>
                                                <span class="im-pill" x-show="it.article_status_label" x-text="it.article_status_label"></span>
                                                <a
                                                    class="underline text-blue-600 dark:text-blue-400"
                                                    x-show="it.article_edit_url"
                                                    :href="it.article_edit_url"
                                                    target="_blank"
                                                    rel="noopener"
                                                >{{ __('seo-content-ai::filament.projects.idea_manager_distribution_open_article') }}</a>
                                            </div>
                                        </div>
                                        <button type="button" class="im-btn im-btn--ghost" x-on:click="untick(it.cluster_ref)">
                                            {{ __('seo-content-ai::filament.projects.idea_manager_distribution_untick') }}
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <div class="im-dist__actions">
                            <div class="im-dist__result" :class="{ 'is-error': result && !result.ok }" x-show="result" x-cloak>
                                <span x-text="resultLabel()"></span>
                                <template x-if="result && result.run_detail_url">
                                    <a class="underline ml-2 font-semibold" :href="result.run_detail_url" target="_blank" rel="noopener">{{ __('seo-content-ai::filament.projects.idea_manager_distribution_view_run') }}</a>
                                </template>
                            </div>
                            <div class="text-xs text-blue-700 dark:text-blue-300 font-medium" x-show="generating" x-cloak>
                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_generating') }}
                            </div>
                            <div class="text-xs text-amber-700 dark:text-amber-300 font-medium" x-show="reconciling" x-cloak>
                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_reconciling') }}
                            </div>
                            <p class="im-dist__hint" x-show="!canDistributeNow() && !generating" x-cloak>
                                {{ __('seo-content-ai::filament.projects.idea_manager_distribution_need_valid') }}
                            </p>
                            <div class="im-actions" style="width:100%">
                                <button
                                    type="button"
                                    class="im-btn im-btn--ghost"
                                    style="flex:1"
                                    data-im-distribution-check="1"
                                    :disabled="!canCheckNow()"
                                    x-on:click="checkDuplicates()"
                                >
                                    {{ __('seo-content-ai::filament.projects.idea_manager_distribution_check_btn') }}
                                </button>
                                <button
                                    type="button"
                                    class="im-btn im-btn--primary"
                                    style="flex:1.3"
                                    data-im-distribution-confirm="1"
                                    :disabled="!canDistributeNow()"
                                    x-on:click="distributeWithAi()"
                                >
                                    {{ __('seo-content-ai::filament.projects.idea_manager_distribution_ai_btn') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>


    <script>
        function ideaManagerClusterBridge(cfg) {
            return {
                rows: cfg.rows || [],
                total: Number(cfg.total || 0),
                onRows(detail) {
                    const d = detail || {};
                    const rows = d.rows ?? d[0] ?? [];
                    const total = d.total ?? d[1] ?? 0;
                    this.rows = Array.isArray(rows) ? rows : [];
                    this.total = Number(total || 0);
                },
            };
        }

        function ideaManagerDistribution(cfg) {
            return {
                targetSiteId: Number(cfg.targetSiteId || 0),
                targetDomain: String(cfg.targetDomain || ''),
                activeMonthLabel: String(cfg.activeMonthLabel || ''),
                capacityLabel: String(cfg.capacityLabel || ''),
                count: Number((window.__imSelection && window.__imSelection.count) || 0),
                items: (window.__imSelection && window.__imSelection.items) || [],
                analysisByRef: {},
                warningCount: 0,
                exactCount: 0,
                unknownCount: 0,
                checking: false,
                generating: false,
                reconciling: false,
                confirming: false,
                pendingRequestKey: '',
                activeRunId: 0,
                result: null,
                _timer: null,
                _pollTimer: null,
                _reqSeq: 0,

                get displayItems() {
                    // Selection is SSOT — analysis overlays evidence; never hide while checking.
                    return (this.items || []).map((it, i) => {
                        const ref = it.cluster_ref;
                        const wkey = it.warehouse_key || ((it.owner_site_id || it.source_site_id || 0) + ':' + (ref || i));
                        const name = it.name || it.cluster_name_snapshot || ref;
                        const ev = this.analysisByRef[ref] || {};
                        return {
                            warehouse_key: wkey,
                            cluster_ref: ref,
                            name: name,
                            exact_match: !!ev.exact_match,
                            warning: !!ev.warning,
                            check_status: ev.check_status || (this.checking ? 'pending' : 'unchecked'),
                            similarity_score: (ev.similarity_score !== undefined) ? ev.similarity_score : null,
                            matched: ev.matched || null,
                            matched_type: ev.matched_type || null,
                            focus_keyword: ev.focus_keyword || null,
                            article_id: ev.article_id || null,
                            article_title: ev.article_title || null,
                            article_status: ev.article_status || null,
                            article_status_label: ev.article_status_label || null,
                            article_edit_url: ev.article_edit_url || null,
                            reason: ev.reason || null,
                        };
                    });
                },

                init() {},

                canCheckNow() {
                    return this.targetSiteId > 0
                        && this.items.length > 0
                        && !this.checking
                        && !this.generating
                        && !this.reconciling;
                },

                canDistributeNow() {
                    return this.targetSiteId > 0
                        && this.items.length > 0
                        && !this.generating
                        && !this.reconciling
                        && !this.confirming;
                },

                canConfirmNow() {
                    return this.canDistributeNow();
                },

                onSelection(detail) {
                    const d = detail || {};
                    this.count = Number(d.count || 0);
                    this.items = Array.isArray(d.items) ? d.items : [];
                    this.result = null;
                    this.pendingRequestKey = '';
                    // Invalidate stale check results for removed refs; keep others until user re-checks.
                    const keep = {};
                    for (const it of this.items) {
                        if (this.analysisByRef[it.cluster_ref]) {
                            keep[it.cluster_ref] = this.analysisByRef[it.cluster_ref];
                        }
                    }
                    this.analysisByRef = keep;
                    this.recomputeCounts();
                },

                onTargetChanged(detail) {
                    const d = detail || {};
                    const raw = (d.siteId !== undefined && d.siteId !== null)
                        ? d.siteId
                        : ((d[0] !== undefined && d[0] !== null) ? d[0] : this.targetSiteId);
                    this.targetSiteId = Number(raw || 0);
                    if (d.domain) this.targetDomain = String(d.domain);
                    this.result = null;
                    this.pendingRequestKey = '';
                    // Invalidate stale check results — keep selected items intact.
                    this.analysisByRef = {};
                    this.warningCount = 0;
                    this.exactCount = 0;
                    this.unknownCount = 0;
                },

                onActiveMonthChanged(detail) {
                    const d = detail || {};
                    const month = d.month || d[0] || '';
                    if (month) {
                        const parts = String(month).split('-');
                        if (parts.length >= 2) {
                            this.activeMonthLabel = parts[1] + '/' + parts[0];
                        }
                    }
                    this.pendingRequestKey = '';
                },

                recomputeCounts() {
                    let w = 0, e = 0, u = 0;
                    for (const it of this.displayItems) {
                        if (it.exact_match) e += 1;
                        else if (it.warning) w += 1;
                        else if (it.check_status === 'unavailable') u += 1;
                    }
                    this.warningCount = w;
                    this.exactCount = e;
                    this.unknownCount = u;
                },

                schedulePreview() {
                    if (this._timer) clearTimeout(this._timer);
                    this._timer = setTimeout(() => this.refreshPreview(), 200);
                },

                async refreshPreview() {
                    if (this.targetSiteId <= 0 || this.items.length === 0) {
                        this.checking = false;
                        this.recomputeCounts();
                        return;
                    }
                    const seq = ++this._reqSeq;
                    const siteAtStart = this.targetSiteId;
                    this.checking = true;
                    let settled = false;
                    const finish = () => {
                        if (!settled) {
                            settled = true;
                            if (seq === this._reqSeq) this.checking = false;
                        }
                    };
                    const hangWatch = setTimeout(() => {
                        if (settled || seq !== this._reqSeq || siteAtStart !== this.targetSiteId) return;
                        // Keep selection list; Unknown evidence — never fabricate scores / never block Confirm.
                        const next = { ...this.analysisByRef };
                        for (const it of this.items) {
                            next[it.cluster_ref] = {
                                exact_match: false,
                                warning: false,
                                check_status: 'unavailable',
                                similarity_score: null,
                                matched: null,
                                matched_type: null,
                                reason: 'preview_timeout',
                            };
                        }
                        this.analysisByRef = next;
                        this.recomputeCounts();
                        finish();
                    }, 12000);
                    try {
                        const res = await this.$wire.previewDistribution(this.items);
                        if (seq !== this._reqSeq || siteAtStart !== this.targetSiteId) return;
                        if (res && res.target_domain) this.targetDomain = String(res.target_domain);
                        const next = {};
                        for (const row of (Array.isArray(res?.items) ? res.items : [])) {
                            next[row.cluster_ref] = {
                                exact_match: !!row.exact_match,
                                warning: !!row.warning,
                                check_status: row.check_status || 'analyzed',
                                similarity_score: (row.similarity_score !== undefined) ? row.similarity_score : null,
                                matched: row.matched || null,
                                matched_type: row.matched_type || null,
                                focus_keyword: row.focus_keyword || null,
                                article_id: row.article_id || null,
                                article_title: row.article_title || null,
                                article_status: row.article_status || null,
                                article_status_label: row.article_status_label || null,
                                article_edit_url: row.article_edit_url || null,
                                reason: row.reason || null,
                            };
                        }
                        this.analysisByRef = next;
                        this.recomputeCounts();
                    } catch (e) {
                        if (seq !== this._reqSeq || siteAtStart !== this.targetSiteId) return;
                        const next = { ...this.analysisByRef };
                        for (const it of this.items) {
                            next[it.cluster_ref] = {
                                exact_match: false,
                                warning: false,
                                check_status: 'unavailable',
                                similarity_score: null,
                                matched: null,
                                matched_type: null,
                                reason: 'preview_error',
                            };
                        }
                        this.analysisByRef = next;
                        this.recomputeCounts();
                    } finally {
                        clearTimeout(hangWatch);
                        finish();
                    }
                },

                untick(ref) {
                    window.dispatchEvent(new CustomEvent('im-untick', { detail: { cluster_ref: ref } }));
                },

                async checkDuplicates() {
                    return this.refreshPreview();
                },

                async distributeWithAi() {
                    if (!this.canDistributeNow()) return;
                    const confirmTpl = @js(__('seo-content-ai::filament.projects.idea_manager_distribution_ai_confirm'));
                    const msg = String(confirmTpl)
                        .replace(':count', String(this.items.length))
                        .replace(':domain', String(this.targetDomain || ('#' + this.targetSiteId)))
                        .replace(':month', String(this.activeMonthLabel || ''));
                    if (!window.confirm(msg)) return;

                    if (!this.pendingRequestKey) {
                        this.pendingRequestKey = (window.crypto && typeof window.crypto.randomUUID === 'function')
                            ? window.crypto.randomUUID()
                            : ('req-' + Date.now() + '-' + Math.random().toString(36).slice(2));
                    }

                    this.generating = true;
                    this.confirming = true;
                    this.result = null;
                    try {
                        const res = await this.$wire.distributeWithAi(this.items, this.pendingRequestKey);
                        if (!res || !res.ok || !res.planner_run_id) {
                            this.result = {
                                ok: false,
                                status: 'failed',
                                message: (res && res.message) ? res.message : 'AI generation failed',
                                run_detail_url: (res && res.run_detail_url) || null,
                            };
                            this.generating = false;
                            this.confirming = false;
                            return;
                        }
                        this.activeRunId = Number(res.planner_run_id);
                        this.startRunPolling(this.activeRunId);
                    } catch (e) {
                        // Network timeout / disconnect: reconcile with server before allowing retry.
                        await this.reconcileAfterTimeout();
                    }
                },

                startRunPolling(runId) {
                    if (this._pollTimer) clearTimeout(this._pollTimer);
                    const tick = async () => {
                        try {
                            const poll = await this.$wire.pollDistributionRun(runId);
                            if (poll && poll.terminal) {
                                this.generating = false;
                                this.confirming = false;
                                this.pendingRequestKey = '';
                                this.result = {
                                    ok: !!poll.ok,
                                    status: poll.status,
                                    added: Number(poll.added || 0),
                                    duplicate_skipped: Number(poll.duplicate_skipped || 0),
                                    message: poll.error || '',
                                    run_detail_url: poll.run_detail_url || null,
                                };
                                return;
                            }
                        } catch (_e) {}
                        this._pollTimer = setTimeout(tick, 2500);
                    };
                    this._pollTimer = setTimeout(tick, 1500);
                },

                async reconcileAfterTimeout() {
                    this.reconciling = true;
                    try {
                        const run = await this.$wire.reconcileDistributionRun(this.targetSiteId);
                        if (run && run.found && !run.terminal) {
                            this.activeRunId = Number(run.planner_run_id || 0);
                            this.reconciling = false;
                            if (this.activeRunId > 0) {
                                this.startRunPolling(this.activeRunId);
                                return;
                            }
                        }
                        if (run && run.found && run.terminal) {
                            this.result = {
                                ok: !!run.ok,
                                status: run.status,
                                added: Number(run.added || 0),
                                duplicate_skipped: Number(run.duplicate_skipped || 0),
                                message: run.error || '',
                                run_detail_url: run.run_detail_url || null,
                            };
                            this.pendingRequestKey = '';
                        } else {
                            this.result = {
                                ok: false,
                                status: 'failed',
                                message: 'Request timed out',
                            };
                        }
                    } catch (_e) {
                        this.result = { ok: false, status: 'failed', message: 'Reconcile failed' };
                    } finally {
                        this.reconciling = false;
                        this.generating = false;
                        this.confirming = false;
                    }
                },

                async confirm() {
                    return this.distributeWithAi();
                },

                resultLabel() {
                    if (!this.result) return '';
                    if (!this.result.ok) {
                        const failTpl = @js(__('seo-content-ai::filament.projects.idea_manager_distribution_ai_failed'));
                        return String(failTpl).replace(':error', String(this.result.message || 'error'));
                    }
                    const okTpl = @js(__('seo-content-ai::filament.projects.idea_manager_distribution_ai_success'));
                    return String(okTpl)
                        .replace(':added', String(this.result.added || 0))
                        .replace(':skipped', String(this.result.duplicate_skipped || 0));
                },
            };
        }

        function ideaManagerRoot(cfg) {
            const IM_GLOBAL_DRAFT = 'seoOps:idea-manager:draft:v2:global';
            const IM_PREFIX = 'seoOps:idea-manager:draft:v1:site:';
            const AUDIT_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:';
            const INTAKE_PING = 'seoOps:idea-manager:intake-ping';
            const DEBOUNCE_MS = 3000;
            const MAX_SYNC_MS = 30000;

            return {
                siteId: Number(cfg.siteId || 0),
                siteDomain: String(cfg.siteDomain || ''),
                sourceFilterSiteId: Number(cfg.sourceFilterSiteId || 0),
                sourceFilterDomain: String(cfg.sourceFilterDomain || ''),
                tab: cfg.activeTab || 'ideas',
                plannerUrl: cfg.plannerUrl || '',
                labels: cfg.labels || {},
                items: [],
                baseRevision: 0,
                siteRevisions: {},
                dirtyOwners: {},
                serverMeta: { revision: 0, updated_at: null, item_count: 0, note_items: [], sites: {} },
                dirty: false,
                editGeneration: 0,
                syncStatus: 'synced',
                conflict: false,
                conflictBannerOpen: false,
                conflictSiteId: 0,
                search: '',
                manualDraft: '',
                dnaDrafts: {},
                expanded: {},
                showBulk: false,
                bulkText: '',
                bulkPreview: null,
                importPreview: null,
                legacyScan: null,
                legacyDismissed: false,
                clusterRows: cfg.clusterRows || [],
                clusterTotal: Number(cfg.clusterTotal || 0),
                selected: {},
                sourceTypeFilter: 'all',
                saveTimer: null,
                maxSyncTimer: null,
                inflight: false,
                pendingResave: false,
                inflightGeneration: 0,
                lastSyncAttemptAt: 0,
                _forceOverwrite: false,
                _forceOverwriteSiteId: 0,

                // Temporary runtime diagnostics (?idea_debug=1, manager+). Read-only.
                debugEnabled: !!cfg.debugEnabled,
                debugProbeNeedle: String(cfg.probeNeedle || ''),
                debugLivewireCount: Number((cfg.server && cfg.server.item_count) || ((cfg.server && cfg.server.note_items) || []).length || 0),
                debugSeq: 0,
                debugLastEvent: '',
                debugLastAt: 0,
                debugHydrationError: '',
                debugEvents: [],

                init() {
                    try {
                        this.bootstrapFromServer(cfg.server);
                    } catch (e) {
                        this.debugHydrationError = 'init_bootstrap:' + String((e && e.message) || e || 'error');
                    }
                    // Livewire re-fetch: first-paint snapshot alone must not be the only source of truth.
                    queueMicrotask(() => this.softReloadGlobal());
                    const onVis = () => {
                        if (document.visibilityState === 'visible') this.softReloadGlobal();
                    };
                    const onFocus = () => this.softReloadGlobal();
                    const onStorage = (e) => {
                        if (e && e.key === INTAKE_PING) this.softReloadGlobal();
                    };
                    window.addEventListener('focus', onFocus);
                    document.addEventListener('visibilitychange', onVis);
                    window.addEventListener('storage', onStorage);
                },

                itemKey(it) {
                    if (!it) return '';
                    if (it.warehouse_key) return String(it.warehouse_key);
                    const owner = Number(it.owner_site_id || it.source_site_id || 0);
                    return owner + ':' + String(it.cluster_ref || '');
                },

                ownerOf(it) {
                    return Number((it && (it.owner_site_id || it.source_site_id)) || this.siteId || 0);
                },

                /** Bounded mutation trace — counts/owner only; never Idea text or draft JSON. */
                traceWorkingSetReplace(name, before, after, source, owner) {
                    this.debugSeq += 1;
                    this.debugLastEvent = String(name || '');
                    this.debugLastAt = Date.now();
                    if (!this.debugEnabled) return;
                    const row = {
                        seq: this.debugSeq,
                        name: String(name || ''),
                        before: Number(before || 0),
                        after: Number(after || 0),
                        source: String(source || ''),
                        owner: owner ? Number(owner) : 0,
                    };
                    this.debugEvents = [...(this.debugEvents || []), row].slice(-20);
                },

                setWorkingItems(next, name, source, owner) {
                    const before = Array.isArray(this.items) ? this.items.length : 0;
                    const list = Array.isArray(next) ? next : [];
                    this.items = list;
                    this.traceWorkingSetReplace(name, before, list.length, source, owner);
                },

                debugServerCount() {
                    return Array.isArray(this.serverMeta?.note_items) ? this.serverMeta.note_items.length : 0;
                },

                debugDirtyOwnerIds() {
                    const ids = Object.keys(this.dirtyOwners || {}).filter((k) => this.dirtyOwners[k]);
                    return ids.length ? ids.join(',') : '—';
                },

                debugSiteRevisions() {
                    const parts = [];
                    for (const sid of Object.keys(this.siteRevisions || {})) {
                        parts.push(sid + ':' + Number(this.siteRevisions[sid]?.revision || 0));
                    }
                    return parts.length ? parts.join(',') : '—';
                },

                probePresentIn(list) {
                    const needle = this.normalizeKey(this.debugProbeNeedle || '');
                    if (!needle || !Array.isArray(list)) return false;
                    return list.some((it) => {
                        const name = this.normalizeKey((it && (it.cluster_name_snapshot || it.seed_text)) || '');
                        return name === needle;
                    });
                },

                onClusterRows(detail) {
                    const d = detail || {};
                    const rows = d.rows ?? d[0] ?? [];
                    const total = d.total ?? d[1] ?? 0;
                    this.clusterRows = Array.isArray(rows) ? rows : [];
                    this.clusterTotal = Number(total || 0);
                },

                // Sidebar Cluster filter only — never remount warehouse / clear selection / dirty draft.
                onSourceFilterChanged(detail) {
                    const d = detail || {};
                    this.sourceFilterSiteId = Number(d.siteId ?? d[0] ?? 0);
                    this.sourceFilterDomain = String(d.domain ?? d[1] ?? '');
                },

                ideaProvenanceDomain(item) {
                    const stamped = String((item && (item.source_domain || item.owner_domain)) || '').trim();
                    if (stamped) return stamped;
                    return this.siteDomain || ('#' + this.siteId);
                },

                // Default seed-owner change only — Kho Idea stays global (no single-site remount).
                onSiteChanged(nextId) {
                    const id = Number(nextId || 0);
                    if (this.dirty) this.persistDraft();
                    this.siteId = id;
                    this.importPreview = null;
                    this.bulkPreview = null;
                    this.legacyScan = null;
                    this.legacyDismissed = false;
                    if (id <= 0) {
                        this.siteDomain = '';
                        return;
                    }
                    this.$wire.loadIdeaWorkspace(id).then((snap) => {
                        this.siteDomain = String((snap && snap.domain) || '');
                        if (!this.dirty) {
                            this.bootstrapFromServer(snap);
                        } else {
                            this.applyServerSiteMeta(snap);
                            this.detectRevisionConflicts();
                        }
                    });
                },

                applyServerSiteMeta(server) {
                    const sites = (server && server.sites && typeof server.sites === 'object') ? server.sites : {};
                    this.siteRevisions = {};
                    for (const key of Object.keys(sites)) {
                        const row = sites[key] || {};
                        this.siteRevisions[String(key)] = {
                            revision: Number(row.revision || 0),
                            domain: String(row.domain || ''),
                            item_count: Number(row.item_count || 0),
                            updated_at: row.updated_at || null,
                        };
                    }
                    this.serverMeta = {
                        revision: 0,
                        updated_at: server.updated_at || null,
                        item_count: Number(server.item_count || (server.note_items || []).length || 0),
                        note_items: Array.isArray(server.note_items) ? server.note_items : [],
                        sites,
                        global: true,
                    };
                },

                detectRevisionConflicts() {
                    const local = this.readGlobalDraft();
                    const localRevs = (local && local.site_revisions) ? local.site_revisions : {};
                    let conflict = false;
                    for (const sid of Object.keys(this.dirtyOwners || {})) {
                        const localRev = Number(localRevs[sid] ?? this.siteRevisions[sid]?.revision ?? 0);
                        const serverRev = Number(this.siteRevisions[sid]?.revision ?? 0);
                        if (localRev !== serverRev) {
                            conflict = true;
                            this.conflictSiteId = Number(sid);
                            break;
                        }
                    }
                    // Also compare any local draft revision map against server when dirty.
                    if (!conflict && this.dirty) {
                        for (const sid of Object.keys(localRevs)) {
                            const serverRev = this.siteRevisions[sid];
                            if (!serverRev) continue;
                            if (Number(localRevs[sid]) !== Number(serverRev.revision || 0)) {
                                conflict = true;
                                this.conflictSiteId = Number(sid);
                                break;
                            }
                        }
                    }
                    this.conflict = conflict;
                    this.conflictBannerOpen = conflict;
                    return conflict;
                },

                unwrapReactive(value) {
                    let cur = value;
                    for (let i = 0; i < 4; i++) {
                        if (!cur || typeof cur !== 'object') break;
                        const next = (window.Alpine && typeof window.Alpine.raw === 'function')
                            ? window.Alpine.raw(cur)
                            : cur;
                        if (!next || next === cur) break;
                        cur = next;
                    }
                    return cur;
                },

                toPlainCloneable(value, seen = null) {
                    const raw = this.unwrapReactive(value);
                    if (raw === null || typeof raw !== 'object') {
                        return (typeof raw === 'function' || typeof raw === 'symbol') ? undefined : raw;
                    }
                    if (raw instanceof Date) return new Date(raw.getTime());
                    if (raw instanceof RegExp) return new RegExp(raw.source, raw.flags);
                    const visited = seen || new WeakSet();
                    if (visited.has(raw)) return undefined;
                    visited.add(raw);
                    try {
                        if (Array.isArray(raw)) {
                            const arr = [];
                            for (let i = 0; i < raw.length; i++) {
                                const el = this.toPlainCloneable(raw[i], visited);
                                arr.push(el === undefined ? null : el);
                            }
                            return arr;
                        }
                        const out = {};
                        for (const key of Object.keys(raw)) {
                            const v = raw[key];
                            if (typeof v === 'function' || typeof v === 'symbol') continue;
                            out[key] = this.toPlainCloneable(v, visited);
                        }
                        return out;
                    } finally {
                        visited.delete(raw);
                    }
                },

                clonePlainItem(item) {
                    if (!item || typeof item !== 'object') return null;
                    const plain = this.toPlainCloneable(item);
                    if (!plain || typeof plain !== 'object' || Array.isArray(plain)) return null;
                    return typeof structuredClone === 'function' ? structuredClone(plain) : plain;
                },

                clonePlainItems(list) {
                    const rawList = this.unwrapReactive(list);
                    if (!Array.isArray(rawList)) return [];
                    const out = [];
                    for (let i = 0; i < rawList.length; i++) {
                        try {
                            const cloned = this.clonePlainItem(rawList[i]);
                            if (cloned) out.push(cloned);
                        } catch (_e) {
                            // Isolate malformed item so valid warehouse Ideas still hydrate.
                        }
                    }
                    return out;
                },

                /**
                 * Local-first for dirty owners; always keep server Ideas from clean owners.
                 * Prevents a site-1 dirty draft from hiding site-4 warehouse Ideas.
                 */
                reconcileGlobalWorkingSet(serverItems, draftItems, dirtyOwners) {
                    const server = Array.isArray(serverItems) ? serverItems : [];
                    const draft = Array.isArray(draftItems) ? draftItems : [];
                    const dirty = {};
                    if (dirtyOwners && typeof dirtyOwners === 'object') {
                        for (const k of Object.keys(dirtyOwners)) {
                            if (dirtyOwners[k]) {
                                const sid = String(Number(k) || 0);
                                if (sid !== '0') dirty[sid] = true;
                            }
                        }
                    }
                    const partition = (list) => {
                        const out = {};
                        for (const it of list) {
                            if (!it || typeof it !== 'object') continue;
                            const owner = String(this.ownerOf(it) || 0);
                            if (owner === '0') continue;
                            if (!out[owner]) out[owner] = [];
                            out[owner].push(it);
                        }
                        return out;
                    };
                    if (Object.keys(dirty).length === 0) {
                        const seen = {};
                        const out = [];
                        for (const it of draft) {
                            if (!it || typeof it !== 'object') continue;
                            const key = this.itemKey(it);
                            seen[key] = true;
                            out.push(it);
                        }
                        for (const it of server) {
                            if (!it || typeof it !== 'object') continue;
                            const key = this.itemKey(it);
                            if (seen[key]) continue;
                            out.push(it);
                        }
                        return out;
                    }
                    const serverBy = partition(server);
                    const draftBy = partition(draft);
                    const owners = Array.from(new Set([
                        ...Object.keys(serverBy),
                        ...Object.keys(draftBy),
                    ])).sort();
                    const out = [];
                    for (const sid of owners) {
                        const rows = dirty[sid] ? (draftBy[sid] || []) : (serverBy[sid] || []);
                        for (const it of rows) out.push(it);
                    }
                    return out;
                },

                bootstrapFromServer(snap) {
                    try {
                        const server = snap && typeof snap === 'object' ? snap : {
                            revision: 0, note_items: [], updated_at: null, item_count: 0, sites: {}
                        };
                        this.applyServerSiteMeta(server);
                        this.debugLivewireCount = Array.isArray(this.serverMeta.note_items)
                            ? this.serverMeta.note_items.length
                            : Number(server.item_count || 0);
                        const serverItems = this.clonePlainItems(
                            Array.isArray(server.note_items) ? server.note_items : this.serverMeta.note_items
                        );
                        const draft = this.readGlobalDraft();
                        if (draft && draft.dirty) {
                            const draftItems = this.clonePlainItems(draft.note_items);
                            this.dirtyOwners = (draft.dirty_owners && typeof draft.dirty_owners === 'object')
                                ? { ...draft.dirty_owners }
                                : {};
                            // NEVER replace the global warehouse with a single-site dirty draft.
                            const merged = this.reconcileGlobalWorkingSet(serverItems, draftItems, this.dirtyOwners);
                            this.setWorkingItems(merged, 'bootstrap_dirty_reconcile', 'draft+server', this.siteId);
                            this.editGeneration = Number(draft.edit_generation || 0);
                            this.dirty = true;
                            this.syncStatus = 'editing';
                            this.baseRevision = 0;
                            this.debugHydrationError = '';
                            this.persistDraft();
                            if (this.detectRevisionConflicts()) {
                                // Stale local vs server (e.g. Editor intake advanced revision) — never autosave.
                            } else {
                                this.scheduleSave(false);
                            }
                            this.scanLegacyAudit();
                            return;
                        }
                        this.setWorkingItems(serverItems, 'bootstrap_server', 'server', this.siteId);
                        this.baseRevision = 0;
                        this.dirty = false;
                        this.dirtyOwners = {};
                        this.conflict = false;
                        this.conflictBannerOpen = false;
                        this.syncStatus = 'synced';
                        this.debugHydrationError = '';
                        this.writeGlobalDraft({ dirty: false });
                        this.scanLegacyAudit();
                    } catch (e) {
                        this.debugHydrationError = 'bootstrap:' + String((e && e.message) || e || 'error');
                    }
                },

                softReloadGlobal() {
                    if (this.inflight) return;
                    this.$wire.loadGlobalIdeaWarehouse().then((snap) => {
                        if (this.inflight) return;
                        // Clean tab: adopt server warehouse (shows Editor intake immediately).
                        if (!this.dirty && !this.conflict) {
                            this.bootstrapFromServer(snap);
                            return;
                        }
                        // Dirty tab: merge clean-owner server Ideas; never silent-overwrite dirty owners.
                        try {
                            this.applyServerSiteMeta(snap);
                            this.debugLivewireCount = Array.isArray(this.serverMeta.note_items)
                                ? this.serverMeta.note_items.length
                                : Number((snap && snap.item_count) || 0);
                            const serverItems = this.clonePlainItems(
                                Array.isArray(snap && snap.note_items) ? snap.note_items : this.serverMeta.note_items
                            );
                            const draftItems = this.clonePlainItems(this.items);
                            const merged = this.reconcileGlobalWorkingSet(serverItems, draftItems, this.dirtyOwners);
                            this.setWorkingItems(merged, 'soft_reload_dirty_merge', 'livewire+local', this.siteId);
                            this.debugHydrationError = '';
                            this.persistDraft();
                            this.detectRevisionConflicts();
                        } catch (e) {
                            this.debugHydrationError = 'soft_reload:' + String((e && e.message) || e || 'error');
                        }
                    }).catch((e) => {
                        this.debugHydrationError = 'soft_reload_wire:' + String((e && e.message) || e || 'error');
                    });
                },

                draftKey(siteId) { return IM_PREFIX + String(siteId || 0); },
                auditKey(siteId) { return AUDIT_PREFIX + String(siteId || 0); },

                readGlobalDraft() {
                    try {
                        const raw = localStorage.getItem(IM_GLOBAL_DRAFT);
                        if (!raw) return null;
                        const parsed = JSON.parse(raw);
                        if (!parsed || Number(parsed.v) !== 2) return null;
                        return parsed;
                    } catch (e) { return null; }
                },

                writeGlobalDraft(extra = {}) {
                    try {
                        const site_revisions = {};
                        for (const sid of Object.keys(this.siteRevisions || {})) {
                            site_revisions[sid] = Number(this.siteRevisions[sid]?.revision || 0);
                        }
                        localStorage.setItem(IM_GLOBAL_DRAFT, JSON.stringify({
                            v: 2,
                            note_items: this.items,
                            dirty: this.dirty,
                            dirty_owners: this.dirtyOwners,
                            site_revisions,
                            edit_generation: this.editGeneration,
                            default_owner_site_id: this.siteId,
                            updated_at_ms: Date.now(),
                            last_sync_attempt_at_ms: this.lastSyncAttemptAt,
                            last_sync_error: this.syncStatus === 'unsynced_error' ? 'error' : null,
                            ...extra,
                        }));
                    } catch (e) {}
                },

                persistDraft() { this.writeGlobalDraft(); },

                markDirty(ownerSiteId = null) {
                    this.editGeneration += 1;
                    this.dirty = true;
                    this.syncStatus = 'editing';
                    const oid = Number(ownerSiteId || 0);
                    if (oid > 0) {
                        this.dirtyOwners[String(oid)] = true;
                    } else {
                        for (const it of this.items) {
                            const o = this.ownerOf(it);
                            if (o > 0) this.dirtyOwners[String(o)] = true;
                        }
                    }
                    this.persistDraft();
                    this.scheduleSave(false);
                },

                scheduleSave(force) {
                    if (this.saveTimer) clearTimeout(this.saveTimer);
                    const delay = force ? 0 : DEBOUNCE_MS;
                    this.saveTimer = setTimeout(() => this.flushSave(), delay);
                    if (!this.maxSyncTimer) {
                        this.maxSyncTimer = setTimeout(() => {
                            this.maxSyncTimer = null;
                            if (this.dirty) this.flushSave();
                        }, MAX_SYNC_MS);
                    }
                },

                partitionByOwner() {
                    const out = {};
                    for (const it of this.items) {
                        const owner = this.ownerOf(it);
                        if (owner <= 0) continue;
                        const clean = this.clonePlainItem(it);
                        if (!clean) continue;
                        const key = String(owner);
                        if (!out[key]) out[key] = [];
                        // Keep owner_site_id for server-side reject of cross-owner rows; server strips warehouse keys.
                        clean.owner_site_id = owner;
                        out[key].push(clean);
                    }
                    return out;
                },

                async flushSave() {
                    if (!this.dirty) return;
                    if (this.inflight) {
                        this.pendingResave = true;
                        return;
                    }
                    if (this.conflict && !this._forceOverwrite) return;

                    const genAtSend = this.editGeneration;
                    const partitions = this.partitionByOwner();
                    let owners = Object.keys(this.dirtyOwners || {}).filter((sid) => partitions[sid]);
                    if (this._forceOverwrite && this._forceOverwriteSiteId > 0) {
                        owners = [String(this._forceOverwriteSiteId)];
                    } else if (owners.length === 0) {
                        owners = Object.keys(partitions);
                    }
                    if (owners.length === 0) {
                        this.dirty = false;
                        this.syncStatus = 'synced';
                        this.persistDraft();
                        return;
                    }

                    this.inflight = true;
                    this.inflightGeneration = genAtSend;
                    this.syncStatus = 'saving';
                    this.lastSyncAttemptAt = Date.now();
                    this.persistDraft();

                    try {
                        for (const sid of owners) {
                            const siteId = Number(sid);
                            const payload = partitions[sid] || [];
                            const meta = this.siteRevisions[sid] || { revision: 0 };
                            const serverRev = Number(
                                (this.serverMeta.sites && this.serverMeta.sites[sid]
                                    ? this.serverMeta.sites[sid].revision
                                    : meta.revision) || 0
                            );
                            const expected = (this._forceOverwrite && Number(this._forceOverwriteSiteId) === siteId)
                                ? serverRev
                                : Number(meta.revision || 0);
                            const result = await this.$wire.saveIdeaWorkspace(siteId, payload, expected);
                            const status = result?.status || 'error';
                            if (status === 'conflict') {
                                this.conflictSiteId = siteId;
                                if (result.sites) this.applyServerSiteMeta(result);
                                else {
                                    this.siteRevisions[sid] = {
                                        revision: Number(result.revision || 0),
                                        domain: String(result.domain || this.siteRevisions[sid]?.domain || ''),
                                        item_count: Number(result.item_count || 0),
                                        updated_at: result.updated_at || null,
                                    };
                                    this.serverMeta.revision = Number(result.revision || 0);
                                    this.serverMeta.item_count = Number(result.item_count || 0);
                                    this.serverMeta.updated_at = result.updated_at || null;
                                }
                                this.conflict = true;
                                this.conflictBannerOpen = true;
                                this.dirty = true;
                                this.syncStatus = 'unsynced_error';
                                this.persistDraft();
                                return;
                            }
                            if (status !== 'ok' && status !== 'noop') {
                                this.syncStatus = 'unsynced_error';
                                this.dirty = true;
                                this.persistDraft();
                                return;
                            }
                            this.siteRevisions[sid] = {
                                revision: Number(result.revision || meta.revision || 0),
                                domain: String(this.siteRevisions[sid]?.domain || ''),
                                item_count: Number(result.item_count || payload.length),
                                updated_at: result.updated_at || null,
                            };
                            delete this.dirtyOwners[sid];
                        }
                        const newer = this.editGeneration > genAtSend;
                        if (!newer && Object.keys(this.dirtyOwners).length === 0) {
                            this.dirty = false;
                            this.syncStatus = 'synced';
                        } else {
                            this.dirty = true;
                            this.syncStatus = 'editing';
                        }
                        this.persistDraft();
                    } catch (e) {
                        this.syncStatus = 'unsynced_error';
                        this.persistDraft();
                    } finally {
                        this.inflight = false;
                        this._forceOverwrite = false;
                        this._forceOverwriteSiteId = 0;
                        if (this.maxSyncTimer) { clearTimeout(this.maxSyncTimer); this.maxSyncTimer = null; }
                        if (this.pendingResave || (this.dirty && this.editGeneration > genAtSend && !this.conflict)) {
                            this.pendingResave = false;
                            this.scheduleSave(false);
                        }
                    }
                },

                handleSaveResult(result, genAtSend) {
                    // Legacy single-site path kept for conflict banner helpers; flushSave handles multi-owner.
                    const status = result?.status || 'error';
                    const newer = this.editGeneration > genAtSend;
                    if (status === 'conflict') {
                        this.conflict = true;
                        this.conflictBannerOpen = true;
                        this.dirty = true;
                        this.syncStatus = 'unsynced_error';
                        this.persistDraft();
                        return;
                    }
                    if (status === 'ok' || status === 'noop') {
                        if (!newer) {
                            this.dirty = false;
                            this.syncStatus = 'synced';
                        } else {
                            this.dirty = true;
                            this.syncStatus = 'editing';
                        }
                        this.persistDraft();
                        return;
                    }
                    this.syncStatus = 'unsynced_error';
                    this.dirty = true;
                    this.persistDraft();
                },

                statusLabel() {
                    if (this.syncStatus === 'editing') return this.labels.editing;
                    if (this.syncStatus === 'saving') return this.labels.saving;
                    if (this.syncStatus === 'unsynced_error') return this.labels.error;
                    return this.labels.synced;
                },

                siteLabel() {
                    if (this.siteId <= 0) return '—';
                    return this.siteDomain || ('#' + this.siteId);
                },

                setTab(t) { this.tab = t; },

                filteredItems() {
                    const q = (this.search || '').trim().toLowerCase();
                    const src = this.sourceTypeFilter || 'all';
                    // Global warehouse: search is independent of sourceFilterSiteId / destination.
                    return this.items.filter((it) => {
                        if (src !== 'all') {
                            const t = String(it?.source_type || 'cluster');
                            if (src === 'manual' && t !== 'manual_seed') return false;
                            if (src === 'cluster' && t !== 'cluster') return false;
                            if (src === 'generated' && t !== 'generated') return false;
                        }
                        if (!q) return true;
                        const name = String(it.cluster_name_snapshot || it.seed_text || '').toLowerCase();
                        const domain = String(it.source_domain || it.owner_domain || '').toLowerCase();
                        return name.includes(q)
                            || String(it.cluster_ref || '').toLowerCase().includes(q)
                            || domain.includes(q);
                    });
                },

                selectedCount() {
                    return Object.keys(this.selected || {}).filter((k) => this.selected[k]).length;
                },

                selectedItems() {
                    return this.items.filter((it) => this.selected[this.itemKey(it)]);
                },

                emitSelection() {
                    const items = this.selectedItems().map((it) => ({
                        source_type: it.source_type || 'cluster',
                        cluster_ref: it.cluster_ref,
                        warehouse_key: this.itemKey(it),
                        cluster_name_snapshot: it.cluster_name_snapshot || it.seed_text || it.cluster_ref,
                        name: it.cluster_name_snapshot || it.seed_text || it.cluster_ref,
                        seed_text: it.seed_text ?? null,
                        mcp_share_snapshot: it.mcp_share_snapshot ?? null,
                        target_dna_count: it.target_dna_count,
                        target_mode: it.target_mode || 'manual',
                        dna: Array.isArray(it.dna) ? it.dna : [],
                        source_site_id: Number(it.source_site_id || it.owner_site_id || this.siteId || 0) || null,
                        source_domain: String(it.source_domain || it.owner_domain || this.siteDomain || ''),
                        owner_site_id: Number(it.owner_site_id || it.source_site_id || 0) || null,
                    }));
                    const detail = { count: items.length, items, sourceSiteId: this.siteId, sourceDomain: this.siteDomain };
                    window.__imSelection = detail;
                    window.dispatchEvent(new CustomEvent('im-selection-changed', { detail }));
                },

                toggleSelect(refOrKey) {
                    const key = String(refOrKey || '');
                    this.selected[key] = !this.selected[key];
                    this.emitSelection();
                },

                selectAllVisible() {
                    for (const it of this.filteredItems()) {
                        this.selected[this.itemKey(it)] = true;
                    }
                    this.emitSelection();
                },

                clearSelection() {
                    this.selected = {};
                    this.emitSelection();
                },

                isSelected(refOrKey) { return !!this.selected[String(refOrKey || '')]; },

                untickFromDistribution(ref) {
                    if (!ref) return;
                    // Untick by cluster_ref across owners (distribution event).
                    let changed = false;
                    for (const it of this.items) {
                        if (String(it.cluster_ref) === String(ref)) {
                            const key = this.itemKey(it);
                            if (this.selected[key]) {
                                delete this.selected[key];
                                changed = true;
                            }
                        }
                    }
                    if (this.selected[ref]) {
                        delete this.selected[ref];
                        changed = true;
                    }
                    if (changed) this.emitSelection();
                },

                normalizeKey(s) {
                    return String(s || '').normalize('NFC').trim().toLowerCase().replace(/\s+/g, ' ');
                },

                findDup(ref, name) {
                    const key = this.normalizeKey(name);
                    return this.items.find((it) => {
                        if (ref && it.cluster_ref === ref) return true;
                        const n = this.normalizeKey(it.cluster_name_snapshot || it.seed_text || '');
                        return key && n === key;
                    }) || null;
                },

                sourceLabel(item) {
                    const t = String(item?.source_type || 'cluster');
                    if (t === 'manual_seed') return 'Manual';
                    if (t === 'generated') return 'Generated';
                    return 'Cluster';
                },

                isExpanded(ref) { return !!this.expanded[ref]; },
                toggleExpand(ref) { this.expanded[ref] = !this.expanded[ref]; },

                addManual() {
                    const seed = String(this.manualDraft || '').trim();
                    if (!seed) return;
                    if (this.findDup(null, seed)) { this.manualDraft = ''; return; }
                    const ref = 'manual:' + (crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Date.now()));
                    const owner = this.siteId;
                    const item = {
                        source_type: 'manual_seed',
                        cluster_ref: ref,
                        cluster_name_snapshot: seed,
                        seed_text: seed,
                        mcp_share_snapshot: null,
                        target_dna_count: 5,
                        target_mode: 'manual',
                        dna: [],
                        source_site_id: owner,
                        source_domain: this.siteDomain || '',
                        owner_site_id: owner,
                        owner_domain: this.siteDomain || '',
                        warehouse_key: owner + ':' + ref,
                    };
                    this.items.push(item);
                    this.manualDraft = '';
                    this.expanded[ref] = true;
                    this.markDirty(owner);
                    this.scanLegacyAudit();
                },

                async previewBulk() {
                    const result = await this.$wire.previewBulkIdeas(this.bulkText, this.items);
                    this.bulkPreview = result;
                },

                confirmBulk() {
                    if (!this.bulkPreview || !Array.isArray(this.bulkPreview.valid)) return;
                    const owner = this.siteId;
                    for (const raw of this.bulkPreview.valid) {
                        if (!this.findDup(raw.cluster_ref, raw.cluster_name_snapshot || raw.seed_text)) {
                            const item = { ...raw };
                            item.source_site_id = Number(item.source_site_id || owner);
                            item.source_domain = String(item.source_domain || this.siteDomain || '');
                            item.owner_site_id = owner;
                            item.owner_domain = this.siteDomain || '';
                            item.warehouse_key = owner + ':' + String(item.cluster_ref || '');
                            this.items.push(item);
                        }
                    }
                    this.bulkPreview = null;
                    this.bulkText = '';
                    this.showBulk = false;
                    this.markDirty(owner);
                    this.scanLegacyAudit();
                },

                bulkLabel(kind, count) {
                    const tpl = kind === 'valid'
                        ? (this.labels.bulkValid || 'Valid: :count')
                        : (kind === 'dups'
                            ? (this.labels.bulkDups || 'Duplicates: :count')
                            : (this.labels.bulkInvalid || 'Invalid: :count'));
                    return String(tpl).replace(':count', String(count));
                },

                buildAuditPreviewFromParsed(parsed) {
                    if (!parsed || Number(parsed.site_id) !== Number(this.siteId)) {
                        return { importable: [], duplicates: [], invalid: 0, items: [] };
                    }
                    const rawItems = Array.isArray(parsed.items) ? parsed.items : [];
                    const importable = [];
                    const duplicates = [];
                    let invalid = 0;
                    for (const row of rawItems) {
                        const name = String(row?.cluster_name_snapshot || row?.seed_text || row?.cluster_name || '').trim();
                        const ref = String(row?.cluster_ref || '').trim();
                        if (!name || !ref) { invalid += 1; continue; }
                        if (this.findDup(ref, name)) { duplicates.push(row); continue; }
                        importable.push({
                            source_type: row.source_type || (ref.startsWith('manual:') ? 'manual_seed' : (ref.startsWith('generated:') ? 'generated' : 'cluster')),
                            cluster_ref: ref,
                            cluster_name_snapshot: name,
                            seed_text: row.seed_text || null,
                            mcp_share_snapshot: row.mcp_share_snapshot ?? row.mcp_share ?? null,
                            target_dna_count: Number(row.target_dna_count || 5),
                            target_mode: row.target_mode || 'manual',
                            dna: Array.isArray(row.dna) ? row.dna : [],
                        });
                    }
                    return { importable, duplicates, invalid, items: rawItems };
                },

                // Read-only SEO Audit key — never write/delete.
                scanLegacyAudit() {
                    this.legacyScan = null;
                    if (this.siteId <= 0) return;
                    let parsed = null;
                    try {
                        const raw = localStorage.getItem(this.auditKey(this.siteId));
                        parsed = raw ? JSON.parse(raw) : null;
                    } catch (e) { parsed = null; }
                    const preview = this.buildAuditPreviewFromParsed(parsed);
                    this.legacyScan = preview;
                    if ((preview.importable || []).length === 0) {
                        this.legacyDismissed = false;
                    }
                },

                legacyImportableCount() {
                    return Array.isArray(this.legacyScan?.importable) ? this.legacyScan.importable.length : 0;
                },

                legacyBannerText() {
                    const n = this.legacyImportableCount();
                    return String(this.labels.legacyBanner || 'Found :count ideas from SEO Audit — Restore into Idea Manager')
                        .replace(':count', String(n));
                },

                openLegacyPreview() {
                    this.scanLegacyAudit();
                    this.importPreview = this.legacyScan || { importable: [], duplicates: [], invalid: 0, items: [] };
                    this.tab = 'ideas';
                },

                previewAuditImport() {
                    this.scanLegacyAudit();
                    this.importPreview = this.legacyScan || { importable: [], duplicates: [], invalid: 0, items: [] };
                },

                importSummaryText() {
                    if (!this.importPreview) return '';
                    return 'Importable: ' + this.importPreview.importable.length
                        + ' · Duplicates: ' + this.importPreview.duplicates.length
                        + ' · Invalid: ' + this.importPreview.invalid;
                },

                confirmAuditImport() {
                    if (!this.importPreview) return;
                    const owner = this.siteId;
                    for (const raw of this.importPreview.importable) {
                        if (!this.findDup(raw.cluster_ref, raw.cluster_name_snapshot)) {
                            const item = { ...raw };
                            item.source_site_id = Number(item.source_site_id || owner);
                            item.source_domain = String(item.source_domain || this.siteDomain || '');
                            item.owner_site_id = owner;
                            item.owner_domain = this.siteDomain || '';
                            item.warehouse_key = owner + ':' + String(item.cluster_ref || '');
                            this.items.push(item);
                        }
                    }
                    this.importPreview = null;
                    this.markDirty(owner);
                    this.scanLegacyAudit();
                },

                setTarget(ref, value) {
                    const item = this.items.find((it) => it.cluster_ref === ref || this.itemKey(it) === ref);
                    if (!item) return;
                    let n = parseInt(value, 10);
                    if (!Number.isFinite(n) || n < 1) n = 1;
                    if (n > 100) n = 100;
                    const specified = this.specifiedCount(item);
                    if (n < specified) n = specified;
                    item.target_dna_count = n;
                    item.target_mode = 'manual';
                    this.markDirty(this.ownerOf(item));
                },

                specifiedCount(item) {
                    return (item.dna || []).reduce((sum, d) => sum + Number(d.slots || 1), 0);
                },

                addDna(ref) {
                    const phrase = String(this.dnaDrafts[ref] || '').trim();
                    if (!phrase) return;
                    const item = this.items.find((it) => it.cluster_ref === ref || this.itemKey(it) === ref);
                    if (!item) return;
                    item.dna = Array.isArray(item.dna) ? item.dna : [];
                    const key = this.normalizeKey(phrase);
                    const existing = item.dna.find((d) => this.normalizeKey(d.phrase) === key);
                    if (existing) {
                        existing.slots = Number(existing.slots || 1) + 1;
                    } else {
                        item.dna.push({ phrase, slots: 1, source: 'manual', placement: 'after' });
                    }
                    const specified = this.specifiedCount(item);
                    if (Number(item.target_dna_count || 0) < specified) item.target_dna_count = specified;
                    this.dnaDrafts[ref] = '';
                    this.markDirty(this.ownerOf(item));
                },

                removeDna(ref, index) {
                    const item = this.items.find((it) => it.cluster_ref === ref || this.itemKey(it) === ref);
                    if (!item || !Array.isArray(item.dna)) return;
                    item.dna.splice(index, 1);
                    this.markDirty(this.ownerOf(item));
                },

                removeItem(ref) {
                    if (!window.confirm(this.labels.deleteConfirm)) return;
                    const victim = this.items.find((it) => it.cluster_ref === ref || this.itemKey(it) === ref);
                    const owner = victim ? this.ownerOf(victim) : 0;
                    const key = victim ? this.itemKey(victim) : String(ref || '');
                    const next = this.items.filter((it) => this.itemKey(it) !== key && it.cluster_ref !== ref);
                    this.setWorkingItems(next, 'remove_item', 'ui', owner);
                    if (this.selected[key]) {
                        delete this.selected[key];
                        this.emitSelection();
                    } else if (this.selected[ref]) {
                        delete this.selected[ref];
                        this.emitSelection();
                    }
                    this.markDirty(owner);
                    this.scanLegacyAudit();
                },

                addCluster(row) {
                    if (!row) return;
                    if (this.findDup(row.cluster_ref, row.cluster_name)) return;
                    const dna = Array.isArray(row.cluster_dna)
                        ? row.cluster_dna.map((d) => ({
                            phrase: d.phrase || d.value,
                            slots: 1,
                            source: 'cluster',
                            placement: 'after',
                        })).filter((d) => d.phrase)
                        : [];
                    // Provenance = Cluster source filter at add-time — never live sidebar remount.
                    const provenanceSiteId = this.sourceFilterSiteId > 0 ? this.sourceFilterSiteId : this.siteId;
                    const provenanceDomain = this.sourceFilterDomain || this.siteDomain || '';
                    // Owning workspace for persistence = default seed owner (working site), not source filter.
                    const owner = this.siteId;
                    this.items.push({
                        source_type: 'cluster',
                        cluster_ref: row.cluster_ref,
                        cluster_name_snapshot: row.cluster_name,
                        seed_text: null,
                        mcp_share_snapshot: row.mcp_share ?? 0,
                        target_dna_count: Math.max(5, dna.length || 0),
                        target_mode: 'manual',
                        dna,
                        source_site_id: provenanceSiteId,
                        source_domain: provenanceDomain,
                        owner_site_id: owner,
                        owner_domain: this.siteDomain || '',
                        warehouse_key: owner + ':' + String(row.cluster_ref || ''),
                    });
                    this.expanded[row.cluster_ref] = true;
                    this.markDirty(owner);
                    this.tab = 'ideas';
                    this.scanLegacyAudit();
                },

                conflictBodyText() {
                    const sid = String(this.conflictSiteId || 0);
                    const localRev = Number((this.readGlobalDraft()?.site_revisions || {})[sid] || this.siteRevisions[sid]?.revision || 0);
                    const serverRev = Number(this.siteRevisions[sid]?.revision || this.serverMeta.revision || 0);
                    return String(this.labels.conflictBody || '')
                        .replace(':server', String(serverRev))
                        .replace(':local', String(localRev));
                },
                conflictLocalText() {
                    return String(this.labels.conflictLocal || '')
                        .replace(':count', String(this.items.length))
                        .replace(':rev', String(this.conflictSiteId || this.baseRevision));
                },
                conflictServerText() {
                    const sid = String(this.conflictSiteId || 0);
                    const meta = this.siteRevisions[sid] || {};
                    return String(this.labels.conflictServer || '')
                        .replace(':count', String(meta.item_count || this.serverMeta.item_count || 0))
                        .replace(':rev', String(meta.revision || this.serverMeta.revision || 0))
                        .replace(':updated', String(meta.updated_at || this.serverMeta.updated_at || '—'));
                },

                confirmKeepMine() {
                    if (!window.confirm(this.labels.keepMineConfirm)) return;
                    this._forceOverwrite = true;
                    this._forceOverwriteSiteId = Number(this.conflictSiteId || this.siteId || 0);
                    this.conflict = false;
                    this.conflictBannerOpen = false;
                    this.dirty = true;
                    if (this._forceOverwriteSiteId > 0) {
                        this.dirtyOwners[String(this._forceOverwriteSiteId)] = true;
                    }
                    this.scheduleSave(true);
                },

                confirmLoadServer() {
                    if (!window.confirm(this.labels.loadServerConfirm)) return;
                    this.$wire.loadGlobalIdeaWarehouse().then((snap) => {
                        this.dirty = false;
                        this.dirtyOwners = {};
                        this.conflict = false;
                        this.conflictBannerOpen = false;
                        this.conflictSiteId = 0;
                        this.bootstrapFromServer(snap);
                    });
                },

                dismissConflict() {
                    // Closing conflict UI must keep local draft — never silent force overwrite.
                    this.conflictBannerOpen = false;
                    this.conflict = true;
                    this.dirty = true;
                    this.syncStatus = 'unsynced_error';
                    this.persistDraft();
                },
            };
        }
    </script>
</x-filament-panels::page>
