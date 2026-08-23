@php
    $status = (string) ($siteSyncV2Status ?? '');
    $running = (bool) ($siteSyncV2Running ?? false);
    $stuck = (bool) ($siteSyncV2Stuck ?? false);
    $retryLabel = $siteSyncV2RetryLabel ?? null;
    $retrying = filled($retryLabel);
    $failed = $status === 'failed';
    $completed = in_array($status, ['completed', 'completed_with_warnings'], true);
    $canceled = in_array($status, ['canceled', 'cancelled'], true);
    $stopping = (bool) ($siteSyncV2Stopping ?? false);
    $steps = $siteSyncV2Steps ?? [];
    $stepTotal = count($steps);
    $phaseLabel = $siteSyncV2PhaseLabel ?? null;
    $activeStep = null;
    foreach ($steps as $row) {
        $st = (string) ($row['status'] ?? 'pending');
        $isActiveStep = $st === 'running'
            || (
                ($siteSyncV2Phase ?? '') === ($row['key'] ?? '')
                && ! in_array($st, ['completed', 'skipped', 'failed'], true)
            );
        if ($isActiveStep) {
            $activeStep = $row;
            break;
        }
    }
    $activeOrder = (int) ($activeStep['order'] ?? 0);
    $counters = $siteSyncV2Counters ?? [];
    $checked = (int) ($counters['checked'] ?? $siteSyncV2Progress ?? 0);
    $toCheck = (int) ($counters['total_to_check'] ?? $siteSyncV2Total ?? 0);
    $pct = $siteSyncV2Percentage;
    $showCounts = $toCheck > 0 && ($running || $stuck || $failed || $completed);
    $showPanel = $running || $stuck || $failed || $completed || $canceled || $retrying || ! empty($steps);
    $idleHint = __('seo-content-ai::filament.domain.site_sync_idle_hint');

    $title = match (true) {
        $stuck => __('seo-content-ai::filament.domain.site_sync_stuck_title'),
        $retrying && ($running || $stuck) => __('seo-content-ai::filament.domain.site_sync_retrying_title'),
        $failed => __('seo-content-ai::filament.domain.site_sync_failed_title'),
        $completed => __('seo-content-ai::filament.domain.site_sync_completed_title'),
        $canceled => __('seo-content-ai::filament.domain.site_sync_canceled_title'),
        $running => __('seo-content-ai::filament.domain.site_sync_running_title'),
        default => null,
    };
@endphp

<div
    class="site-sync-progress space-y-3 text-gray-800 dark:text-gray-200"
    wire:key="site-sync-progress-{{ $siteSyncV2RunId ?? 'idle' }}"
>
    @if (! $showPanel)
        <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $idleHint }}</p>
    @else
        <div>
            <p @class([
                'text-[15px] font-semibold leading-snug',
                'text-amber-800 dark:text-amber-300' => $stuck || $retrying,
                'text-danger-700 dark:text-danger-400' => $failed && ! $stuck,
                'text-success-700 dark:text-success-400' => $completed,
                'text-primary-800 dark:text-primary-200' => $running && ! $stuck && ! $retrying,
            ])>
                @if ($completed)
                    <span aria-hidden="true">✓</span>
                @elseif ($failed)
                    <span aria-hidden="true">✕</span>
                @elseif ($retrying)
                    <span aria-hidden="true">↻</span>
                @elseif ($stuck)
                    <span aria-hidden="true">⚠</span>
                @endif
                {{ $title }}
            </p>

            @if ($canceled && $stopping)
                <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.domain.site_sync_stopping_chunk') }}
                </p>
            @endif

            @if (($running || $stuck || $failed) && ($phaseLabel || $activeOrder > 0))
                <p class="mt-1 text-[14px] font-semibold leading-snug text-primary-700 dark:text-primary-300">
                    @if ($activeOrder > 0 && $stepTotal > 0)
                        {{ __('seo-content-ai::filament.domain.site_sync_step_of', ['current' => $activeOrder, 'total' => $stepTotal]) }}
                        @if ($phaseLabel)
                            · {{ $phaseLabel }}
                        @endif
                    @elseif ($phaseLabel)
                        {{ $phaseLabel }}
                    @endif
                </p>
            @endif

            @if ($retrying)
                <p class="mt-1 text-[13px] font-medium text-amber-800 dark:text-amber-300">{{ $retryLabel }}</p>
            @endif
        </div>

        @if ($stuck)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[13px] leading-relaxed text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="status">
                <p class="font-semibold">⚠ {{ __('seo-content-ai::filament.domain.site_sync_stuck_title') }}</p>
                @if (!empty($siteSyncV2LastActivityLabel))
                    <p class="mt-0.5">{{ $siteSyncV2LastActivityLabel }}</p>
                @endif
            </div>
        @endif

        @if ($showCounts)
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-[13px] font-semibold tabular-nums">
                    <span>
                        {{ __('seo-content-ai::filament.domain.site_sync_step_progress', [
                            'current' => number_format($checked),
                            'total' => number_format($toCheck),
                        ]) }}
                    </span>
                    @if ($pct !== null)
                        <span>{{ $pct }}%</span>
                    @endif
                </div>
                @if ($pct !== null)
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" aria-hidden="true">
                        <div
                            class="h-2 rounded-full {{ $completed ? 'bg-success-500' : ($failed ? 'bg-danger-500' : 'bg-primary-600') }}"
                            style="width: {{ max(0, min(100, (int) $pct)) }}%"
                        ></div>
                    </div>
                @endif
                @if ($completed)
                    <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-300">
                        {{ __('seo-content-ai::filament.domain.site_sync_records_checked', ['count' => number_format($checked)]) }}
                    </p>
                @endif
            </div>
        @endif

        @if ($failed && filled($siteSyncV2StatusMessage))
            <div class="rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 text-[13px] leading-relaxed text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                <p class="font-semibold">{{ __('seo-content-ai::filament.domain.site_sync_reason') }}</p>
                <p class="mt-0.5">{{ $siteSyncV2StatusMessage }}</p>
            </div>
        @endif

        @if (! empty($steps) && ($running || $stuck || $failed || $completed))
            <details class="site-sync-progress__steps" @if (! $completed) open @endif>
                <summary class="cursor-pointer text-[13px] font-semibold text-gray-800 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.domain.site_sync_steps_heading') }}
                </summary>
                <ol class="mt-2 space-y-1.5">
                    @foreach ($steps as $stepRow)
                        @php
                            $st = (string) ($stepRow['status'] ?? 'pending');
                            $active = $st === 'running'
                                || (
                                    ($siteSyncV2Phase ?? '') === ($stepRow['key'] ?? '')
                                    && ! in_array($st, ['completed', 'skipped', 'failed'], true)
                                );
                            $visual = $st === 'failed'
                                ? 'failed'
                                : (($active && $retrying) ? 'retrying' : ($active ? 'active' : (in_array($st, ['completed', 'skipped'], true) ? 'completed' : 'pending')));
                            $mark = match ($visual) {
                                'failed' => '✕',
                                'completed' => '✓',
                                'retrying' => '↻',
                                'active' => '→',
                                default => '○',
                            };
                            $stateLabel = match ($visual) {
                                'failed' => __('seo-content-ai::filament.domain.site_sync_failed'),
                                'completed' => __('seo-content-ai::filament.domain.site_sync_completed'),
                                'retrying' => __('seo-content-ai::filament.domain.site_sync_retrying'),
                                'active' => __('seo-content-ai::filament.domain.site_sync_running_title'),
                                default => __('seo-content-ai::filament.domain.site_sync_pending'),
                            };
                        @endphp
                        <li
                            class="site-sync-step rounded-md px-2 py-1.5 leading-relaxed {{ $visual === 'active' ? 'border-l-4 border-primary-600 bg-primary-100 dark:border-primary-400 dark:bg-primary-500/20' : '' }} {{ $visual === 'retrying' ? 'border-l-4 border-warning-500 bg-warning-50 dark:border-warning-400 dark:bg-warning-500/15' : '' }}"
                            data-state="{{ $visual }}"
                        >
                            <span class="inline-flex items-start gap-2 text-[13px] {{ $visual === 'active' ? 'font-semibold text-primary-800 dark:text-primary-200' : '' }} {{ $visual === 'completed' ? 'font-medium text-success-800 dark:text-success-400' : '' }} {{ $visual === 'failed' ? 'font-medium text-danger-700 dark:text-danger-400' : '' }} {{ $visual === 'pending' ? 'text-gray-400 dark:text-gray-500' : '' }} {{ $visual === 'retrying' ? 'font-semibold text-warning-800 dark:text-warning-200' : '' }}">
                                <span @class([
                                    'mt-0.5 w-4 shrink-0 text-center',
                                    'text-success-600 dark:text-success-400' => $visual === 'completed',
                                    'text-primary-700 dark:text-primary-300' => $visual === 'active',
                                    'text-danger-600 dark:text-danger-400' => $visual === 'failed',
                                    'text-warning-600 dark:text-warning-400' => $visual === 'retrying',
                                    'text-gray-400 dark:text-gray-500' => $visual === 'pending',
                                ]) aria-hidden="true">{{ $mark }}</span>
                                <span>
                                    <span class="sr-only">{{ $stateLabel }}: </span>
                                    {{ __('seo-content-ai::filament.domain.site_sync_step_of', ['current' => $stepRow['order'] ?? '', 'total' => $stepTotal]) }}
                                    · {{ $stepRow['label'] ?? '' }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endif

        @if ($showCounts)
            <div class="flex flex-wrap gap-2 text-[13px] leading-relaxed">
                <span class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-2 py-1 font-medium text-primary-800 dark:bg-primary-500/15 dark:text-primary-200">
                    {{ __('seo-content-ai::filament.domain.site_sync_changed') }}
                    <span class="tabular-nums font-semibold">{{ number_format((int) ($counters['updated'] ?? 0)) }}</span>
                </span>
                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                    {{ __('seo-content-ai::filament.domain.site_sync_unchanged') }}
                    <span class="tabular-nums font-semibold">{{ number_format((int) ($counters['unchanged'] ?? 0)) }}</span>
                </span>
                <span @class([
                    'inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium',
                    'bg-danger-50 text-danger-800 dark:bg-danger-500/15 dark:text-danger-200' => (int) ($counters['failed'] ?? 0) > 0,
                    'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200' => (int) ($counters['failed'] ?? 0) === 0,
                ])>
                    {{ __('seo-content-ai::filament.domain.site_sync_failed_items') }}
                    <span class="tabular-nums font-semibold">{{ number_format((int) ($counters['failed'] ?? 0)) }}</span>
                </span>
            </div>
        @endif

        <div class="space-y-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            @if (!empty($siteSyncV2LastActivityLabel) && ($running || $stuck))
                <p>{{ $siteSyncV2LastActivityLabel }}</p>
            @elseif (!empty($siteSyncV2LastProgressAt) && ($running || $stuck))
                <p>{{ __('seo-content-ai::filament.domain.site_sync_last_activity') }} · {{ $siteSyncV2LastProgressAt }}</p>
            @endif
            @if (!empty($siteSyncV2ElapsedLabel) && ($running || $stuck || $completed))
                <p>{{ $siteSyncV2ElapsedLabel }}</p>
            @endif
        </div>

        @if (!empty($siteSyncV2Substeps) && ($running || $stuck))
            <details class="text-xs text-gray-500 dark:text-gray-400">
                <summary class="cursor-pointer font-medium">{{ __('seo-content-ai::filament.domain.site_sync_substeps') }}</summary>
                <ul class="mt-1 space-y-1">
                    @foreach ($siteSyncV2Substeps as $sub)
                        @php
                            $ss = (string) ($sub['status'] ?? 'pending');
                            $sm = $ss === 'completed' ? '✓' : ($ss === 'active' || $ss === 'running' ? '→' : '○');
                        @endphp
                        <li>
                            {{ $sm }} {{ $sub['label'] ?? '' }}
                            @if (isset($sub['current']))
                                {{ number_format((int) $sub['current']) }}@if(isset($sub['total']) && $sub['total']) / {{ number_format((int) $sub['total']) }}@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($running || $stuck || $failed)
            <button
                type="button"
                class="text-[13px] font-semibold text-primary-700 hover:underline dark:text-primary-300"
                wire:click="refreshSiteSyncV2Progress"
                wire:loading.attr="disabled"
                wire:target="refreshSiteSyncV2Progress"
            >
                <span wire:loading.remove wire:target="refreshSiteSyncV2Progress">{{ __('seo-content-ai::filament.domain.site_sync_check_status') }}</span>
                <span wire:loading wire:target="refreshSiteSyncV2Progress">{{ __('seo-content-ai::filament.domain.site_sync_checking_status') }}</span>
            </button>
        @endif
    @endif

    @if ($siteSyncNeedsBootstrap ?? false)
        <div class="text-[13px] text-amber-700 dark:text-amber-300">Site chưa bootstrap Site Sync V2 — lần bấm đầu sẽ xem preview rồi xác nhận.</div>
    @endif
    @if (!empty($siteSyncBootstrapPreview))
        <div
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[13px] dark:border-amber-500/30 dark:bg-amber-500/10"
            x-data="{ open: true }"
            x-on:open-site-sync-bootstrap-preview.window="open = true"
            x-show="open"
        >
            <div class="font-semibold">Xác nhận đồng bộ lần đầu</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">
                ~{{ $siteSyncBootstrapPreview['articles_remote'] ?? 0 }} bài remote ·
                {{ $siteSyncBootstrapPreview['estimated_batches'] ?? '?' }} batch ·
                Provider: {{ $siteSyncBootstrapPreview['provider_label'] ?? 'Không phát hiện' }}
            </div>
            @foreach (($siteSyncBootstrapPreview['warnings'] ?? []) as $w)
                <div class="mt-1 text-amber-800 dark:text-amber-300">{{ $w }}</div>
            @endforeach
            <div class="mt-2 flex gap-2">
                <x-filament::button size="xs" color="success" wire:click="confirmSiteSyncBootstrapAction">
                    Xác nhận bootstrap
                </x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="cancelSiteSyncBootstrapPreview" @click="open = false">
                    Đóng
                </x-filament::button>
            </div>
        </div>
    @endif
    @foreach (($siteSyncV2Warnings ?? []) as $warning)
        <div class="text-[13px] text-amber-700 dark:text-amber-300">{{ $warning }}</div>
    @endforeach

    <details class="site-sync-progress__tech text-xs text-gray-500 dark:text-gray-400">
        <summary class="cursor-pointer font-medium">{{ __('seo-content-ai::filament.domain.site_sync_technical_details') }}</summary>
        <div class="mt-1.5 space-y-1 leading-relaxed">
            @if (!empty($siteSyncV2ModeLabel) && ($running || $stuck))
                <p>{{ $siteSyncV2ModeLabel }}</p>
            @endif
            @if (!empty($siteSyncV2SourceChips ?? []))
                <p>
                    @foreach ($siteSyncV2SourceChips as $i => $chip)
                        @if ($i > 0) · @endif{{ $chip['label'] ?? '' }}
                    @endforeach
                </p>
            @elseif (!empty($siteSyncV2Sources['provider'] ?? null))
                <p>Provider: {{ $siteSyncV2Sources['provider'] }}</p>
            @endif
            <p>Điểm SEO giữa các plugin dùng công thức khác nhau và không thể so sánh trực tiếp.</p>
        </div>
    </details>
</div>
