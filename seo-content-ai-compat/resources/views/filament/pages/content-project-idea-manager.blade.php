<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />
    @once
        <style>
            .im-root { display: flex; flex-direction: column; gap: 0.85rem; }
            .im-toolbar {
                display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
                gap: 0.65rem; padding: 0.55rem 0.75rem;
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff;
            }
            .dark .im-toolbar { border-color: #374151; background: #111827; }
            .im-toolbar__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; min-width: 0; }
            .im-toolbar__domain { font-size: 0.875rem; font-weight: 600; color: #111827; }
            .dark .im-toolbar__domain { color: #f9fafb; }
            .im-toolbar__hint { font-size: 0.75rem; color: #6b7280; }
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

            .im-tabs { display: flex; flex-wrap: wrap; gap: 0.35rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.4rem; }
            .dark .im-tabs { border-color: #374151; }
            .im-tab {
                border: 1px solid transparent; background: transparent; border-radius: 0.55rem;
                padding: 0.4rem 0.75rem; font-size: 0.8125rem; font-weight: 600; color: #6b7280; cursor: pointer;
            }
            .im-tab:hover { background: #f3f4f6; color: #111827; }
            .im-tab.is-active { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
            .dark .im-tab:hover { background: #1f2937; color: #f9fafb; }
            .dark .im-tab.is-active { background: rgba(37,99,235,.2); color: #93c5fd; border-color: #1e3a8a; }

            .im-banner {
                border-radius: 0.75rem; padding: 0.75rem 0.9rem; border: 1px solid #bfdbfe;
                background: #eff6ff; display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: center; justify-content: space-between;
            }
            .dark .im-banner { border-color: #1e3a8a; background: rgba(30,58,138,.35); }
            .im-banner__text { font-size: 0.875rem; color: #1e3a8a; font-weight: 600; }
            .dark .im-banner__text { color: #bfdbfe; }
            .im-banner__sub { font-size: 0.75rem; color: #64748b; font-weight: 400; margin-top: 0.15rem; }

            .im-actions { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; }
            .im-input {
                border: 1px solid #d1d5db; border-radius: 0.55rem; padding: 0.45rem 0.65rem;
                font-size: 0.875rem; background: #fff; color: #111827; min-width: 12rem;
            }
            .dark .im-input { border-color: #4b5563; background: #0f172a; color: #f8fafc; }
            .im-btn {
                display: inline-flex; align-items: center; justify-content: center; gap: 0.3rem;
                border-radius: 0.55rem; border: 1px solid transparent; padding: 0.45rem 0.75rem;
                font-size: 0.8125rem; font-weight: 600; cursor: pointer; line-height: 1.2;
            }
            .im-btn:disabled { opacity: .55; cursor: not-allowed; }
            .im-btn--primary { background: #2563eb; color: #fff; }
            .im-btn--primary:hover { background: #1d4ed8; }
            .im-btn--success { background: #16a34a; color: #fff; }
            .im-btn--success:hover { background: #15803d; }
            .im-btn--ghost { background: #f3f4f6; color: #111827; border-color: #e5e7eb; }
            .im-btn--ghost:hover { background: #e5e7eb; }
            .im-btn--danger { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
            .im-btn--danger:hover { background: #ffe4e6; }
            .dark .im-btn--ghost { background: #1f2937; color: #e5e7eb; border-color: #374151; }
            .dark .im-btn--danger { background: rgba(190,18,60,.2); color: #fecdd3; border-color: #9f1239; }

            .im-panel {
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff; padding: 0.75rem;
            }
            .dark .im-panel { border-color: #374151; background: #111827; }
            .im-empty {
                border: 1px dashed #d1d5db; border-radius: 0.75rem; padding: 1.75rem 1rem;
                text-align: center; color: #6b7280; font-size: 0.875rem; background: #fafafa;
            }
            .dark .im-empty { border-color: #4b5563; background: #0b1220; color: #9ca3af; }

            .im-card {
                border: 1px solid #e5e7eb; border-radius: 0.75rem; background: #fff; padding: 0.85rem 0.95rem;
                display: flex; flex-direction: column; gap: 0.55rem;
            }
            .dark .im-card { border-color: #374151; background: #0f172a; }
            .im-card__top { display: flex; flex-wrap: wrap; gap: 0.65rem; justify-content: space-between; align-items: flex-start; }
            .im-card__title { font-size: 0.95rem; font-weight: 650; color: #111827; word-break: break-word; }
            .dark .im-card__title { color: #f8fafc; }
            .im-card__meta { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.3rem; }
            .im-pill {
                display: inline-flex; align-items: center; border-radius: 9999px; padding: 0.15rem 0.5rem;
                font-size: 0.7rem; font-weight: 600; background: #f3f4f6; color: #374151;
            }
            .dark .im-pill { background: #1f2937; color: #d1d5db; }
            .im-pill--blue { background: #eff6ff; color: #1d4ed8; }
            .im-pill--green { background: #ecfdf5; color: #047857; }
            .dark .im-pill--blue { background: rgba(29,78,216,.25); color: #93c5fd; }
            .dark .im-pill--green { background: rgba(4,120,87,.25); color: #6ee7b7; }
            .im-card__controls { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; }
            .im-target { width: 4.25rem; }
            .im-dna-row { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
            .im-dna-chip {
                display: inline-flex; align-items: center; gap: 0.25rem; border-radius: 9999px;
                background: #f3f4f6; padding: 0.15rem 0.45rem; font-size: 0.72rem; color: #111827;
            }
            .dark .im-dna-chip { background: #1f2937; color: #e5e7eb; }
            .im-dna-chip button { border: 0; background: transparent; color: #be123c; cursor: pointer; font-weight: 700; }

            .im-cluster-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
            .im-cluster-row {
                display: flex; gap: 0.75rem; align-items: center; justify-content: space-between;
                border: 1px solid #e5e7eb; border-radius: 0.65rem; padding: 0.65rem 0.75rem; background: #fff;
            }
            .dark .im-cluster-row { border-color: #374151; background: #0f172a; }
            .im-cluster-row__body { min-width: 0; flex: 1; }
            .im-cluster-row__name { font-size: 0.875rem; font-weight: 650; color: #111827; }
            .dark .im-cluster-row__name { color: #f8fafc; }
            .im-cluster-row__meta { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.25rem; }

            .im-conflict { border-color: #fcd34d; background: #fffbeb; }
            .dark .im-conflict { border-color: #92400e; background: rgba(146,64,14,.25); }

            @media (max-width: 900px) {
                .im-input { min-width: 100%; width: 100%; }
                .im-actions { width: 100%; }
                .im-card__controls { width: 100%; }
            }
        </style>
    @endonce

    @php
        $site = $this->workingSitePayload;
        $server = is_array($this->workspaceSnapshot) ? $this->workspaceSnapshot : null;
        $plannerUrl = \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::getUrl();
    @endphp

    <div
        class="im-root"
        wire:ignore.self
        x-data="ideaManagerRoot({
            siteId: {{ (int) ($site['site_id'] ?? 0) }},
            siteDomain: @js((string) ($site['domain'] ?? '')),
            server: @js($server),
            clusterRows: @js($this->clusterRows),
            clusterTotal: {{ (int) $this->clusterTotal }},
            activeTab: @js($this->activeTab),
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
        x-on:idea-manager-cluster-rows.window="onClusterRows($event.detail)"
    >
        {{-- Compact header (Filament page title already hidden via getHeader=null) --}}
        <div class="im-toolbar">
            <div class="im-toolbar__meta">
                <span class="im-toolbar__domain" x-text="siteLabel()"></span>
                <span class="im-toolbar__hint">{{ __('seo-content-ai::filament.projects.idea_manager_toolbar_hint') }}</span>
            </div>
            <div class="im-actions">
                <span
                    class="im-status"
                    :class="{
                        'im-status--editing': syncStatus === 'editing',
                        'im-status--saving': syncStatus === 'saving',
                        'im-status--synced': syncStatus === 'synced',
                        'im-status--error': syncStatus === 'unsynced_error'
                    }"
                    x-text="statusLabel()"
                ></span>
                <button type="button" class="im-btn im-btn--ghost" x-show="syncStatus === 'unsynced_error'" x-cloak x-on:click="scheduleSave(true)">
                    {{ __('seo-content-ai::filament.projects.idea_manager_retry_save') }}
                </button>
            </div>
        </div>

        <template x-if="siteId <= 0">
            <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_pick_site') }}</div>
        </template>

        <template x-if="siteId > 0">
            <div class="space-y-3">
                <div x-show="conflict" x-cloak class="im-panel im-conflict">
                    <div class="font-semibold text-amber-900 dark:text-amber-100">{{ __('seo-content-ai::filament.projects.idea_manager_conflict_title') }}</div>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200" x-text="conflictBodyText()"></p>
                    <div class="mt-2 grid gap-1 text-xs text-amber-900 dark:text-amber-100">
                        <div x-text="conflictLocalText()"></div>
                        <div x-text="conflictServerText()"></div>
                    </div>
                    <div class="im-actions mt-3">
                        <button type="button" class="im-btn im-btn--primary" x-on:click="confirmKeepMine()">{{ __('seo-content-ai::filament.projects.idea_manager_keep_mine') }}</button>
                        <button type="button" class="im-btn im-btn--ghost" x-on:click="confirmLoadServer()">{{ __('seo-content-ai::filament.projects.idea_manager_load_server') }}</button>
                        <button type="button" class="im-btn im-btn--ghost" x-on:click="dismissConflict()">{{ __('seo-content-ai::filament.projects.idea_manager_conflict_dismiss') }}</button>
                    </div>
                </div>

                <div
                    x-show="legacyImportableCount() > 0 && !legacyDismissed"
                    x-cloak
                    class="im-banner"
                    data-im-legacy-banner="1"
                >
                    <div>
                        <div class="im-banner__text" x-text="legacyBannerText()"></div>
                        <div class="im-banner__sub">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_banner_hint') }}</div>
                    </div>
                    <div class="im-actions">
                        <button type="button" class="im-btn im-btn--primary" x-on:click="openLegacyPreview()">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_restore') }}</button>
                        <button type="button" class="im-btn im-btn--ghost" x-on:click="legacyDismissed = true">{{ __('seo-content-ai::filament.projects.idea_manager_legacy_dismiss') }}</button>
                    </div>
                </div>

                <div class="im-tabs" role="tablist">
                    <button type="button" class="im-tab" :class="tab === 'ideas' ? 'is-active' : ''" x-on:click="setTab('ideas')">{{ __('seo-content-ai::filament.projects.idea_manager_tab_ideas') }}</button>
                    <button type="button" class="im-tab" :class="tab === 'cluster' ? 'is-active' : ''" x-on:click="setTab('cluster'); $wire.setActiveTab('cluster')">{{ __('seo-content-ai::filament.projects.idea_manager_tab_cluster') }}</button>
                    <button type="button" class="im-tab" :class="tab === 'published' ? 'is-active' : ''" x-on:click="setTab('published'); $wire.setActiveTab('published')">{{ __('seo-content-ai::filament.projects.idea_manager_tab_published') }}</button>
                </div>

                {{-- Ideas --}}
                <div x-show="tab === 'ideas'" class="space-y-3">
                    <div class="im-panel space-y-3">
                        <div class="im-actions">
                            <input type="search" class="im-input" style="flex:1;min-width:14rem" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_search') }}" x-model="search" />
                            <input type="text" class="im-input" style="flex:1;min-width:14rem" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_add_placeholder') }}" x-model="manualDraft" x-on:keydown.enter.prevent="addManual()" />
                            <button type="button" class="im-btn im-btn--success" x-on:click="addManual()">{{ __('seo-content-ai::filament.projects.idea_manager_add_manual') }}</button>
                            <button type="button" class="im-btn im-btn--primary" x-on:click="showBulk = !showBulk">{{ __('seo-content-ai::filament.projects.idea_manager_bulk_add') }}</button>
                            <button type="button" class="im-btn im-btn--ghost" x-on:click="previewAuditImport()">{{ __('seo-content-ai::filament.projects.idea_manager_import_audit') }}</button>
                        </div>
                        <p class="text-xs text-gray-500">{{ __('seo-content-ai::filament.projects.idea_manager_ai_fill_hint') }}
                            <a class="underline" :href="plannerUrl" target="_blank" rel="noopener">Content Planning</a>
                        </p>

                        <div x-show="showBulk" x-cloak class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <textarea class="im-input w-full min-h-[8rem] font-mono text-sm" style="width:100%" x-model="bulkText" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_bulk_placeholder') }}"></textarea>
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
                                    <ul class="max-h-36 overflow-auto text-sm space-y-1">
                                        <template x-for="(row, idx) in importPreview.importable.slice(0, 40)" :key="'imp-'+idx">
                                            <li class="text-gray-700 dark:text-gray-200" x-text="row.cluster_name_snapshot || row.seed_text"></li>
                                        </template>
                                    </ul>
                                    <button type="button" class="im-btn im-btn--primary" x-on:click="confirmAuditImport()">{{ __('seo-content-ai::filament.projects.idea_manager_import_confirm') }}</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <template x-if="filteredItems().length === 0">
                        <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_empty') }}</div>
                    </template>

                    <div class="space-y-2">
                        <template x-for="item in filteredItems()" :key="item.cluster_ref">
                            <div class="im-card">
                                <div class="im-card__top">
                                    <div class="min-w-0 flex-1">
                                        <div class="im-card__title" x-text="item.cluster_name_snapshot || item.seed_text"></div>
                                        <div class="im-card__meta">
                                            <span class="im-pill" x-text="sourceLabel(item)"></span>
                                            <span class="im-pill im-pill--blue" x-text="'DNA ' + specifiedCount(item) + '/' + item.target_dna_count"></span>
                                            <span class="im-pill im-pill--green" x-show="Number(item.target_dna_count || 0) > specifiedCount(item)" x-text="'AI +' + (Number(item.target_dna_count || 0) - specifiedCount(item))"></span>
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

                {{-- Cluster Notes --}}
                <div x-show="tab === 'cluster'" class="space-y-3">
                    <div class="im-panel">
                        <div class="im-actions">
                            <input type="search" class="im-input" style="flex:1" wire:model="clusterSearch" placeholder="{{ __('seo-content-ai::filament.projects.idea_manager_cluster_search') }}" />
                            <button type="button" class="im-btn im-btn--ghost" wire:click="applyClusterSearch">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_apply') }}</button>
                            <select class="im-input" style="min-width:9rem" wire:model.live="clusterFilter">
                                <option value="all">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_all') }}</option>
                                <option value="mcp_low">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_mcp_low') }}</option>
                                <option value="has_focus">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_has_focus') }}</option>
                                <option value="no_focus">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_filter_no_focus') }}</option>
                            </select>
                        </div>
                        <div wire:loading.flex wire:target="loadClusterSuggestions,applyClusterSearch,gotoClusterPage,updatedClusterFilter" class="mt-2 text-sm text-gray-500">
                            {{ __('seo-content-ai::filament.projects.idea_manager_cluster_loading') }}
                        </div>
                    </div>

                    <template x-if="(clusterRows || []).length === 0">
                        <div class="im-empty">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_empty') }}</div>
                    </template>

                    <ul class="im-cluster-list">
                        <template x-for="row in clusterRows" :key="row.cluster_ref">
                            <li class="im-cluster-row">
                                <div class="im-cluster-row__body">
                                    <div class="im-cluster-row__name" x-text="row.cluster_name"></div>
                                    <div class="im-cluster-row__meta">
                                        <span class="im-pill" x-text="'MCP ' + Number(row.mcp_share || 0).toFixed(1) + '%'"></span>
                                        <span class="im-pill im-pill--blue" x-text="'DNA ' + Number(row.dna_count || 0)"></span>
                                        <span class="im-pill" x-show="Number(row.article_count || 0) > 0" x-text="'Articles ' + Number(row.article_count || 0)"></span>
                                    </div>
                                </div>
                                <button type="button" class="im-btn im-btn--primary" x-on:click="addCluster(row)">{{ __('seo-content-ai::filament.projects.idea_manager_cluster_add') }}</button>
                            </li>
                        </template>
                    </ul>

                    <div class="im-actions text-sm" x-show="clusterTotal > 25">
                        <button type="button" class="im-btn im-btn--ghost" wire:click="gotoClusterPage({{ max(1, (int) $this->clusterPage - 1) }})">Prev</button>
                        <span class="text-gray-500" x-text="'Total: ' + clusterTotal"></span>
                        <button type="button" class="im-btn im-btn--ghost" wire:click="gotoClusterPage({{ (int) $this->clusterPage + 1 }})">Next</button>
                    </div>
                </div>

                {{-- Published DNA BLOCKED --}}
                <div x-show="tab === 'published'">
                    <div class="im-empty space-y-3">
                        <div class="text-base font-semibold text-gray-700 dark:text-gray-200">{{ __('seo-content-ai::filament.projects.idea_manager_published_title') }}</div>
                        <p>{{ __('seo-content-ai::filament.projects.idea_manager_published_blocked') }}</p>
                        <button type="button" class="im-btn im-btn--ghost" disabled>{{ __('seo-content-ai::filament.projects.idea_manager_clone_blocked') }}</button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <script>
        function ideaManagerRoot(cfg) {
            const IM_PREFIX = 'seoOps:idea-manager:draft:v1:site:';
            const AUDIT_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:';
            const DEBOUNCE_MS = 3000;
            const MAX_SYNC_MS = 30000;

            return {
                siteId: Number(cfg.siteId || 0),
                siteDomain: String(cfg.siteDomain || ''),
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

                onSiteChanged(nextId) {
                    const id = Number(nextId || 0);
                    if (this.siteId > 0 && this.dirty) this.persistDraft();
                    this.siteId = id;
                    this.conflict = false;
                    this.importPreview = null;
                    this.bulkPreview = null;
                    this.legacyScan = null;
                    this.legacyDismissed = false;
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
                    if (!q) return this.items;
                    return this.items.filter((it) => {
                        const name = String(it.cluster_name_snapshot || it.seed_text || '').toLowerCase();
                        return name.includes(q) || String(it.cluster_ref || '').toLowerCase().includes(q);
                    });
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
                    this.items.push({
                        source_type: 'cluster',
                        cluster_ref: row.cluster_ref,
                        cluster_name_snapshot: row.cluster_name,
                        seed_text: null,
                        mcp_share_snapshot: row.mcp_share ?? 0,
                        target_dna_count: Math.max(5, dna.length || 0),
                        target_mode: 'manual',
                        dna,
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
