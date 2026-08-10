@php
    $cssPath = base_path('addons/seo/resources/css/ai-keyword-discovery.css');
    $suggestions = $this->suggestions;
    $selectedCount = $this->getSelectedSuggestionCount();
@endphp

<x-filament-panels::page class="ai-discovery-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    <div class="performance-hub-shell space-y-5">
        <header class="performance-hub-header">
            <div>
                <h1 class="performance-hub-title">{{ __('seo-content-ai::filament.keyword.ai_discovery_title') }}</h1>
                <p class="performance-hub-subtitle">{{ __('seo-content-ai::filament.keyword.ai_discovery_subtitle') }}</p>
            </div>
        </header>

        <div
            class="ai-discovery-layout"
            x-data="{
                copyPhrase(phrase) {
                    if (! phrase) return;
                    navigator.clipboard?.writeText(phrase);
                }
            }"
            @discovery-copy-keyword.window="copyPhrase($event.detail.phrase)"
        >
            <aside class="ai-discovery-form-pane">
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('seo-content-ai::filament.keyword.discovery_form_heading') }}
                        </h2>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.discovery_form_hint') }}
                        </p>
                    </div>

                    <form wire:submit="generateAiKeywords" class="space-y-4 p-4">
                        <div>
                            <label for="discovery-seed" class="ai-discovery-label">
                                {{ __('seo-content-ai::filament.keyword.discovery_seed_label') }}
                            </label>
                            <input
                                id="discovery-seed"
                                type="text"
                                wire:model="seedKeyword"
                                wire:loading.attr="disabled"
                                wire:target="generateAiKeywords"
                                placeholder="{{ __('seo-content-ai::filament.keyword.discovery_seed_placeholder') }}"
                                class="ai-discovery-input"
                            />
                        </div>

                        <div>
                            <label for="discovery-intent" class="ai-discovery-label">
                                {{ __('seo-content-ai::filament.keyword.discovery_intent_label') }}
                            </label>
                            <x-select
                                id="discovery-intent"
                                wire:model="searchIntent"
                                wire:loading.attr="disabled"
                                wire:target="generateAiKeywords"
                                class="ai-discovery-select"
                            >
                                <option value="any">{{ __('seo-content-ai::filament.keyword.discovery_intent_any') }}</option>
                                <option value="informational">{{ __('seo-content-ai::filament.keyword.discovery_intent_informational') }}</option>
                                <option value="commercial">{{ __('seo-content-ai::filament.keyword.discovery_intent_commercial') }}</option>
                                <option value="transactional">{{ __('seo-content-ai::filament.keyword.discovery_intent_transactional') }}</option>
                            </x-select>
                        </div>

                        <div>
                            <label for="discovery-region" class="ai-discovery-label">
                                {{ __('seo-content-ai::filament.keyword.discovery_region_label') }}
                            </label>
                            <x-select
                                id="discovery-region"
                                wire:model="targetRegion"
                                wire:loading.attr="disabled"
                                wire:target="generateAiKeywords"
                                class="ai-discovery-select"
                            >
                                <option value="vietnam">{{ __('seo-content-ai::filament.keyword.discovery_region_vietnam') }}</option>
                                <option value="global">{{ __('seo-content-ai::filament.keyword.discovery_region_global') }}</option>
                                <option value="us">{{ __('seo-content-ai::filament.keyword.discovery_region_us') }}</option>
                                <option value="uk">{{ __('seo-content-ai::filament.keyword.discovery_region_uk') }}</option>
                                <option value="sea">{{ __('seo-content-ai::filament.keyword.discovery_region_sea') }}</option>
                            </x-select>
                        </div>

                        <button
                            type="submit"
                            class="ai-discovery-generate-btn"
                            wire:loading.attr="disabled"
                            wire:target="generateAiKeywords"
                        >
                            <span wire:loading.remove wire:target="generateAiKeywords">
                                {{ __('seo-content-ai::filament.keyword.discovery_generate') }}
                            </span>
                            <span wire:loading wire:target="generateAiKeywords" class="inline-flex items-center gap-2">
                                <svg class="ai-discovery-spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                {{ __('seo-content-ai::filament.keyword.discovery_generating') }}
                            </span>
                        </button>
                    </form>
                </div>
            </aside>

            <section class="ai-discovery-results-pane min-w-0">
                @if ($suggestions === [])
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-empty-state')
                @else
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-results-table', [
                        'suggestions' => $suggestions,
                        'selectedSuggestionIds' => $this->selectedSuggestionIds,
                        'isAllSelected' => $this->isAllSelected(),
                    ])
                @endif
            </section>
        </div>

        @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-action-bar', [
            'selectedCount' => $selectedCount,
            'hasSuggestions' => $suggestions !== [],
        ])
    </div>
</x-filament-panels::page>
