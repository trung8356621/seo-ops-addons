@props([
    'showTest' => false,
])

@php
    $v2Ui = method_exists($this, 'siteSyncV2UiEnabled') ? $this->siteSyncV2UiEnabled() : true;
    $legacyVisible = method_exists($this, 'siteSyncV2LegacyVisible') ? $this->siteSyncV2LegacyVisible() : false;
    $syncDisabled = $incrementalSyncRunning || $metadataSyncRunning || $keywordResyncRunning || ($siteSyncV2Running ?? false);
    $useResume = ($siteSyncV2Resumable ?? false) && ! ($siteSyncV2Running ?? false);
@endphp

<div class="seo-sync-actions">
    @if ($v2Ui)
        <div class="seo-sync-actions__primary space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button
                    type="button"
                    color="success"
                    icon="heroicon-o-arrow-path"
                    wire:click="{{ $useResume ? 'resumeSiteSyncV2Action' : 'openSiteSyncPreflight' }}"
                    wire:loading.attr="disabled"
                    wire:target="openSiteSyncPreflight,runSiteSyncV2Action,resumeSiteSyncV2Action,cancelSiteSyncV2Action"
                    :disabled="$syncDisabled && ! $useResume"
                >
                    <span wire:loading.remove wire:target="openSiteSyncPreflight,runSiteSyncV2Action,resumeSiteSyncV2Action">
                        @if ($siteSyncV2Running ?? false)
                            {{ __('seo-content-ai::filament.domain.site_sync_running_button') }}
                        @elseif ($useResume)
                            Tiếp tục đồng bộ & kiểm tra
                        @else
                            Đồng bộ & kiểm tra website
                        @endif
                    </span>
                    <span wire:loading wire:target="openSiteSyncPreflight,runSiteSyncV2Action,resumeSiteSyncV2Action" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        Đang kiểm tra…
                    </span>
                </x-filament::button>

                @if (($siteSyncV2Running ?? false) || ($siteSyncV2Cancellable ?? false))
                    <x-filament::button
                        type="button"
                        color="danger"
                        outlined
                        size="sm"
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

            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.site-sync-preflight-modal')
            @include('seo-content-ai::filament.resources.domain-resource.pages.partials.site-sync-progress')
        </div>
    @endif

    @if ($legacyVisible)
    <details class="seo-sync-actions__legacy mt-4 text-[12px] text-gray-500 dark:text-gray-400">
        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Legacy sync (cutover / dual-run)</summary>
        <div class="mt-2 space-y-3 divide-y divide-gray-200 dark:divide-white/10">
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
        </div>
    </details>
    @endif
</div>
