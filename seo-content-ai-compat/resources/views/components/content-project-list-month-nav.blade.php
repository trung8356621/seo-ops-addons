@php
    $isAllMonths = (bool) ($isAllMonths ?? false);
    $allMonthsUrl = (string) ($allMonthsUrl ?? '');
    $activeMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($activeMonth ?? null);
    $radius = max(1, (int) ($radius ?? 2));
    $windowSize = (2 * $radius) + 1;
    /** @var list<array{value: string, short: string, label: string, url: string, year: string}> $monthCatalog */
    $monthCatalog = is_array($monthCatalog ?? null) ? $monthCatalog : [];
    if ($monthCatalog === []) {
        // Fallback: nearby window only (arrows become no-ops at edges).
        foreach (\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::nearbyMonths($activeMonth, max(6, $radius)) as $month) {
            $monthCatalog[] = [
                'value' => $month,
                'short' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shortLabel($month),
                'label' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($month),
                'url' => is_array($monthUrls ?? null) ? (string) ($monthUrls[$month] ?? '#') : '#',
                'year' => substr($month, 0, 4),
            ];
        }
    }
    $selectedMonth = $isAllMonths ? null : $activeMonth;
@endphp

<div
    class="flex min-w-0 flex-wrap items-center gap-2"
    data-cp-list-month-nav="1"
    data-cp-month-strip="1"
    x-data="cpListMonthStrip({
        catalog: {{ \Illuminate\Support\Js::from($monthCatalog) }},
        windowSize: {{ $windowSize }},
        selected: {{ \Illuminate\Support\Js::from($selectedMonth) }},
        initialCenter: {{ \Illuminate\Support\Js::from($activeMonth) }},
    })"
>
    <div class="flex flex-col gap-0.5">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.active_month') }}
        </span>
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            @if ($isAllMonths)
                {{ __('seo-content-ai::filament.projects.active_month_all') }}
            @else
                {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($activeMonth) }}
            @endif
        </span>
    </div>

    <div class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-gray-900">
        @if ($allMonthsUrl !== '')
            <a
                href="{{ $allMonthsUrl }}"
                wire:navigate
                @class([
                    'inline-flex min-w-[2.75rem] items-center justify-center rounded-md px-2 py-1.5 text-xs font-semibold transition',
                    'bg-primary-600 text-white shadow-sm' => $isAllMonths,
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10' => ! $isAllMonths,
                ])
                @if ($isAllMonths) aria-current="true" @endif
                title="{{ __('seo-content-ai::filament.projects.active_month_all') }}"
            >
                {{ __('seo-content-ai::filament.projects.active_month_all_short') }}
            </a>
        @endif

        {{-- Strip pagination only — does not change selected month / navigate / reload. --}}
        <button
            type="button"
            @click.prevent="shiftStrip(-1)"
            :disabled="! canPrev"
            :class="! canPrev ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-white/10'"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 dark:text-gray-300"
            title="{{ __('seo-content-ai::filament.projects.active_month_strip_prev') }}"
            aria-label="{{ __('seo-content-ai::filament.projects.active_month_strip_prev') }}"
        >
            <x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4" />
        </button>

        <template x-for="month in visibleMonths" :key="month.value">
            <a
                :href="month.url"
                wire:navigate
                class="inline-flex min-w-[2.75rem] flex-col items-center justify-center rounded-md px-2 py-1 text-xs font-semibold transition"
                :class="isSelected(month.value)
                    ? 'bg-primary-600 text-white shadow-sm'
                    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10'"
                :aria-current="isSelected(month.value) ? 'true' : null"
                :title="month.label"
            >
                <span x-text="month.short"></span>
                <span
                    class="text-[9px] font-medium leading-none opacity-80"
                    x-show="showYearHint()"
                    x-text="month.year.slice(2)"
                ></span>
            </a>
        </template>

        <button
            type="button"
            @click.prevent="shiftStrip(1)"
            :disabled="! canNext"
            :class="! canNext ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-white/10'"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 dark:text-gray-300"
            title="{{ __('seo-content-ai::filament.projects.active_month_strip_next') }}"
            aria-label="{{ __('seo-content-ai::filament.projects.active_month_strip_next') }}"
        >
            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
        </button>
    </div>
</div>

@script
<script>
    Alpine.data('cpListMonthStrip', (config = {}) => {
        const catalog = Array.isArray(config.catalog) ? config.catalog : [];
        const windowSize = Math.max(1, Number(config.windowSize) || 5);
        const selected = config.selected || null;
        const initialCenter = config.initialCenter || selected || (catalog[0] ? catalog[0].value : null);

        return {
            catalog,
            windowSize,
            selected,
            startIndex: 0,
            init() {
                const idx = catalog.findIndex((m) => m.value === initialCenter);
                const half = Math.floor(windowSize / 2);
                const maxStart = Math.max(0, catalog.length - windowSize);
                this.startIndex = Math.max(0, Math.min((idx >= 0 ? idx : 0) - half, maxStart));
            },
            get visibleMonths() {
                return this.catalog.slice(this.startIndex, this.startIndex + this.windowSize);
            },
            get canPrev() {
                return this.startIndex > 0;
            },
            get canNext() {
                return this.startIndex + this.windowSize < this.catalog.length;
            },
            get visibleYears() {
                return new Set(this.visibleMonths.map((m) => m.year));
            },
            isSelected(value) {
                return this.selected !== null && this.selected === value;
            },
            showYearHint() {
                return this.visibleYears.size > 1;
            },
            shiftStrip(direction) {
                // Paginate the visible month group only — no selection / navigation / API.
                const step = this.windowSize;
                const maxStart = Math.max(0, this.catalog.length - this.windowSize);
                this.startIndex = Math.max(0, Math.min(this.startIndex + (direction * step), maxStart));
            },
        };
    });
</script>
@endscript
