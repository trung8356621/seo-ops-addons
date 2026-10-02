<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'keywords'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>Match &amp; Research</h1>
                    <p>Inspect global matching rules, generated industry rules, and deterministic matcher diagnostics.</p>
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

                <div class="mx-auto mt-8 max-w-3xl space-y-4">
                    <x-filament::section heading="Industry Rules" description="Read-only rules resolved from the current site's active Match & Research revision.">
                        @if ($industryProvenance)
                            <div class="mb-4 flex flex-wrap gap-3 text-sm">
                                <span><strong>Industry Context:</strong> {{ $industryProvenance['industry_context_key'] }}</span>
                                <span><strong>Match revision:</strong> #{{ $industryProvenance['match_revision_id'] }}</span>
                                <x-filament::badge :color="($industryProvenance['stale'] ?? true) ? 'warning' : 'success'">
                                    {{ ($industryProvenance['stale'] ?? true) ? 'Stale' : 'Fresh' }}
                                </x-filament::badge>
                            </div>
                            <div class="space-y-4">
                                @foreach ($industryRules as $group => $entries)
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ str($group)->replace('_', ' ')->title() }}</h3>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @forelse ($entries as $entry)
                                                @php($label = is_array($entry) ? ($entry['canonical'] ?? $entry['term'] ?? '') : (string) $entry)
                                                @if ($label !== '')
                                                    <x-filament::badge color="gray">{{ $label }}</x-filament::badge>
                                                @endif
                                            @empty
                                                <span class="text-sm text-gray-500">—</span>
                                            @endforelse
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">Chưa có Match &amp; Research đang hoạt động cho Industry Context của site hiện tại.</p>
                            @if ($industryContextKey)
                                <p class="mt-1 text-xs text-gray-400">Industry Context key: {{ $industryContextKey }}</p>
                            @endif
                        @endif
                    </x-filament::section>
                    <x-filament::section heading="Debug Matcher" description="Deterministic diagnostics only; no AI provider is called.">
                        <div class="space-y-3">
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="debugPhrase" placeholder="Nhập cụm từ cần kiểm tra" />
                            </x-filament::input.wrapper>
                            <x-filament::button type="button" wire:click="debugMatcher" wire:loading.attr="disabled" wire:target="debugMatcher">Debug Matcher</x-filament::button>
                            @if (is_array($matcherReport))
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 font-mono text-xs text-gray-100">{{ json_encode($matcherReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        </div>
                    </x-filament::section>
                </div>

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
