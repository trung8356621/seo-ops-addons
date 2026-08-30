@php
    /** @var \Omnichannel\Addons\Content\Filament\Pages\ArticleIndexHealth $this */
    $summary = $this->summary;
    $rows = $this->rows;
    $gscConfigured = $this->gscConfigured;
@endphp

<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ __('seo-content-ai::filament.index_health.title') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.index_health.subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($gscConfigured)
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm"
                        wire:click="inspectDueWithGsc"
                        wire:loading.attr="disabled"
                        wire:target="inspectDueWithGsc"
                    >
                        <span wire:loading.remove wire:target="inspectDueWithGsc">{{ __('seo-content-ai::filament.index_health.inspect_due_gsc') }}</span>
                        <span wire:loading wire:target="inspectDueWithGsc" class="inline-flex items-center gap-1">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            {{ __('seo-content-ai::filament.index_health.inspecting') }}
                        </span>
                    </button>
                @elseif ((int) ($this->filterSiteId ?? 0) > 0)
                    <div class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:border-warning-600 dark:bg-warning-500/10 dark:text-warning-200">
                        {{ __('seo-content-ai::filament.index_health.gsc_not_configured') }}
                        <a href="{{ $this->gscConfigureUrl }}" class="ml-2 font-semibold underline">{{ __('seo-content-ai::filament.index_health.configure_gsc') }}</a>
                    </div>
                @endif
            </div>
        </div>

        @if (! empty($this->activeInspectionRun))
            <div class="rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-900 dark:border-primary-700 dark:bg-primary-500/10 dark:text-primary-100" wire:poll.5s="refreshActiveRun">
                {{ __('seo-content-ai::filament.index_health.run_progress', [
                    'requested' => (int) ($this->activeInspectionRun['requested'] ?? 0),
                    'inspected' => (int) ($this->activeInspectionRun['inspected'] ?? 0),
                    'failed' => (int) ($this->activeInspectionRun['failed'] ?? 0),
                    'status' => (string) ($this->activeInspectionRun['status'] ?? ''),
                ]) }}
            </div>
        @endif

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            <button type="button" wire:click="$set('tab', 'needs_review')" class="rounded-lg border px-3 py-2 text-left text-sm {{ $this->tab === 'needs_review' ? 'border-primary-400 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-gray-700' }}">
                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.due_for_check') }}</div>
                <div class="text-lg font-semibold">{{ $summary['needs_review'] ?? 0 }}</div>
            </button>
            <button type="button" wire:click="$set('tab', 'indexed')" class="rounded-lg border px-3 py-2 text-left text-sm {{ $this->tab === 'indexed' ? 'border-primary-400 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-gray-700' }}">
                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.indexed') }}</div>
                <div class="text-lg font-semibold">{{ $summary['indexed'] ?? 0 }}</div>
            </button>
            <button type="button" wire:click="$set('tab', 'not_indexed')" class="rounded-lg border px-3 py-2 text-left text-sm {{ $this->tab === 'not_indexed' ? 'border-primary-400 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-gray-700' }}">
                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.not_indexed') }}</div>
                <div class="text-lg font-semibold">{{ $summary['not_indexed'] ?? 0 }}</div>
            </button>
            <button type="button" wire:click="$set('tab', 'dropped')" class="rounded-lg border px-3 py-2 text-left text-sm {{ $this->tab === 'dropped' ? 'border-primary-400 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-gray-700' }}">
                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.dropped') }}</div>
                <div class="text-lg font-semibold">{{ $summary['dropped'] ?? 0 }}</div>
            </button>
            <div class="rounded-lg border border-gray-200 px-3 py-2 text-left text-sm dark:border-gray-700">
                <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.never_checked') }}</div>
                <div class="text-lg font-semibold">{{ $summary['never_checked'] ?? 0 }}</div>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-[10rem]">
                <label class="mb-1 block text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.filter_tab') }}</label>
                <x-select wire:model.live="tab" class="!w-full">
                    <option value="needs_review">{{ __('seo-content-ai::filament.index_health.tab_needs_review') }}</option>
                    <option value="dropped">{{ __('seo-content-ai::filament.index_health.tab_dropped') }}</option>
                    <option value="not_indexed">{{ __('seo-content-ai::filament.index_health.tab_not_indexed') }}</option>
                    <option value="indexed">{{ __('seo-content-ai::filament.index_health.tab_indexed') }}</option>
                    <option value="all">{{ __('seo-content-ai::filament.index_health.tab_all') }}</option>
                </x-select>
            </div>
            <div class="min-w-[12rem]">
                <label class="mb-1 block text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.filter_site') }}</label>
                <x-select wire:model.live="filterSiteId" class="!w-full">
                    <option value="">{{ __('seo-content-ai::filament.index_health.all_sites') }}</option>
                    @foreach ($this->siteOptions as $id => $domain)
                        <option value="{{ $id }}">{{ $domain }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="min-w-[10rem] flex-1">
                <label class="mb-1 block text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.search') }}</label>
                <form wire:submit="applySearch" class="contents">
                    <input type="search" wire:model="searchInput" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" placeholder="{{ __('seo-content-ai::filament.index_health.search') }}" autocomplete="off" />
                </form>
            </div>
        </div>

        @if (count($this->selectedArticleIds) > 0)
            <div class="flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                <span>{{ count($this->selectedArticleIds) }} selected</span>
                <button type="button" class="fi-btn fi-btn-color-success fi-size-sm" wire:click="bulkMarkIndexed" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.index_health.mark_indexed') }}</button>
                <button type="button" class="fi-btn fi-btn-color-danger fi-size-sm" wire:click="bulkMarkNotIndexed" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.index_health.mark_not_indexed') }}</button>
                @if ($gscConfigured)
                    <button type="button" class="fi-btn fi-btn-color-primary fi-size-sm" wire:click="inspectSelectedWithGsc" wire:loading.attr="disabled" wire:target="inspectSelectedWithGsc">{{ __('seo-content-ai::filament.index_health.inspect_selected_gsc') }}</button>
                @endif
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900">
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_article') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_health') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_last_checked') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_published') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_check') }}</th>
                        <th class="px-3 py-2">{{ __('seo-content-ai::filament.index_health.col_action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr @class([
                            'bg-primary-50/40 dark:bg-primary-500/5' => (int) ($this->focusArticleId ?? 0) === (int) $row['article_id'],
                        ]) wire:key="ih-{{ $row['article_id'] }}">
                            <td class="px-3 py-2">
                                <input type="checkbox" value="{{ $row['article_id'] }}" wire:model.live="selectedArticleIds" />
                            </td>
                            <td class="px-3 py-2">
                                @if (! empty($row['canonical_url']))
                                    <a href="{{ $row['canonical_url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 hover:underline dark:text-primary-300">
                                        {{ $row['title'] !== '' ? $row['title'] : ('#'.$row['article_id']) }}
                                    </a>
                                @else
                                    <span class="font-medium">{{ $row['title'] !== '' ? $row['title'] : ('#'.$row['article_id']) }}</span>
                                @endif
                                <div class="text-xs text-gray-500">{{ $row['post_type'] }} · {{ $row['domain'] }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span @class([
                                    'font-medium',
                                    'text-success-700 dark:text-success-400' => ($row['health'] ?? null) === 'indexed',
                                    'text-warning-700 dark:text-warning-400' => ($row['health'] ?? null) === 'not_indexed',
                                    'text-danger-700 dark:text-danger-400' => ($row['health'] ?? null) === 'dropped',
                                ])>{{ $row['health_label'] }}</span>
                                @if ($row['needs_attention'] ?? false)
                                    <div class="text-xs text-danger-600">{{ __('seo-content-ai::filament.index_health.needs_attention') }}</div>
                                @endif
                                @if (! empty($row['last_check_source_label']))
                                    <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.source_label', ['source' => $row['last_check_source_label']]) }}</div>
                                @endif
                                @if (! empty($row['google_crawl_label']))
                                    <div class="text-xs text-gray-500">{{ __('seo-content-ai::filament.index_health.google_crawl', ['date' => $row['google_crawl_label']]) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $row['last_checked_label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $row['next_check_label'] }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $row['published_label'] }}</td>
                            <td class="px-3 py-2">
                                @if (! empty($row['check_url']))
                                    <a href="{{ $row['check_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-primary-700 hover:underline dark:text-primary-300">
                                        {{ __('seo-content-ai::filament.index_health.check_index') }} ↗
                                    </a>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-success-700 hover:bg-success-50 dark:text-success-300" wire:click="markIndexed({{ $row['article_id'] }})" wire:loading.attr="disabled" wire:target="markIndexed({{ $row['article_id'] }})">✓ {{ __('seo-content-ai::filament.index_health.mark_indexed') }}</button>
                                    <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-danger-700 hover:bg-danger-50 dark:text-danger-300" wire:click="markNotIndexed({{ $row['article_id'] }})" wire:loading.attr="disabled">✕ {{ __('seo-content-ai::filament.index_health.mark_not_indexed') }}</button>
                                    <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300" wire:click="markUnsure({{ $row['article_id'] }})" wire:loading.attr="disabled">? {{ __('seo-content-ai::filament.index_health.mark_unsure') }}</button>
                                    @if ($gscConfigured && (int) ($row['site_id'] ?? 0) === (int) ($this->filterSiteId ?? 0))
                                        <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-300" wire:click="inspectWithGsc({{ $row['article_id'] }})" wire:loading.attr="disabled" wire:target="inspectWithGsc({{ $row['article_id'] }})">{{ __('seo-content-ai::filament.index_health.inspect_gsc') }}</button>
                                    @endif
                                    <button type="button" class="rounded px-2 py-1 text-xs text-gray-500 hover:underline" wire:click="toggleHistory({{ $row['article_id'] }})">{{ __('seo-content-ai::filament.index_health.view_history') }}</button>
                                </div>
                                @if (! empty($this->historyByArticle[$row['article_id']]))
                                    <ul class="mt-2 space-y-1 border-t border-gray-100 pt-2 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300">
                                        @foreach ($this->historyByArticle[$row['article_id']] as $hist)
                                            <li>
                                                {{ $hist['checked_at_label'] }} · {{ $hist['effective_health'] }} · {{ $hist['source_label'] ?? $hist['source'] }}
                                                @if (! empty($hist['verdict']) || ! empty($hist['last_crawl_time']) || ! empty($hist['canonical_mismatch']))
                                                    <div class="text-[11px] text-gray-500">
                                                        @if (! empty($hist['verdict'])) {{ __('seo-content-ai::filament.index_health.diag_verdict') }}: {{ $hist['verdict'] }} @endif
                                                        @if (! empty($hist['coverage_state'])) · {{ $hist['coverage_state'] }} @endif
                                                        @if (! empty($hist['last_crawl_time'])) · {{ __('seo-content-ai::filament.index_health.diag_crawl') }}: {{ $hist['last_crawl_time'] }} @endif
                                                        @if (! empty($hist['canonical_mismatch'])) · {{ __('seo-content-ai::filament.index_health.diag_canonical_mismatch') }} @endif
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-gray-500">{{ __('seo-content-ai::filament.index_health.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $rows->links() }}
        </div>
    </div>
</x-filament-panels::page>
