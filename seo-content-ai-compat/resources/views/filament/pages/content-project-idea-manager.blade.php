<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />
    @once
        <style>
            .im-root { display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }
            .im-stepbar {
                display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
                gap: 0.65rem; padding: 0.55rem 0.75rem;
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff;
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
                min-width: 0; display: flex; flex-direction: column; max-height: calc(100vh - 11rem);
            }
            .dark .im-side, .dark .im-main, .dark .im-right { border-color: #374151; background: #111827; }
            .im-side__head, .im-main__head, .im-right__head {
                display: flex; align-items: center; justify-content: space-between; gap: 0.4rem;
                padding: 0.55rem 0.7rem; border-bottom: 1px solid #e5e7eb; flex: 0 0 auto;
            }
            .dark .im-side__head, .dark .im-main__head, .dark .im-right__head { border-color: #374151; }
            .im-side__title, .im-main__title, .im-right__title { font-size: 0.8125rem; font-weight: 700; color: #111827; }
            .dark .im-side__title, .dark .im-main__title, .dark .im-right__title { color: #f9fafb; }
            .im-side__body, .im-main__body, .im-right__body { padding: 0.65rem; overflow: auto; flex: 1 1 auto; min-height: 0; }
            .im-right__foot {
                flex: 0 0 auto;
                padding: 0.65rem;
                border-top: 1px solid #e5e7eb;
                background: #fff;
                max-height: 42%;
                overflow: auto;
            }
            .dark .im-right__foot { border-color: #374151; background: #111827; }
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
            .im-dist__preview { max-height: 9rem; overflow: auto; font-size: 0.75rem; list-style: none; margin: 0; padding: 0; }
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

            @media (max-width: 1200px) {
                .im-layout, .im-layout.is-collapsed {
                    grid-template-columns: minmax(12rem, 15rem) minmax(0, 1fr);
                }
                .im-right { grid-column: 1 / -1; max-height: none; }
            }
            @media (max-width: 900px) {
                .im-layout, .im-layout.is-collapsed { grid-template-columns: 1fr; }
                .im-side, .im-main, .im-right { max-height: none; }
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
                <div class="im-side__body space-y-2" x-show="!sidebarCollapsed" x-cloak>
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
                        <div class="space-y-3">
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

                            <template x-if="filteredItems().length === 0">
                                <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_empty') }}</div>
                            </template>

                            <div class="space-y-2">
                                <template x-for="item in filteredItems()" :key="item.cluster_ref">
                                    <div class="im-card" :class="{ 'is-selected': isSelected(item.cluster_ref) }">
                                        <div class="im-card__top">
                                            <div class="min-w-0 flex-1 flex gap-2">
                                                <input type="checkbox" class="mt-1" :checked="isSelected(item.cluster_ref)" x-on:change="toggleSelect(item.cluster_ref)" />
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
                                <span x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_distribution_warn_count', ['count' => '__N__']) }}'.replace('__N__', String(warningCount))"></span>
                                ·
                                <span x-text="'{{ __('seo-content-ai::filament.projects.idea_manager_distribution_exact_count', ['count' => '__N__']) }}'.replace('__N__', String(exactCount))"></span>
                            </div>
                        </div>
                        <p class="im-dist__hint">{{ __('seo-content-ai::filament.projects.idea_manager_distribution_contract_hint') }}</p>
                        <div class="text-xs text-gray-500" x-show="checking" x-cloak>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_checking') }}</div>
                        <ul class="im-dist__preview" x-show="displayItems.length > 0" x-cloak>
                            <template x-for="(it, i) in displayItems" :key="it.cluster_ref || i">
                                <li :class="{ 'is-dup': it.exact_match, 'is-warn': it.warning && !it.exact_match }">
                                    <div class="min-w-0 flex-1">
                                        <div x-text="it.name"></div>
                                        <div class="im-dist__dup" x-show="it.exact_match" x-cloak>
                                            <span>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_exact') }}</span>
                                            <span x-show="it.matched" x-text="'· ' + (it.matched || '')"></span>
                                            <span x-show="it.matched_type" class="text-gray-400 font-normal" x-text="'[' + (it.matched_type || '') + ']'"></span>
                                        </div>
                                        <div class="im-dist__warn" x-show="it.warning && !it.exact_match && it.check_status !== 'unavailable'" x-cloak>
                                            <span>{{ __('seo-content-ai::filament.projects.idea_manager_distribution_warn') }}</span>
                                            <span x-show="it.similarity_score != null" x-text="'· score ' + Number(it.similarity_score).toFixed(3)"></span>
                                            <span x-show="it.matched" x-text="'· ' + (it.matched || '')"></span>
                                            <span x-show="it.matched_type" class="text-gray-400 font-normal" x-text="'[' + (it.matched_type || '') + ']'"></span>
                                        </div>
                                        <div class="text-[11px] text-amber-700 dark:text-amber-300" x-show="it.check_status === 'unavailable'" x-cloak>
                                            {{ __('seo-content-ai::filament.projects.idea_manager_distribution_unknown') }}
                                        </div>
                                        <div class="text-[11px] text-gray-400" x-show="it.reason && it.check_status !== 'unavailable'" x-text="it.reason"></div>
                                    </div>
                                    <button type="button" class="im-btn im-btn--ghost" x-on:click="untick(it.cluster_ref)">
                                        {{ __('seo-content-ai::filament.projects.idea_manager_distribution_untick') }}
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <div class="im-dist__result" :class="{ 'is-error': result && !result.ok }" x-show="result" x-cloak x-text="resultLabel()"></div>
                        <p class="im-dist__hint" x-show="!canConfirmNow() && !confirming" x-cloak>
                            {{ __('seo-content-ai::filament.projects.idea_manager_distribution_need_valid') }}
                        </p>
                        <button
                            type="button"
                            class="im-btn im-btn--primary"
                            data-im-distribution-confirm="1"
                            :disabled="!canConfirmNow()"
                            x-on:click="confirm()"
                        >
                            {{ __('seo-content-ai::filament.projects.idea_manager_distribution_confirm') }}
                        </button>
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
                checking: false,
                confirming: false,
                result: null,
                _timer: null,
                _reqSeq: 0,

                get displayItems() {
                    // Selection is SSOT — analysis overlays evidence; never hide while checking.
                    return (this.items || []).map((it) => {
                        const ref = it.cluster_ref;
                        const name = it.name || it.cluster_name_snapshot || ref;
                        const ev = this.analysisByRef[ref] || {};
                        return {
                            cluster_ref: ref,
                            name: name,
                            exact_match: !!ev.exact_match,
                            warning: !!ev.warning,
                            check_status: ev.check_status || (this.checking ? 'pending' : 'analyzed'),
                            similarity_score: (ev.similarity_score !== undefined) ? ev.similarity_score : null,
                            matched: ev.matched || null,
                            matched_type: ev.matched_type || null,
                            reason: ev.reason || null,
                        };
                    });
                },

                init() {
                    if (this.count > 0 && this.targetSiteId > 0) {
                        this.schedulePreview();
                    }
                },

                canConfirmNow() {
                    return this.targetSiteId > 0
                        && this.items.length > 0
                        && !this.confirming;
                },

                onSelection(detail) {
                    const d = detail || {};
                    this.count = Number(d.count || 0);
                    this.items = Array.isArray(d.items) ? d.items : [];
                    this.result = null;
                    // Drop analysis for removed refs; keep others until refresh.
                    const keep = {};
                    for (const it of this.items) {
                        if (this.analysisByRef[it.cluster_ref]) {
                            keep[it.cluster_ref] = this.analysisByRef[it.cluster_ref];
                        }
                    }
                    this.analysisByRef = keep;
                    this.recomputeCounts();
                    this.schedulePreview();
                },

                onTargetChanged(detail) {
                    const d = detail || {};
                    const raw = (d.siteId !== undefined && d.siteId !== null)
                        ? d.siteId
                        : ((d[0] !== undefined && d[0] !== null) ? d[0] : this.targetSiteId);
                    this.targetSiteId = Number(raw || 0);
                    if (d.domain) this.targetDomain = String(d.domain);
                    this.result = null;
                    // Invalidate analysis only — keep selected items visible.
                    this.analysisByRef = {};
                    this.warningCount = 0;
                    this.exactCount = 0;
                    this.schedulePreview();
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
                    this.schedulePreview();
                },

                recomputeCounts() {
                    let w = 0, e = 0;
                    for (const it of this.displayItems) {
                        if (it.exact_match) e += 1;
                        else if (it.warning) w += 1;
                    }
                    this.warningCount = w;
                    this.exactCount = e;
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

                async confirm() {
                    if (!this.canConfirmNow()) {
                        this.result = {
                            ok: false,
                            distributed: 0,
                            already_present: 0,
                            failed: 0,
                            duplicate: 0,
                            message: 'confirm_not_eligible',
                        };
                        return;
                    }
                    this.confirming = true;
                    this.result = null;
                    try {
                        const res = await this.$wire.confirmDistribution(this.items);
                        this.result = res || { ok: false, message: 'empty_response' };
                        // Copy, never consume: keep selection + warehouse; only refresh dest advisory.
                        // Source Ideas are not deleted/hidden by Confirm (server: source_unchanged).
                        await this.refreshPreview();
                    } catch (e) {
                        this.result = {
                            ok: false,
                            distributed: 0,
                            already_present: 0,
                            failed: this.items.length,
                            duplicate: 0,
                            message: 'request_failed',
                        };
                    } finally {
                        this.confirming = false;
                    }
                },

                resultLabel() {
                    if (!this.result) return '';
                    const tpl = @js(__('seo-content-ai::filament.projects.idea_manager_distribution_success'));
                    return String(tpl)
                        .replace(':ok', String(this.result.distributed || 0))
                        .replace(':already', String(this.result.already_present || 0))
                        .replace(':failed', String(this.result.failed || 0))
                        .replace(':dup', String(this.result.duplicate || 0));
                },
            };
        }

        function ideaManagerRoot(cfg) {
            const IM_PREFIX = 'seoOps:idea-manager:draft:v1:site:';
            const AUDIT_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:';
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
                serverMeta: { revision: 0, updated_at: null, item_count: 0, note_items: [] },
                dirty: false,
                editGeneration: 0,
                syncStatus: 'synced',
                conflict: false,
                conflictBannerOpen: false,
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

                init() {
                    this.bootstrapFromServer(cfg.server);
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
                    const stamped = String((item && item.source_domain) || '').trim();
                    if (stamped) return stamped;
                    // Legacy items without provenance: warehouse ownership domain (stable across source filter).
                    return this.siteDomain || ('#' + this.siteId);
                },

                // Global Domain / working warehouse ownership change only.
                onSiteChanged(nextId) {
                    const id = Number(nextId || 0);
                    if (this.siteId > 0 && this.dirty) this.persistDraft();
                    this.siteId = id;
                    this.conflict = false;
                    this.importPreview = null;
                    this.bulkPreview = null;
                    this.legacyScan = null;
                    this.legacyDismissed = false;
                    this.selected = {};
                    this.emitSelection();
                    if (id <= 0) {
                        this.items = [];
                        this.baseRevision = 0;
                        this.dirty = false;
                        this.syncStatus = 'synced';
                        return;
                    }
                    this.$wire.loadIdeaWorkspace(id).then((snap) => {
                        this.siteDomain = String((snap && snap.domain) || '');
                        this.bootstrapFromServer(snap);
                    });
                },

                bootstrapFromServer(snap) {
                    const server = snap && typeof snap === 'object' ? snap : {
                        revision: 0, note_items: [], updated_at: null, item_count: 0
                    };
                    this.serverMeta = {
                        revision: Number(server.revision || 0),
                        updated_at: server.updated_at || null,
                        item_count: Number(server.item_count || (server.note_items || []).length || 0),
                        note_items: Array.isArray(server.note_items) ? server.note_items : [],
                    };
                    const draft = this.readDraft(this.siteId);
                    if (draft && draft.dirty) {
                        this.items = Array.isArray(draft.note_items) ? draft.note_items : [];
                        this.baseRevision = Number(draft.base_revision || 0);
                        this.editGeneration = Number(draft.edit_generation || 0);
                        this.dirty = true;
                        this.syncStatus = 'editing';
                        if (this.baseRevision !== this.serverMeta.revision) {
                            this.conflict = true;
                            this.conflictBannerOpen = true;
                        } else {
                            this.scheduleSave(false);
                        }
                        this.scanLegacyAudit();
                        return;
                    }
                    this.items = Array.isArray(this.serverMeta.note_items) ? structuredClone(this.serverMeta.note_items) : [];
                    this.baseRevision = this.serverMeta.revision;
                    this.dirty = false;
                    this.syncStatus = 'synced';
                    this.writeDraft({ dirty: false });
                    this.scanLegacyAudit();
                },

                draftKey(siteId) { return IM_PREFIX + String(siteId || 0); },
                auditKey(siteId) { return AUDIT_PREFIX + String(siteId || 0); },

                readDraft(siteId) {
                    try {
                        const raw = localStorage.getItem(this.draftKey(siteId));
                        if (!raw) return null;
                        const parsed = JSON.parse(raw);
                        if (!parsed || Number(parsed.site_id) !== Number(siteId)) return null;
                        return parsed;
                    } catch (e) { return null; }
                },

                writeDraft(extra = {}) {
                    if (this.siteId <= 0) return;
                    try {
                        localStorage.setItem(this.draftKey(this.siteId), JSON.stringify({
                            v: 1,
                            site_id: this.siteId,
                            base_revision: this.baseRevision,
                            note_items: this.items,
                            dirty: this.dirty,
                            edit_generation: this.editGeneration,
                            updated_at_ms: Date.now(),
                            last_sync_attempt_at_ms: this.lastSyncAttemptAt,
                            last_sync_error: this.syncStatus === 'unsynced_error' ? 'error' : null,
                            ...extra,
                        }));
                    } catch (e) {}
                },

                persistDraft() { this.writeDraft(); },

                markDirty() {
                    this.editGeneration += 1;
                    this.dirty = true;
                    this.syncStatus = 'editing';
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

                async flushSave() {
                    if (!this.dirty || this.siteId <= 0) return;
                    if (this.inflight) {
                        this.pendingResave = true;
                        return;
                    }
                    if (this.conflict && !this._forceOverwrite) return;

                    const genAtSend = this.editGeneration;
                    const expected = this._forceOverwrite
                        ? this.serverMeta.revision
                        : this.baseRevision;
                    const payload = structuredClone(this.items);
                    this.inflight = true;
                    this.inflightGeneration = genAtSend;
                    this.syncStatus = 'saving';
                    this.lastSyncAttemptAt = Date.now();
                    this.persistDraft();

                    try {
                        const result = await this.$wire.saveIdeaWorkspace(this.siteId, payload, expected);
                        this.handleSaveResult(result, genAtSend);
                    } catch (e) {
                        this.syncStatus = 'unsynced_error';
                        this.persistDraft();
                    } finally {
                        this.inflight = false;
                        this._forceOverwrite = false;
                        if (this.maxSyncTimer) { clearTimeout(this.maxSyncTimer); this.maxSyncTimer = null; }
                        if (this.pendingResave || (this.dirty && this.editGeneration > genAtSend)) {
                            this.pendingResave = false;
                            this.scheduleSave(false);
                        }
                    }
                },

                handleSaveResult(result, genAtSend) {
                    const status = result?.status || 'error';
                    const newer = this.editGeneration > genAtSend;

                    if (status === 'conflict') {
                        this.serverMeta = {
                            revision: Number(result.revision || 0),
                            updated_at: result.updated_at || null,
                            item_count: Number(result.item_count || (result.note_items || []).length || 0),
                            note_items: Array.isArray(result.note_items) ? result.note_items : [],
                        };
                        // Never overwrite newer working set with conflict body.
                        this.conflict = true;
                        this.conflictBannerOpen = true;
                        this.dirty = true;
                        this.syncStatus = 'unsynced_error';
                        this.persistDraft();
                        return;
                    }

                    if (status === 'ok' || status === 'noop') {
                        const newRev = Number(result.revision || this.baseRevision);
                        this.baseRevision = newRev;
                        this.serverMeta.revision = newRev;
                        this.serverMeta.updated_at = result.updated_at || this.serverMeta.updated_at;
                        this.serverMeta.item_count = Number(result.item_count || this.items.length);
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
                    return this.items.filter((it) => {
                        if (src !== 'all') {
                            const t = String(it?.source_type || 'cluster');
                            if (src === 'manual' && t !== 'manual_seed') return false;
                            if (src === 'cluster' && t !== 'cluster') return false;
                            if (src === 'generated' && t !== 'generated') return false;
                        }
                        if (!q) return true;
                        const name = String(it.cluster_name_snapshot || it.seed_text || '').toLowerCase();
                        return name.includes(q) || String(it.cluster_ref || '').toLowerCase().includes(q);
                    });
                },

                selectedCount() {
                    return Object.keys(this.selected || {}).filter((k) => this.selected[k]).length;
                },

                selectedItems() {
                    return this.items.filter((it) => this.selected[it.cluster_ref]);
                },

                emitSelection() {
                    const items = this.selectedItems().map((it) => ({
                        source_type: it.source_type || 'cluster',
                        cluster_ref: it.cluster_ref,
                        cluster_name_snapshot: it.cluster_name_snapshot || it.seed_text || it.cluster_ref,
                        name: it.cluster_name_snapshot || it.seed_text || it.cluster_ref,
                        seed_text: it.seed_text ?? null,
                        mcp_share_snapshot: it.mcp_share_snapshot ?? null,
                        target_dna_count: it.target_dna_count,
                        target_mode: it.target_mode || 'manual',
                        dna: Array.isArray(it.dna) ? it.dna : [],
                        source_site_id: Number(it.source_site_id || this.siteId || 0) || null,
                        source_domain: String(it.source_domain || this.siteDomain || ''),
                    }));
                    const detail = { count: items.length, items, sourceSiteId: this.siteId, sourceDomain: this.siteDomain };
                    window.__imSelection = detail;
                    window.dispatchEvent(new CustomEvent('im-selection-changed', { detail }));
                },

                toggleSelect(ref) {
                    this.selected[ref] = !this.selected[ref];
                    this.emitSelection();
                },

                selectAllVisible() {
                    for (const it of this.filteredItems()) {
                        this.selected[it.cluster_ref] = true;
                    }
                    this.emitSelection();
                },

                clearSelection() {
                    this.selected = {};
                    this.emitSelection();
                },

                isSelected(ref) { return !!this.selected[ref]; },

                untickFromDistribution(ref) {
                    if (!ref) return;
                    if (this.selected[ref]) {
                        delete this.selected[ref];
                        this.emitSelection();
                    }
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
                    this.items.push({
                        source_type: 'manual_seed',
                        cluster_ref: ref,
                        cluster_name_snapshot: seed,
                        seed_text: seed,
                        mcp_share_snapshot: null,
                        target_dna_count: 5,
                        target_mode: 'manual',
                        dna: [],
                        source_site_id: this.siteId,
                        source_domain: this.siteDomain || '',
                    });
                    this.manualDraft = '';
                    this.expanded[ref] = true;
                    this.markDirty();
                    this.scanLegacyAudit();
                },

                async previewBulk() {
                    const result = await this.$wire.previewBulkIdeas(this.bulkText, this.items);
                    this.bulkPreview = result;
                },

                confirmBulk() {
                    if (!this.bulkPreview || !Array.isArray(this.bulkPreview.valid)) return;
                    for (const item of this.bulkPreview.valid) {
                        if (!this.findDup(item.cluster_ref, item.cluster_name_snapshot || item.seed_text)) {
                            this.items.push(item);
                        }
                    }
                    this.bulkPreview = null;
                    this.bulkText = '';
                    this.showBulk = false;
                    this.markDirty();
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
                    for (const item of this.importPreview.importable) {
                        if (!this.findDup(item.cluster_ref, item.cluster_name_snapshot)) {
                            this.items.push(item);
                        }
                    }
                    this.importPreview = null;
                    this.markDirty();
                    this.scanLegacyAudit();
                },

                setTarget(ref, value) {
                    const item = this.items.find((it) => it.cluster_ref === ref);
                    if (!item) return;
                    let n = parseInt(value, 10);
                    if (!Number.isFinite(n) || n < 1) n = 1;
                    if (n > 100) n = 100;
                    const specified = this.specifiedCount(item);
                    if (n < specified) n = specified;
                    item.target_dna_count = n;
                    item.target_mode = 'manual';
                    this.markDirty();
                },

                specifiedCount(item) {
                    return (item.dna || []).reduce((sum, d) => sum + Number(d.slots || 1), 0);
                },

                addDna(ref) {
                    const phrase = String(this.dnaDrafts[ref] || '').trim();
                    if (!phrase) return;
                    const item = this.items.find((it) => it.cluster_ref === ref);
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
                    this.markDirty();
                },

                removeDna(ref, index) {
                    const item = this.items.find((it) => it.cluster_ref === ref);
                    if (!item || !Array.isArray(item.dna)) return;
                    item.dna.splice(index, 1);
                    this.markDirty();
                },

                removeItem(ref) {
                    if (!window.confirm(this.labels.deleteConfirm)) return;
                    this.items = this.items.filter((it) => it.cluster_ref !== ref);
                    if (this.selected[ref]) {
                        delete this.selected[ref];
                        this.emitSelection();
                    }
                    this.markDirty();
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
                    });
                    this.expanded[row.cluster_ref] = true;
                    this.markDirty();
                    this.tab = 'ideas';
                    this.scanLegacyAudit();
                },

                conflictBodyText() {
                    return String(this.labels.conflictBody || '')
                        .replace(':server', String(this.serverMeta.revision))
                        .replace(':local', String(this.baseRevision));
                },
                conflictLocalText() {
                    return String(this.labels.conflictLocal || '')
                        .replace(':count', String(this.items.length))
                        .replace(':rev', String(this.baseRevision));
                },
                conflictServerText() {
                    return String(this.labels.conflictServer || '')
                        .replace(':count', String(this.serverMeta.item_count))
                        .replace(':rev', String(this.serverMeta.revision))
                        .replace(':updated', String(this.serverMeta.updated_at || '—'));
                },

                confirmKeepMine() {
                    if (!window.confirm(this.labels.keepMineConfirm)) return;
                    this._forceOverwrite = true;
                    this.conflict = false;
                    this.conflictBannerOpen = false;
                    this.dirty = true;
                    this.scheduleSave(true);
                },

                confirmLoadServer() {
                    if (!window.confirm(this.labels.loadServerConfirm)) return;
                    this.items = structuredClone(this.serverMeta.note_items || []);
                    this.baseRevision = this.serverMeta.revision;
                    this.dirty = false;
                    this.conflict = false;
                    this.conflictBannerOpen = false;
                    this.syncStatus = 'synced';
                    this.persistDraft();
                    this.scanLegacyAudit();
                },

                dismissConflict() {
                    // Closing conflict UI must keep local draft.
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
