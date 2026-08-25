<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'ai-center'])

            <div
                id="ai-center-root"
                class="seo-settings-main"
                wire:ignore.self
                wire:key="ai-center-root"
                x-data="seoAiCenter({
                    tab: @js($tab),
                    area: @js($modelArea),
                    modelsHydrated: @js($modelsHydrated),
                    routingHydrated: @js($routingHydrated),
                    resilienceHydrated: @js($resilienceHydrated),
                    healthHydrated: @js($healthHydrated),
                    editingProfile: @js($editingProfile),
                    routingUnsaved: @js($routingUnsaved),
                })"
            >
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.ai_center.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.ai_center.intro') }}</p>
                </header>

                <nav class="seo-ai-segment" aria-label="{{ __('seo-content-ai::filament.ai_center.title') }}">
                    @foreach (['models', 'routing', 'resilience', 'health'] as $tabKey)
                        <button
                            type="button"
                            @click="setTab('{{ $tabKey }}')"
                            :class="{ 'seo-ai-segment__item': true, 'is-active': activeMainTab === '{{ $tabKey }}' }"
                            class="seo-ai-segment__item"
                        >
                            {{ __('seo-content-ai::filament.ai_center.tab_'.$tabKey) }}
                        </button>
                    @endforeach
                </nav>

                @if ($modelsHydrated)
                    @php($areaCounts = $this->areaCounts())
                    <div
                        id="ai-center-models"
                        class="seo-ai-panel"
                        x-show="activeMainTab === 'models'"
                        style="display: none;"
                        wire:key="ai-center-models"
                    >
                        <div class="seo-ai-section-head">
                            <div>
                                <h2 class="seo-ai-section-title">{{ __('seo-content-ai::filament.ai_center.models_page_title') }}</h2>
                                <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.models_page_intro') }}</p>
                            </div>
                            <div class="seo-ai-section-actions">
                                <x-filament::button tag="a" :href="\Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::getUrl('create')" size="sm">
                                    {{ __('seo-content-ai::filament.ai_center.add_connection') }}
                                </x-filament::button>
                                <x-filament::button wire:click="openImportModal" size="sm" color="gray" wire:loading.attr="disabled" wire:target="openImportModal">
                                    {{ __('seo-content-ai::filament.ai_center.import_template_short') }}
                                </x-filament::button>
                                <x-filament::button wire:click="syncAllModels" size="sm" icon="heroicon-o-arrow-path" wire:loading.attr="disabled" wire:target="syncAllModels">
                                    <span wire:loading.remove wire:target="syncAllModels">{{ __('seo-content-ai::filament.ai_center.sync_all') }}</span>
                                    <span wire:loading wire:target="syncAllModels">…</span>
                                </x-filament::button>
                            </div>
                        </div>

                        <section class="seo-ai-strategy">
                            <div class="seo-ai-strategy__title">{{ __('seo-content-ai::filament.ai_center.strategy_title') }}</div>
                            <div class="seo-ai-strategy__choices">
                                <label>
                                    <input type="radio" wire:model="globalUsageMode" value="economy" />
                                    {{ __('seo-content-ai::filament.ai_model_ux.mode_economy') }}
                                </label>
                                <label>
                                    <input type="radio" wire:model="globalUsageMode" value="quality_first" />
                                    {{ __('seo-content-ai::filament.ai_model_ux.mode_quality_first') }}
                                </label>
                            </div>
                            <p class="seo-ai-strategy__help">{{ __('seo-content-ai::filament.ai_center.strategy_short') }}</p>
                        </section>

                        <nav class="seo-ai-segment seo-ai-segment--wide" aria-label="{{ __('seo-content-ai::filament.ai_center.models_page_title') }}">
                            @foreach (\Omnichannel\Addons\AiPrompt\Support\AiModelArea::uiCases() as $areaEnum)
                                @php($areaKey = $areaEnum->value)
                                <button
                                    type="button"
                                    @click="setArea('{{ $areaKey }}')"
                                    :class="{ 'seo-ai-segment__item': true, 'is-active': activeCapability === '{{ $areaKey }}' }"
                                    class="seo-ai-segment__item"
                                >
                                    {{ __('seo-content-ai::filament.ai_model_ux.tab_'.$areaKey) }}
                                    <span class="seo-ai-muted">{{ ($areaCounts[$areaKey]['enabled'] ?? 0) }}</span>
                                </button>
                            @endforeach
                        </nav>

                        <div class="seo-ai-toolbar">
                            <input type="search" wire:model.live.debounce.300ms="modelSearch" placeholder="{{ __('seo-content-ai::filament.ai_center.search_models') }}" />
                            <select wire:model.live="modelProvider">
                                <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_provider') }}</option>
                                @foreach ($this->aiProviderFilterOptions() as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <select wire:model.live="modelCost">
                                <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_cost_all') }}</option>
                                <option value="free">{{ __('seo-content-ai::filament.ai_center.filter_cost_free') }}</option>
                                <option value="paid">{{ __('seo-content-ai::filament.ai_center.filter_cost_paid') }}</option>
                            </select>
                            <x-filament::button
                                type="button"
                                size="sm"
                                @click="await $wire.openModelPickerForArea(activeCapability)"
                            >
                                <span x-text="addModelsLabel"></span>
                            </x-filament::button>
                        </div>
                        <div class="seo-ai-toolbar-extra">
                            <label><input type="checkbox" wire:model.live="modelTechnical" /> {{ __('seo-content-ai::filament.ai_center.technical_models') }}</label>
                            <span class="seo-ai-muted" x-text="areaSummary"></span>
                            <span class="seo-ai-muted" x-show="modelsOrderDirty" x-cloak>{{ __('seo-content-ai::filament.ai_center.unsaved_changes') }}</span>
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="success"
                                x-show="modelsOrderDirty"
                                x-cloak
                                @click="saveModelOrders()"
                                wire:loading.attr="disabled"
                                wire:target="reorderCapabilityModels"
                            >
                                {{ __('seo-content-ai::filament.ai_center.save_model_order') }}
                            </x-filament::button>
                        </div>

                        @foreach (\Omnichannel\Addons\AiPrompt\Support\AiModelArea::uiCases() as $areaEnum)
                            @php($areaKey = $areaEnum->value)
                            @php($areaCount = $areaCounts[$areaKey] ?? ['enabled' => 0, 'available' => 0])
                            <div
                                x-show="activeCapability === '{{ $areaKey }}'"
                                x-cloak
                                wire:key="ai-center-models-{{ $areaKey }}"
                                data-models-area="{{ $areaKey }}"
                                data-area-summary="{{ $areaCount['enabled'] }} {{ __('seo-content-ai::filament.ai_center.enabled') }} · {{ $areaCount['available'] }} {{ __('seo-content-ai::filament.ai_center.status_available') }}"
                                data-add-label="{{ __('seo-content-ai::filament.ai_center.add_area_models.'.$areaKey) }}"
                            >
                                <x-seo-content-ai::sortable-ai-model-list
                                    :area="$areaKey"
                                    :models="$this->areaModelRowsFor($areaKey)"
                                    :can-reorder="$this->canReorderModels()"
                                />
                            </div>
                        @endforeach
                        <p class="seo-ai-conn-block__link">
                            <a href="{{ \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::getUrl() }}">
                                {{ __('seo-content-ai::filament.ai_center.all_connections_link') }}
                            </a>
                        </p>
                    </div>
                @endif

                @if ($routingHydrated)
                    <form
                        id="ai-center-routing"
                        @submit.prevent="submitRouting()"
                        class="seo-ai-routing seo-ai-panel"
                        x-show="activeMainTab === 'routing'"
                        style="display: none;"
                        wire:key="ai-center-routing"
                    >
                        <nav class="seo-ai-segment seo-ai-segment--wide" aria-label="{{ __('seo-content-ai::filament.ai_center.tab_routing') }}">
                            @foreach (['text', 'image', 'video'] as $group)
                                <button
                                    type="button"
                                    @click="setArea('{{ $group }}')"
                                    :class="{ 'seo-ai-segment__item': true, 'is-active': activeCapability === '{{ $group }}' }"
                                    class="seo-ai-segment__item"
                                >
                                    {{ __('seo-content-ai::filament.ai_model_ux.tab_'.$group) }}
                                </button>
                            @endforeach
                        </nav>

                        @foreach (['text', 'image', 'video'] as $group)
                            <div class="seo-ai-profile-list" x-show="activeCapability === '{{ $group }}'" x-cloak wire:key="ai-center-routing-{{ $group }}">
                                @foreach ($this->routingCardsFor($group) as $card)
                                    <article
                                        class="seo-ai-profile"
                                        wire:key="profile-{{ $card['key'] }}"
                                    >
                                        <div class="seo-ai-profile__head">
                                            <div>
                                                <h3>{{ $card['name'] }}</h3>
                                                <p>{{ $card['description'] }}</p>
                                            </div>
                                            <label class="seo-ai-profile__active">
                                                {{ __('seo-content-ai::filament.ai_model_ux.active') }}
                                                <button
                                                    type="button"
                                                    class="seo-ai-switch"
                                                    :class="{ 'is-on': draftEnabled(@js($card['key']), @js($card['enabled'])) }"
                                                    @click="toggleEnabled(@js($card['key']), @js($card['enabled']))"
                                                ></button>
                                            </label>
                                        </div>
                                        <div class="seo-ai-profile__body" x-show="editingProfile !== @js($card['key'])">
                                            @if ($group === 'text')
                                                <p class="seo-ai-muted text-sm">{{ __('seo-content-ai::filament.ai_center.text_routing_follows_models') }}</p>
                                            @else
                                            <div class="seo-ai-profile__summary" x-show="draftMode(@js($card['key']), @js($card['selection_mode'])) === 'custom'">
                                                @if ($card['family_labels'] === [])
                                                    <span class="seo-ai-muted">{{ __('seo-content-ai::filament.ai_center.no_models') }}</span>
                                                @else
                                                    <div class="seo-ai-chip-row">
                                                        @foreach ($card['family_labels'] as $execKey => $opt)
                                                            @php($chip = is_array($opt) ? $opt : ['full_label' => (string) $opt, 'short_code' => '', 'badge_variant' => 'badge-1', 'model_name' => (string) $opt])
                                                            <span class="seo-ai-chip" x-show="draftSelected(@js($card['key']), @js(array_values($card['family_keys']))).includes(@js($execKey))">
                                                                @if (filled($chip['short_code'] ?? null))
                                                                    <span class="seo-ai-code seo-ai-code--{{ $chip['badge_variant'] ?? 'badge-1' }}">{{ $chip['short_code'] }}</span>
                                                                @endif
                                                                {{ $chip['model_name'] ?? $chip['full_label'] }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                            <button
                                                type="button"
                                                class="seo-ai-icon-btn"
                                                @click="startEdit(@js($card['key']), @js($card['selection_mode']), @js($card['enabled']), @js(array_values($card['family_keys'])))"
                                                title="{{ __('seo-content-ai::filament.ai_center.edit') }}"
                                                aria-label="{{ __('seo-content-ai::filament.ai_center.edit') }}"
                                            >
                                                <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                            </button>
                                            @endif
                                        </div>
                                        @if ($group !== 'text')
                                        <div class="seo-ai-profile__edit" x-show="editingProfile === @js($card['key'])" x-cloak>
                                            <p class="seo-ai-profile__label">{{ __('seo-content-ai::filament.ai_center.model_selection') }}</p>
                                            <div class="seo-ai-strategy__choices">
                                                <label>
                                                    <input type="radio" name="mode-{{ $card['key'] }}" value="automatic" @click="setMode(@js($card['key']), 'automatic', @js(array_values($card['family_keys'])))" :checked="draftMode(@js($card['key']), @js($card['selection_mode'])) === 'automatic'" />
                                                    {{ __('seo-content-ai::filament.ai_model_ux.automatic') }}
                                                </label>
                                                <label>
                                                    <input type="radio" name="mode-{{ $card['key'] }}" value="custom" @click="setMode(@js($card['key']), 'custom', @js(array_values($card['family_keys'])))" :checked="draftMode(@js($card['key']), @js($card['selection_mode'])) === 'custom'" />
                                                    {{ __('seo-content-ai::filament.ai_center.selection_custom') }}
                                                </label>
                                            </div>
                                            <div class="seo-ai-allowed" x-show="draftMode(@js($card['key']), @js($card['selection_mode'])) === 'custom'">
                                                <p class="seo-ai-profile__label">{{ __('seo-content-ai::filament.ai_center.allowed_models') }}</p>
                                                <div class="seo-ai-chip-row">
                                                    @foreach ($card['family_options'] as $familyKey => $familyOpt)
                                                        @php($chipOpt = is_array($familyOpt) ? $familyOpt : ['full_label' => (string) $familyOpt, 'short_code' => '', 'badge_variant' => 'badge-1', 'model_name' => (string) $familyOpt])
                                                        <button
                                                            type="button"
                                                            class="seo-ai-chip"
                                                            :class="{ 'is-selected': draftSelected(@js($card['key']), @js(array_values($card['family_keys']))).includes(@js($familyKey)) }"
                                                            @click="toggleChip(@js($card['key']), @js($familyKey), @js(array_values($card['family_keys'])))"
                                                        >
                                                            @if (filled($chipOpt['short_code'] ?? null))
                                                                <span class="seo-ai-code seo-ai-code--{{ $chipOpt['badge_variant'] ?? 'badge-1' }}">{{ $chipOpt['short_code'] }}</span>
                                                            @endif
                                                            {{ $chipOpt['model_name'] ?? $chipOpt['full_label'] }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        @endif
                                        @if ($showRoutingTechnical && $card['resolved_order'] !== '')
                                            <pre class="seo-ai-tech">{{ $card['resolved_order'] }}</pre>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endforeach

                        <div class="seo-ai-routing-foot">
                            <button type="button" class="seo-ai-link" wire:click="$toggle('showRoutingTechnical')">
                                {{ $showRoutingTechnical ? __('seo-content-ai::filament.ai_center.hide_technical_details') : __('seo-content-ai::filament.ai_center.view_technical_details') }}
                            </button>
                            <span class="seo-ai-muted" x-show="routingUnsaved" x-cloak>{{ __('seo-content-ai::filament.ai_center.unsaved_changes') }}</span>
                            <x-seo-content-ai::form-save-button target="saveRouting" :label="__('Save settings')" />
                        </div>
                    </form>
                @endif

                @if ($resilienceHydrated)
                    <div
                        id="ai-center-resilience"
                        class="seo-ai-panel"
                        x-show="activeMainTab === 'resilience'"
                        style="display: none;"
                        wire:key="ai-center-resilience"
                    >
                        <section class="seo-ai-section-head">
                            <div>
                                <h2 class="seo-ai-section-title">{{ __('seo-content-ai::filament.ai_center.resilience_fallback_policy') }}</h2>
                                <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.resilience_fallback_help') }}</p>
                            </div>
                        </section>

                        <form wire:submit.prevent="saveResilienceSettings" class="seo-ai-resilience-policy space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium">{{ __('seo-content-ai::filament.ai_center.max_ai_attempts') }}</span>
                                    <input type="number" min="1" max="20" wire:model="maxAiAttempts" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium">{{ __('seo-content-ai::filament.ai_center.max_free_attempts') }}</span>
                                    <input type="number" min="0" max="20" wire:model="maxFreeAttempts" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                                </label>
                            </div>
                            <x-seo-content-ai::form-save-button target="saveResilienceSettings" :label="__('Save settings')" />
                        </form>
                    </div>
                @endif

                @if ($healthHydrated)
                    <div
                        id="ai-center-health"
                        class="seo-ai-panel seo-ai-health"
                        x-show="activeMainTab === 'health'"
                        style="display: none;"
                        wire:key="ai-center-health"
                        x-data="{
                            q: '',
                            provider: 'all',
                            area: 'all',
                            health: 'all',
                            matchRow(el) {
                                const q = this.q.trim().toLowerCase();
                                const blob = (el.dataset.search || '');
                                if (q && !blob.includes(q)) return false;
                                if (this.provider !== 'all' && el.dataset.provider !== this.provider) return false;
                                if (this.area !== 'all' && el.dataset.area !== this.area) return false;
                                if (this.health !== 'all' && el.dataset.health !== this.health) return false;
                                return true;
                            }
                        }"
                    >
                        <section class="seo-ai-section-head mb-4">
                            <div>
                                <h2 class="seo-ai-section-title">{{ __('seo-content-ai::filament.ai_center.health_page_title') }}</h2>
                                <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.health_intro') }}</p>
                                <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.health_intro_extra') }}</p>
                            </div>
                            <div class="seo-ai-section-actions">
                                <x-filament::button wire:click="$refresh" size="sm" color="gray" icon="heroicon-o-arrow-path" wire:loading.attr="disabled" wire:target="$refresh">
                                    {{ __('seo-content-ai::filament.ai_center.refresh_health') }}
                                </x-filament::button>
                            </div>
                        </section>

                        @php($healthSummary = $this->healthSummary())
                        <div class="seo-ai-health-stats" aria-label="{{ __('seo-content-ai::filament.ai_center.health_summary') }}">
                            <div class="seo-ai-health-stat">
                                <span class="seo-ai-health-stat__label">{{ __('seo-content-ai::filament.ai_center.health_stat_healthy') }}</span>
                                <span class="seo-ai-health-stat__value seo-ai-health-stat__value--success">{{ $healthSummary['healthy'] }}</span>
                            </div>
                            <div class="seo-ai-health-stat">
                                <span class="seo-ai-health-stat__label">{{ __('seo-content-ai::filament.ai_center.health_stat_degraded') }}</span>
                                <span class="seo-ai-health-stat__value seo-ai-health-stat__value--warning">{{ $healthSummary['degraded'] }}</span>
                            </div>
                            <div class="seo-ai-health-stat">
                                <span class="seo-ai-health-stat__label">{{ __('seo-content-ai::filament.ai_center.health_stat_issues') }}</span>
                                <span class="seo-ai-health-stat__value seo-ai-health-stat__value--danger">{{ $healthSummary['issues'] }}</span>
                            </div>
                            <div class="seo-ai-health-stat">
                                <span class="seo-ai-health-stat__label">{{ __('seo-content-ai::filament.ai_center.health_stat_no_data') }}</span>
                                <span class="seo-ai-health-stat__value">{{ $healthSummary['no_data'] }}</span>
                            </div>
                        </div>

                        <section class="seo-ai-health-section">
                            <div class="seo-ai-section-head">
                                <div>
                                    <h3 class="seo-ai-section-title">{{ __('seo-content-ai::filament.ai_center.connection_health') }}</h3>
                                    <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.connection_health_help') }}</p>
                                </div>
                            </div>
                            <div class="seo-ai-health-scroll">
                                <table class="seo-ai-health-table">
                                    <thead>
                                        <tr>
                                            <th class="seo-ai-health-col--name">{{ __('seo-content-ai::filament.ai_center.col_connection') }}</th>
                                            <th class="seo-ai-health-col--provider">{{ __('seo-content-ai::filament.ai_center.col_provider') }}</th>
                                            <th class="seo-ai-health-col--health">{{ __('seo-content-ai::filament.ai_center.col_health') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_success') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_failed') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_consecutive') }}</th>
                                            <th class="seo-ai-health-col--issue">{{ __('seo-content-ai::filament.ai_center.col_last_issue') }}</th>
                                            <th class="seo-ai-health-col--time">{{ __('seo-content-ai::filament.ai_center.col_last_success') }}</th>
                                            <th class="seo-ai-health-col--action">{{ __('seo-content-ai::filament.ai_center.col_action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->connectionHealthRows() as $row)
                                            @php($badge = $row['status_badge'] ?? ['tone' => 'neutral', 'label' => '—'])
                                            <tr wire:key="conn-health-{{ $row['connection_id'] }}">
                                                <td class="seo-ai-health-col--name">
                                                    <div class="seo-ai-health-primary">{{ $row['display_name'] ?? $row['connection_name'] }}</div>
                                                    @if (($row['provider_key'] ?? '') !== '')
                                                        <div class="seo-ai-health-secondary">{{ $row['provider_key'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--provider">
                                                    @if (($row['short_code'] ?? '') !== '')
                                                        <span class="seo-ai-code seo-ai-code--{{ $row['badge_variant'] ?? 'badge-1' }}">{{ $row['short_code'] }}</span>
                                                        <span class="seo-ai-health-provider-label">{{ $row['provider_label'] ?: ($row['provider_key'] ?: '—') }}</span>
                                                    @else
                                                        {{ $row['provider_label'] ?: ($row['provider_key'] ?: '—') }}
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--health">
                                                    <span class="seo-ai-health-badge seo-ai-health-badge--{{ $badge['tone'] }}">{{ $badge['label'] }}</span>
                                                    @if (($row['health_status'] ?? '') === 'budget_limited')
                                                        <div class="seo-ai-health-secondary">{{ __('seo-content-ai::filament.ai_center.budget_limited_help') }}</div>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--num {{ ($row['success_count'] ?? 0) === 0 ? 'is-muted' : '' }}">{{ $row['success_count'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--num {{ ($row['failure_count'] ?? 0) > 0 ? 'is-emphasis' : (($row['failure_count'] ?? 0) === 0 ? 'is-muted' : '') }}">{{ $row['failure_count'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--num {{ ($row['consecutive_failures'] ?? 0) > 0 ? 'is-emphasis' : 'is-muted' }}">{{ $row['consecutive_failures'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--issue">
                                                    @if (($row['issue_primary'] ?? '—') !== '—')
                                                        <div class="seo-ai-health-primary">{{ $row['issue_primary'] }}</div>
                                                        @if (($row['issue_secondary'] ?? '') !== '' && ($row['health_status'] ?? '') !== 'budget_limited')
                                                            <div class="seo-ai-health-secondary">{{ $row['issue_secondary'] }}</div>
                                                        @endif
                                                    @else
                                                        <span class="is-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--time">{{ $row['last_success_label'] ?? '—' }}</td>
                                                <td class="seo-ai-health-col--action">
                                                    @if (($row['action_name'] ?? '') === 'unlock_connection')
                                                        <x-filament::button type="button" size="sm" color="gray" wire:click="unlockConnectionHealth({{ $row['connection_id'] }})" wire:loading.attr="disabled" wire:target="unlockConnectionHealth({{ $row['connection_id'] }})">
                                                            {{ $row['action_label'] }}
                                                        </x-filament::button>
                                                    @elseif (($row['action_name'] ?? '') === 'enable_paid_routes')
                                                        <x-filament::button type="button" size="sm" color="gray" wire:click="enablePaidRoutes({{ $row['connection_id'] }})" wire:loading.attr="disabled" wire:target="enablePaidRoutes({{ $row['connection_id'] }})">
                                                            {{ $row['action_label'] }}
                                                        </x-filament::button>
                                                    @else
                                                        <span class="is-muted">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="9" class="seo-ai-health-empty">{{ __('seo-content-ai::filament.ai_center.no_health_data') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="seo-ai-health-section">
                            <div class="seo-ai-section-head">
                                <div>
                                    <h3 class="seo-ai-section-title">{{ __('seo-content-ai::filament.ai_center.model_health') }}</h3>
                                    <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.model_health_help') }}</p>
                                </div>
                                <div class="seo-ai-section-actions">
                                    <a href="{{ \Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::getUrl(['tab' => 'models']) }}" class="seo-ai-health-link">{{ __('seo-content-ai::filament.ai_center.review_models') }}</a>
                                </div>
                            </div>

                            <div class="seo-ai-toolbar seo-ai-health-toolbar">
                                <input type="search" x-model="q" placeholder="{{ __('seo-content-ai::filament.ai_center.search_models') }}" />
                                <select x-model="provider">
                                    <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_provider') }}</option>
                                    @foreach ($this->healthProviderFilterOptions() as $providerKey)
                                        <option value="{{ $providerKey }}">{{ \Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders::label((string) $providerKey) }}</option>
                                    @endforeach
                                </select>
                                <select x-model="area">
                                    <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_area_all') }}</option>
                                    @foreach ($this->healthAreaFilterOptions() as $areaLabel)
                                        <option value="{{ $areaLabel }}">{{ $areaLabel }}</option>
                                    @endforeach
                                </select>
                                <select x-model="health">
                                    <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_health_all') }}</option>
                                    <option value="healthy">{{ __('seo-content-ai::filament.ai_center.health_status_healthy') }}</option>
                                    <option value="degraded">{{ __('seo-content-ai::filament.ai_center.health_status_degraded') }}</option>
                                    <option value="unavailable">{{ __('seo-content-ai::filament.ai_center.health_status_unavailable') }}</option>
                                    <option value="no_data">{{ __('seo-content-ai::filament.ai_center.health_status_no_data') }}</option>
                                </select>
                            </div>

                            <div class="seo-ai-health-scroll">
                                <table class="seo-ai-health-table">
                                    <thead>
                                        <tr>
                                            <th class="seo-ai-health-col--name">{{ __('seo-content-ai::filament.ai_center.col_model') }}</th>
                                            <th class="seo-ai-health-col--provider">{{ __('seo-content-ai::filament.ai_center.col_provider') }}</th>
                                            <th class="seo-ai-health-col--area">{{ __('seo-content-ai::filament.ai_center.col_area') }}</th>
                                            <th class="seo-ai-health-col--health">{{ __('seo-content-ai::filament.ai_center.col_health') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_success') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_failed') }}</th>
                                            <th class="seo-ai-health-col--num">{{ __('seo-content-ai::filament.ai_center.col_consecutive') }}</th>
                                            <th class="seo-ai-health-col--issue">{{ __('seo-content-ai::filament.ai_center.col_last_issue') }}</th>
                                            <th class="seo-ai-health-col--time">{{ __('seo-content-ai::filament.ai_center.col_last_success') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->modelHealthRows() as $row)
                                            @php($badge = $row['status_badge'] ?? ['tone' => 'neutral', 'label' => '—'])
                                            <tr
                                                wire:key="model-health-{{ $row['model_id'] }}"
                                                data-search="{{ $row['search_blob'] ?? '' }}"
                                                data-provider="{{ $row['provider_key'] ?? '' }}"
                                                data-area="{{ $row['area_label'] ?? '' }}"
                                                data-health="{{ $row['health_status'] ?? 'no_data' }}"
                                                x-show="matchRow($el)"
                                            >
                                                <td class="seo-ai-health-col--name">
                                                    <div class="seo-ai-health-primary">{{ $row['model_name'] }}</div>
                                                    @if (($row['raw_model_name'] ?? '') !== '' && ($row['raw_model_name'] ?? '') !== ($row['model_name'] ?? ''))
                                                        <div class="seo-ai-health-secondary">{{ $row['raw_model_name'] }}</div>
                                                    @elseif (($row['provider_label'] ?? '') !== '')
                                                        <div class="seo-ai-health-secondary">{{ $row['provider_label'] }}</div>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--provider">
                                                    @if (($row['short_code'] ?? '') !== '')
                                                        <span class="seo-ai-code seo-ai-code--{{ $row['badge_variant'] ?? 'badge-1' }}">{{ $row['short_code'] }}</span>
                                                        <span class="seo-ai-health-provider-label">{{ $row['provider_label'] ?: ($row['provider_key'] ?: '—') }}</span>
                                                    @else
                                                        {{ $row['provider_label'] ?: ($row['provider_key'] ?: '—') }}
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--area">
                                                    @if (($row['area_label'] ?? '—') !== '—')
                                                        <span class="seo-ai-health-area">{{ $row['area_label'] }}</span>
                                                    @else
                                                        <span class="is-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--health">
                                                    <span class="seo-ai-health-badge seo-ai-health-badge--{{ $badge['tone'] }}">{{ $badge['label'] }}</span>
                                                    @if (! empty($row['is_connection_budget_issue']))
                                                        <div class="seo-ai-health-secondary">{{ $row['issue_secondary'] ?? '' }}</div>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--num {{ ($row['success_count'] ?? 0) === 0 ? 'is-muted' : '' }}">{{ $row['success_count'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--num {{ ($row['failure_count'] ?? 0) > 0 ? 'is-emphasis' : 'is-muted' }}">{{ $row['failure_count'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--num {{ ($row['consecutive_failures'] ?? 0) > 0 ? 'is-emphasis' : 'is-muted' }}">{{ $row['consecutive_failures'] ?? 0 }}</td>
                                                <td class="seo-ai-health-col--issue">
                                                    @if (! empty($row['is_connection_budget_issue']))
                                                        <div class="seo-ai-health-primary">{{ $row['issue_primary'] }}</div>
                                                        <div class="seo-ai-health-secondary">{{ $row['issue_secondary'] ?? '' }}</div>
                                                    @elseif (($row['issue_primary'] ?? '—') !== '—')
                                                        <div class="seo-ai-health-primary">{{ $row['issue_primary'] }}</div>
                                                        @if (($row['issue_secondary'] ?? '') !== '')
                                                            <div class="seo-ai-health-secondary">{{ $row['issue_secondary'] }}</div>
                                                        @endif
                                                    @else
                                                        <span class="is-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="seo-ai-health-col--time">{{ $row['last_success_label'] ?? '—' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="9" class="seo-ai-health-empty">{{ __('seo-content-ai::filament.ai_center.no_health_data') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <p class="seo-ai-muted mt-4 text-sm">
                                <a href="{{ \Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::getUrl(['tab' => 'routing']) }}" class="seo-ai-health-link">{{ __('seo-content-ai::filament.ai_center.review_routing') }}</a>
                            </p>
                        </section>
                    </div>
                @endif

                <div
                    class="seo-ai-panel-loading"
                    x-show="activeMainTab === 'resilience' && (panelLoading || !resilienceHydrated)"
                    x-cloak
                    wire:key="ai-center-resilience-loading"
                >
                    <div class="animate-pulse space-y-3 py-6">
                        <div class="h-4 w-1/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                    </div>
                </div>

                <div
                    class="seo-ai-panel-loading"
                    x-show="activeMainTab === 'health' && (panelLoading || !healthHydrated)"
                    x-cloak
                    wire:key="ai-center-health-loading"
                >
                    <div class="animate-pulse space-y-3 py-6">
                        <div class="h-4 w-1/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                    </div>
                </div>

                <div
                    class="seo-ai-panel-loading"
                    x-show="activeMainTab === 'routing' && (panelLoading || !routingHydrated)"
                    x-cloak
                    wire:key="ai-center-routing-loading"
                >
                    <div class="animate-pulse space-y-3 py-6">
                        <div class="h-4 w-1/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                        <div class="h-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                    </div>
                </div>
            </div>
        </div>

        @if ($pickerOpen)
            @php($picker = $this->pickerState())
            <div class="seo-capability-matrix-overlay" wire:key="ai-center-model-picker">
                <div class="seo-capability-matrix-backdrop" wire:click="closeModelPicker" aria-hidden="true"></div>
                <div class="seo-ai-modal seo-ai-modal--wide" role="dialog" aria-modal="true">
                    <h2>{{ __('seo-content-ai::filament.ai_center.add_area_title.'.$modelArea) }}</h2>
                    <div class="seo-ai-toolbar">
                        <div class="seo-ai-toolbar__search">
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="pickerSearch"
                                placeholder="{{ __('seo-content-ai::filament.ai_center.search_models') }}"
                            />
                            <span
                                class="seo-ai-toolbar__spinner"
                                wire:loading
                                wire:target="pickerSearch,pickerProvider,pickerStatus,pickerPrevPage,pickerNextPage"
                                aria-hidden="true"
                            ></span>
                        </div>
                        <select wire:model.live="pickerProvider" wire:loading.attr="disabled" wire:target="pickerSearch,pickerProvider,pickerStatus">
                            <option value="all">{{ __('seo-content-ai::filament.ai_center.filter_provider') }}</option>
                            @foreach ($this->aiProviderFilterOptions() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="pickerStatus" wire:loading.attr="disabled" wire:target="pickerSearch,pickerProvider,pickerStatus">
                            <option value="available">{{ __('seo-content-ai::filament.ai_center.status_available') }}</option>
                            <option value="unknown">{{ __('seo-content-ai::filament.ai_center.status_unknown') }}</option>
                        </select>
                    </div>
                    @php($pickerEnabled = $this->pickerEnabledRows())
                    <div class="seo-ai-picker-added" wire:key="picker-added-{{ $modelArea }}-{{ count($pickerEnabled) }}">
                        <div class="seo-ai-picker-added__label">
                            {{ __('seo-content-ai::filament.ai_center.picker_added_label') }}
                            <span class="seo-ai-muted">{{ count($pickerEnabled) }}</span>
                        </div>
                        <div class="seo-ai-picker-added__chips">
                            @forelse ($pickerEnabled as $enabledRow)
                                <span class="seo-ai-chip seo-ai-chip--added" wire:key="picker-added-{{ $enabledRow['identity'] ?? ($enabledRow['ids'][0] ?? '') }}">
                                    @if (! empty($enabledRow['short_code']))
                                        <span class="seo-ai-code seo-ai-code--{{ $enabledRow['badge_variant'] ?? 'badge-1' }}">{{ $enabledRow['short_code'] }}</span>
                                    @endif
                                    {{ $enabledRow['model_name'] ?? $enabledRow['label'] }}
                                </span>
                            @empty
                                <span class="seo-ai-muted">{{ __('seo-content-ai::filament.ai_center.picker_added_empty') }}</span>
                            @endforelse
                        </div>
                    </div>
                    <div
                        class="seo-ai-picker-table-wrap"
                        wire:loading.class="is-loading"
                        wire:target="pickerSearch,pickerProvider,pickerStatus,pickerPrevPage,pickerNextPage,addAvailableModels"
                    >
                        <div
                            class="seo-ai-picker-loading"
                            wire:loading.style="display:flex"
                            wire:target="pickerSearch,pickerProvider,pickerStatus,pickerPrevPage,pickerNextPage,addAvailableModels"
                        >
                            <span class="seo-ai-toolbar__spinner" aria-hidden="true"></span>
                            <span>{{ __('seo-content-ai::filament.ai_center.picker_loading') }}</span>
                        </div>
                        <table class="seo-ai-models-table w-full text-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('seo-content-ai::filament.ai_center.col_model') }}</th>
                                    <th>{{ __('seo-content-ai::filament.ai_center.col_source') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($enabledIdSet = [])
                                @foreach ($pickerEnabled as $enabledRow)
                                    @foreach (($enabledRow['ids'] ?? []) as $enabledId)
                                        @php($enabledIdSet[(int) $enabledId] = true)
                                    @endforeach
                                @endforeach
                                @forelse ($picker['rows'] as $row)
                                    @php($pickerIds = array_values(array_map('intval', $row['ids'] ?? [])))
                                    @php($primaryId = (int) ($pickerIds[0] ?? 0))
                                    @php($alreadyAdded = $primaryId > 0 && isset($enabledIdSet[$primaryId]))
                                    <tr wire:key="pick-{{ $row['identity'] ?? $primaryId }}" @class(['is-added' => $alreadyAdded])>
                                        <td>
                                            @if (! empty($row['short_code']))
                                                <span class="seo-ai-code seo-ai-code--{{ $row['badge_variant'] ?? 'badge-1' }}">{{ $row['short_code'] }}</span>
                                            @endif
                                            {{ $row['model_name'] ?? $row['label'] }}
                                        </td>
                                        <td>{{ $row['source'] ?? $row['provider'] }}</td>
                                        <td>
                                            @if ($alreadyAdded)
                                                <span class="seo-ai-picker-added-badge">{{ __('seo-content-ai::filament.ai_center.picker_row_added') }}</span>
                                            @else
                                                <button
                                                    type="button"
                                                    class="seo-ai-picker-add"
                                                    wire:click="addAvailableModels({{ $primaryId }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="addAvailableModels({{ $primaryId }})"
                                                    @disabled($primaryId <= 0)
                                                >
                                                    <span wire:loading.remove wire:target="addAvailableModels({{ $primaryId }})">
                                                        {{ __('seo-content-ai::filament.ai_center.add_model') }}
                                                    </span>
                                                    <span wire:loading.style="display:inline-flex" wire:target="addAvailableModels({{ $primaryId }})" class="items-center gap-1" style="display:none">
                                                        <span class="seo-ai-toolbar__spinner" aria-hidden="true"></span>
                                                        {{ __('seo-content-ai::filament.common.saving') }}
                                                    </span>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="seo-ai-muted">
                                            @if (($picker['status'] ?? 'available') === 'available')
                                                {{ __('seo-content-ai::filament.ai_center.picker_empty_available') }}
                                            @else
                                                {{ __('seo-content-ai::filament.ai_center.no_models') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="seo-ai-picker-pager">
                        <span class="seo-ai-muted">{{ $picker['total'] }} · {{ $picker['page'] }}/{{ $picker['last_page'] }}</span>
                        <div>
                            <x-filament::button size="sm" color="gray" wire:click="pickerPrevPage" :disabled="$picker['page'] <= 1">{{ __('seo-content-ai::filament.ai_center.prev_page') }}</x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="pickerNextPage" :disabled="$picker['page'] >= $picker['last_page']">{{ __('seo-content-ai::filament.ai_center.next_page') }}</x-filament::button>
                        </div>
                    </div>
                    <div class="seo-ai-modal__actions">
                        <x-filament::button color="gray" wire:click="closeModelPicker">{{ __('seo-content-ai::filament.ai_center.cancel') }}</x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        @if ($showImportModal)
            <div class="seo-capability-matrix-overlay" wire:key="ai-center-import-modal">
                <div class="seo-capability-matrix-backdrop" wire:click="closeImportModal" aria-hidden="true"></div>
                <div class="seo-ai-modal" role="dialog" aria-modal="true">
                    <h2>{{ __('seo-content-ai::filament.ai_center.import_modal_title') }}</h2>
                    <p class="seo-ai-section-help">{{ __('seo-content-ai::filament.ai_center.import_template_help') }}</p>
                    <div
                        class="seo-ai-dropzone"
                        x-data="{ dragging: false }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="
                            dragging = false;
                            const input = $refs.templateFile;
                            input.files = $event.dataTransfer.files;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        "
                        x-bind:class="dragging ? 'is-drag' : ''"
                    >
                        <p>{{ __('seo-content-ai::filament.settings_transfer.drop_json') }}</p>
                        <p class="seo-ai-muted">{{ __('seo-content-ai::filament.settings_transfer.or') }}</p>
                        <label class="seo-ai-file-btn">
                            {{ __('seo-content-ai::filament.settings_transfer.choose_json') }}
                            <input x-ref="templateFile" type="file" accept=".json,application/json" wire:model="templateFile" class="sr-only" />
                        </label>
                        <p class="seo-ai-muted">{{ __('seo-content-ai::filament.settings_transfer.accepted_json') }}</p>
                    </div>
                    @if (is_array($importPreview))
                        <dl class="seo-ai-preview">
                            @foreach ($importPreview as $k => $v)
                                <dt>{{ $k }}</dt>
                                <dd>{{ $v }}</dd>
                            @endforeach
                        </dl>
                        @foreach ($importWarnings as $warning)
                            <p class="text-sm text-amber-600">{{ $warning }}</p>
                        @endforeach
                        @foreach ($importDiff as $line)
                            <p class="text-sm">{{ $line }}</p>
                        @endforeach
                    @endif
                    <div class="seo-ai-modal__actions">
                        <x-filament::button color="gray" wire:click="closeImportModal">{{ __('seo-content-ai::filament.ai_center.cancel') }}</x-filament::button>
                        @if (is_array($importPreview))
                            <x-filament::button wire:click="confirmImport">{{ __('seo-content-ai::filament.ai_center.confirm_import') }}</x-filament::button>
                        @else
                            <x-filament::button disabled color="gray">{{ __('seo-content-ai::filament.ai_center.preview') }}</x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </x-filament-panels::page>
    <script>
        function seoAiCenter(initial) {
            return {
                activeMainTab: ['routing', 'resilience', 'health'].includes(initial.tab) ? initial.tab : 'models',
                activeCapability: (function () {
                    const modelAreas = ['fast_text', 'long_form_text', 'reasoning_text', 'image', 'video'];
                    const routingGroups = ['text', 'image', 'video'];
                    if (initial.tab === 'routing') {
                        if (routingGroups.includes(initial.area)) return initial.area;
                        if (['fast_text', 'long_form_text', 'reasoning_text', 'text'].includes(initial.area)) return 'text';
                        return 'text';
                    }
                    if (modelAreas.includes(initial.area)) return initial.area;
                    if (initial.area === 'text') return 'fast_text';
                    return 'fast_text';
                })(),
                modelsHydrated: initial.modelsHydrated !== false,
                routingHydrated: !!initial.routingHydrated,
                resilienceHydrated: !!initial.resilienceHydrated,
                healthHydrated: !!initial.healthHydrated,
                panelLoading: false,
                editingProfile: initial.editingProfile || null,
                routingUnsaved: !!initial.routingUnsaved,
                modelsOrderDirty: false,
                pendingOrders: {},
                drafts: {},
                get areaSummary() {
                    const el = this.$root.querySelector(`[data-models-area="${this.activeCapability}"]`);
                    return el?.dataset?.areaSummary || '';
                },
                get addModelsLabel() {
                    const el = this.$root.querySelector(`[data-models-area="${this.activeCapability}"]`);
                    return el?.dataset?.addLabel || '';
                },
                ensureDraft(key, seed) {
                    if (! this.drafts[key]) {
                        this.drafts[key] = {
                            mode: seed?.mode || 'automatic',
                            enabled: seed?.enabled !== false,
                            selected: Array.isArray(seed?.selected) ? [...seed.selected] : [],
                        };
                    }
                    return this.drafts[key];
                },
                draftMode(key, fallback) {
                    return this.drafts[key]?.mode || fallback || 'automatic';
                },
                draftEnabled(key, fallback) {
                    return this.drafts[key] ? !!this.drafts[key].enabled : !!fallback;
                },
                draftSelected(key, fallback) {
                    return this.drafts[key]?.selected || fallback || [];
                },
                replaceUrl() {
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('tab', this.activeMainTab);
                        url.searchParams.set('modelArea', this.activeCapability);
                        history.replaceState({}, '', url);
                    } catch (e) {}
                },
                routingGroupForCapability(capability) {
                    return ['image', 'video'].includes(capability) ? capability : 'text';
                },
                markPanelHydrated(panel) {
                    if (panel === 'routing') {
                        this.routingHydrated = !!this.$root.querySelector('#ai-center-routing');
                        return this.routingHydrated;
                    }
                    if (panel === 'models') {
                        this.modelsHydrated = !!this.$root.querySelector('#ai-center-models') || this.modelsHydrated;
                        return this.modelsHydrated;
                    }
                    if (panel === 'resilience') {
                        this.resilienceHydrated = !!this.$root.querySelector('#ai-center-resilience');
                        return this.resilienceHydrated;
                    }
                    if (panel === 'health') {
                        this.healthHydrated = !!this.$root.querySelector('#ai-center-health');
                        return this.healthHydrated;
                    }
                    return false;
                },
                async setTab(next) {
                    if (this.panelLoading || this.activeMainTab === next) {
                        return;
                    }
                    this.activeMainTab = next;
                    let areaForWire = null;
                    if (next === 'routing') {
                        const group = this.routingGroupForCapability(this.activeCapability);
                        this.activeCapability = group;
                        areaForWire = group;
                    } else if (next === 'models' && this.activeCapability === 'text') {
                        this.activeCapability = 'fast_text';
                        areaForWire = 'fast_text';
                    }
                    this.replaceUrl();
                    this.panelLoading = true;
                    try {
                        if (next === 'routing' && ! this.routingHydrated) {
                            await this.$wire.openPanel('routing', areaForWire);
                            await this.$nextTick();
                            if (! this.markPanelHydrated('routing')) {
                                await this.$wire.openPanel('routing', areaForWire);
                                await this.$nextTick();
                                this.markPanelHydrated('routing');
                            }
                        } else if (next === 'models' && ! this.modelsHydrated) {
                            await this.$wire.openPanel('models', areaForWire ?? this.activeCapability);
                            await this.$nextTick();
                            this.markPanelHydrated('models');
                        } else if (next === 'resilience' && ! this.resilienceHydrated) {
                            await this.$wire.openPanel('resilience');
                            await this.$nextTick();
                            this.markPanelHydrated('resilience');
                        } else if (next === 'health' && ! this.healthHydrated) {
                            await this.$wire.openPanel('health');
                            await this.$nextTick();
                            this.markPanelHydrated('health');
                        } else if (areaForWire !== null) {
                            await this.$wire.setModelArea(areaForWire);
                        }
                    } catch (e) {
                    } finally {
                        this.panelLoading = false;
                    }
                },
                setArea(next) {
                    if (this.panelLoading || this.activeCapability === next) {
                        return;
                    }
                    this.activeCapability = next;
                    this.replaceUrl();
                    this.$wire.setModelArea(next);
                },
                startEdit(key, mode, enabled, selected) {
                    this.ensureDraft(key, {
                        mode: mode || 'automatic',
                        enabled: enabled !== false,
                        selected: Array.isArray(selected) ? selected : [],
                    });
                    this.editingProfile = key;
                    this.$wire.startEditProfile(key);
                },
                setMode(key, mode, selectedFallback) {
                    const draft = this.ensureDraft(key, {
                        mode: mode,
                        selected: Array.isArray(selectedFallback) ? selectedFallback : [],
                    });
                    draft.mode = mode === 'custom' ? 'custom' : 'automatic';
                    if (draft.mode !== 'custom') {
                        draft.selected = [];
                    }
                    this.routingUnsaved = true;
                    this.$wire.setSelectionMode(key, draft.mode);
                },
                toggleEnabled(key, fallback) {
                    const draft = this.ensureDraft(key, { enabled: fallback });
                    draft.enabled = ! draft.enabled;
                    this.routingUnsaved = true;
                    this.$wire.toggleRoutingEnabled(key);
                },
                toggleChip(key, familyKey, selectedFallback) {
                    const draft = this.ensureDraft(key, {
                        mode: 'custom',
                        selected: Array.isArray(selectedFallback) ? selectedFallback : [],
                    });
                    draft.mode = 'custom';
                    if (draft.selected.includes(familyKey)) {
                        draft.selected = draft.selected.filter((x) => x !== familyKey);
                    } else {
                        draft.selected = [...draft.selected, familyKey];
                    }
                    this.routingUnsaved = true;
                    this.$wire.toggleFamily(key, familyKey);
                },
                markModelsOrder(area, ids) {
                    this.pendingOrders[area] = ids;
                    this.modelsOrderDirty = Object.keys(this.pendingOrders).length > 0;
                },
                async saveModelOrders() {
                    const entries = Object.entries(this.pendingOrders);
                    if (entries.length === 0) {
                        return;
                    }
                    try {
                        for (const [area, ids] of entries) {
                            const ok = await this.$wire.reorderCapabilityModels(area, ids);
                            if (ok === false) {
                                return;
                            }
                        }
                        this.pendingOrders = {};
                        this.modelsOrderDirty = false;
                    } catch (e) {}
                },
                async submitRouting() {
                    await this.$wire.saveRouting();
                    this.routingUnsaved = false;
                    this.editingProfile = null;
                },
            };
        }

        function seoAiSortableList() {
            return {
                snapshot: [],
                ended: false,
                ids() {
                    return Array.from(this.$refs.list.querySelectorAll('[data-target-id]'))
                        .map((el) => Number(el.dataset.targetId))
                        .filter((id) => id > 0);
                },
                itemFromEvent(event) {
                    return event.target.closest('[data-target-id]');
                },
                notifyDirty() {
                    const area = this.$root.dataset.area;
                    const ids = this.ids();
                    let parent = this.$root.parentElement;
                    while (parent) {
                        if (parent._x_dataStack) {
                            const data = Alpine.$data(parent);
                            if (data && typeof data.markModelsOrder === 'function') {
                                data.markModelsOrder(area, ids);
                                break;
                            }
                        }
                        parent = parent.parentElement;
                    }
                },
                start(event) {
                    this.ended = false;
                    const item = this.itemFromEvent(event);
                    if (! item || this.$root.dataset.canReorder !== '1') {
                        event.preventDefault();
                        return;
                    }
                    this.snapshot = this.ids();
                    event.dataTransfer.setData('text/plain', item.dataset.targetId);
                    event.dataTransfer.effectAllowed = 'move';
                    item.classList.add('is-dragging');
                },
                over(event) {
                    const dragging = this.$refs.list.querySelector('.is-dragging');
                    const over = this.itemFromEvent(event);
                    if (! dragging || ! over || dragging === over) {
                        return;
                    }
                    const rect = over.getBoundingClientRect();
                    const before = event.clientY < (rect.top + rect.height / 2);
                    this.$refs.list.insertBefore(dragging, before ? over : over.nextSibling);
                    this.renumber();
                },
                renumber() {
                    Array.from(this.$refs.list.querySelectorAll('.seo-ai-sort-item__n')).forEach((el, index) => {
                        el.textContent = String(index + 1);
                    });
                },
                nudge(targetId, delta) {
                    if (this.$root.dataset.canReorder !== '1') {
                        return;
                    }
                    const ids = this.ids();
                    const from = ids.indexOf(Number(targetId));
                    const to = from + Number(delta);
                    if (from < 0 || to < 0 || to >= ids.length) {
                        return;
                    }
                    const nodes = Array.from(this.$refs.list.querySelectorAll('[data-target-id]'));
                    const moving = nodes[from];
                    const anchor = nodes[to];
                    if (! moving || ! anchor) {
                        return;
                    }
                    if (delta < 0) {
                        this.$refs.list.insertBefore(moving, anchor);
                    } else {
                        this.$refs.list.insertBefore(moving, anchor.nextSibling);
                    }
                    this.renumber();
                    this.notifyDirty();
                },
                end() {
                    if (this.ended) {
                        return;
                    }
                    this.ended = true;
                    const dragging = this.$refs.list.querySelector('.is-dragging');
                    dragging?.classList.remove('is-dragging');
                    const ids = this.ids();
                    if (JSON.stringify(ids) === JSON.stringify(this.snapshot) || ids.length === 0) {
                        return;
                    }
                    this.notifyDirty();
                },
            };
        }
    </script>
</div>
