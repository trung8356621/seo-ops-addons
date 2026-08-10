@props([
    'showTest' => false,
])

@php
    $v2Ui = method_exists($this, 'siteSyncV2UiEnabled') ? $this->siteSyncV2UiEnabled() : true;
    $legacyVisible = method_exists($this, 'siteSyncV2LegacyVisible') ? $this->siteSyncV2LegacyVisible() : true;
    $syncDisabled = $incrementalSyncRunning || $metadataSyncRunning || $keywordResyncRunning || ($siteSyncV2Running ?? false);
    $sources = $siteSyncV2Sources ?? [];
    $forceFull = (bool) ($siteSyncForceFull ?? false);
    $useResume = ! $forceFull && ($siteSyncV2Resumable ?? false) && ! ($siteSyncV2Running ?? false);
    $primaryClick = $forceFull
        ? 'runForceFullSiteSyncAction'
        : ($useResume ? 'resumeSiteSyncV2Action' : 'runSiteSyncV2Action');
    $forceFullConfirm = 'Đồng bộ lại toàn bộ website? Hệ thống sẽ tải và xử lý lại toàn bộ bài viết, trang và sản phẩm từ WordPress, kể cả các dữ liệu đã đồng bộ và không thay đổi. Quá trình có thể mất nhiều thời gian nhưng không xóa dữ liệu thủ công.';
@endphp

<div class="seo-sync-actions divide-y divide-gray-200 dark:divide-white/10">
    @if ($v2Ui)
        <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-start sm:gap-4">
            <div class="space-y-2">
                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input
                        type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                        wire:model.live="siteSyncForceFull"
                        @disabled($siteSyncV2Running ?? false)
                    />
                    <span>
                        <span class="font-medium">Đồng bộ lại toàn bộ website</span>
                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                            Tải và kiểm tra lại toàn bộ bài viết, trang và sản phẩm từ WordPress, bất kể dữ liệu hiện có.
                        </span>
                    </span>
                </label>

                @if ($forceFull)
                    <x-filament::button
                        type="button"
                        color="success"
                        icon="heroicon-o-arrow-path"
                        class="w-full justify-center"
                        wire:click="runForceFullSiteSyncAction"
                        wire:confirm="{{ $forceFullConfirm }}"
                        wire:loading.attr="disabled"
                        wire:target="runForceFullSiteSyncAction,cancelSiteSyncV2Action"
                        :disabled="$syncDisabled"
                    >
                        <span wire:loading.remove wire:target="runForceFullSiteSyncAction">
                            @if ($siteSyncV2Running ?? false)
                                Đang đồng bộ lại toàn bộ…
                            @else
                                Đồng bộ lại toàn bộ website
                            @endif
                        </span>
                        <span wire:loading wire:target="runForceFullSiteSyncAction" class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            Đang xếp hàng…
                        </span>
                    </x-filament::button>
                @else
                    <x-filament::button
                        type="button"
                        color="success"
                        icon="heroicon-o-arrow-path"
                        class="w-full justify-center"
                        wire:click="{{ $primaryClick }}"
                        wire:loading.attr="disabled"
                        wire:target="runSiteSyncV2Action,resumeSiteSyncV2Action,cancelSiteSyncV2Action"
                        :disabled="$syncDisabled && ! $useResume"
                    >
                        <span wire:loading.remove wire:target="runSiteSyncV2Action,resumeSiteSyncV2Action">
                            @if ($siteSyncV2Running ?? false)
                                Đang đồng bộ & kiểm tra…
                            @elseif ($useResume)
                                Tiếp tục đồng bộ & kiểm tra
                            @else
                                Đồng bộ & kiểm tra website
                            @endif
                        </span>
                        <span wire:loading wire:target="runSiteSyncV2Action,resumeSiteSyncV2Action" class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            Đang xếp hàng…
                        </span>
                    </x-filament::button>
                @endif
                @if (($siteSyncV2Running ?? false) || ($siteSyncV2Cancellable ?? false))
                    <x-filament::button
                        type="button"
                        color="danger"
                        class="w-full justify-center"
                        wire:click="cancelSiteSyncV2Action"
                        wire:loading.attr="disabled"
                        wire:target="cancelSiteSyncV2Action"
                        wire:confirm="Hủy lần đồng bộ hiện tại? Dữ liệu đã đối soát thành công được giữ."
                    >
                        Hủy đồng bộ
                    </x-filament::button>
                @endif
            </div>
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <div @class([
                    'text-danger-600 dark:text-danger-400 font-medium' => ($siteSyncV2Status ?? '') === 'failed',
                ])>{{ $siteSyncV2StatusMessage ?: 'Đồng bộ thay đổi từ website và kiểm tra liên kết / từ khóa / điểm SEO.' }}</div>
                @if (($siteSyncV2Status ?? '') === 'failed')
                    <div class="text-xs text-danger-600 dark:text-danger-400">
                        Run đã dừng ở bước lỗi. Nút «Tiếp tục» thử lại bước đó — không phải đang chạy.
                    </div>
                @endif
                @if (!empty($siteSyncV2ModeLabel) && (($siteSyncV2Running ?? false) || ($siteSyncV2Stuck ?? false)))
                    <div class="text-xs font-medium text-primary-700 dark:text-primary-300">Chế độ: {{ $siteSyncV2ModeLabel }}</div>
                @endif
                @if (!empty($siteSyncV2PhaseLabel) && (($siteSyncV2Running ?? false) || ($siteSyncV2Stuck ?? false) || ($siteSyncV2Status ?? '') === 'failed'))
                    <div class="text-xs opacity-90">Bước hiện tại: {{ $siteSyncV2PhaseLabel }}</div>
                @endif
                @if (!empty($siteSyncV2LastProgressAt) && (($siteSyncV2Running ?? false) || ($siteSyncV2Stuck ?? false)))
                    <div class="text-xs opacity-70">Tiến trình gần nhất: {{ $siteSyncV2LastProgressAt }}</div>
                @endif
                @if (($siteSyncV2Stuck ?? false))
                    <div class="text-xs font-medium text-amber-700 dark:text-amber-300">
                        Run kẹt — dùng «Tiếp tục» để reclaim theo policy hiện có (không watchdog mới).
                    </div>
                @endif
                @if (($siteSyncV2Total ?? 0) > 0 && (($siteSyncV2Running ?? false) || ($siteSyncV2Stuck ?? false)))
                    @php
                        $ffCounters = $siteSyncV2Counters ?? [];
                        $isForceProgress = !empty($siteSyncV2ModeLabel);
                    @endphp
                    @if ($isForceProgress)
                        <div class="space-y-0.5 text-xs opacity-90">
                            <div>Tổng cần kiểm tra: {{ number_format((int) ($ffCounters['total_to_check'] ?? $siteSyncV2Total)) }}</div>
                            <div>Đã kiểm tra: {{ number_format((int) ($ffCounters['checked'] ?? $siteSyncV2Progress)) }}</div>
                            <div>Có thay đổi: {{ number_format((int) ($ffCounters['updated'] ?? 0)) }}</div>
                            <div>Không thay đổi: {{ number_format((int) ($ffCounters['unchanged'] ?? 0)) }}</div>
                            <div>Thất bại: {{ number_format((int) ($ffCounters['failed'] ?? 0)) }}</div>
                        </div>
                    @else
                        <div class="text-xs opacity-80">Tiến độ {{ $siteSyncV2Progress }}/{{ $siteSyncV2Total }}</div>
                    @endif
                @endif
                @if (!empty($siteSyncV2SourceChips ?? []))
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        @foreach ($siteSyncV2SourceChips as $chip)
                            <span class="rounded-md bg-gray-100 px-2 py-0.5 dark:bg-white/10">{{ $chip['label'] ?? '' }}</span>
                        @endforeach
                    </div>
                @elseif (!empty($sources))
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        @if (!empty($sources['provider']))
                            <span class="rounded-md bg-gray-100 px-2 py-0.5 dark:bg-white/10">Provider: {{ $sources['provider'] }}</span>
                        @endif
                    </div>
                @endif
                @if ($siteSyncNeedsBootstrap ?? false)
                    <div class="text-xs text-amber-600 dark:text-amber-400">Site chưa bootstrap Site Sync V2 — lần bấm đầu sẽ xem preview rồi xác nhận.</div>
                @endif
                @if (!empty($siteSyncBootstrapPreview))
                    <div
                        class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-500/30 dark:bg-amber-500/10"
                        x-data="{ open: true }"
                        x-on:open-site-sync-bootstrap-preview.window="open = true"
                        x-show="open"
                    >
                        <div class="font-medium">Xác nhận đồng bộ lần đầu</div>
                        <div class="mt-1 opacity-90">
                            ~{{ $siteSyncBootstrapPreview['articles_remote'] ?? 0 }} bài remote ·
                            {{ $siteSyncBootstrapPreview['estimated_batches'] ?? '?' }} batch ·
                            Provider: {{ $siteSyncBootstrapPreview['provider_label'] ?? 'Không phát hiện' }}
                        </div>
                        @foreach (($siteSyncBootstrapPreview['warnings'] ?? []) as $w)
                            <div class="mt-1 text-amber-700 dark:text-amber-300">{{ $w }}</div>
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
                    <div class="text-xs text-amber-600 dark:text-amber-400">{{ $warning }}</div>
                @endforeach
                <div class="text-xs text-amber-700 dark:text-amber-300">
                    Điểm SEO giữa các plugin dùng công thức khác nhau và không thể so sánh trực tiếp.
                </div>
            </div>
        </div>
    @endif

    @if ($legacyVisible)
    <div class="py-2 text-xs font-medium uppercase tracking-wide text-gray-400">
        Legacy sync (cutover / dual-run)
    </div>
    {{-- Đồng bộ bổ sung --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="success"
                icon="heroicon-o-arrow-down-tray"
                class="w-full justify-center"
                wire:click="runIncrementalSyncAction"
                wire:loading.attr="disabled"
                wire:target="runIncrementalSyncAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runIncrementalSyncAction">
                    @if ($incrementalSyncRunning)
                        {{ __('seo-content-ai::filament.domain.sync_incremental_running') }}
                    @elseif ($incrementalSyncResumable)
                        {{ __('seo-content-ai::filament.domain.sync_incremental_resume') }}
                    @else
                        {{ __('seo-content-ai::filament.domain.sync_incremental') }}
                    @endif
                </span>
                <span wire:loading wire:target="runIncrementalSyncAction">
                    {{ __('seo-content-ai::filament.domain.sync_incremental_preparing') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $incrementalSyncStatus,
                'message' => $incrementalSyncStatusMessage,
                'loadingTarget' => 'runIncrementalSyncAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.sync_incremental_preparing'),
            ])
        </div>
    </div>

    {{-- Cập nhật thành phần bài --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="info"
                icon="heroicon-o-arrow-path-rounded-square"
                class="w-full justify-center"
                wire:click="runMetadataResyncAction"
                wire:confirm="{{ __('seo-content-ai::filament.domain.sync_metadata_confirm') }}"
                wire:loading.attr="disabled"
                wire:target="runMetadataResyncAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runMetadataResyncAction">
                    @if ($metadataSyncRunning)
                        {{ __('seo-content-ai::filament.domain.sync_metadata_running') }}
                    @elseif ($metadataSyncResumable)
                        {{ __('seo-content-ai::filament.domain.sync_metadata_resume') }}
                    @else
                        {{ __('seo-content-ai::filament.domain.sync_metadata') }}
                    @endif
                </span>
                <span wire:loading wire:target="runMetadataResyncAction">
                    {{ __('seo-content-ai::filament.domain.sync_metadata_preparing') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $metadataSyncStatus,
                'message' => $metadataSyncStatusMessage,
                'loadingTarget' => 'runMetadataResyncAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.sync_metadata_preparing'),
            ])
        </div>
    </div>

    {{-- Cào lại keywords --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="danger"
                icon="heroicon-o-arrow-path"
                class="w-full justify-center"
                wire:click="runRescrapeKeywordsAction"
                wire:confirm="{{ __('seo-content-ai::filament.keyword.resync_linked_confirm') }}"
                wire:loading.attr="disabled"
                wire:target="runRescrapeKeywordsAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runRescrapeKeywordsAction">
                    @if ($keywordResyncRunning)
                        {{ __('seo-content-ai::filament.keyword.resync_linked_running') }}
                    @else
                        {{ __('seo-content-ai::filament.keyword.resync_linked') }}
                    @endif
                </span>
                <span wire:loading wire:target="runRescrapeKeywordsAction">
                    {{ __('seo-content-ai::filament.keyword.resync_linked_dispatching') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $keywordResyncStatus,
                'message' => $keywordResyncStatusMessage,
                'loadingTarget' => 'runRescrapeKeywordsAction',
                'loadingLabel' => __('seo-content-ai::filament.keyword.resync_linked_dispatching'),
            ])
        </div>
    </div>

    {{-- Kiểm tra link chết --}}
    <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
        <div>
            <x-filament::button
                type="button"
                color="warning"
                icon="heroicon-o-link-slash"
                class="w-full justify-center"
                wire:click="runAuditLinkStatusAction"
                wire:loading.attr="disabled"
                wire:target="runAuditLinkStatusAction"
                :disabled="$syncDisabled"
            >
                <span wire:loading.remove wire:target="runAuditLinkStatusAction">
                    {{ __('seo-content-ai::filament.domain.audit_link_status') }}
                </span>
                <span wire:loading wire:target="runAuditLinkStatusAction">
                    {{ __('seo-content-ai::filament.domain.audit_link_status_dispatching') }}
                </span>
            </x-filament::button>
        </div>
        <div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-action-status', [
                'status' => $auditLinkStatus,
                'message' => $auditLinkStatusMessage,
                'loadingTarget' => 'runAuditLinkStatusAction',
                'loadingLabel' => __('seo-content-ai::filament.domain.audit_link_status_dispatching'),
            ])
        </div>
    </div>
    @endif

    @if ($showTest)
        {{-- Advanced: re-score all — admin only, confirmation required --}}
        <div class="grid grid-cols-1 gap-2 border-t border-gray-200 py-3 dark:border-gray-700 sm:grid-cols-2 sm:items-center sm:gap-4">
            <div class="space-y-1">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Advanced</div>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-arrow-path-rounded-square"
                    class="w-full justify-center"
                    wire:click="runRequeueAllSeoScoringAction"
                    wire:confirm="Chấm lại toàn bộ bài viết? Hệ thống sẽ rebuild Workspace score cho mọi bài đủ điều kiện — không ghi đè provider score. Quá trình có thể lâu."
                    wire:loading.attr="disabled"
                    wire:target="runRequeueAllSeoScoringAction"
                    :disabled="$syncDisabled"
                >
                    <span wire:loading.remove wire:target="runRequeueAllSeoScoringAction">
                        Chấm lại toàn bộ bài viết
                    </span>
                    <span wire:loading wire:target="runRequeueAllSeoScoringAction">
                        {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                    </span>
                </x-filament::button>
                @if (($this->getSeoScoringProgress()['failed'] ?? 0) > 0)
                    <x-filament::button
                        type="button"
                        color="warning"
                        icon="heroicon-o-arrow-path"
                        class="w-full justify-center"
                        wire:click="runRetryFailedSeoScoringAction"
                        wire:loading.attr="disabled"
                        wire:target="runRetryFailedSeoScoringAction"
                        :disabled="$syncDisabled"
                    >
                        <span wire:loading.remove wire:target="runRetryFailedSeoScoringAction">
                            {{ __('seo-content-ai::filament.domain.seo_scoring_retry_failed') }}
                        </span>
                        <span wire:loading wire:target="runRetryFailedSeoScoringAction">
                            {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                        </span>
                    </x-filament::button>
                @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                Chấm SEO missing/stale đã gắn vào «Đồng bộ & kiểm tra website». Advanced chỉ dùng khi cần rebuild toàn bộ Workspace score.
            </div>
        </div>
    @endif

    @if ($showTest)
        <div class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-2 sm:items-center sm:gap-4">
            <div>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-bug-ant"
                    class="w-full justify-center"
                    wire:click="mountAction('test_sync_data')"
                    wire:loading.attr="disabled"
                    wire:target="runIncrementalSyncAction, runMetadataResyncAction, mountAction('test_sync_data')"
                    :disabled="$incrementalSyncRunning || $metadataSyncRunning"
                >
                    {{ __('seo-content-ai::filament.domain.test_sync_debug') }}
                </x-filament::button>
            </div>
            <div class="min-h-[2.75rem] flex items-center sm:justify-end text-sm text-gray-500 dark:text-gray-400 sm:text-right">
                {{ __('seo-content-ai::filament.domain.sync_action_status_ready') }}
            </div>
        </div>
    @endif
</div>
