@php
    /** @var \Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub $this */
    $payload = $this->queuePayload;
    $stats = $payload['stats'] ?? [];
    $rows = $payload['rows'] ?? [];
    $active = $this->stateFilter;
    $project = $this->project;
    $hasProject = $project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject;
    $selectedCount = count($this->selectedTaskIds);
    $pageCount = count($rows);
    $filteredTotal = (int) ($stats['total'] ?? $pageCount);
    if (trim((string) $this->stateFilter) !== '' || trim((string) $this->search) !== '') {
        $filteredTotal = $pageCount;
    }
    $hasActiveFilters = trim((string) $this->search) !== '' || trim((string) $this->stateFilter) !== '';
    $health = $this->queueHealth;
    $tz = $this->timezoneLabel;
    $autoPreview = $this->autoPreview;
    $quickPreview = $this->quickPreview;
    $autoEligible = count($autoPreview['eligible_ids'] ?? []);
    $autoExcluded = count($autoPreview['excluded'] ?? []);
    $quickEligible = count($quickPreview['eligible_ids'] ?? []);
    $quickExcluded = count($quickPreview['excluded'] ?? []);
    $stuckCount = (int) ($health['stuck_publishing'] ?? 0);
    $recoverableStuck = max($stuckCount, count($this->stuckPublishingIds));
    $kpiCards = [
        ['key' => 'unscheduled', 'card' => 'unscheduled', 'filter' => 'unscheduled', 'label' => 'Chưa lên lịch', 'hint' => 'Trong queue, chưa có lịch', 'value' => (int) ($stats['unscheduled'] ?? 0)],
        ['key' => 'scheduled', 'card' => 'scheduled', 'filter' => 'scheduled', 'label' => 'Đã lên lịch', 'hint' => 'Chờ tới giờ đăng', 'value' => (int) ($stats['scheduled'] ?? 0)],
        ['key' => 'awaiting_delivery', 'card' => 'scheduled', 'filter' => 'awaiting_delivery', 'label' => 'Đang chuẩn bị', 'hint' => 'Đã gửi yêu cầu, chờ bắt đầu', 'value' => (int) ($stats['awaiting_delivery'] ?? $stats['awaiting_worker'] ?? 0)],
        ['key' => 'publishing', 'card' => 'publishing', 'filter' => 'publishing', 'label' => 'Đang xuất bản', 'hint' => 'Đang xuất bản lên WordPress', 'value' => (int) ($stats['publishing'] ?? 0)],
        ['key' => 'retry_wait', 'card' => 'scheduled', 'filter' => 'retry_wait', 'label' => 'Thử lại sau', 'hint' => 'Chờ auto retry', 'value' => (int) ($stats['retry_wait'] ?? 0)],
        ['key' => 'published', 'card' => 'published', 'filter' => 'published', 'label' => 'Đã xuất bản', 'hint' => 'WordPress đã xác nhận', 'value' => (int) ($stats['published'] ?? 0)],
        ['key' => 'failed', 'card' => 'failed', 'filter' => 'failed', 'label' => 'Không thể xuất bản', 'hint' => 'Hết lần thử / lỗi', 'value' => (int) ($stats['failed'] ?? 0)],
        ['key' => 'needs_attention', 'card' => 'failed', 'filter' => 'needs_attention', 'label' => 'Cần xử lý', 'hint' => 'State không dự đoán được', 'value' => (int) ($stats['needs_attention'] ?? 0)],
    ];
    $kpiCards = array_values(array_filter(
        $kpiCards,
        static fn (array $c): bool => $c['key'] !== 'needs_attention' || (int) $c['value'] > 0,
    ));
    $projectedSum = (int) ($stats['projected_sum'] ?? 0);
    $queueTotal = (int) ($stats['total'] ?? 0);
    $invariantOk = ($stats['invariant_ok'] ?? true) === true;
@endphp

<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{
            autoOpen: false,
            quickOpen: false,
            mode: 'auto',
            openNeedsReviewArticle(taskId, isNeedsReview, url) {
                // No-op navigate — real anchors open Edit Article in a new tab.
            },
            claimNeedsReviewArticle(taskId, isNeedsReview) {
                // Publishing Queue has no Needs Review claim flow.
            },
        }"
        x-on:visibilitychange.window="$wire.refreshQueueHealth()"
        wire:poll.30s="refreshQueueHealth"
    >
        <div class="flex flex-wrap items-center gap-2">
            <span
                class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                title="{{ __('seo-content-ai::filament.projects.publishing_queue_timezone_tooltip') }}"
            >
                <x-filament::icon icon="heroicon-o-clock" class="h-3.5 w-3.5" />
                {{ $tz }}
                @if ($this->dateTimeSettingsUrl)
                    <a href="{{ $this->dateTimeSettingsUrl }}" class="ml-1 text-primary-700 underline dark:text-primary-300" wire:navigate>
                        {{ __('seo-content-ai::filament.projects.publishing_queue_timezone_settings') }}
                    </a>
                @endif
            </span>

            <button type="button" wire:click="refreshQueueHealth" class="ml-auto text-xs font-semibold text-primary-700 hover:underline dark:text-primary-300">
                Refresh
            </button>
        </div>

        {{-- Queue health --}}
        <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900">
            @if (($health['runner_status'] ?? '') === 'connection_failed')
                <span class="inline-flex items-center gap-1 font-medium text-danger-700 dark:text-danger-400">
                    <span class="h-2 w-2 rounded-full bg-danger-500"></span>
                    {{ $health['runner_status_label'] ?? 'Publishing connection failed' }}
                </span>
            @elseif (! empty($health['runner_healthy']))
                <span class="inline-flex items-center gap-1 font-medium text-success-700 dark:text-success-400">
                    <span class="h-2 w-2 rounded-full bg-success-500"></span>
                    {{ $health['runner_status_label'] ?? 'Runner healthy' }}
                </span>
            @else
                <span class="inline-flex items-center gap-1 font-medium text-warning-700 dark:text-warning-400">
                    <span class="h-2 w-2 rounded-full bg-warning-500"></span>
                    {{ $health['runner_status_label'] ?? 'Runner stale / unavailable' }}
                </span>
            @endif
            <span class="text-gray-600 dark:text-gray-300">
                @if ($health['runner_last_ran_minutes_ago'] !== null)
                    {{ __('seo-content-ai::filament.projects.health_last_ran_minutes', ['minutes' => (int) $health['runner_last_ran_minutes_ago']]) }}
                @else
                    {{ __('seo-content-ai::filament.projects.health_runner_never') }}
                @endif
            </span>
            @if ($recoverableStuck > 0)
                <span class="font-semibold text-danger-700 dark:text-danger-400">Stuck Publishing: {{ $recoverableStuck }}</span>
            @endif
            <span class="text-gray-500">{{ __('seo-content-ai::filament.projects.publishing_queue_system_tz_note', ['tz' => $tz]) }}</span>
        </div>

        <x-seo-content-ai::content-project-summary-cards
            :cards="$kpiCards"
            :active="$active"
            wire-method="applyStateFilter"
            aria-label="Publishing Queue summary"
            loading-targets="applyStateFilter,clearFilters,search,stateFilter"
        />
        @unless ($invariantOk)
            <div class="rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-xs text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-200" role="alert">
                Count invariant lệch: queue={{ $queueTotal }}, projected={{ $projectedSum }}. Kiểm tra tab Cần xử lý.
            </div>
        @endunless

        <div>
            @if ($this->bulkRunning && count($this->pendingTaskIds) > 0)
                <div class="mb-2 inline-flex items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-800 dark:border-primary-500/40 dark:bg-primary-500/10 dark:text-primary-200" role="status" aria-live="polite">
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    {{ __('seo-content-ai::filament.projects.publishing_queue_bulk_updating', ['count' => count($this->pendingTaskIds)]) }}
                </div>
            @endif
            <x-seo-content-ai::content-project-filter-toolbar variant="publishing_queue" />

            @if ($hasProject)
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <div class="inline-flex overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                        <button
                            type="button"
                            @click="mode = 'auto'; autoOpen = true"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition
                                border-r border-gray-200 dark:border-gray-700
                                hover:bg-primary-50 dark:hover:bg-primary-500/10"
                            :class="mode === 'auto' && autoOpen ? 'bg-primary-50 text-primary-800 border-primary-300 dark:bg-primary-500/15 dark:text-primary-200' : 'bg-white text-gray-700 dark:bg-gray-900 dark:text-gray-200'"
                        >
                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                            Auto Schedule
                        </button>
                        <button
                            type="button"
                            @click="mode = 'quick'; quickOpen = true"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition
                                hover:bg-primary-50 dark:hover:bg-primary-500/10"
                            :class="mode === 'quick' && quickOpen ? 'bg-primary-50 text-primary-800 dark:bg-primary-500/15 dark:text-primary-200' : 'bg-white text-gray-700 dark:bg-gray-900 dark:text-gray-200'"
                        >
                            <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4" />
                            Quick Mode
                        </button>
                    </div>
                </div>

            @endif

            @if ($selectedCount > 0 && $selectedCount >= $pageCount && $pageCount > 0 && ! $this->selectAllMatching && $filteredTotal > $pageCount)
                <div class="mt-2 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-500/30 dark:bg-primary-500/10">
                    Đã chọn {{ $pageCount }} bài trên trang này.
                    <button type="button" wire:click="selectAllMatchingResults" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">
                        Chọn toàn bộ {{ $filteredTotal }} bài phù hợp.
                    </button>
                </div>
            @endif

            <x-seo-content-ai::content-project-bulk-selection-toolbar
                variant="publishing_queue"
                :selected-count="$selectedCount"
                :timezone-label="$tz"
            />
        </div>

        <x-seo-content-ai::content-project-items-list
            variant="publishing_queue"
            :rows="$rows"
            :has-active-filters="$hasActiveFilters"
            :show-checkbox="true"
            :use-row-visibility="false"
            :selected-ids="$this->selectedTaskIds"
            :pending-task-ids="$this->pendingTaskIds"
            :pending-phase="$this->pendingPhase"
        />

        @if ($hasProject)
            {{-- Auto Schedule modal --}}
            <div x-show="autoOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="w-full max-w-lg rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="autoOpen = false">
                    <h3 class="mb-1 text-sm font-semibold">Auto Schedule</h3>
                    <p class="mb-3 text-xs text-gray-500">Phân bố đều eligible items theo tháng dự án. Không cần tick checkbox.</p>
                    <p class="mb-3 text-xs font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.projects.publishing_queue_all_times_use', ['tz' => $tz]) }}
                    </p>
                    <div class="mb-3 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Eligible: <strong>{{ $autoEligible }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Excluded: <strong>{{ $autoExcluded }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">First: <strong>{{ $autoPreview['first_publish_at'] ? \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($autoPreview['first_publish_at']) : '—' }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Last: <strong>{{ $autoPreview['last_publish_at'] ? \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($autoPreview['last_publish_at']) : '—' }}</strong></div>
                    </div>
                    <div class="mb-3 grid grid-cols-2 gap-2">
                        <x-select wire:model.live="autoDayStart" class="!w-full">
                            @foreach (['08:00','09:00','10:00','11:00'] as $t)
                                <option value="{{ $t }}">Day start {{ $t }}</option>
                            @endforeach
                        </x-select>
                        <x-select wire:model.live="autoDayEnd" class="!w-full">
                            @foreach (['16:00','17:00','18:00','19:00'] as $t)
                                <option value="{{ $t }}">Day end {{ $t }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    @if (! empty($autoPreview['blocked']))
                        <p class="mb-3 text-xs text-warning-700 dark:text-warning-400">{{ $autoPreview['blocked'] }}</p>
                        <p class="mb-3 text-xs text-gray-600 dark:text-gray-300">Dùng Quick Mode (In Day / N days) thay cho Auto Schedule.</p>
                    @elseif ($autoEligible === 0)
                        <p class="mb-3 text-xs text-warning-700">Không có bài chưa lên lịch phù hợp</p>
                    @endif
                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-sm" @click="autoOpen = false">Đóng</button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-primary fi-size-sm"
                            wire:click="runProjectMonthAutoSchedule"
                            @click="autoOpen = false"
                            @disabled($autoEligible === 0 || ! empty($autoPreview['blocked']))
                        >Apply</button>
                    </div>
                </div>
            </div>

            {{-- Quick Mode modal --}}
            <div x-show="quickOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="w-full max-w-lg rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="quickOpen = false">
                    <h3 class="mb-1 text-sm font-semibold">Quick Mode</h3>
                    <p class="mb-3 text-xs text-gray-500">Deadline recovery — toàn bộ eligible items. Không cần selection.</p>
                    <p class="mb-3 text-xs font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.projects.publishing_queue_all_times_use', ['tz' => $tz]) }}
                    </p>

                    <div class="mb-3">
                        <x-select wire:model.live="quickSubmode" class="!w-full">
                            <option value="in_day">In Day — Đăng trong ngày</option>
                            <option value="n_days">N days</option>
                        </x-select>
                    </div>

                    @if ($this->quickSubmode === 'in_day')
                        <div class="mb-3 space-y-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600">Interval between articles</label>
                            <x-select wire:model.live="inDayIntervalMinutes" class="!w-full">
                                @foreach ([5,10,15,20,30,45,60] as $m)
                                    <option value="{{ $m }}">{{ $m }} minutes</option>
                                @endforeach
                            </x-select>
                            <input
                                type="number"
                                min="5"
                                max="180"
                                wire:model.live="inDayIntervalMinutes"
                                class="fi-input w-full text-sm"
                                aria-label="Custom interval minutes"
                                placeholder="Custom minutes (≥5)"
                            />
                        </div>
                    @else
                        <div class="mb-3 space-y-2">
                            <x-select wire:model.live="quickDays" class="!w-full">
                                @foreach ([1,2,3,5,7] as $d)
                                    <option value="{{ $d }}">{{ $d }} day{{ $d > 1 ? 's' : '' }}</option>
                                @endforeach
                            </x-select>
                            <x-select wire:model.live="quickStartTime" class="!w-full">
                                @foreach (['08:00','09:00','10:00','11:00','12:00'] as $t)
                                    <option value="{{ $t }}">Start {{ $t }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    @endif

                    <div class="mb-3 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Eligible: <strong>{{ $quickEligible }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Excluded: <strong>{{ $quickExcluded }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">First: <strong>{{ $quickPreview['first_publish_at'] ? \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($quickPreview['first_publish_at']) : '—' }}</strong></div>
                        <div class="rounded border border-gray-200 p-2 dark:border-gray-700">Last: <strong>{{ $quickPreview['last_publish_at'] ? \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($quickPreview['last_publish_at']) : '—' }}</strong></div>
                    </div>

                    @if (! empty($quickPreview['blocked']))
                        <p class="mb-3 text-xs text-danger-700">{{ $quickPreview['blocked'] }}</p>
                        @if (! empty($quickPreview['suggested_max_interval']))
                            <p class="mb-3 text-xs text-gray-600">Gợi ý interval tối đa: {{ (int) $quickPreview['suggested_max_interval'] }} phút</p>
                        @endif
                    @endif

                    @if ($quickEligible === 0)
                        <p class="mb-3 text-xs text-warning-700">Không có bài chưa lên lịch phù hợp</p>
                    @endif

                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-sm" @click="quickOpen = false">Đóng</button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-primary fi-size-sm"
                            wire:click="runQuickSchedule"
                            @click="quickOpen = false"
                            @disabled($quickEligible === 0 || ! empty($quickPreview['blocked']))
                        >Apply</button>
                    </div>
                </div>
            </div>

            {{-- Advanced recover modal removed from normal PQ UX; inline row recover only. --}}
        @endif
    </div>

    <x-seo-content-ai::content-project-ops-styles />
</x-filament-panels::page>
