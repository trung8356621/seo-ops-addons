@props([
    'row' => [],
    'disabled' => false,
])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $articleUrl = $row['article_edit_url'] ?? null;
    $wpPermalink = trim((string) ($row['wp_permalink'] ?? ''));
    $a = \Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter::forRow($row);
    $itemClass = 'cp-ops-menu__item';
    $dangerClass = 'cp-ops-menu__item cp-ops-menu__item--danger';
    $tz = \Omnichannel\Addons\Content\Support\SystemDateTime::timezoneChip();
@endphp

<div
    {{ $attributes->class([
        'relative inline-flex flex-col items-end gap-1',
        'pointer-events-none opacity-50' => (bool) $disabled,
    ]) }}
    x-data="{
        open: false,
        scheduleOpen: false,
        customOpen: false,
        customAt: '',
        place: 'bottom-end',
        style: '',
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.scheduleOpen = false;
                this.$nextTick(() => this.reposition());
            } else {
                this.style = '';
                this.scheduleOpen = false;
            }
        },
        reposition() {
            const panel = this.$refs.menu;
            const btn = this.$refs.trigger;
            if (!panel || !btn) return;
            const br = btn.getBoundingClientRect();
            const pw = Math.min(280, Math.max(240, panel.offsetWidth || 240));
            const ph = panel.offsetHeight || 240;
            const spaceBelow = window.innerHeight - br.bottom;
            const spaceAbove = br.top;
            // Prefer flip upward near viewport bottom when enough room above.
            const flipUp = spaceBelow < ph + 12 && spaceAbove > spaceBelow;
            let top = flipUp ? (br.top - ph - 4) : (br.bottom + 4);
            let left = br.right - pw;
            if (left < 12) left = 12;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            if (top < 12) top = 12;
            if (top + ph > window.innerHeight - 12) {
                top = Math.max(12, window.innerHeight - ph - 12);
            }
            this.place = (flipUp ? 'top' : 'bottom') + '-end';
            // Fixed + body teleport escapes table overflow clipping (not z-index alone).
            this.style = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;z-index:80;';
        },
    }"
    @keydown.escape.window="open = false; customOpen = false"
    @resize.window="open && reposition()"
    @scroll.window="open && reposition()"
>
    @if (! empty($a['show_recover_banner']))
        <div class="mb-1 max-w-[16rem] rounded-md border border-warning-300 bg-warning-50 px-2 py-1.5 text-left text-[11px] text-warning-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
            <p class="font-medium">Quá trình xuất bản bị gián đoạn.</p>
            <div class="mt-1 flex flex-wrap gap-2">
                <button type="button" class="font-semibold underline" wire:click="recoverOne({{ $tid }})">Khôi phục</button>
                @if (! empty($a['view_technical_details']) || ! empty($row['publish_operation_key']))
                    <button
                        type="button"
                        class="underline"
                        @click="window.alert(@js('Chi tiết: '.trim((string) ($row['last_publish_error_message'] ?? $row['last_publish_error'] ?? $row['publish_operation_key'] ?? 'Không có thêm thông tin.'))))"
                    >Xem chi tiết</button>
                @endif
            </div>
        </div>
    @endif

    <div class="relative">
        <button
            type="button"
            x-ref="trigger"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-gray-700 dark:hover:bg-gray-800"
            @click="toggle()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="Thao tác bài"
            @disabled($disabled)
        >
            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-4 w-4" />
        </button>

        <template x-teleport="body">
            <div
                x-ref="menu"
                x-show="open"
                x-cloak
                x-transition
                @click.outside="open = false"
                role="menu"
                class="cp-ops-menu cp-ops-menu--portal"
                :style="style"
                :class="{
                    'cp-ops-menu--top': place.startsWith('top'),
                    'cp-ops-menu--bottom': place.startsWith('bottom'),
                    'cp-ops-menu--start': place.endsWith('start'),
                    'cp-ops-menu--end': place.endsWith('end'),
                }"
            >
                @if ($disabled)
                    <p class="cp-ops-menu__note">{{ __('seo-content-ai::filament.projects.publishing_queue_pending_updating') }}</p>
                @else
                    @if (! empty($a['immediate_disabled']))
                        <p class="cp-ops-menu__note">{{ $a['immediate_disabled_reason'] ?? 'Bài đang được xuất bản.' }}</p>
                    @elseif (! empty($a['publish_now']))
                        <button role="menuitem" type="button" wire:click="publishOneNow({{ $tid }})" wire:confirm="Xuất bản ngay?" @click="open = false" class="{{ $itemClass }}">
                            <x-filament::icon icon="heroicon-o-globe-alt" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Xuất bản ngay</span>
                        </button>
                    @elseif (! empty($a['retry_now']))
                        <button role="menuitem" type="button" wire:click="retryPublishOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Thử lại ngay</span>
                        </button>
                    @endif

                    @if (! empty($a['has_schedule_group']))
                        <button type="button" class="{{ $itemClass }}" @click="scheduleOpen = !scheduleOpen; $nextTick(() => reposition())">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">{{ ! empty($a['unschedule']) || ($a['state'] ?? '') === 'scheduled' ? 'Đổi lịch' : 'Lịch xuất bản' }}</span>
                        </button>
                        <div x-show="scheduleOpen" x-cloak class="border-t border-gray-100 py-1 dark:border-gray-800">
                            <p class="cp-ops-menu__note">Timezone: {{ $tz }}</p>
                            @if (! empty($a['schedule']))
                                <button role="menuitem" type="button" wire:click="scheduleOneInMinutes({{ $tid }}, 5)" @click="open = false" class="{{ $itemClass }}"><span class="cp-ops-menu__label">Sau 5 phút</span></button>
                                <button role="menuitem" type="button" wire:click="scheduleOneInMinutes({{ $tid }}, 60)" @click="open = false" class="{{ $itemClass }}"><span class="cp-ops-menu__label">Sau 1 giờ</span></button>
                                <button role="menuitem" type="button" wire:click="scheduleOneTomorrowMorning({{ $tid }})" @click="open = false" class="{{ $itemClass }}"><span class="cp-ops-menu__label">Ngày mai lúc 09:00</span></button>
                                <button role="menuitem" type="button" class="{{ $itemClass }}" @click="open = false; customOpen = true"><span class="cp-ops-menu__label">Chọn ngày giờ…</span></button>
                            @endif
                            @if (! empty($a['unschedule']))
                                <button role="menuitem" type="button" wire:click="unscheduleOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}"><span class="cp-ops-menu__label">Hủy lịch</span></button>
                            @endif
                        </div>
                    @endif

                    @if (! empty($a['cancel_pending_delivery']))
                        <button role="menuitem" type="button" wire:click="cancelPublishOne({{ $tid }})" wire:confirm="Hủy yêu cầu đang chờ?" @click="open = false" class="{{ $dangerClass }}">
                            <x-filament::icon icon="heroicon-o-x-mark" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Hủy yêu cầu đang chờ</span>
                        </button>
                    @endif

                    @if (! empty($a['view_error']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; window.alert(@js(\Illuminate\Support\Str::limit((string) ($row['last_publish_error_message'] ?? $row['last_publish_error'] ?? 'Không có chi tiết lỗi'), 400)))"
                            class="{{ $itemClass }}"
                        >
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Xem lỗi</span>
                        </button>
                    @endif

                    @if (! empty($a['remove_from_queue']))
                        <button role="menuitem" type="button" wire:click="cancelPublishOne({{ $tid }})" wire:confirm="Bỏ bài khỏi Publishing Queue?" @click="open = false" class="{{ $dangerClass }}">
                            <x-filament::icon icon="heroicon-o-x-mark" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Bỏ khỏi Publishing Queue</span>
                        </button>
                    @endif

                    @if (! empty($a['return_to_content_project']))
                        <button role="menuitem" type="button" wire:click="returnOne({{ $tid }})" wire:confirm="Trả về Content Project? Bài trên WordPress (nếu đã xuất bản) không bị gỡ." @click="open = false" class="{{ $itemClass }}">
                            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Trả về Content Project</span>
                        </button>
                    @endif

                    @if (! empty($a['stop_publish']))
                        <button
                            role="menuitem"
                            type="button"
                            wire:click="forceRecoverOne({{ $tid }})"
                            wire:confirm="Dừng xuất bản và khôi phục bài này?"
                            @click="open = false"
                            class="{{ $dangerClass }}"
                        >
                            <x-filament::icon icon="heroicon-o-no-symbol" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Dừng xuất bản</span>
                        </button>
                    @endif

                    @if (! empty($a['view_technical_details']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; window.alert(@js('Chi tiết kỹ thuật: '.trim((string) ($row['publish_operation_key'] ?? $row['last_publish_error'] ?? 'Không có thêm thông tin.'))))"
                            class="{{ $itemClass }}"
                        >
                            <x-filament::icon icon="heroicon-o-information-circle" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Xem chi tiết kỹ thuật</span>
                        </button>
                    @endif

                    @if ($a['view_on_wordpress'] && $wpPermalink !== '')
                        <a role="menuitem" href="{{ $wpPermalink }}" target="_blank" rel="noopener noreferrer" @click="open = false" class="{{ $itemClass }}">
                            <x-filament::icon icon="heroicon-o-globe-alt" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Mở bài WordPress</span>
                        </a>
                    @endif

                    @if (! empty($a['resync_wordpress']))
                        <button
                            role="menuitem"
                            type="button"
                            wire:click="resyncPublishedItemWordPress({{ $tid }})"
                            wire:loading.attr="disabled"
                            wire:target="resyncPublishedItemWordPress({{ $tid }})"
                            @click="open = false"
                            class="{{ $itemClass }}"
                        >
                            <x-filament::icon icon="heroicon-o-arrow-path" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Đồng bộ lại WordPress</span>
                        </button>
                    @endif

                    @if (! empty($a['view_sync_history']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; window.alert(@js('Lịch sử đồng bộ: '.trim((string) ($row['last_post_publish_sync_operation_id'] ?? $row['publish_operation_key'] ?? 'Không có operation gần đây.'))))"
                            class="{{ $itemClass }}"
                        >
                            <x-filament::icon icon="heroicon-o-clock" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Xem lịch sử đồng bộ</span>
                        </button>
                    @endif

                    @if ($a['open_article'] && $articleUrl)
                        <a role="menuitem" href="{{ $articleUrl }}" target="_blank" rel="noopener noreferrer" @click="open = false" class="{{ $itemClass }}">
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Mở bài trong editor</span>
                        </a>
                    @endif
                @endif
            </div>
        </template>
    </div>

    <template x-teleport="body">
        <div x-show="customOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-sm rounded-xl bg-white p-4 shadow-xl dark:bg-gray-900" @click.outside="customOpen = false">
                <h3 class="mb-1 text-sm font-semibold">Chọn ngày giờ</h3>
                <p class="mb-3 text-xs text-gray-500">Timezone: {{ $tz }}</p>
                <input type="datetime-local" x-model="customAt" class="mb-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800" />
                <div class="flex justify-end gap-2">
                    <button type="button" class="text-sm" @click="customOpen = false">Đóng</button>
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-primary fi-size-sm"
                        @click="if (customAt) { $wire.scheduleOneAt({{ $tid }}, customAt); customOpen = false; }"
                    >Áp dụng</button>
                </div>
            </div>
        </div>
    </template>
</div>
