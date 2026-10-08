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
                    <div class="mx-auto max-w-3xl space-y-6">
                        <x-filament::section heading="Match & Research registry" description="Current system knowledge. This is not a match result.">
                            <ul class="space-y-2">
                                @forelse ($registrySystem as $row)
                                    <li class="rounded-md bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                                        <div class="font-medium">{{ $row['label'] }}</div>
                                        <div class="mt-1 font-mono text-xs text-gray-500">{{ $row['key'] }}</div>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <x-filament::badge color="gray">{{ $row['match_mode'] ?: '—' }}</x-filament::badge>
                                            <x-filament::badge :color="($row['capabilities']['can_match'] ?? false) ? 'success' : 'gray'">
                                                {{ ($row['capabilities']['can_match'] ?? false) ? 'can match' : 'cannot match' }}
                                            </x-filament::badge>
                                            <x-filament::badge :color="($row['capabilities']['can_exclude'] ?? false) ? 'warning' : 'gray'">
                                                {{ ($row['capabilities']['can_exclude'] ?? false) ? 'can exclude' : 'cannot exclude' }}
                                            </x-filament::badge>
                                            <x-filament::badge :color="($row['enabled'] ?? false) ? 'success' : 'gray'">
                                                {{ ($row['enabled'] ?? false) ? 'enabled' : 'disabled' }}
                                            </x-filament::badge>
                                            <x-filament::badge color="gray">{{ $row['source_locale'] }}</x-filament::badge>
                                        </div>
                                    </li>
                                @empty
                                    <li class="text-sm text-gray-500">No system resources.</li>
                                @endforelse
                            </ul>
                        </x-filament::section>

                        <form wire:submit="saveKeywordSettings" class="space-y-4">
                            <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                                Still used by legacy consumers; not the new Python matching result.
                            </p>
                            {{ $this->form }}
                            <div class="flex justify-end">
                                <x-seo-content-ai::form-save-button
                                    target="saveKeywordSettings"
                                    :label="__('seo-content-ai::filament.settings_keywords.save')"
                                />
                            </div>
                        </form>
                    </div>
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
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    @if (($industryMatchStatus ?? null) === 'match_revision_inactive')
                                        <span class="font-mono">match_revision_inactive</span> — Match &amp; Research revision chưa được kích hoạt.
                                    @elseif (($industryMatchStatus ?? null) === 'no_match_revision')
                                        <span class="font-mono">no_match_revision</span> — Chưa có revision Match &amp; Research cho Industry Context của site hiện tại.
                                    @elseif (($industryMatchStatus ?? null) === 'match_revision_stale')
                                        <span class="font-mono">match_revision_stale</span> — Match revision đã cũ (stale) nên không được dùng để match.
                                    @elseif (($industryMatchStatus ?? null) === 'no_taxonomy_groups')
                                        <span class="font-mono">no_taxonomy_groups</span> — Match &amp; Research đang hoạt động nhưng taxonomy Industry Group đang trống.
                                    @elseif (($industryMatchStatus ?? null) === 'no_active_industry_groups')
                                        <span class="font-mono">no_active_industry_groups</span> — Không có Industry Group đang hoạt động.
                                    @else
                                        Chưa có Match &amp; Research đang hoạt động cho Industry Context của site hiện tại.
                                    @endif
                                </p>
                                @if ($industryContextKey)
                                    <p class="mt-1 text-xs text-gray-400">Industry Context key: {{ $industryContextKey }}</p>
                                @endif
                            @endif
                        </x-filament::section>

                        <x-filament::section heading="Live Industry Match" description="Runs the active Industry Groups through seo-ops-semantic Concept Matching.">
                            <div class="space-y-3" x-data="{ showAll: false }">
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" wire:model="industryMatchText" placeholder="Text to match, e.g. balo học sinh cấp 1" />
                                </x-filament::input.wrapper>
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-filament::button type="button" wire:click="testIndustryMatch" wire:loading.attr="disabled" wire:target="testIndustryMatch">
                                        <span wire:loading.remove wire:target="testIndustryMatch">Run live match</span>
                                        <span wire:loading wire:target="testIndustryMatch">Matching…</span>
                                    </x-filament::button>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" x-model="showAll" />
                                        Show all evidence
                                    </label>
                                </div>

                                @if (is_array($industryMatchResult))
                                    @php
                                        $reason = (string) ($industryMatchResult['reason'] ?? '');
                                        $evidence = $industryMatchResult['evidence'] ?? [];
                                        $fmt = static fn (mixed $value): string => is_numeric($value)
                                            ? number_format((float) $value, 4, '.', '')
                                            : '—';
                                        $groupNames = [];
                                        foreach ($industryGroupsByType as $items) {
                                            foreach ($items as $item) {
                                                $groupNames[(string) ($item['key'] ?? '')] = (string) ($item['name'] ?? '');
                                            }
                                        }
                                    @endphp
                                    <dl class="grid grid-cols-2 gap-2 text-sm">
                                        <div><dt class="text-xs text-gray-500">called_python</dt><dd>{{ ($industryMatchResult['called_python'] ?? false) ? 'true' : 'false' }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">analysis_id</dt><dd class="font-mono text-xs">{{ $industryMatchResult['analysis_id'] ?: '—' }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">concepts_used</dt><dd>{{ (int) ($industryMatchResult['concepts_used'] ?? 0) }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">stale_skipped</dt><dd>{{ (int) ($industryMatchResult['stale_skipped'] ?? 0) }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">disabled_skipped</dt><dd>{{ (int) ($industryMatchResult['disabled_skipped'] ?? 0) }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">reason</dt><dd class="font-mono text-xs">{{ $reason !== '' ? $reason : '—' }}</dd></div>
                                    </dl>
                                    @if ($reason !== '')
                                        <p class="text-sm text-gray-700 dark:text-gray-200">{{ \Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords::industryMatchStatusLabel($reason) }}</p>
                                    @endif
                                    @if (! empty($industryMatchResult['error']) && $reason !== 'semantic_disabled')
                                        <p class="text-sm text-danger-600">{{ $industryMatchResult['error'] }}</p>
                                    @endif

                                    <p class="text-sm font-medium">{{ $industryMatchResult['query'] ?? '' }}</p>
                                    <ul class="space-y-2">
                                        @forelse ($evidence as $row)
                                            @php($suggested = ($row['suggested_match'] ?? false) === true)
                                            <li
                                                @unless ($suggested) x-show="showAll" x-cloak @endunless
                                                @class([
                                                    'rounded-md px-3 py-2 text-sm',
                                                    'border border-success-500 bg-success-50 dark:bg-success-500/10' => $suggested,
                                                    'bg-gray-50 text-gray-500 dark:bg-white/5' => ! $suggested,
                                                ])
                                            >
                                                <div class="font-medium">{{ $groupNames[$row['industry_group_key'] ?? ''] ?? ($row['industry_group_key'] ?? '') }}</div>
                                                <div class="font-mono text-xs text-gray-500">{{ $row['industry_group_key'] ?? '' }}</div>
                                                <div class="mt-1 flex flex-wrap gap-1.5">
                                                    <x-filament::badge color="gray">{{ $row['group_type'] ?: '—' }}</x-filament::badge>
                                                    <x-filament::badge :color="($row['lexical_matched'] ?? false) ? 'success' : 'gray'">
                                                        lexical {{ ($row['lexical_matched'] ?? false) ? 'true' : 'false' }}
                                                    </x-filament::badge>
                                                    <x-filament::badge :color="$suggested ? 'success' : 'gray'">
                                                        suggested {{ $suggested ? 'true' : 'false' }}
                                                    </x-filament::badge>
                                                </div>
                                                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                                    <div>Semantic similarity (positive_max): {{ $fmt($row['positive_max'] ?? null) }}</div>
                                                    <div x-show="showAll" x-cloak>Top-K mean: {{ $fmt($row['positive_top_k_mean'] ?? null) }}</div>
                                                    <div x-show="showAll" x-cloak>Negative max: {{ $fmt($row['negative_max'] ?? null) }}</div>
                                                    <div x-show="showAll" x-cloak>Margin: {{ $fmt($row['margin'] ?? null) }}</div>
                                                    <div x-show="showAll" x-cloak>Best positive: {{ $row['best_positive_example'] ?: '—' }}</div>
                                                    <div x-show="showAll" x-cloak>Best negative: {{ $row['best_negative_example'] ?: '—' }}</div>
                                                </dl>
                                            </li>
                                        @empty
                                            <li class="text-sm text-gray-500">No evidence returned.</li>
                                        @endforelse
                                    </ul>
                                    <p class="text-xs text-gray-500" x-show="!showAll">Only suggested matches are listed. Semantic similarity is not a match when suggested is false.</p>
                                @endif
                            </div>
                        </x-filament::section>

                        @if ($industryProvenance && ($industryNonGroupResources ?? []) !== [])
                            <x-filament::section heading="Other Industry Rules" description="Secondary knowledge: generic cores, service intent, aliases, and ambiguities. Not Industry Group matches.">
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
                        <x-filament::section heading="Custom Concepts" description="Site-scoped custom Match & Research concepts.">
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
                </div>
            </div>
        </div>
    </x-filament-panels::page>
</div>
