@php
    /** @var list<array<string, mixed>> $suggestions */
    /** @var list<string> $selectedSuggestionIds */
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40">
    <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
            {{ __('seo-content-ai::filament.keyword.discovery_results_heading') }}
        </h2>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.keyword.discovery_results_hint', ['count' => count($suggestions)]) }}
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="ai-discovery-table min-w-full divide-y divide-gray-100 dark:divide-white/10">
            <thead class="bg-gray-50/80 dark:bg-white/5">
                <tr>
                    <th scope="col" class="ai-discovery-th w-10">
                        <input
                            type="checkbox"
                            @checked($isAllSelected)
                            wire:click="toggleSelectAll"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20"
                            aria-label="{{ __('seo-content-ai::filament.keyword.discovery_select_all') }}"
                        />
                    </th>
                    <th scope="col" class="ai-discovery-th">{{ __('seo-content-ai::filament.keyword.discovery_col_keyword') }}</th>
                    <th scope="col" class="ai-discovery-th">{{ __('seo-content-ai::filament.keyword.discovery_col_intent') }}</th>
                    <th scope="col" class="ai-discovery-th">{{ __('seo-content-ai::filament.keyword.discovery_col_difficulty') }}</th>
                    <th scope="col" class="ai-discovery-th">{{ __('seo-content-ai::filament.keyword.discovery_col_title') }}</th>
                    <th scope="col" class="ai-discovery-th">{{ __('seo-content-ai::filament.keyword.discovery_col_reason') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($suggestions as $row)
                    @php
                        $rowId = (string) ($row['id'] ?? '');
                        $intent = (string) ($row['intent'] ?? 'informational');
                        $difficulty = (string) ($row['difficulty'] ?? 'medium');
                        $isChecked = in_array($rowId, $selectedSuggestionIds, true);
                    @endphp
                    <tr wire:key="discovery-row-{{ $rowId }}" @class(['bg-indigo-50/40 dark:bg-indigo-500/5' => $isChecked])>
                        <td class="ai-discovery-td">
                            <input
                                type="checkbox"
                                @checked($isChecked)
                                wire:click="toggleSuggestion('{{ $rowId }}')"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20"
                            />
                        </td>
                        <td class="ai-discovery-td">
                            <div class="flex items-start gap-2">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $row['keyword'] ?? '—' }}</span>
                                <button
                                    type="button"
                                    wire:click="copyKeyword('{{ $rowId }}')"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10"
                                    title="{{ __('seo-content-ai::filament.keyword.discovery_copy') }}"
                                >
                                    <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                        <td class="ai-discovery-td">
                            @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-intent-badge', [
                                'intent' => $intent,
                            ])
                        </td>
                        <td class="ai-discovery-td">
                            @include('seo-content-ai::filament.resources.keywords.pages.partials.ai-discovery-difficulty-meter', [
                                'difficulty' => $difficulty,
                            ])
                        </td>
                        <td class="ai-discovery-td">
                            <p class="max-w-xs text-sm font-medium leading-snug text-gray-900 dark:text-white">
                                {{ $row['title_idea'] ?? '—' }}
                            </p>
                        </td>
                        <td class="ai-discovery-td">
                            <div class="prose prose-sm dark:prose-invert max-w-md text-gray-600 dark:text-gray-300">
                                {!! \Illuminate\Support\Str::markdown((string) ($row['relevancy_reason'] ?? '—')) !!}
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
