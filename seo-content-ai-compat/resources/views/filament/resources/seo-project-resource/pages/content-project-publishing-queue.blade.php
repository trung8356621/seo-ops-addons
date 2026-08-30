<x-filament-panels::page>
    @php
        /** @var array<string, int> $stats */
        $stats = $this->stats;
        /** @var list<array{key: string, label: string, at: string|null, done: bool}> $timeline */
        $timeline = $this->timeline;
        $rows = $this->queueRows;
    @endphp

    {{-- Dashboard stats --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        @foreach ([
            'total_items' => 'stat_total_items',
            'waiting_ai' => 'stat_waiting_ai',
            'ai_running' => 'stat_ai_running',
            'waiting_review' => 'stat_waiting_review',
            'approved' => 'stat_approved',
            'waiting_publish' => 'stat_waiting_publish',
            'published' => 'stat_published',
            'failed' => 'stat_failed',
            'archived' => 'stat_archived',
        ] as $key => $lang)
            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.projects.'.$lang) }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">
                    {{ (int) ($stats[$key] ?? 0) }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Business timeline --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">
            {{ __('seo-content-ai::filament.projects.timeline_heading') }}
        </h3>
        <ol class="space-y-2">
            @foreach ($timeline as $step)
                <li class="flex items-start gap-3 text-sm">
                    <span @class([
                        'mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold',
                        'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $step['done'],
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $step['done'],
                    ])>
                        {{ $step['done'] ? '✓' : '·' }}
                    </span>
                    <div>
                        <div class="font-medium text-gray-900 dark:text-white">{{ $step['label'] }}</div>
                        <div class="text-xs text-gray-500">{{ $step['at'] ?: '—' }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Toolbar --}}
    <div
        class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
        x-data="{ autoOpen: false, moveAt: '' }"
    >
        <div class="flex flex-wrap items-center gap-2">
            <x-select wire:model.live="statusFilter" class="!w-44">
                <option value="">{{ __('seo-content-ai::filament.projects.queue_filter_all') }}</option>
                <option value="waiting">waiting</option>
                <option value="processing">processing</option>
                <option value="retrying">retrying</option>
                <option value="failed">failed</option>
                <option value="published">published</option>
                <option value="skipped">skipped</option>
                <option value="cancelled">cancelled</option>
            </x-select>

            <form wire:submit="applySearch" class="contents">
                <input
                    type="search"
                    wire:model="searchInput"
                    placeholder="{{ __('seo-content-ai::filament.projects.queue_search') }}"
                    class="fi-input block w-56 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                    autocomplete="off"
                />
            </form>

            <button type="button" wire:click="selectPage" class="fi-btn fi-btn-color-gray fi-size-sm">
                {{ __('seo-content-ai::filament.projects.queue_select_page') }}
            </button>
            <button type="button" wire:click="clearSelection" class="fi-btn fi-btn-color-gray fi-size-sm">
                {{ __('seo-content-ai::filament.projects.queue_clear_selection') }}
            </button>
            <span class="text-xs text-gray-500">{{ count($selectedTaskIds) }} selected</span>
        </div>

        <div class="flex flex-wrap gap-2" @class(['opacity-50 pointer-events-none' => $bulkRunning])>
            <button type="button" wire:click="bulkPublishNow" wire:loading.attr="disabled" wire:target="bulkPublishNow" class="fi-btn fi-btn-color-primary fi-size-sm">
                <span wire:loading.remove wire:target="bulkPublishNow">{{ __('seo-content-ai::filament.projects.queue_publish_now') }}</span>
                <span wire:loading wire:target="bulkPublishNow">…</span>
            </button>
            <button type="button" wire:click="bulkResyncPublishedWordPress" wire:loading.attr="disabled" wire:target="bulkResyncPublishedWordPress" class="fi-btn fi-btn-color-gray fi-size-sm" title="Chỉ cập nhật bài WordPress đã Published — không tạo bài mới">
                <span wire:loading.remove wire:target="bulkResyncPublishedWordPress">Đồng bộ lại WordPress</span>
                <span wire:loading wire:target="bulkResyncPublishedWordPress">…</span>
            </button>
            <button type="button" wire:click="bulkRetry" class="fi-btn fi-btn-color-warning fi-size-sm">
                {{ __('seo-content-ai::filament.projects.queue_retry') }}
            </button>
            <button type="button" wire:click="bulkUnschedule" class="fi-btn fi-btn-color-gray fi-size-sm">
                {{ __('seo-content-ai::filament.projects.queue_unschedule') }}
            </button>
            <button type="button" wire:click="bulkClearSchedule" class="fi-btn fi-btn-color-gray fi-size-sm">
                {{ __('seo-content-ai::filament.projects.queue_clear_schedule') }}
            </button>
            <button type="button" @click="autoOpen = true" class="fi-btn fi-btn-color-primary fi-size-sm">
                {{ __('seo-content-ai::filament.projects.auto_schedule') }}
            </button>
        </div>

        {{-- Auto Schedule modal — Alpine open first --}}
        <div
            x-show="autoOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @keydown.escape.window="autoOpen = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.outside="autoOpen = false">
                <h3 class="mb-3 text-base font-semibold">{{ __('seo-content-ai::filament.projects.auto_schedule') }}</h3>
                <div class="space-y-3 text-sm">
                    <label class="block">
                        <span class="mb-1 block text-gray-600">{{ __('seo-content-ai::filament.projects.auto_schedule_mode') }}</span>
                        <x-select wire:model.live="autoMode">
                            <option value="monthly_even">{{ __('seo-content-ai::filament.projects.evenly_across_month') }}</option>
                            <option value="interval">Interval</option>
                            <option value="per_day">Per day</option>
                            <option value="random_windows">Random windows</option>
                            <option value="project_month">Project month (legacy)</option>
                        </x-select>
                    </label>

                    @if ($this->autoMode === 'monthly_even')
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.projects.publishing_window_label') }}:
                            <strong>{{ $this->requireProject()->month?->format('F Y') ?? '—' }}</strong>
                        </div>
                        <label class="block">
                            <span class="mb-1 block text-gray-600">{{ __('seo-content-ai::filament.projects.min_spacing_minutes') }}</span>
                            <input type="number" min="1" max="1440" wire:model="autoMinSpacingMinutes" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label>
                                <span class="mb-1 block text-gray-600">{{ __('seo-content-ai::filament.projects.day_window_from') }}</span>
                                <input type="time" wire:model="autoDayStart" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                            <label>
                                <span class="mb-1 block text-gray-600">{{ __('seo-content-ai::filament.projects.day_window_to') }}</span>
                                <input type="time" wire:model="autoDayEnd" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                        </div>
                    @else
                    <label class="block">
                        <span class="mb-1 block text-gray-600">Start</span>
                        <input type="datetime-local" wire:model="autoStartAt" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    <label class="block" x-show="autoMode === 'interval' || $wire.autoMode === 'interval'">
                        <span class="mb-1 block text-gray-600">Interval (minutes)</span>
                        <input type="number" min="1" wire:model="autoIntervalMinutes" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    <div x-show="$wire.autoMode === 'per_day'" class="grid grid-cols-3 gap-2">
                        <label>
                            <span class="mb-1 block text-gray-600">/day</span>
                            <input type="number" min="1" wire:model="autoPerDay" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        <label>
                            <span class="mb-1 block text-gray-600">From</span>
                            <input type="time" wire:model="autoDayStart" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        <label>
                            <span class="mb-1 block text-gray-600">To</span>
                            <input type="time" wire:model="autoDayEnd" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                    </div>
                    @endif
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="autoOpen = false" class="fi-btn fi-btn-color-gray fi-size-sm">
                        {{ __('seo-content-ai::filament.projects.archive_cancel') }}
                    </button>
                    <button
                        type="button"
                        @click="autoOpen = false; $wire.runAutoSchedule()"
                        class="fi-btn fi-btn-color-primary fi-size-sm"
                        wire:loading.attr="disabled"
                        wire:target="runAutoSchedule"
                    >
                        {{ __('seo-content-ai::filament.projects.auto_schedule_submit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Queue table --}}
    <x-seo-content-ai::list-table-loading-shell
        class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
        preset="livewire-page"
        targets="statusFilter,search,applySearch,clearSearch,clearFilters"
    >
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800/60">
                <tr>
                    <th class="px-3 py-2 text-left"></th>
                    <th class="px-3 py-2 text-left">Item</th>
                    <th class="px-3 py-2 text-left">Lifecycle</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_scheduled') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_status') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_retry') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_last_attempt') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_published_at') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('seo-content-ai::filament.projects.queue_col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($rows as $task)
                    @php
                        /** @var \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask $task */
                        $article = $task->article;
                        $selected = in_array((int) $task->id, $selectedTaskIds, true);
                    @endphp
                    <tr class="align-top">
                        <td class="px-3 py-2">
                            <input type="checkbox" @checked($selected) wire:click="toggleSelect({{ (int) $task->id }})" />
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium text-gray-900 dark:text-white">
                                {{ $article?->title ?: ('#'.$task->id) }}
                            </div>
                            <div class="text-xs text-gray-500">#{{ (int) $task->id }} · article {{ (int) $task->article_id }}</div>
                            @if (filled($task->last_publish_error))
                                <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">
                                    {{ $task->last_publish_error }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $this->resolvePhaseLabel($task) }}</td>
                        <td class="px-3 py-2">{{ $task->scheduled_publish_at?->toDateTimeString() ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $task->publish_queue_status ?: 'none' }}</td>
                        <td class="px-3 py-2">{{ (int) ($task->publish_retry_count ?? 0) }}</td>
                        <td class="px-3 py-2">{{ $task->last_publish_attempt_at?->toDateTimeString() ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $task->publish_published_at?->toDateTimeString() ?: ($article?->publishingState?->published_at?->toDateTimeString() ?: '—') }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <button type="button" wire:click="retryOne({{ (int) $task->id }})" class="fi-btn fi-btn-color-warning fi-size-xs" wire:loading.attr="disabled" wire:target="retryOne({{ (int) $task->id }})">
                                    Retry
                                </button>
                                <button type="button" wire:click="skipOne({{ (int) $task->id }})" class="fi-btn fi-btn-color-gray fi-size-xs">
                                    Skip
                                </button>
                                <button type="button" wire:click="cancelOne({{ (int) $task->id }})" class="fi-btn fi-btn-color-danger fi-size-xs">
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-8 text-center text-gray-500">
                            {{ __('seo-content-ai::filament.projects.queue_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4 px-3 pb-3">
            {{ $rows->links() }}
        </div>
    </x-seo-content-ai::list-table-loading-shell>
</x-filament-panels::page>
