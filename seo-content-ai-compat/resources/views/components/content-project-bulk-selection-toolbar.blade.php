@props([
    'selectedCount' => 0,
    'canDebugLifecycle' => false,
    'variant' => 'content_project',
    'timezoneLabel' => null,
])

@if ((int) $selectedCount > 0 && \Omnichannel\Addons\Seo\Support\SeoAccessControl::canManageContentProjectWorkflow())
    @php
        $tzNote = $timezoneLabel ?: \Omnichannel\Addons\Content\Support\SystemDateTime::timezoneChip();
    @endphp
    <div
        {{ $attributes->class([
            'mt-3 flex flex-col gap-2 rounded-lg border border-primary-200/80 bg-primary-50/50 p-3 dark:border-primary-500/30 dark:bg-primary-500/10',
            'pq-bulk-toolbar' => $variant === 'publishing_queue',
        ]) }}
        role="toolbar"
        aria-label="Bulk selection actions"
        @if ($variant === 'publishing_queue')
            x-data="{
                publishOpen: false,
                scheduleOpen: false,
                moreOpen: false,
                customOpen: false,
                customAt: '',
                closeAll() { this.publishOpen = false; this.scheduleOpen = false; this.moreOpen = false; },
            }"
        @endif
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ (int) $selectedCount }} đã chọn
            </span>
            <button
                type="button"
                wire:click="clearSelection"
                class="text-xs font-semibold text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-300"
            >
                {{ __('seo-content-ai::filament.projects.queue_clear_selection') }}
            </button>
        </div>

        @if ($variant === 'publishing_queue')
            <div class="pq-bulk-actions flex flex-wrap gap-2">
                {{-- Publish group --}}
                <div class="relative">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm inline-flex items-center gap-1"
                        @click="closeAll(); publishOpen = !publishOpen"
                        :aria-expanded="publishOpen.toString()"
                    >
                        Xuất bản
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                    </button>
                    <div
                        x-show="publishOpen"
                        x-cloak
                        @click.outside="publishOpen = false"
                        class="absolute left-0 z-40 mt-1 min-w-[14rem] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                        role="menu"
                    >
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            wire:click="bulkPublishNow"
                            wire:confirm="Xuất bản ngay các bài đã chọn?"
                            wire:loading.attr="disabled"
                            wire:target="bulkPublishNow"
                            @click="publishOpen = false"
                        >Xuất bản ngay</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            wire:click="bulkRetryPublish"
                            @click="publishOpen = false"
                        >Thử lại ngay</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            wire:click="bulkReobserveWordPressStatus"
                            wire:loading.attr="disabled"
                            wire:target="bulkReobserveWordPressStatus"
                            @click="publishOpen = false"
                        >Kiểm tra lại trạng thái</button>
                    </div>
                </div>

                {{-- Schedule group --}}
                <div class="relative">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-gray fi-size-sm inline-flex items-center gap-1"
                        @click="closeAll(); scheduleOpen = !scheduleOpen"
                        :aria-expanded="scheduleOpen.toString()"
                    >
                        Lịch xuất bản
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                    </button>
                    <div
                        x-show="scheduleOpen"
                        x-cloak
                        @click.outside="scheduleOpen = false"
                        class="absolute left-0 z-40 mt-1 min-w-[16rem] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                        role="menu"
                    >
                        <p class="px-3 py-1.5 text-[11px] text-gray-500">Timezone: {{ $tzNote }}</p>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" wire:click="bulkScheduleInMinutes(5)" @click="scheduleOpen = false">Sau 5 phút</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" wire:click="bulkScheduleInMinutes(60)" @click="scheduleOpen = false">Sau 1 giờ</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" wire:click="bulkScheduleTomorrowMorning" @click="scheduleOpen = false">Ngày mai lúc 09:00</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="scheduleOpen = false; customOpen = true">Chọn ngày giờ…</button>
                        <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" wire:click="bulkUnschedule" @click="scheduleOpen = false">Hủy lịch</button>
                    </div>
                </div>

                {{-- More group --}}
                <div class="relative">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-gray fi-size-sm inline-flex items-center gap-1"
                        @click="closeAll(); moreOpen = !moreOpen"
                        :aria-expanded="moreOpen.toString()"
                    >
                        Thêm
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                    </button>
                    <div
                        x-show="moreOpen"
                        x-cloak
                        @click.outside="moreOpen = false"
                        class="absolute left-0 z-40 mt-1 min-w-[16rem] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                        role="menu"
                    >
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm text-danger-700 hover:bg-gray-50 dark:text-danger-300 dark:hover:bg-gray-800"
                            wire:click="bulkCancelPublish"
                            wire:confirm="Bỏ các bài đã chọn khỏi Publishing Queue?"
                            @click="moreOpen = false"
                        >Bỏ khỏi Publishing Queue</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            wire:click="bulkReturn"
                            wire:confirm="Trả các bài đã chọn về Content Project?"
                            @click="moreOpen = false"
                        >Trả về Content Project</button>
                    </div>
                </div>
            </div>

            {{-- Custom datetime popover --}}
            <div x-show="customOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="w-full max-w-sm rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="customOpen = false">
                    <h3 class="mb-1 text-sm font-semibold">Chọn ngày giờ</h3>
                    <p class="mb-3 text-xs text-gray-500">Timezone: {{ $tzNote }}</p>
                    <input
                        type="datetime-local"
                        x-model="customAt"
                        class="mb-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                    />
                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-sm" @click="customOpen = false">Đóng</button>
                        <button
                            type="button"
                            class="fi-btn fi-btn-color-primary fi-size-sm"
                            @click="if (customAt) { $wire.bulkSchedule(customAt); customOpen = false; }"
                        >Áp dụng</button>
                    </div>
                </div>
            </div>
        @else
            <div class="flex flex-wrap gap-2">
                <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Content</span>
                <button type="button" wire:click="generateSelected" wire:loading.attr="disabled" wire:target="generateSelected" class="fi-btn fi-btn-color-success fi-size-sm">
                    <span wire:loading.remove wire:target="generateSelected">Generate working items</span>
                    <span wire:loading wire:target="generateSelected" class="inline-flex items-center gap-1"><x-filament::loading-indicator class="h-4 w-4" />…</span>
                </button>
                <button type="button" wire:click="bulkRegenOutline" wire:confirm="Regenerate outline for selection?" class="fi-btn fi-btn-color-gray fi-size-sm">Regen outline</button>
                <button type="button" wire:click="bulkRegenArticle" wire:confirm="Regenerate article for selection?" class="fi-btn fi-btn-color-gray fi-size-sm">Regen article</button>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Review</span>
                <button type="button" wire:click="startReviewSelected" class="fi-btn fi-btn-color-warning fi-size-sm">Start review</button>
                <button type="button" wire:click="approveSelected" class="fi-btn fi-btn-color-success fi-size-sm">Approve</button>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:w-auto sm:self-center">Publishing Queue</span>
                <button
                    type="button"
                    wire:click="bulkSendToPublishingQueue"
                    wire:confirm="{{ __('seo-content-ai::filament.projects.send_to_publishing_queue_bulk_confirm') }}"
                    wire:loading.attr="disabled"
                    wire:target="bulkSendToPublishingQueue"
                    class="fi-btn fi-btn-color-primary fi-size-sm"
                >
                    {{ __('seo-content-ai::filament.projects.send_to_publishing_queue') }}
                </button>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="w-full text-[10px] font-semibold uppercase tracking-wide text-danger-600 sm:w-auto sm:self-center dark:text-danger-400">Lifecycle</span>
                <button
                    type="button"
                    wire:click="archiveSelected"
                    wire:confirm="{{ __('seo-content-ai::filament.projects.archive_selected_confirm', ['count' => (int) $selectedCount]) }}"
                    class="fi-btn fi-btn-color-danger fi-size-sm"
                >
                    {{ __('seo-content-ai::filament.projects.archive_selected') }}
                </button>
            </div>
        @endif
    </div>
@endif
