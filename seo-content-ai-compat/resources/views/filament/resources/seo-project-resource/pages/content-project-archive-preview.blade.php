@php
    $summary = $this->getHeaderSummary();
    $rows = is_array($this->articleRows ?? null) ? $this->articleRows : [];

    $month = (int) ($summary['month'] ?? 0);
    $year = (int) ($summary['year'] ?? 0);
    $period = ($month > 0 && $year > 0) ? sprintf('%02d/%d', $month, $year) : '—';
@endphp

<x-filament-panels::page>
    <div class="fi-archive-preview space-y-6">
        @if (filled($this->snapshotLoadError))
            <div class="rounded-xl border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-100">
                {{ $this->snapshotLoadError }}
            </div>
        @endif

        <div class="fi-archive-preview-summary rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 sm:p-5">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ e((string) ($summary['project_name'] ?? '')) ?: '—' }}
            </h2>

            <div class="fi-archive-preview-summary-grid mt-4">
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.article_list.domain') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ e((string) ($summary['domain'] ?? '')) ?: '—' }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.month') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ e($period) }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.owner') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ e((string) ($summary['owner'] ?? '')) ?: '—' }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_archived_at') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::formatTaskTimestamp($summary['archived_at'] ?? null) }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_total') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ (int) ($summary['total_articles'] ?? 0) }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_completed') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ (int) ($summary['completed_articles'] ?? 0) }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_synced') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ (int) ($summary['synced_articles'] ?? 0) }}</div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</div>
                    <div class="fi-archive-preview-summary-value">
                        @if (($summary['average_seo_score'] ?? null) !== null)
                            {{ number_format((float) $summary['average_seo_score'], 2) }}
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div class="fi-archive-preview-summary-item">
                    <div class="fi-archive-preview-summary-label">{{ __('seo-content-ai::filament.projects.archive_col_archived_by') }}</div>
                    <div class="fi-archive-preview-summary-value">{{ e((string) ($summary['archived_by'] ?? '')) ?: '—' }}</div>
                </div>
            </div>
        </div>

        @if (trim((string) ($summary['note'] ?? '')) !== '')
            <div class="fi-archive-preview-note-bar" role="note">
                <span class="fi-archive-preview-note-label">{{ __('seo-content-ai::filament.projects.archive_note') }}</span>
                <span class="fi-archive-preview-note-text">{{ e((string) $summary['note']) }}</span>
            </div>
        @endif

        <div class="fi-archive-preview-table w-full overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full min-w-full table-auto divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-800/95">
                    <tr>
                        <th class="w-10 px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                        <th class="min-w-[16rem] w-[32%] px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_title') }}</th>
                        <th class="min-w-[8rem] px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_keyword') }}</th>
                        <th class="px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_status') }}</th>
                        <th class="w-16 px-3 py-2.5 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400" title="{{ __('seo-content-ai::filament.projects.archive_preview_col_internal_links') }}">{{ __('seo-content-ai::filament.projects.archive_preview_col_int') }}</th>
                        <th class="w-16 px-3 py-2.5 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400" title="{{ __('seo-content-ai::filament.projects.archive_preview_col_external_links') }}">{{ __('seo-content-ai::filament.projects.archive_preview_col_ext') }}</th>
                        <th class="w-20 px-3 py-2.5 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_col_avg_seo') }}</th>
                        <th class="px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_sync') }}</th>
                        <th class="min-w-[9rem] px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.archive_preview_col_index') }}</th>
                        <th class="w-24 px-3 py-2.5 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        @php
                            $title = trim((string) ($row['title'] ?? ''));
                            $keyword = trim((string) ($row['keyword'] ?? ''));
                            $status = trim((string) ($row['task_status'] ?? ''));
                            $syncStatus = trim((string) ($row['sync_status'] ?? ''));
                            $seoScore = $row['seo_score'] ?? null;
                            $articleExists = (bool) ($row['article_exists'] ?? false);
                            $itemId = (int) ($row['item_id'] ?? 0);
                            $position = (int) ($row['position'] ?? 0);
                            $internalLinks = (int) ($row['internal_link_count'] ?? 0);
                            $externalLinks = (int) ($row['external_link_count'] ?? 0);
                            $wpUrl = trim((string) ($row['wordpress_url'] ?? ''));
                            $hasWpUrl = (bool) ($row['has_public_wordpress_url'] ?? false) && $wpUrl !== '';
                            $indexedLabel = is_string($row['indexed_at_label'] ?? null) ? trim((string) $row['indexed_at_label']) : '';
                            $previousIndexedLabel = is_string($row['previous_indexed_at_label'] ?? null) ? trim((string) $row['previous_indexed_at_label']) : '';
                            $indexBusy = (bool) ($this->markingIndexBusy ?? false)
                                && (int) ($this->markingIndexItemId ?? 0) === $itemId;
                        @endphp
                        <tr
                            wire:key="archive-preview-item-{{ $itemId }}"
                            class="transition hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                        >
                            <td class="whitespace-nowrap px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $position > 0 ? $position : $loop->iteration }}</td>
                            <td class="px-3 py-2.5">
                                <div
                                    class="flex min-w-0 items-start gap-1.5"
                                    @if ($hasWpUrl)
                                        x-data="{ copied: false }"
                                    @endif
                                >
                                    <div class="min-w-0 flex-1">
                                        @if ($hasWpUrl)
                                            <a
                                                href="{{ $wpUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-primary-600 hover:underline dark:text-primary-400"
                                                title="{{ $title !== '' ? $title : '' }}"
                                            >
                                                <span class="line-clamp-2">{{ $title !== '' ? e($title) : '—' }}</span>
                                            </a>
                                        @else
                                            <span class="line-clamp-2 font-medium text-gray-950 dark:text-white" title="{{ $title !== '' ? $title : '' }}">
                                                {{ $title !== '' ? e($title) : '—' }}
                                            </span>
                                        @endif

                                        @if (! $articleExists)
                                            <span class="mt-1 inline-flex items-center rounded-md bg-warning-50 px-1.5 py-0.5 text-[11px] font-medium text-warning-800 ring-1 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-200 dark:ring-warning-500/30">
                                                {{ __('seo-content-ai::filament.projects.archive_preview_article_missing') }}
                                            </span>
                                        @endif
                                    </div>

                                    @if ($hasWpUrl)
                                        <button
                                            type="button"
                                            class="mt-0.5 inline-flex shrink-0 items-center rounded-md px-1.5 py-1 text-[11px] font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                            title="{{ __('seo-content-ai::filament.projects.archive_preview_copy_link') }}"
                                            x-on:click.stop="
                                                navigator.clipboard.writeText(@js($wpUrl)).then(() => {
                                                    copied = true;
                                                    setTimeout(() => copied = false, 1500);
                                                }).catch(() => {})
                                            "
                                        >
                                            <span x-text="copied ? @js(__('seo-content-ai::filament.projects.archive_preview_copied')) : @js(__('seo-content-ai::filament.projects.archive_preview_copy_link'))"></span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-gray-700 dark:text-gray-200">{{ $keyword !== '' ? e($keyword) : '—' }}</td>
                            <td class="px-3 py-2.5">
                                @if ($status !== '')
                                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ e($status) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $internalLinks }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $externalLinks }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right text-gray-700 dark:text-gray-200">{{ $seoScore !== null ? number_format((float) $seoScore, 2) : '—' }}</td>
                            <td class="px-3 py-2.5">
                                @if ($syncStatus !== '')
                                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ e($syncStatus) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-col items-start gap-0.5">
                                    <button
                                        type="button"
                                        class="inline-flex max-w-full items-center rounded-md px-2 py-1 text-left text-xs font-medium ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-50 {{ $indexedLabel !== '' ? 'bg-success-50 text-success-800 ring-success-200 hover:bg-success-100 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-500/30' : 'bg-gray-50 text-gray-700 ring-gray-200 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' }}"
                                        wire:click="markArticleIndexed({{ $itemId }})"
                                        wire:loading.attr="disabled"
                                        wire:target="markArticleIndexed({{ $itemId }})"
                                        @disabled($indexBusy || (bool) ($this->markingIndexBusy ?? false))
                                    >
                                        <span wire:loading.remove wire:target="markArticleIndexed({{ $itemId }})">
                                            @if ($indexedLabel !== '')
                                                {{ __('seo-content-ai::filament.projects.archive_preview_index_done', ['date' => $indexedLabel]) }}
                                            @else
                                                {{ __('seo-content-ai::filament.projects.archive_preview_index_none') }}
                                            @endif
                                        </span>
                                        <span wire:loading wire:target="markArticleIndexed({{ $itemId }})" class="inline-flex items-center gap-1">
                                            <x-filament::loading-indicator class="h-3.5 w-3.5" />
                                            {{ __('seo-content-ai::filament.projects.archive_preview_index_saving') }}
                                        </span>
                                    </button>
                                    @if ($previousIndexedLabel !== '')
                                        <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ __('seo-content-ai::filament.projects.archive_preview_index_previous', ['date' => $previousIndexedLabel]) }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right">
                                <x-filament::button
                                    color="gray"
                                    size="sm"
                                    tag="button"
                                    type="button"
                                    wire:click="mountAction('viewArchiveItem', { itemId: {{ $itemId }} })"
                                >
                                    {{ __('seo-content-ai::filament.projects.archive_preview_item') }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.archive_preview_empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <style>
        .fi-archive-preview-summary-grid {
            display: grid;
            gap: 0.875rem 1.25rem;
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }

        @media (min-width: 640px) {
            .fi-archive-preview-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .fi-archive-preview-summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .fi-archive-preview-summary-label {
            font-size: 0.75rem;
            line-height: 1rem;
            font-weight: 500;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            color: rgb(107 114 128);
        }

        .dark .fi-archive-preview-summary-label {
            color: rgb(156 163 175);
        }

        .fi-archive-preview-summary-value {
            margin-top: 0.25rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: rgb(17 24 39);
            word-break: break-word;
        }

        .dark .fi-archive-preview-summary-value {
            color: rgb(255 255 255);
        }

        .fi-archive-preview-note-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 0.5rem 0.75rem;
            width: 100%;
            border-radius: 0.75rem;
            border: 1px solid rgb(147 197 253);
            background: rgb(239 246 255);
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: rgb(30 64 175);
        }

        .dark .fi-archive-preview-note-bar {
            border-color: rgba(59, 130, 246, 0.45);
            background: rgba(59, 130, 246, 0.12);
            color: rgb(191 219 254);
        }

        .fi-archive-preview-note-label {
            flex-shrink: 0;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            font-size: 0.75rem;
        }

        .fi-archive-preview-note-label::after {
            content: ':';
        }

        .fi-archive-preview-note-text {
            min-width: 0;
            flex: 1 1 auto;
            word-break: break-word;
        }

        .fi-modal-window.fi-archive-preview-item-slideover {
            width: min(52rem, 85vw) !important;
            max-width: 85vw !important;
        }

        @media (max-width: 768px) {
            .fi-modal-window.fi-archive-preview-item-slideover {
                width: 100% !important;
                max-width: 100vw !important;
            }
        }
    </style>
</x-filament-panels::page>
