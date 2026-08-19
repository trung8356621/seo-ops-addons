@props([
    'selectedCount' => 0,
    'canDebugLifecycle' => false,
    'variant' => 'content_project',
    'timezoneLabel' => null,
    'bulkMenuGroups' => [],
])

@php
    $canManageBulk = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canManageContentProjectWorkflow();
    $showCpBulk = $variant !== 'publishing_queue' && (int) $selectedCount > 0 && $canManageBulk;
    $showPqBulk = $variant !== 'content_project' && $canManageBulk;
@endphp
@if ($showPqBulk || $showCpBulk)
    @php
        $tzNote = $timezoneLabel ?: \Omnichannel\Addons\Content\Support\SystemDateTime::timezoneChip();
    @endphp
    <div
        {{ $attributes->class([
            'mt-3 flex flex-col gap-2 rounded-lg border border-primary-200/80 bg-primary-50/50 p-3 dark:border-primary-500/30 dark:bg-primary-500/10',
            'pq-bulk-toolbar' => $showPqBulk,
        ]) }}
        role="toolbar"
        aria-label="Bulk selection actions"
        @if ($showPqBulk)
            x-show="$store.pqOpsUi && $store.pqOpsUi.selectedCount() > 0"
            x-cloak
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
        @if ($showPqBulk)
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                <span x-text="($store.pqOpsUi?.selectedCount() || 0) + ' đã chọn'">{{ (int) $selectedCount }} đã chọn</span>
            </span>
            <button
                type="button"
                @click="$store.pqOpsUi?.clearSelection()"
                class="text-xs font-semibold text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-300"
            >
                {{ __('seo-content-ai::filament.projects.queue_clear_selection') }}
            </button>
        </div>
        @endif

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
                            wire:loading.attr="disabled"
                            wire:target="bulkPublishNow"
                            @click="$store.pqOpsUi.runBulk('bulkPublishNow', 'publishing', 'Xuất bản ngay các bài đã chọn?'); publishOpen = false"
                        >Xuất bản ngay</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            @click="$store.pqOpsUi.runBulk('bulkRetryPublish', 'publishing'); publishOpen = false"
                        >Thử lại ngay</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            wire:loading.attr="disabled"
                            wire:target="bulkReobserveWordPressStatus"
                            @click="$store.pqOpsUi.runBulk('bulkReobserveWordPressStatus', 'publishing'); publishOpen = false"
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
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="$store.pqOpsUi.runBulk('bulkScheduleInMinutes', 'publishing', null, [5]); scheduleOpen = false">Sau 5 phút</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="$store.pqOpsUi.runBulk('bulkScheduleInMinutes', 'publishing', null, [60]); scheduleOpen = false">Sau 1 giờ</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="$store.pqOpsUi.runBulk('bulkScheduleTomorrowMorning', 'publishing'); scheduleOpen = false">Ngày mai lúc 09:00</button>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="scheduleOpen = false; customOpen = true">Chọn ngày giờ…</button>
                        <div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>
                        <button type="button" role="menuitem" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="$store.pqOpsUi.runBulk('bulkUnschedule', 'publishing'); scheduleOpen = false">Hủy lịch</button>
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
                            @click="$store.pqOpsUi.runBulk('bulkCancelPublish', 'publishing', 'Bỏ các bài đã chọn khỏi Publishing Queue?'); moreOpen = false"
                        >Bỏ khỏi Publishing Queue</button>
                        <button
                            type="button"
                            role="menuitem"
                            class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            @click="$store.pqOpsUi.runBulk('bulkReturn', 'publishing', 'Trả các bài đã chọn về Content Project? Bài trên WordPress (nếu đã xuất bản) không bị gỡ.'); moreOpen = false"
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
                            @click="if (customAt) { $store.pqOpsUi.runBulk('bulkSchedule', 'publishing', null, [customAt]); customOpen = false; }"
                        >Áp dụng</button>
                    </div>
                </div>
            </div>
        @else
            {{-- Canonical bulk methods (ContentProjectItemActionCatalog): generateSelected, bulkRegenOutline, bulkRegenArticle, skipGenerationSelected, startReviewSelected, approveSelected, bulkSendToPublishingQueue, archiveSelected. Confirm: archive_selected_confirm --}}
            @php
                $catalog = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionCatalog::class;
                $cpBulkGroups = is_array($bulkMenuGroups) ? $bulkMenuGroups : [];
                if ($cpBulkGroups === []) {
                    $cpBulkGroups = $catalog::groupBulkMenu(
                        $catalog::summarizeBulk((int) $selectedCount, []),
                    );
                }
            @endphp
            <div
                class="flex flex-wrap items-center gap-2"
                x-data="{ actionsOpen: false }"
                @keydown.escape.window="actionsOpen = false"
            >
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                    {{ (int) $selectedCount }} đã chọn
                </span>
                <div class="relative">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-gray fi-size-sm inline-flex items-center gap-1"
                        @click="actionsOpen = !actionsOpen"
                        :aria-expanded="actionsOpen.toString()"
                        aria-haspopup="menu"
                    >
                        {{ __('seo-content-ai::filament.projects.item_actions_menu') }}
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                    </button>
                    <div
                        x-show="actionsOpen"
                        x-cloak
                        x-transition
                        @click.outside="actionsOpen = false"
                        role="menu"
                        class="cp-ops-menu cp-ops-menu--bottom cp-ops-menu--start z-40 mt-1"
                    >
                        @foreach ($cpBulkGroups as $groupIndex => $group)
                            @if ($groupIndex > 0)
                                <div class="cp-ops-menu__divider"></div>
                            @endif
                            <p class="cp-ops-menu__heading">{{ $group['heading'] }}</p>
                            @foreach ($group['actions'] as $action)
                                @php
                                    $enabled = ! empty($action['enabled']);
                                    $state = (string) ($action['state'] ?? 'none');
                                    $eligible = (int) ($action['eligible'] ?? 0);
                                    $total = (int) ($action['total'] ?? $selectedCount);
                                    $ineligible = max(0, $total - $eligible);
                                    $label = __($action['label_key']);
                                    if ($state === 'partial') {
                                        $label .= ' ('.__('seo-content-ai::filament.projects.item_action_partial_eligible', [
                                            'eligible' => $eligible,
                                            'total' => $total,
                                        ]).')';
                                    }
                                    $title = $state === 'partial'
                                        ? __('seo-content-ai::filament.projects.item_action_partial_tooltip', ['count' => $ineligible])
                                        : ($state === 'none'
                                            ? __('seo-content-ai::filament.projects.item_action_none_tooltip')
                                            : $label);
                                    $itemClass = ! empty($action['destructive'])
                                        ? 'cp-ops-menu__item cp-ops-menu__item--danger'
                                        : 'cp-ops-menu__item';
                                    if (! $enabled) {
                                        $itemClass .= ' cp-ops-menu__item--disabled';
                                    }
                                    $confirm = $action['confirm_key']
                                        ? __($action['confirm_key'], ['count' => (int) $selectedCount])
                                        : null;
                                    $kind = $action['processing_kind'] ?? null;
                                @endphp
                                <button
                                    type="button"
                                    role="menuitem"
                                    class="{{ $itemClass }}"
                                    title="{{ $title }}"
                                    @if ($enabled)
                                        wire:click="{{ $action['bulk_method'] }}"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $action['bulk_method'] }}"
                                        @if ($confirm)
                                            wire:confirm="{{ $confirm }}"
                                        @endif
                                        @click="actionsOpen = false; @if ($kind) ($wire.selectedTaskIds || []).forEach((id) => typeof beginRowProcessing === 'function' && beginRowProcessing(id, @js($kind))) @endif"
                                    @else
                                        disabled
                                        aria-disabled="true"
                                    @endif
                                >
                                    <x-filament::icon :icon="$action['icon']" class="cp-ops-menu__icon" />
                                    <span class="cp-ops-menu__label">{{ $label }}</span>
                                </button>
                            @endforeach
                        @endforeach
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="clearSelection"
                    class="ml-auto text-xs font-semibold text-primary-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-300"
                >
                    {{ __('seo-content-ai::filament.projects.queue_clear_selection') }}
                </button>
            </div>
        @endif
    </div>
@endif
