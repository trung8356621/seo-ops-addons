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
                                {{ __('seo-content-ai::filament.domain.site_sync_running_button') }}
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
                                {{ __('seo-content-ai::filament.domain.site_sync_running_button') }}
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
                        outlined
                        size="sm"
                        class="w-full justify-center"
                        wire:click="cancelSiteSyncV2Action"
                        wire:loading.attr="disabled"
                        wire:target="cancelSiteSyncV2Action"
                        wire:confirm="Hủy lần đồng bộ hiện tại? Dữ liệu đã đối soát thành công được giữ."
                    >
                        <span wire:loading.remove wire:target="cancelSiteSyncV2Action">{{ __('seo-content-ai::filament.domain.site_sync_cancel') }}</span>
                        <span wire:loading wire:target="cancelSiteSyncV2Action">{{ __('seo-content-ai::filament.domain.site_sync_canceling') }}</span>
                    </x-filament::button>
                @endif
            </div>
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.site-sync-progress')
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
