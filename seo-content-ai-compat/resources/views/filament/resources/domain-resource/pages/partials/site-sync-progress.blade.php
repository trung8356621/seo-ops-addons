@php
    $status = (string) ($siteSyncV2Status ?? '');
    $running = (bool) ($siteSyncV2Running ?? false);
    $stuck = (bool) ($siteSyncV2Stuck ?? false);
    $retryLabel = $siteSyncV2RetryLabel ?? null;
    $retrying = filled($retryLabel);
    $failed = in_array($status, ['failed', 'needs_attention'], true);
    $completed = in_array($status, ['completed', 'completed_with_warnings'], true);
    $canceled = in_array($status, ['canceled', 'cancelled'], true);
    $stopping = (bool) ($siteSyncV2Stopping ?? false);
    $steps = $siteSyncV2Steps ?? [];
    $macroSteps = $siteSyncV3MacroSteps ?? [];
    // Always prefer 3 user macros; never render raw 7/6 step list in default UI.
    $userSteps = $macroSteps !== [] ? $macroSteps : [];
    $stepTotal = count($userSteps);
    $activeStep = null;
    foreach ($userSteps as $row) {
        $st = (string) ($row['status'] ?? 'pending');
        if ($st === 'running' || $st === 'failed') {
            $activeStep = $row;
            break;
        }
    }
    $activeOrder = (int) ($activeStep['order'] ?? 0);
    $activeUserLabel = $activeStep['label'] ?? null;
    $counters = $siteSyncV2Counters ?? [];
    $checked = (int) ($counters['checked'] ?? $siteSyncV2Progress ?? 0);
    $toCheck = (int) ($counters['total_to_check'] ?? $siteSyncV2Total ?? 0);
    $pct = $siteSyncV2Percentage;
    $jobNumber = (int) ($counters['job_number'] ?? 0);
    $showCounts = $toCheck > 0 && ($running || $stuck || $failed);
    $showPanel = $running || $stuck || $failed || $completed || $canceled || $retrying || $userSteps !== [];
    $idleHint = __('seo-content-ai::filament.domain.site_sync_idle_hint');
    $lastSyncedLabel = $siteSyncV2LastActivityLabel
        ?? (filled($siteSyncV2LastProgressAt ?? null) ? (string) $siteSyncV2LastProgressAt : null);

    $title = match (true) {
        $stuck => __('seo-content-ai::filament.domain.site_sync_stuck_title'),
        $retrying && ($running || $stuck) => __('seo-content-ai::filament.domain.site_sync_retrying_title'),
        $failed => __('seo-content-ai::filament.domain.site_sync_failed_title'),
        $completed => __('seo-content-ai::filament.domain.site_sync_completed_title'),
        $canceled => __('seo-content-ai::filament.domain.site_sync_canceled_title'),
        $running && filled($activeUserLabel) => $activeUserLabel,
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
                @elseif ($retrying || $running)
                    <span aria-hidden="true">↻</span>
                @elseif ($stuck)
                    <span aria-hidden="true">⚠</span>
                @endif
                {{ $title }}
            </p>

            @if ($completed)
                <div class="mt-1 space-y-0.5 text-[13px] text-gray-600 dark:text-gray-300">
                    <p>
                        Đồng bộ hoàn tất
                        @if ($checked > 0)
                            · {{ number_format($checked) }} records
                        @endif
                    </p>
                    @if (filled($lastSyncedLabel))
                        <p>Lần cuối: {{ $lastSyncedLabel }}</p>
                    @elseif (!empty($siteSyncV2ElapsedLabel))
                        <p>{{ $siteSyncV2ElapsedLabel }}</p>
                    @endif
                </div>
            @endif

            @if ($canceled && $stopping)
                <p class="mt-1 text-[13px] text-gray-600 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.domain.site_sync_stopping_chunk') }}
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
            <div class="text-[13px] font-semibold tabular-nums text-gray-700 dark:text-gray-200">
                @if ($jobNumber > 0)
                    <span>Đợt {{ $jobNumber }}</span>
                    <span class="mx-1 text-gray-400">·</span>
                @endif
                <span>
                    {{ number_format($checked) }}
                    @if ($toCheck > 0)
                        / {{ number_format($toCheck) }}
                    @endif
                </span>
                @if ($pct !== null)
                    <span class="ml-2 text-gray-500">{{ $pct }}%</span>
                @endif
            </div>
            @if ($pct !== null)
                <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" aria-hidden="true">
                    <div
                        class="h-2 rounded-full {{ $failed ? 'bg-danger-500' : 'bg-primary-600' }}"
                        style="width: {{ max(0, min(100, (int) $pct)) }}%"
                    ></div>
                </div>
            @endif
        @endif

        @if ($failed && filled($siteSyncV2StatusMessage))
            <div class="rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 text-[13px] leading-relaxed text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                <p class="font-semibold">{{ __('seo-content-ai::filament.domain.site_sync_reason') }}</p>
                <p class="mt-0.5">{{ $siteSyncV2StatusMessage }}</p>
            </div>
        @endif

        @if ($userSteps !== [] && ($running || $stuck || $failed || $completed))
            <ol class="site-sync-progress__macro space-y-1" data-macro-count="{{ $stepTotal }}">
                @foreach ($userSteps as $stepRow)
                    @php
                        $st = (string) ($stepRow['status'] ?? 'pending');
                        $active = $st === 'running';
                        $visual = $st === 'failed'
                            ? 'failed'
                            : (($active && $retrying) ? 'retrying' : ($active ? 'active' : (in_array($st, ['completed', 'skipped'], true) ? 'completed' : 'pending')));
                        $mark = match ($visual) {
                            'failed' => '✕',
                            'completed' => '✓',
                            'retrying', 'active' => '↻',
                            default => '○',
                        };
                    @endphp
                    <li
                        class="site-sync-step text-[13px] leading-snug"
                        data-state="{{ $visual }}"
                        data-macro-key="{{ $stepRow['key'] ?? '' }}"
                    >
                        <span @class([
                            'inline-flex items-center gap-2',
                            'font-semibold text-primary-800 dark:text-primary-200' => $visual === 'active' || $visual === 'retrying',
                            'font-medium text-success-800 dark:text-success-400' => $visual === 'completed',
                            'font-medium text-danger-700 dark:text-danger-400' => $visual === 'failed',
                            'text-gray-400 dark:text-gray-500' => $visual === 'pending',
                        ])>
                            <span aria-hidden="true">{{ $mark }}</span>
                            <span>{{ $stepRow['order'] ?? '' }}. {{ $stepRow['label'] ?? '' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
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
        <div class="text-[13px] text-amber-700 dark:text-amber-300">Site chưa bootstrap Site Sync — lần bấm đầu sẽ xem preview rồi xác nhận.</div>
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
            @if (!empty($siteSyncV2RunId))
                <p class="font-mono">run_id: {{ $siteSyncV2RunId }}</p>
            @endif
            @if (!empty($siteSyncV2Phase))
                <p class="font-mono">phase: {{ $siteSyncV2Phase }}</p>
            @endif
            @if (!empty($siteSyncV2Status))
                <p class="font-mono">status: {{ $siteSyncV2Status }}</p>
            @endif
            @if (!empty($siteSyncV2ModeLabel))
                <p>{{ $siteSyncV2ModeLabel }}</p>
            @endif
            @if (! empty($steps))
                <p class="font-semibold text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.domain.site_sync_phases_technical') }}</p>
                <ol class="space-y-0.5 font-mono">
                    @foreach ($steps as $techStep)
                        <li data-phase-key="{{ $techStep['key'] ?? '' }}">
                            {{ $techStep['order'] ?? '' }}. {{ $techStep['key'] ?? '' }}
                            · {{ $techStep['status'] ?? 'pending' }}
                            · {{ $techStep['label'] ?? '' }}
                        </li>
                    @endforeach
                </ol>
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
            @if (!empty($siteSyncV2Substeps) && ($running || $stuck))
                <p class="font-semibold">Substeps</p>
                <ul class="space-y-0.5">
                    @foreach ($siteSyncV2Substeps as $sub)
                        <li>{{ $sub['label'] ?? '' }} · {{ $sub['status'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif
            <p>Điểm SEO giữa các plugin dùng công thức khác nhau và không thể so sánh trực tiếp.</p>
        </div>
    </details>
</div>
