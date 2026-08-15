<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'overview'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Overview') }}</h1>
                    <p>{{ __('General settings and AI model status (sync, priority, quota).') }}</p>
                </header>

                <section class="seo-rec-overview-teaser">
                    <div class="seo-rec-overview-teaser__content">
                        <h2 class="seo-rec-overview-teaser__title">
                            <x-filament::icon icon="heroicon-o-book-open" class="seo-rec-overview-teaser__icon" />
                            {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_title') }}
                        </h2>
                        <p class="seo-rec-overview-teaser__body">
                            {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_body') }}
                        </p>
                    </div>
                    <x-filament::button
                        tag="a"
                        :href="\Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsRecommendations::getUrl()"
                        color="gray"
                        outlined
                        icon="heroicon-o-arrow-right"
                        icon-position="after"
                    >
                        {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_link') }}
                    </x-filament::button>
                </section>

                <section class="seo-ai-models-panel" x-data="{ advanced: false }">
                    <div class="seo-ai-models-panel__head">
                        <div>
                            <h2 class="seo-ai-models-panel__title">AI model status</h2>
                            <p class="seo-ai-models-panel__meta">
                                {{ $aiModelsOverview['total_models'] ?? 0 }} model
                                @if (filled($aiModelsOverview['last_synced_at'] ?? null))
                                    · {{ __('seo-content-ai::filament.performance_hub.gsc_last_synced', ['time' => $aiModelsOverview['last_synced_at']]) }}
                                @endif
                                · Nhóm theo capability (Unknown không vào routing mặc định)
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::button
                                type="button"
                                color="gray"
                                size="sm"
                                x-on:click="advanced = ! advanced"
                            >
                                <span x-text="advanced ? 'Ẩn nâng cao' : 'Nâng cao'"></span>
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                icon="heroicon-o-arrow-path"
                                wire:click="syncAllAiModels"
                                wire:loading.attr="disabled"
                                wire:target="syncAllAiModels"
                            >
                                <span wire:loading.remove wire:target="syncAllAiModels">Sync all</span>
                                <span wire:loading wire:target="syncAllAiModels">Syncing...</span>
                            </x-filament::button>
                        </div>
                    </div>

                    @php
                        $groupLabels = [
                            'text' => 'Text',
                            'image' => 'Image',
                            'image_typography' => 'Image Typography',
                            'video' => 'Video',
                            'unknown' => 'Unknown',
                        ];
                    @endphp

                    @forelse ($aiModelsOverview['connections'] ?? [] as $connection)
                        <div class="seo-ai-connection-block" wire:key="ai-conn-{{ $connection['id'] }}">
                            <div class="seo-ai-connection-block__head">
                                <div>
                                    <h3 class="seo-ai-connection-block__name">{{ $connection['name'] }}</h3>
                                    <p class="seo-ai-connection-block__meta">
                                        {{ strtoupper((string) $connection['provider']) }}
                                        · {{ $connection['model_count'] }} model
                                        · Kết nối: <span class="seo-ai-status seo-ai-status--{{ $connection['status'] }}">{{ $connection['status'] }}</span>
                                    </p>
                                </div>
                                <div class="seo-ai-connection-block__actions">
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        tag="a"
                                        :href="$this->aiConnectionEditUrl($connection['id'])"
                                    >
                                        Edit connection
                                    </x-filament::button>
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        icon="heroicon-o-arrow-path"
                                        wire:click="syncConnectionAiModels({{ $connection['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="syncConnectionAiModels({{ $connection['id'] }})"
                                    >
                                        Sync
                                    </x-filament::button>
                                </div>
                            </div>

                            @php
                                $groups = is_array($connection['groups'] ?? null) ? $connection['groups'] : [];
                            @endphp

                            @if (($connection['model_count'] ?? 0) === 0)
                                <p class="seo-ai-models-empty">
                                    No models in <code>seo_ai_models</code> yet. Click "Sync" - Imagen / Nano Banana are seeded from internal catalog (Google API often does not list Imagen).
                                </p>
                            @else
                                @foreach ($groupLabels as $groupKey => $groupLabel)
                                    @php $groupModels = is_array($groups[$groupKey] ?? null) ? $groups[$groupKey] : []; @endphp
                                    @if ($groupModels === [])
                                        @continue
                                    @endif
                                    <h4 class="mt-3 mb-1 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $groupLabel }} ({{ count($groupModels) }})</h4>
                                    <div class="seo-ai-models-table-wrap">
                                        <table class="seo-ai-models-table">
                                            <thead>
                                                <tr>
                                                    <th>Model</th>
                                                    <th>Status</th>
                                                    <th x-show="advanced" x-cloak>Raw / Priority</th>
                                                    <th x-show="advanced" x-cloak>Capabilities</th>
                                                    @if ($groupKey === 'unknown')
                                                        <th>Admin enable</th>
                                                    @endif
                                                    <th>Updated</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($groupModels as $model)
                                                    <tr wire:key="ai-model-{{ $model['id'] }}">
                                                        <td>
                                                            <span class="seo-ai-models-table__cat">{{ $model['display_name'] }}</span>
                                                            <span class="seo-ai-models-table__sub">{{ $groupLabel }}</span>
                                                        </td>
                                                        <td>
                                                            <span class="seo-ai-status seo-ai-status--{{ $model['status'] }}">
                                                                {{ $model['status'] }}
                                                            </span>
                                                            @if (($model['routing_status'] ?? '') === 'disabled')
                                                                <span class="seo-ai-models-table__err" title="{{ $model['disabled_reason'] ?? '' }}">
                                                                    routing: disabled
                                                                    @if (filled($model['disabled_reason'] ?? null))
                                                                        ({{ $model['disabled_reason'] }})
                                                                    @endif
                                                                </span>
                                                            @endif
                                                            @if (filled($model['last_error'] ?? null))
                                                                <span class="seo-ai-models-table__err" title="{{ $model['last_error'] }}">Quota error</span>
                                                            @endif
                                                        </td>
                                                        <td x-show="advanced" x-cloak>
                                                            <code>{{ $model['raw_model_name'] }}</code>
                                                            · p{{ $model['priority'] }}
                                                        </td>
                                                        <td x-show="advanced" x-cloak>
                                                            <code class="text-xs">{{ implode(', ', $model['capabilities_resolved'] ?? []) }}</code>
                                                        </td>
                                                        @if ($groupKey === 'unknown')
                                                            <td>
                                                                <x-filament::button
                                                                    type="button"
                                                                    size="xs"
                                                                    color="{{ !empty($model['admin_enabled_unknown']) ? 'success' : 'gray' }}"
                                                                    wire:click="toggleUnknownModelRouting(@js($model['raw_model_name']), {{ !empty($model['admin_enabled_unknown']) ? 'false' : 'true' }})"
                                                                >
                                                                    {{ !empty($model['admin_enabled_unknown']) ? 'Enabled' : 'Enable for test' }}
                                                                </x-filament::button>
                                                            </td>
                                                        @endif
                                                        <td class="seo-ai-models-table__time">{{ $model['updated_at'] ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @empty
                        <p class="seo-ai-models-empty">
                            No AI connections yet. Add one in <a href="{{ \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::getUrl() }}" class="text-primary-600 underline">AI settings</a>.
                        </p>
                    @endforelse
                </section>

                <section class="seo-ai-models-panel mt-6">
                    <form wire:submit="saveTeamChatSettings">
                        {{ $this->teamChatForm }}

                        <div class="mt-4">
                            <x-seo-content-ai::form-save-button
                                target="saveTeamChatSettings"
                                :label="__('seo-content-ai::filament.settings_overview.team_chat_save')"
                            />
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </x-filament-panels::page>
</div>
