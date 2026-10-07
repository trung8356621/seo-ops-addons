<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'keywords'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>Match &amp; Research</h1>
                    <p>Shared matching / research authority — System, Industry, and Custom knowledge. No auto-translation.</p>
                </header>

                <div class="mx-auto mb-6 flex max-w-3xl gap-2">
                    @foreach (['system' => 'System', 'industry' => 'Industry', 'custom' => 'Custom'] as $tab => $label)
                        <x-filament::button
                            type="button"
                            size="sm"
                            :color="$activeOriginTab === $tab ? 'primary' : 'gray'"
                            wire:click="setOriginTab('{{ $tab }}')"
                        >{{ $label }}</x-filament::button>
                    @endforeach
                </div>

                @if ($activeOriginTab === 'system')
                    <form wire:submit="saveKeywordSettings" class="max-w-3xl mx-auto space-y-6">
                        {{ $this->form }}

                        <div class="rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">
                            <p class="mb-2 font-medium">Registry projection (read-only identity)</p>
                            <ul class="max-h-48 space-y-1 overflow-y-auto font-mono text-xs text-gray-600 dark:text-gray-300">
                                @foreach ($registrySystem as $row)
                                    <li>
                                        {{ $row['key'] }}
                                        · match={{ ($row['capabilities']['can_match'] ?? false) ? '1' : '0' }}
                                        · tag={{ ($row['capabilities']['can_tag'] ?? false) ? '1' : '0' }}
                                        · deletable={{ ($row['deletable'] ?? false) ? '1' : '0' }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>

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
                @endif

                @if ($activeOriginTab === 'industry')
                    <div class="mx-auto max-w-3xl space-y-4">
                        <x-filament::section heading="Industry Groups" description="Read-only concept groups from the active Industry Match & Research taxonomy. Not Keywords, Topics, or Tags.">
                            @if ($industryProvenance)
                                <div class="mb-4 flex flex-wrap gap-3 text-sm">
                                    <span><strong>Industry Context:</strong> {{ $industryProvenance['industry_context_key'] }}</span>
                                    <span><strong>Match revision:</strong> #{{ $industryProvenance['match_revision_id'] }}</span>
                                    <x-filament::badge :color="($industryProvenance['stale'] ?? true) ? 'warning' : 'success'">
                                        {{ ($industryProvenance['stale'] ?? true) ? 'Stale' : 'Fresh' }}
                                    </x-filament::badge>
                                </div>

                                @php
                                    $typeLabels = [
                                        'products' => 'Products',
                                        'product_families' => 'Product Families',
                                        'materials' => 'Materials',
                                        'services' => 'Services',
                                        'audiences' => 'Audiences',
                                        'use_cases' => 'Use Cases',
                                        'features' => 'Features',
                                        'adjacent_products' => 'Adjacent Products',
                                    ];
                                @endphp
                                <div class="space-y-5">
                                    @foreach ($typeLabels as $type => $label)
                                        @php($items = $industryGroupsByType[$type] ?? [])
                                        <div>
                                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</h3>
                                            @if ($items === [])
                                                <p class="mt-1 text-sm text-gray-500">—</p>
                                            @else
                                                <ul class="mt-2 space-y-2">
                                                    @foreach ($items as $item)
                                                        <li class="rounded-md bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <span class="font-medium">{{ $item['name'] }}</span>
                                                                <span class="font-mono text-xs text-gray-500">{{ $item['key'] }}</span>
                                                                @if (! empty($item['match_mode']))
                                                                    <x-filament::badge color="gray">{{ $item['match_mode'] }}</x-filament::badge>
                                                                @endif
                                                                <x-filament::badge :color="($item['localized'] ?? false) ? 'success' : 'warning'">
                                                                    {{ ($item['localized'] ?? false) ? 'Localized' : 'Source locale' }}
                                                                    · {{ $item['effective_locale'] ?? $item['source_locale'] }}
                                                                </x-filament::badge>
                                                                @if ($item['stale'] ?? false)
                                                                    <x-filament::badge color="warning">Stale</x-filament::badge>
                                                                @endif
                                                            </div>
                                                            @if (($item['aliases'] ?? []) !== [])
                                                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                                    @foreach ($item['aliases'] as $alias)
                                                                        <x-filament::badge color="gray">{{ $alias }}</x-filament::badge>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
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

                        @if ($industryProvenance && ($industryNonGroupResources ?? []) !== [])
                            <x-filament::section heading="Other Industry Rules" description="Topic rules, aliases, and ambiguities — Match & Research knowledge, not Industry Groups.">
                                <div class="space-y-2 font-mono text-xs text-gray-600 dark:text-gray-300">
                                    @foreach ($industryNonGroupResources as $row)
                                        <div>
                                            {{ $row['key'] }}
                                            · {{ $row['kind'] }}
                                            · {{ $row['payload']['group'] ?? ($row['provenance']['group'] ?? '') }}
                                            · {{ $row['label'] }}
                                        </div>
                                    @endforeach
                                </div>
                            </x-filament::section>
                        @endif
                    </div>
                @endif

                @if ($activeOriginTab === 'custom')
                    <div class="mx-auto max-w-3xl space-y-6">
                        <x-filament::section heading="Custom Concepts" description="Site-scoped knowledge only. No semantic auto-tagging in this release — Python matching is a separate task.">
                            <div class="space-y-3">
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" wire:model="customForm.name" placeholder="Name" />
                                </x-filament::input.wrapper>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" wire:model="customForm.source_locale" placeholder="source_locale (immutable after create)" :disabled="$editingCustomKey !== null" />
                                </x-filament::input.wrapper>
                                <textarea wire:model="customForm.description" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Description"></textarea>
                                <textarea wire:model="customForm.positive_examples" rows="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Positive examples (one per line)"></textarea>
                                <textarea wire:model="customForm.negative_examples" rows="3" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Negative examples (one per line)"></textarea>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="customForm.enabled" />
                                    Enabled
                                </label>
                                <x-filament::button type="button" wire:click="saveCustomConcept" wire:loading.attr="disabled" wire:target="saveCustomConcept">
                                    {{ $editingCustomKey ? 'Update concept' : 'Create concept' }}
                                </x-filament::button>
                            </div>

                            <ul class="mt-6 space-y-2 text-sm">
                                @forelse ($registryCustom as $row)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-gray-50 px-3 py-2 dark:bg-white/5">
                                        <div>
                                            <span class="font-mono text-xs text-gray-500">{{ $row['key'] }}</span>
                                            <div>{{ $row['label'] }} · {{ $row['source_locale'] }}</div>
                                        </div>
                                        <div class="flex gap-2">
                                            <x-filament::button size="sm" color="gray" type="button" wire:click="editCustomConcept('{{ $row['key'] }}')">Edit</x-filament::button>
                                            <x-filament::button size="sm" color="danger" type="button" wire:click="deleteCustomConcept('{{ $row['key'] }}')" wire:confirm="Delete this custom concept?">Delete</x-filament::button>
                                        </div>
                                    </li>
                                @empty
                                    <li class="text-gray-500">No custom concepts yet.</li>
                                @endforelse
                            </ul>
                        </x-filament::section>
                    </div>
                @endif

                <div class="mx-auto mt-8 max-w-3xl space-y-4">
                    <x-filament::section heading="Localization" description="Export a localization prompt for an external agent, then import JSON. No automatic translation.">
                        <div class="space-y-3">
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="localizationResourceKey" placeholder="resource_key (e.g. system.cta_blacklist or custom.recruitment)" />
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="localizationTargetLocale" placeholder="target_locale (e.g. en)" />
                            </x-filament::input.wrapper>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::button type="button" wire:click="exportLocalizationPrompt" wire:loading.attr="disabled" wire:target="exportLocalizationPrompt">
                                    Export Localization Prompt
                                </x-filament::button>
                                <x-filament::button type="button" color="gray" wire:click="importLocalizationResult" wire:loading.attr="disabled" wire:target="importLocalizationResult">
                                    Import Localized Result
                                </x-filament::button>
                            </div>
                            @if ($localizationPrompt !== '')
                                <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 font-mono text-xs text-gray-100">{{ $localizationPrompt }}</pre>
                            @endif
                            <textarea wire:model="localizationImportJson" rows="6" class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-white/10 dark:bg-white/5" placeholder="Paste localized JSON result here"></textarea>
                        </div>
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
