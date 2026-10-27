@php
    $lockInspect = $this->lockInspect;
    $lockState = (string) ($lockInspect['state'] ?? 'unavailable');
    $lockHolder = is_array($lockInspect['holder'] ?? null) ? $lockInspect['holder'] : null;
    $lockUnavailable = $lockState === \Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock::STATE_UNAVAILABLE;
    $preflight = $this->preflight;
    $progress = $this->progress;
    $overview = is_array($progress['overview'] ?? null) ? $progress['overview'] : [];
    $batch = is_array($progress['batch'] ?? null) ? $progress['batch'] : [];
    $nowPlaying = is_array($progress['now_playing'] ?? null) ? $progress['now_playing'] : null;
    $playlist = is_array($progress['playlist'] ?? null) ? $progress['playlist'] : [];
    $shouldPoll = (bool) ($batch['should_poll'] ?? false);
    $eligibleTotal = (int) ($overview['eligible_total'] ?? 0);
    $processed = (int) ($overview['processed'] ?? 0);
    $percent = (int) ($overview['percent'] ?? 0);
    $noEligibleWork = (bool) ($overview['no_eligible_work'] ?? false);
    $succeeded = (int) ($overview['succeeded'] ?? $overview['completed'] ?? 0);
    $failed = (int) ($overview['failed'] ?? 0);
    $skipped = (int) ($overview['skipped'] ?? 0);
    $processing = (int) ($overview['processing'] ?? 0);
    $total = (int) ($overview['snapshot_total'] ?? $overview['total'] ?? 0);
    $activeTab = $this->activeTab;
@endphp

<x-filament-panels::page>
    @push('styles')
        <style>
            /* Global Monthly Generator — matches Content Project Progress design language */
            .fi-page:has([data-gm-progress-page]) > section {
                gap: 0.625rem !important;
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }

            [data-gm-progress-page] {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
                max-width: none;
                min-height: 0;
                box-sizing: border-box;
            }

            [data-gm-progress-page] > [data-gm-progress-chrome] {
                flex: 0 0 auto;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
            }

            [data-gm-progress-page] [data-gm-card] {
                width: 100%;
                border-radius: 0.75rem;
                border: 1px solid rgb(229 231 235);
                background: #fff;
                padding: 0.625rem 0.75rem;
                box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            }

            .dark [data-gm-progress-page] [data-gm-card] {
                border-color: rgb(255 255 255 / 0.1);
                background: rgb(17 24 39);
            }

            [data-gm-progress-page] [data-gm-playlist-shell] {
                flex: 1 1 auto;
                min-height: 0;
                width: 100%;
                display: flex;
                flex-direction: column;
                border-radius: 0.75rem;
                border: 1px solid rgb(229 231 235);
                background: #fff;
                overflow: hidden;
                box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            }

            .dark [data-gm-progress-page] [data-gm-playlist-shell] {
                border-color: rgb(255 255 255 / 0.1);
                background: rgb(17 24 39);
            }

            [data-gm-progress-page] [data-gm-playlist-scroll] {
                flex: 1 1 auto;
                min-height: 0;
                width: 100%;
                overflow-x: auto;
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            [data-gm-progress-page] table.gm-table {
                width: 100%;
                min-width: 100%;
                table-layout: fixed;
                border-collapse: collapse;
            }

            [data-gm-progress-page] table.gm-table thead th {
                position: sticky;
                top: 0;
                z-index: 1;
                background: rgb(249 250 251);
                padding: 0.4rem 0.75rem;
                font-size: 0.7rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: rgb(107 114 128);
                border-bottom: 1px solid rgb(229 231 235);
            }

            .dark [data-gm-progress-page] table.gm-table thead th {
                background: rgb(31 41 55 / 0.95);
                color: rgb(156 163 175);
                border-bottom-color: rgb(255 255 255 / 0.1);
            }

            [data-gm-progress-page] table.gm-table tbody td {
                padding: 0.35rem 0.75rem;
                vertical-align: middle;
                border-bottom: 1px solid rgb(243 244 246);
                height: 44px;
            }

            .dark [data-gm-progress-page] table.gm-table tbody td {
                border-bottom-color: rgb(255 255 255 / 0.06);
            }

            /* Playlist columns: # | Project | Article | Status | Duration */
            [data-gm-progress-page] table.gm-progress-playlist col.gm-col-idx { width: 3.25rem; }
            [data-gm-progress-page] table.gm-progress-playlist col.gm-col-project { width: 13rem; }
            [data-gm-progress-page] table.gm-progress-playlist col.gm-col-article { width: auto; }
            [data-gm-progress-page] table.gm-progress-playlist col.gm-col-status { width: 9rem; }
            [data-gm-progress-page] table.gm-progress-playlist col.gm-col-duration { width: 7.5rem; }

            /* Setup columns: # | Project | Bài chưa Generate | Trạng thái */
            [data-gm-progress-page] table.gm-setup col.gm-setup-idx { width: 3.25rem; }
            [data-gm-progress-page] table.gm-setup col.gm-setup-project { width: auto; }
            [data-gm-progress-page] table.gm-setup col.gm-setup-pending { width: 13rem; }
            [data-gm-progress-page] table.gm-setup col.gm-setup-status { width: 10rem; }

            /* History columns: #Batch | Tháng | Trạng thái | OK/Fail | AI | Bắt đầu | Chi tiết */
            [data-gm-progress-page] table.gm-history col.gm-hist-id { width: 5.5rem; }
            [data-gm-progress-page] table.gm-history col.gm-hist-month { width: 7rem; }
            [data-gm-progress-page] table.gm-history col.gm-hist-status { width: 8.5rem; }
            [data-gm-progress-page] table.gm-history col.gm-hist-counts { width: 13rem; }
            [data-gm-progress-page] table.gm-history col.gm-hist-ai { width: 7.5rem; }
            [data-gm-progress-page] table.gm-history col.gm-hist-started { width: auto; }
            [data-gm-progress-page] table.gm-history col.gm-hist-action { width: 6.5rem; }

            [data-gm-progress-page] .gm-progress-bar-track {
                height: 0.5rem;
                width: 100%;
                overflow: hidden;
                border-radius: 9999px;
                background: rgb(243 244 246);
            }

            .dark [data-gm-progress-page] .gm-progress-bar-track {
                background: rgb(31 41 55);
            }

            [data-gm-progress-page] .gm-progress-bar-fill {
                height: 100%;
                border-radius: 9999px;
                transition: width 0.4s ease;
            }

            /* Desktop: fit inside viewport without whole body scrolling */
            @media (min-width: 768px) and (min-height: 701px) {
                [data-gm-progress-page][data-gm-progress-fit="1"] {
                    overflow: hidden;
                }
            }

            /* Mobile or short viewports */
            @media (max-width: 767px), (max-height: 700px) {
                [data-gm-progress-page] {
                    max-height: none !important;
                    overflow: visible;
                }

                [data-gm-progress-page] [data-gm-playlist-shell] {
                    flex: 0 0 auto;
                    max-height: min(60vh, 28rem);
                }
            }
        </style>
    @endpush

    <div
        class="w-full max-w-none"
        data-gm-progress-page
        data-gm-progress-fit="0"
        data-gm-batch-id="{{ (int) ($batch['id'] ?? $this->batchId ?? 0) }}"
        x-data="{
            scrollKey: 'gmProgressPlaylistY:{{ (int) ($batch['id'] ?? $this->batchId ?? 0) }}',
            fit() {
                const el = this.$el;
                const narrow = window.matchMedia('(max-width: 767px)').matches;
                const short = window.matchMedia('(max-height: 700px)').matches;
                if (narrow || short) {
                    el.style.maxHeight = '';
                    el.dataset.gmProgressFit = '0';
                    return;
                }
                const top = el.getBoundingClientRect().top;
                const pad = 12;
                const h = Math.max(280, Math.floor(window.innerHeight - top - pad));
                el.style.maxHeight = h + 'px';
                el.dataset.gmProgressFit = '1';
            },
            rememberPlaylistScroll() {
                const sc = this.$refs.playlistScroll;
                if (sc) window[this.scrollKey] = sc.scrollTop;
            },
            restorePlaylistScroll() {
                const sc = this.$refs.playlistScroll;
                if (! sc) return;
                const y = Number(window[this.scrollKey] || 0);
                requestAnimationFrame(() => { sc.scrollTop = y; });
            },
        }"
        x-init="
            fit();
            restorePlaylistScroll();
            const onResize = () => fit();
            window.addEventListener('resize', onResize);
            const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => fit()) : null;
            if (ro) ro.observe(document.documentElement);
            if (window.Livewire && ! window.__gmProgressPollScrollHook) {
                window.__gmProgressPollScrollHook = true;
                Livewire.hook('commit', ({ succeed }) => {
                    const root = document.querySelector('[data-gm-progress-page]');
                    if (! root) return;
                    const sc = root.querySelector('[data-gm-playlist-scroll]');
                    const key = 'gmProgressPlaylistY:' + (root.getAttribute('data-gm-batch-id') || '0');
                    if (sc) window[key] = sc.scrollTop;
                    succeed(() => {
                        requestAnimationFrame(() => {
                            const root2 = document.querySelector('[data-gm-progress-page]');
                            if (! root2) return;
                            const sc2 = root2.querySelector('[data-gm-playlist-scroll]');
                            const key2 = 'gmProgressPlaylistY:' + (root2.getAttribute('data-gm-batch-id') || '0');
                            if (sc2) sc2.scrollTop = Number(window[key2] || 0);
                            const narrow = window.matchMedia('(max-width: 767px)').matches;
                            const short = window.matchMedia('(max-height: 700px)').matches;
                            if (narrow || short) {
                                root2.style.maxHeight = '';
                                root2.dataset.gmProgressFit = '0';
                            } else {
                                const top = root2.getBoundingClientRect().top;
                                root2.style.maxHeight = Math.max(280, Math.floor(window.innerHeight - top - 12)) + 'px';
                                root2.dataset.gmProgressFit = '1';
                            }
                        });
                    });
                });
            }
        "
    >
        {{-- Lock alerts --}}
        @include('seo-content-ai::filament.pages.partials.global-lock-notice', [
            'holder' => $lockHolder,
            'unavailable' => $lockUnavailable,
        ])

        {{-- ========================================== --}}
        {{-- TAB 1: SETUP (COMPACT)                      --}}
        {{-- ========================================== --}}
        @if ($activeTab === 'setup')
            <div data-gm-card class="space-y-3">
                {{-- Top controls, one horizontal row on desktop --}}
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="flex items-center gap-2 flex-1 sm:max-w-xs">
                        <label for="planningMonth" class="shrink-0 text-xs font-medium text-gray-700 dark:text-gray-300">Tháng:</label>
                        <select
                            id="planningMonth"
                            wire:model.live="planningMonth"
                            class="block w-full rounded-lg border-gray-300 py-1.5 px-2.5 text-xs sm:text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                            @foreach (\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::selectOptions() as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 flex-1 sm:max-w-xs">
                        <label for="aiCostPolicy" class="shrink-0 text-xs font-medium text-gray-700 dark:text-gray-300">AI Mode:</label>
                        <select
                            id="aiCostPolicy"
                            wire:model="aiCostPolicy"
                            class="block w-full rounded-lg border-gray-300 py-1.5 px-2.5 text-xs sm:text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="default">Paid</option>
                            <option value="free_only">Free-Split</option>
                        </select>
                    </div>

                    <div class="sm:ms-auto shrink-0">
                        <button
                            type="button"
                            wire:click="startBatch"
                            wire:loading.attr="disabled"
                            wire:target="startBatch"
                            @disabled(! $this->canStartBatch())
                            class="fi-btn fi-btn-color-primary fi-btn-size-md inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            <x-heroicon-o-play class="h-4 w-4 shrink-0" wire:loading.remove wire:target="startBatch" />
                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 animate-spin" wire:loading wire:target="startBatch" />
                            <span wire:loading.remove wire:target="startBatch">Generate tháng</span>
                            <span wire:loading wire:target="startBatch">Đang khởi tạo…</span>
                        </button>
                    </div>
                </div>

                {{-- Compact summary: 7 Project | 83 bài cần Generate | 0 active --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ count($preflight['projects'] ?? []) }} Project</span>
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ (int) ($preflight['eligible_count'] ?? 0) }} bài cần Generate</span>
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ count($preflight['legacy_active_runs'] ?? []) }} active</span>
                </div>
            </div>

            {{-- Project list table: # | Project | Bài chưa Generate | Trạng thái --}}
            <div data-gm-playlist-shell>
                <div data-gm-playlist-scroll>
                    <table class="gm-table gm-setup text-sm">
                        <colgroup>
                            <col class="gm-setup-idx" />
                            <col class="gm-setup-project" />
                            <col class="gm-setup-pending" />
                            <col class="gm-setup-status" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Project</th>
                                <th>Bài chưa Generate</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $activeRunProjectIds = collect($preflight['legacy_active_runs'] ?? [])->pluck('project_id')->all();
                            @endphp
                            @forelse (($preflight['projects'] ?? []) as $project)
                                @php
                                    $eligible = (int) ($project['eligible_count'] ?? 0);
                                    $pid = (int) $project['project_id'];
                                    $isActive = in_array($pid, $activeRunProjectIds, true);
                                    try {
                                        $projectUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('progress', ['record' => $pid]);
                                    } catch (\Throwable) {
                                        $projectUrl = null;
                                    }
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="tabular-nums text-xs text-gray-500 dark:text-gray-400">
                                        {{ $loop->iteration }}
                                    </td>
                                    <td>
                                        @if ($projectUrl)
                                            <a href="{{ $projectUrl }}" class="font-medium text-primary-600 hover:text-primary-500 hover:underline">
                                                #{{ $pid }} {{ $project['name'] ?? '' }}
                                            </a>
                                        @else
                                            <span class="font-medium text-gray-900 dark:text-white">
                                                #{{ $pid }} {{ $project['name'] ?? '' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td @class([
                                        'tabular-nums text-xs',
                                        'font-semibold text-gray-900 dark:text-white' => $eligible > 0,
                                        'text-gray-400 dark:text-gray-500' => $eligible === 0,
                                    ])>
                                        {{ $eligible }} bài
                                    </td>
                                    <td>
                                        @if ($isActive)
                                            <span class="inline-flex items-center gap-1 rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300">
                                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                                Đang chạy
                                            </span>
                                        @elseif ($eligible > 0)
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                                                <x-heroicon-o-clock class="h-3.5 w-3.5" />
                                                Chờ Generate
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500">
                                                <x-heroicon-o-check-circle class="h-3.5 w-3.5 text-gray-400" />
                                                Đã tạo hết
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Không có project nào trong tháng {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($this->planningMonth) }}.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ========================================== --}}
        {{-- TAB 2: PROGRESS (PLAYLIST)                  --}}
        {{-- ========================================== --}}
        @if ($activeTab === 'progress')
            <div data-gm-progress-chrome>
                {{-- Now playing + Global batch meta --}}
                <div data-gm-card>
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <div class="flex items-center gap-2">
                            <span>
                                Trạng thái:
                                <strong class="text-gray-800 dark:text-gray-200">{{ $batch['status_label'] ?? 'Chưa chạy' }}</strong>
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>
                                AI Mode:
                                <strong class="text-gray-800 dark:text-gray-200">{{ $batch['ai_mode_label'] ?? ($this->aiCostPolicy === 'free_only' ? 'Free-Split' : 'Paid') }}</strong>
                            </span>
                            @if (! empty($batch['elapsed_label']))
                                <span class="text-gray-300 dark:text-gray-600">·</span>
                                <span>{{ $batch['elapsed_label'] }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! empty($batch['is_active']) && empty($batch['stop_requested']))
                                <button
                                    type="button"
                                    wire:click="stopBatch"
                                    wire:confirm="Dừng Global Batch? Item đang chạy sẽ được phép hoàn tất."
                                    wire:loading.attr="disabled"
                                    wire:target="stopBatch"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-danger-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-danger-500 disabled:opacity-50"
                                >
                                    <x-heroicon-o-stop-circle class="h-4 w-4" />
                                    <span>Emergency Stop</span>
                                </button>
                            @endif
                            @if (in_array(($batch['status'] ?? ''), ['cancelled', 'failed', 'stopping'], true))
                                <button
                                    type="button"
                                    wire:click="resumeBatch"
                                    wire:loading.attr="disabled"
                                    wire:target="resumeBatch"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-50"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    <span>Resume</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    @if (is_array($nowPlaying))
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                                <x-heroicon-o-arrow-path class="h-5 w-5 animate-spin" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span class="inline-flex items-center gap-1 rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30">
                                        #{{ str_pad((string) ($nowPlaying['position'] ?? 0), 2, '0', STR_PAD_LEFT) }}
                                    </span>
                                    <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 dark:text-primary-300">
                                        {{ $nowPlaying['status_label'] ?? 'Đang chạy' }}
                                    </span>
                                    @if (! empty($nowPlaying['elapsed_label']))
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $nowPlaying['elapsed_label'] }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-0.5 truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $nowPlaying['title'] ?? '' }}">
                                    {{ $nowPlaying['title'] ?? '—' }}
                                </div>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $nowPlaying['project_name'] ?? ($overview['current_project_name'] ?? '—') }}</span>
                                    ·
                                    <span>{{ $nowPlaying['step_label'] ?? ($overview['current_stage'] ?? '—') }}</span>
                                    ·
                                    <span>Bài {{ (int) ($nowPlaying['position'] ?? 0) }}/{{ (int) ($nowPlaying['total'] ?? $total) }}</span>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                            <x-heroicon-o-pause-circle class="h-6 w-6 shrink-0 text-gray-400" />
                            <div class="min-w-0">
                                <div class="truncate font-medium text-gray-900 dark:text-white">
                                    @if (empty($batch))
                                        Chưa có đợt Generate nào trong tháng này.
                                    @elseif (! empty($batch['is_terminal']))
                                        Batch hoàn tất — {{ $succeeded }}/{{ $total }} bài thành công.
                                    @else
                                        Đang chờ bài tiếp theo…
                                    @endif
                                </div>
                                @if (empty($batch))
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        <button type="button" wire:click="setActiveTab('setup')" class="font-medium text-primary-600 hover:text-primary-500 hover:underline">Chuyển sang Thiết lập để bắt đầu</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Overview: Progress bar and compact status counters --}}
                <div data-gm-card>
                    <div class="mb-1.5 flex items-baseline justify-between gap-2">
                        <div class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                            @if ($noEligibleWork)
                                Không có bài eligible
                            @else
                                {{ $processed }}/{{ $eligibleTotal }} bài đã xử lý
                            @endif
                        </div>
                        <div class="text-sm font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                            @if ($noEligibleWork)
                                —
                            @else
                                {{ $percent }}%
                            @endif
                        </div>
                    </div>
                    <div class="gm-progress-bar-track" role="progressbar" aria-valuenow="{{ $noEligibleWork ? 0 : $percent }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="gm-progress-bar-fill bg-primary-600" style="width: {{ $noEligibleWork ? 0 : max(0, min(100, $percent)) }}%"></div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-check-circle class="h-3.5 w-3.5 text-success-600" />
                            Thành công <strong class="tabular-nums">{{ $succeeded }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-primary-600" />
                            Đang chạy <strong class="tabular-nums">{{ $processing }}</strong>
                        </span>
                        <span @class([
                            'inline-flex items-center gap-1 whitespace-nowrap',
                            'font-semibold text-danger-700 dark:text-danger-400' => $failed > 0,
                        ])>
                            <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 text-danger-600" />
                            Lỗi <strong class="tabular-nums">{{ $failed }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-clock class="h-3.5 w-3.5 text-gray-400" />
                            Chờ <strong class="tabular-nums">{{ (int) ($overview['pending'] ?? 0) }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            Bỏ qua <strong class="tabular-nums">{{ $skipped }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            Tổng snapshot <strong class="tabular-nums">{{ $total }}</strong>
                        </span>
                        <span class="ms-auto truncate text-gray-500 dark:text-gray-400">
                            @if (! empty($overview['last_completed_duration_label']))
                                Vừa xong: {{ $overview['last_completed_duration_label'] }}
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Playlist: # | Project | Article | Status | Duration --}}
            <div data-gm-playlist-shell>
                <div
                    data-gm-playlist-scroll
                    x-ref="playlistScroll"
                    @scroll.passive="rememberPlaylistScroll()"
                >
                    <table class="gm-table gm-progress-playlist text-sm">
                        <colgroup>
                            <col class="gm-col-idx" />
                            <col class="gm-col-project" />
                            <col class="gm-col-article" />
                            <col class="gm-col-status" />
                            <col class="gm-col-duration" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Project</th>
                                <th>Bài viết</th>
                                <th>Trạng thái</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody
                            @if ($shouldPoll)
                                wire:poll.5s="refreshProgress"
                            @endif
                        >
                            @forelse ($playlist as $row)
                                @php
                                    $bucket = (string) ($row['status_bucket'] ?? 'pending');
                                    $isCurrent = (bool) ($row['is_current'] ?? false);
                                    $title = (string) ($row['title'] ?? '');
                                    $projName = (string) ($row['project_name'] ?? '');
                                @endphp
                                <tr @class([
                                    'bg-primary-50/80 dark:bg-primary-500/10' => $isCurrent,
                                    'hover:bg-gray-50 dark:hover:bg-white/5 transition-colors' => ! $isCurrent,
                                ])>
                                    <td class="tabular-nums text-xs text-gray-500 dark:text-gray-400">
                                        {{ (int) ($row['position'] ?? 0) }}
                                    </td>
                                    <td class="truncate text-xs" title="{{ $projName }}">
                                        @if (! empty($row['project_url']))
                                            <a href="{{ $row['project_url'] }}" class="text-primary-600 hover:text-primary-500 hover:underline">
                                                {{ $projName }}
                                            </a>
                                        @else
                                            <span class="text-gray-700 dark:text-gray-300">{{ $projName }}</span>
                                        @endif
                                    </td>
                                    <td class="truncate font-medium text-gray-900 dark:text-white" title="{{ $title }}">
                                        {{ $title !== '' ? $title : '—' }}
                                    </td>
                                    <td>
                                        <span @class([
                                            'inline-flex max-w-full items-center gap-1 truncate text-xs font-medium',
                                            'text-success-700 dark:text-success-400' => $bucket === 'completed',
                                            'text-primary-700 dark:text-primary-300' => $bucket === 'processing',
                                            'text-danger-700 dark:text-danger-400' => $bucket === 'failed',
                                            'text-gray-500 dark:text-gray-400' => in_array($bucket, ['pending', 'skipped', 'cancelled'], true),
                                        ])>
                                            @if ($bucket === 'completed')
                                                <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0" />
                                            @elseif ($bucket === 'processing')
                                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" />
                                            @elseif ($bucket === 'failed')
                                                <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 shrink-0" />
                                            @else
                                                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" />
                                            @endif
                                            <span class="truncate">{{ $row['status_label'] ?? $bucket }}</span>
                                        </span>
                                    </td>
                                    <td class="truncate tabular-nums text-xs text-gray-600 dark:text-gray-300" title="{{ $row['duration_label'] ?? '—' }}">
                                        {{ $row['duration_label'] ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        <p class="font-medium">Chưa có đợt Generate nào trong tháng này.</p>
                                        <button
                                            type="button"
                                            wire:click="setActiveTab('setup')"
                                            class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-500 hover:underline"
                                        >
                                            ← Chuyển sang Thiết lập để bắt đầu
                                        </button>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ========================================== --}}
        {{-- TAB 3: HISTORY                              --}}
        {{-- ========================================== --}}
        @if ($activeTab === 'history')
            {{-- History table: compact historical batch list --}}
            <div data-gm-playlist-shell>
                <div data-gm-playlist-scroll>
                    <table class="gm-table gm-history text-sm">
                        <colgroup>
                            <col class="gm-hist-id" />
                            <col class="gm-hist-month" />
                            <col class="gm-hist-status" />
                            <col class="gm-hist-counts" />
                            <col class="gm-hist-ai" />
                            <col class="gm-hist-started" />
                            <col class="gm-hist-action" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Batch</th>
                                <th>Tháng</th>
                                <th>Trạng thái</th>
                                <th>Kết quả</th>
                                <th>AI Mode</th>
                                <th>Bắt đầu</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->history as $row)
                                @php
                                    $st = (string) $row['status'];
                                    $isSuccess = $st === 'completed' || $st === 'succeeded';
                                    $isFailed = $st === 'failed';
                                    $isRunning = in_array($st, ['running', 'pending', 'stopping'], true);
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                    <td class="font-mono text-xs font-semibold text-gray-900 dark:text-white">
                                        #{{ $row['id'] }}
                                    </td>
                                    <td class="text-xs text-gray-700 dark:text-gray-300">
                                        {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($row['planning_month']) }}
                                    </td>
                                    <td>
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium',
                                            'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $isSuccess,
                                            'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $isFailed,
                                            'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400' => $isRunning,
                                            'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => ! $isSuccess && ! $isFailed && ! $isRunning,
                                        ])>
                                            {{ $row['status'] }}
                                        </span>
                                    </td>
                                    <td class="tabular-nums text-xs text-gray-600 dark:text-gray-300">
                                        <span class="font-medium text-success-600">{{ $row['succeeded'] }} OK</span>
                                        @if ($row['failed'] > 0)
                                            · <span class="font-medium text-danger-600">{{ $row['failed'] }} Lỗi</span>
                                        @endif
                                        <span class="text-gray-400">/ {{ $row['total_items'] }}</span>
                                    </td>
                                    <td class="text-xs text-gray-600 dark:text-gray-400">
                                        {{ $row['ai_cost_policy'] === 'free_only' ? 'Free-Split' : 'Paid' }}
                                    </td>
                                    <td class="tabular-nums text-xs text-gray-500">
                                        {{ $row['started_at'] ?? '—' }}
                                    </td>
                                    <td class="text-right">
                                        <button
                                            type="button"
                                            wire:click="openBatch({{ (int) $row['id'] }})"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-500 hover:underline"
                                        >
                                            Xem tiến trình →
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Chưa có đợt Generate nào trong lịch sử.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
