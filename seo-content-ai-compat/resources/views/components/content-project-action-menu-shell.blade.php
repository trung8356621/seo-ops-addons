@props([])

{{--
    Shared row action dropdown shell (trigger button + positioned panel). Body content
    is the default slot. Alpine toggle/reposition logic matches
    content-project-item-actions-menu.blade.php — keep both in sync if this changes.
--}}
<div
    {{ $attributes->class(['relative inline-flex items-center gap-1']) }}
    x-data="{
        open: false,
        place: 'bottom-end',
        style: '',
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.reposition());
            } else {
                this.style = '';
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
            const flipUp = spaceBelow < ph + 12 && br.top > ph + 12;
            let top = flipUp ? (br.top - ph - 4) : (br.bottom + 4);
            let left = br.right - pw;
            if (left < 12) left = 12;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            if (top < 12) top = 12;
            this.place = (flipUp ? 'top' : 'bottom') + '-end';
            this.style = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;z-index:200;';
        },
    }"
    @keydown.escape.window="open = false"
>
    <div class="relative">
        <button
            type="button"
            x-ref="trigger"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-gray-700 dark:hover:bg-gray-800"
            @click.stop="toggle()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="Item actions"
        >
            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-4 w-4" />
        </button>

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
            {{ $slot }}
        </div>
    </div>
</div>
