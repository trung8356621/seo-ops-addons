@php
    $project = $this->project;
    $payload = $this->progressPayload;
    $overview = is_array($payload['overview'] ?? null) ? $payload['overview'] : [];
    $nowPlaying = is_array($payload['now_playing'] ?? null) ? $payload['now_playing'] : null;
    $playlist = is_array($payload['playlist'] ?? null) ? $payload['playlist'] : [];
    $run = is_array($payload['run'] ?? null) ? $payload['run'] : null;
    $hasRun = (bool) ($payload['has_run'] ?? false);
    $shouldPoll = $this->shouldPollProgress();
    $total = (int) ($overview['snapshot_total'] ?? $overview['total'] ?? 0);
    $completed = (int) ($overview['completed'] ?? 0);
    $failed = (int) ($overview['failed'] ?? 0);
    $skipped = (int) ($overview['skipped'] ?? 0);
    $eligibleTotal = (int) ($overview['eligible_total'] ?? max(0, $total - $skipped));
    $processed = (int) ($overview['processed'] ?? ($completed + $failed));
    $percent = (int) ($overview['percent'] ?? 0);
    $noEligibleWork = (bool) ($overview['no_eligible_work'] ?? false);
    $globalLock = $this->globalLockHolder();
    $globalLockInspect = $this->globalLockInspect();
    $globalLockUnavailable = ($globalLockInspect['state'] ?? '') === \Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalGenerationLock::STATE_UNAVAILABLE;
    $runAiModeLabel = $this->displayedAiModeLabel();
@endphp

<x-filament-panels::page>
    @push('styles')
        <style>
            /* Progress page only — do not touch Filament shell / sidebar / header */
            [data-cp-progress-page] {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
                max-width: none;
                min-height: 0;
                box-sizing: border-box;
            }

            [data-cp-progress-page] > [data-cp-progress-chrome] {
                flex: 0 0 auto;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                width: 100%;
            }

            /* Shared mode-tabs partial keeps mb-4 for Details; neutralize here only */
            [data-cp-progress-page] [data-cp-progress-chrome] > div:first-child,
            [data-cp-progress-page] [data-cp-progress-chrome] > div:first-child > div.mb-4 {
                margin-bottom: 0 !important;
            }

            [data-cp-progress-page] [data-cp-progress-card] {
                width: 100%;
                border-radius: 0.75rem;
                border: 1px solid rgb(229 231 235);
                background: #fff;
                padding: 0.625rem 0.75rem;
                box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            }

            .dark [data-cp-progress-page] [data-cp-progress-card] {
                border-color: rgb(255 255 255 / 0.1);
                background: rgb(17 24 39);
            }

            [data-cp-progress-page] [data-cp-playlist-shell] {
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

            .dark [data-cp-progress-page] [data-cp-playlist-shell] {
                border-color: rgb(255 255 255 / 0.1);
                background: rgb(17 24 39);
            }

            [data-cp-progress-page] [data-cp-playlist-scroll] {
                flex: 1 1 auto;
                min-height: 0;
                width: 100%;
                overflow-x: auto;
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            [data-cp-progress-page] table.cp-progress-playlist {
                width: 100%;
                min-width: 100%;
                table-layout: fixed;
                border-collapse: collapse;
            }

            [data-cp-progress-page] table.cp-progress-playlist thead th {
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

            .dark [data-cp-progress-page] table.cp-progress-playlist thead th {
                background: rgb(31 41 55 / 0.95);
                color: rgb(156 163 175);
                border-bottom-color: rgb(255 255 255 / 0.1);
            }

            [data-cp-progress-page] table.cp-progress-playlist tbody td {
                padding: 0.35rem 0.75rem;
                vertical-align: middle;
                border-bottom: 1px solid rgb(243 244 246);
                height: 48px;
            }

            .dark [data-cp-progress-page] table.cp-progress-playlist tbody td {
                border-bottom-color: rgb(255 255 255 / 0.06);
            }

            [data-cp-progress-page] table.cp-progress-playlist col.cp-col-idx { width: 3rem; }
            [data-cp-progress-page] table.cp-progress-playlist col.cp-col-article { width: auto; }
            [data-cp-progress-page] table.cp-progress-playlist col.cp-col-status { width: 8.5rem; }
            [data-cp-progress-page] table.cp-progress-playlist col.cp-col-duration { width: 9rem; }

            [data-cp-progress-page] .cp-progress-bar-track {
                height: 0.5rem;
                width: 100%;
                overflow: hidden;
                border-radius: 9999px;
                background: rgb(243 244 246);
            }

            .dark [data-cp-progress-page] .cp-progress-bar-track {
                background: rgb(31 41 55);
            }

            [data-cp-progress-page] .cp-progress-bar-fill {
                height: 100%;
                border-radius: 9999px;
                transition: width 0.4s ease;
            }

            /* Desktop / tall enough: pin page to remaining viewport; only playlist scrolls */
            @media (min-width: 768px) and (min-height: 701px) {
                [data-cp-progress-page][data-cp-progress-fit="1"] {
                    overflow: hidden;
                }
            }

            /* Mobile / short viewport: allow page scroll; playlist gets a capped panel */
            @media (max-width: 767px), (max-height: 700px) {
                [data-cp-progress-page] {
                    max-height: none !important;
                    overflow: visible;
                }

                [data-cp-progress-page] [data-cp-playlist-shell] {
                    flex: 0 0 auto;
                    max-height: min(60vh, 28rem);
                }
            }
        </style>
    @endpush

    <div
        class="w-full max-w-none"
        data-cp-progress-page
        data-cp-progress-fit="0"
        data-cp-progress-polling="{{ $shouldPoll ? '1' : '0' }}"
        x-data="{
            scrollKey: 'cpProgressPlaylistY:{{ (int) ($project?->getKey() ?? 0) }}',
            fit() {
                const el = this.$el;
                const narrow = window.matchMedia('(max-width: 767px)').matches;
                const short = window.matchMedia('(max-height: 700px)').matches;
                if (narrow || short) {
                    el.style.maxHeight = '';
                    el.dataset.cpProgressFit = '0';
                    return;
                }
                const top = el.getBoundingClientRect().top;
                const pad = 12;
                const h = Math.max(280, Math.floor(window.innerHeight - top - pad));
                el.style.maxHeight = h + 'px';
                el.dataset.cpProgressFit = '1';
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
            if (window.Livewire && ! window.__cpProgressPollScrollHook) {
                window.__cpProgressPollScrollHook = true;
                Livewire.hook('commit', ({ component, succeed }) => {
                    const root = document.querySelector('[data-cp-progress-page]');
                    if (! root) return;
                    const sc = root.querySelector('[data-cp-playlist-scroll]');
                    const key = 'cpProgressPlaylistY:' + (root.getAttribute('data-cp-project-id') || '0');
                    if (sc) window[key] = sc.scrollTop;
                    succeed(() => {
                        requestAnimationFrame(() => {
                            const root2 = document.querySelector('[data-cp-progress-page]');
                            if (! root2) return;
                            const sc2 = root2.querySelector('[data-cp-playlist-scroll]');
                            const key2 = 'cpProgressPlaylistY:' + (root2.getAttribute('data-cp-project-id') || '0');
                            if (sc2) sc2.scrollTop = Number(window[key2] || 0);
                            // Re-fit after morph (chrome height may change)
                            const narrow = window.matchMedia('(max-width: 767px)').matches;
                            const short = window.matchMedia('(max-height: 700px)').matches;
                            if (narrow || short) {
                                root2.style.maxHeight = '';
                                root2.dataset.cpProgressFit = '0';
                            } else {
                                const top = root2.getBoundingClientRect().top;
                                root2.style.maxHeight = Math.max(280, Math.floor(window.innerHeight - top - 12)) + 'px';
                                root2.dataset.cpProgressFit = '1';
                            }
                        });
                    });
                });
            }
        "
        data-cp-project-id="{{ (int) ($project?->getKey() ?? 0) }}"
    >
        <div data-cp-progress-chrome>
            <div class="mb-0 flex justify-end [&>div]:mb-0">
                @include('seo-content-ai::filament.resources.seo-project-resource.pages.partials.content-project-mode-tabs', [
                    'project' => $project,
                    'activeMode' => 'progress',
                ])
            </div>

            @include('seo-content-ai::filament.pages.partials.global-lock-notice', [
                'holder' => $globalLock,
                'unavailable' => $globalLockUnavailable,
            ])

            {{-- Now playing (compact) + run AI mode --}}
            <div data-cp-progress-card>
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>
                        {{ __('seo-content-ai::filament.projects.progress_ai_mode') }}:
                        <strong class="text-gray-800 dark:text-gray-200">{{ $runAiModeLabel }}</strong>
                    </span>
                </div>
                @if (is_array($nowPlaying))
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="h-11 w-11 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            @if (! empty($nowPlaying['thumbnail_url']))
                                <img src="{{ $nowPlaying['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <div class="flex h-full w-full items-center justify-center text-gray-400">
                                    <x-heroicon-o-document-text class="h-5 w-5" />
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="inline-flex items-center gap-1 rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30">
                                    <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                    {{ __('seo-content-ai::filament.projects.progress_now_badge', [
                                        'position' => str_pad((string) ($nowPlaying['position'] ?? 0), 2, '0', STR_PAD_LEFT),
                                    ]) }}
                                </span>
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-700 dark:text-primary-300">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" />
                                    {{ $nowPlaying['status_label'] ?? __('seo-content-ai::filament.projects.progress_status_processing') }}
                                </span>
                                @if (! empty($nowPlaying['elapsed_label']))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('seo-content-ai::filament.projects.progress_elapsed', ['time' => $nowPlaying['elapsed_label']]) }}
                                    </span>
                                @endif
                            </div>
                            <div
                                class="mt-0.5 truncate text-sm font-semibold text-gray-950 dark:text-white"
                                title="{{ $nowPlaying['title'] }}"
                            >
                                @if (! empty($nowPlaying['article_url']))
                                    <a href="{{ $nowPlaying['article_url'] }}" class="hover:underline">{{ $nowPlaying['title'] }}</a>
                                @else
                                    {{ $nowPlaying['title'] }}
                                @endif
                            </div>
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $nowPlaying['step_label'] ?? '—' }}
                                ·
                                {{ __('seo-content-ai::filament.projects.progress_part_of', [
                                    'current' => (int) ($nowPlaying['position'] ?? 0),
                                    'total' => (int) ($nowPlaying['total'] ?? $total),
                                ]) }}
                            </div>
                        </div>

                        @if ($this->canEmergencyStop())
                            <button
                                type="button"
                                wire:click="emergencyStop"
                                wire:confirm="{{ __('seo-content-ai::filament.projects.progress_emergency_stop_confirm') }}"
                                wire:loading.attr="disabled"
                                wire:target="emergencyStop"
                                class="inline-flex shrink-0 items-center gap-1 rounded-md bg-danger-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-danger-500 disabled:opacity-50"
                            >
                                <x-heroicon-o-stop-circle class="h-4 w-4" />
                                <span wire:loading.remove wire:target="emergencyStop">{{ __('seo-content-ai::filament.projects.progress_emergency_stop') }}</span>
                                <span wire:loading wire:target="emergencyStop" class="inline-flex items-center gap-1">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" />
                                </span>
                            </button>
                        @endif
                    </div>
                @else
                    <div class="flex items-center gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                        <x-heroicon-o-pause-circle class="h-6 w-6 shrink-0 text-gray-400" />
                        <div class="min-w-0">
                            <div class="truncate font-medium text-gray-900 dark:text-white">
                                @if (! $hasRun)
                                    {{ __('seo-content-ai::filament.projects.progress_idle_never_run') }}
                                @elseif (($run['status'] ?? '') === 'stopping')
                                    {{ __('seo-content-ai::filament.projects.progress_idle_stopping') }}
                                @elseif (($run['status'] ?? '') === 'cancelled')
                                    {{ __('seo-content-ai::filament.projects.progress_idle_cancelled') }}
                                @elseif (($run['status'] ?? '') === 'failed')
                                    {{ __('seo-content-ai::filament.projects.progress_idle_failed') }}
                                @elseif (($run['status'] ?? '') === 'completed')
                                    @if ($failed > 0 || ((int) ($overview['pending'] ?? 0)) > 0)
                                        Tiến trình kết thúc với lỗi
                                    @else
                                        {{ __('seo-content-ai::filament.projects.progress_idle_completed') }}
                                    @endif
                                @else
                                    {{ __('seo-content-ai::filament.projects.progress_idle_none') }}
                                @endif
                            </div>
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seo-content-ai::filament.projects.progress_idle_hint') }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Overview --}}
            @php
                $presentation = $overview['presentation'] ?? \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPresentation::present(
                    hasRun: $hasRun,
                    runStatus: (string) ($run['status'] ?? ''),
                    snapshotTotal: $total,
                    succeeded: $completed,
                    failed: $failed,
                    skipped: $skipped,
                    pending: (int) ($overview['pending'] ?? 0),
                    processing: (int) ($overview['processing'] ?? 0),
                    cancelled: (int) ($overview['cancelled'] ?? 0),
                    isTerminal: (bool) ($run['is_terminal'] ?? false),
                );
                $isTerminal = (bool) ($presentation['is_terminal'] ?? false);
                $statusHeading = $presentation['status_heading'] ?? null;
                $statusHeadingClass = $presentation['status_heading_class'] ?? '';
                $progressBarClass = $presentation['progress_bar_class'] ?? 'bg-primary-600';
                $summaryText = $presentation['summary_text'] ?? '';
                $pendingLabel = $presentation['pending_label'] ?? __('seo-content-ai::filament.projects.progress_metric_pending');
            @endphp

            <div data-cp-progress-card>
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    @if ($isTerminal && $statusHeading)
                        <div class="text-[18px] sm:text-[20px] font-bold tracking-tight {{ $statusHeadingClass }}">
                            {{ $statusHeading }}
                        </div>
                        <div class="text-sm sm:text-base font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">Lifecycle Progress:</span>
                            <span class="font-bold">{{ $percent }}%</span>
                        </div>
                    @else
                        <div class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                            @if ($noEligibleWork)
                                {{ __('seo-content-ai::filament.projects.progress_no_eligible_work') }}
                            @else
                                {{ __('seo-content-ai::filament.projects.progress_processed_of', [
                                    'processed' => $processed,
                                    'eligible' => $eligibleTotal,
                                ]) }}
                            @endif
                        </div>
                        <div class="text-sm font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                            @if ($noEligibleWork)
                                —
                            @else
                                {{ $percent }}%
                            @endif
                        </div>
                    @endif
                </div>

                <div class="cp-progress-bar-track" role="progressbar" aria-valuenow="{{ (! $isTerminal && $noEligibleWork) ? 0 : $percent }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="cp-progress-bar-fill {{ $progressBarClass }}" style="width: {{ (! $isTerminal && $noEligibleWork) ? 0 : max(0, min(100, $percent)) }}%"></div>
                </div>

                @if ($isTerminal)
                    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <span class="font-medium text-gray-500 dark:text-gray-400">Tóm tắt:</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $summaryText }}</span>
                        <span class="text-gray-300 dark:text-gray-600">·</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.progress_snapshot_total', ['total' => $total]) }}</span>
                        <span class="ms-auto truncate text-gray-500 dark:text-gray-400">
                            @if (! empty($overview['last_completed_duration_label']))
                                {{ __('seo-content-ai::filament.projects.progress_last_completed', [
                                    'time' => $overview['last_completed_duration_label'],
                                ]) }}
                            @else
                                {{ __('seo-content-ai::filament.projects.progress_last_completed_none') }}
                            @endif
                        </span>
                    </div>
                @else
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-check-circle class="h-3.5 w-3.5 text-success-600" />
                            {{ __('seo-content-ai::filament.projects.progress_metric_success') }}
                            <strong class="tabular-nums">{{ $completed }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-primary-600" />
                            {{ __('seo-content-ai::filament.projects.progress_metric_running') }}
                            <strong class="tabular-nums">{{ (int) ($overview['processing'] ?? 0) }}</strong>
                        </span>
                        <span @class([
                            'inline-flex items-center gap-1 whitespace-nowrap',
                            'font-semibold text-danger-700 dark:text-danger-400' => $failed > 0,
                        ])>
                            <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 text-danger-600" />
                            {{ __('seo-content-ai::filament.projects.progress_metric_failed') }}
                            <strong class="tabular-nums">{{ $failed }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            <x-heroicon-o-clock class="h-3.5 w-3.5 text-gray-400" />
                            {{ $pendingLabel }}
                            <strong class="tabular-nums">{{ (int) ($overview['pending'] ?? 0) }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                            {{ __('seo-content-ai::filament.projects.progress_metric_skipped') }}
                            <strong class="tabular-nums">{{ $skipped }}</strong>
                        </span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.projects.progress_snapshot_total', ['total' => $total]) }}
                        </span>
                        @if ((int) ($overview['cancelled'] ?? 0) > 0)
                            <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                {{ __('seo-content-ai::filament.projects.progress_metric_cancelled') }}
                                <strong class="tabular-nums">{{ (int) $overview['cancelled'] }}</strong>
                            </span>
                        @endif
                        <span class="ms-auto truncate text-gray-500 dark:text-gray-400">
                            @if (! empty($overview['last_completed_duration_label']))
                                {{ __('seo-content-ai::filament.projects.progress_last_completed', [
                                    'time' => $overview['last_completed_duration_label'],
                                ]) }}
                            @else
                                {{ __('seo-content-ai::filament.projects.progress_last_completed_none') }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Playlist: full width, own vertical scroll --}}
        <div data-cp-playlist-shell>
            <div
                data-cp-playlist-scroll
                x-ref="playlistScroll"
                @scroll.passive="rememberPlaylistScroll()"
            >
                <table class="cp-progress-playlist text-sm">
                    <colgroup>
                        <col class="cp-col-idx" />
                        <col class="cp-col-article" />
                        <col class="cp-col-status" />
                        <col class="cp-col-duration" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('seo-content-ai::filament.projects.progress_col_article') }}</th>
                            <th>{{ __('seo-content-ai::filament.projects.progress_col_status') }}</th>
                            <th>{{ __('seo-content-ai::filament.projects.progress_col_duration') }}</th>
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
                            @endphp
                            <tr @class([
                                'bg-primary-50/80 dark:bg-primary-500/10' => $isCurrent,
                                'hover:bg-gray-50 dark:hover:bg-white/5' => ! $isCurrent,
                            ])>
                                <td class="tabular-nums text-xs text-gray-500">{{ (int) ($row['position'] ?? 0) }}</td>
                                <td>
                                    <div class="flex min-w-0 items-center gap-2">
                                        <div class="h-8 w-8 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                                            @if (! empty($row['thumbnail_url']))
                                                <img src="{{ $row['thumbnail_url'] }}" alt="" class="h-full w-full object-cover" />
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-white" title="{{ $title }}">
                                            @if (! empty($row['article_url']))
                                                <a href="{{ $row['article_url'] }}" class="hover:underline" title="{{ $title }}">{{ $title }}</a>
                                            @else
                                                {{ $title }}
                                            @endif
                                        </div>
                                    </div>
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
                                <td colspan="4" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.progress_playlist_empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
