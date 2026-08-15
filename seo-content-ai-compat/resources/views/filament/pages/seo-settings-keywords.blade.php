<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'keywords'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_keywords.page_title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_keywords.page_description') }}</p>
                </header>

                <form wire:submit="saveKeywordSettings" class="max-w-3xl mx-auto space-y-6">
                    {{ $this->form }}

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <x-filament::button
                            type="button"
                            color="gray"
                            icon="heroicon-o-bug-ant"
                            wire:click="debugCtaBlacklist"
                            wire:loading.attr="disabled"
                            wire:target="debugCtaBlacklist"
                        >
                            {{ __('seo-content-ai::filament.settings_keywords.debug_cta') }}
                        </x-filament::button>

                        <x-seo-content-ai::form-save-button
                            target="saveKeywordSettings"
                            :label="__('seo-content-ai::filament.settings_keywords.save')"
                        />
                    </div>
                </form>

                @if (is_array($debugReport))
                    <div class="mx-auto mt-8 max-w-3xl space-y-4">
                        <x-filament::section
                            :heading="__('seo-content-ai::filament.settings_keywords.debug_report_title')"
                            :description="__('seo-content-ai::filament.settings_keywords.debug_report_description', [
                                'scanned_keywords' => (int) ($debugReport['scanned_keywords'] ?? 0),
                            ])"
                        >
                            <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">
                                {{ __('seo-content-ai::filament.settings_keywords.debug_matched_keywords') }}
                                ({{ count($debugReport['matched_keywords'] ?? []) }})
                            </h3>

                            @if (($debugReport['matched_keywords'] ?? []) === [])
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.settings_keywords.debug_no_matches') }}
                                </p>
                            @else
                                <ul class="max-h-96 space-y-2 overflow-y-auto text-sm text-gray-700 dark:text-gray-200">
                                    @foreach ($debugReport['matched_keywords'] as $keyword)
                                        <li class="rounded-md bg-gray-50 px-3 py-2 dark:bg-white/5">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">#{{ (int) ($keyword['id'] ?? 0) }}</span>
                                                <span class="inline-flex rounded-md bg-gray-200/80 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                                    {{ (string) ($keyword['type'] ?? '') }}
                                                </span>
                                                <span>{{ (string) ($keyword['phrase'] ?? '') }}</span>
                                            </div>
                                            @if (($keyword['matched_rules'] ?? []) !== [])
                                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                                                    <span class="font-medium">{{ __('seo-content-ai::filament.settings_keywords.debug_matched_by') }}:</span>
                                                    @foreach ($keyword['matched_rules'] as $rule)
                                                        <span class="inline-flex rounded-md bg-amber-100 px-2 py-0.5 font-mono dark:bg-amber-500/10">
                                                            {{ $rule }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-filament::section>
                    </div>
                @endif
            </div>
        </div>
    </x-filament-panels::page>
</div>
