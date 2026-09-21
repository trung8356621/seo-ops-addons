@php
    $activeMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::normalize($activeMonth ?? null);
    $radius = max(1, (int) ($radius ?? 2));
    $nearby = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::nearbyMonths($activeMonth, $radius);
    $prevMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shift($activeMonth, -1);
    $nextMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shift($activeMonth, 1);
    $allOptions = is_array($allOptions ?? null) ? $allOptions : [];
@endphp

<div class="flex min-w-0 flex-wrap items-center gap-2" data-cp-list-month-nav="1">
    <div class="flex flex-col gap-0.5">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.projects.active_month') }}
        </span>
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($activeMonth) }}
        </span>
    </div>

    <div class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-gray-900">
        <a
            href="{{ $prevUrl }}"
            wire:navigate
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10"
            title="{{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($prevMonth) }}"
            aria-label="{{ __('seo-content-ai::filament.projects.active_month_prev') }}"
        >
            <x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4" />
        </a>

        @foreach ($nearby as $month)
            @php
                $isActive = $month === $activeMonth;
                $href = $monthUrls[$month] ?? '#';
            @endphp
            <a
                href="{{ $href }}"
                wire:navigate
                @class([
                    'inline-flex min-w-[2.75rem] items-center justify-center rounded-md px-2 py-1.5 text-xs font-semibold transition',
                    'bg-primary-600 text-white shadow-sm' => $isActive,
                    'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10' => ! $isActive,
                ])
                @if ($isActive) aria-current="true" @endif
            >
                {{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::shortLabel($month) }}
            </a>
        @endforeach

        <a
            href="{{ $nextUrl }}"
            wire:navigate
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10"
            title="{{ \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($nextMonth) }}"
            aria-label="{{ __('seo-content-ai::filament.projects.active_month_next') }}"
        >
            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
        </a>
    </div>

    @if ($allOptions !== [])
        <div class="flex items-center gap-1.5">
            <label class="sr-only" for="planning-month-jump">{{ __('seo-content-ai::filament.projects.active_month_jump') }}</label>
            <x-select
                id="planning-month-jump"
                wire:model.live="planningMonth"
                size="inline"
                class="min-w-[7.5rem] text-sm"
                title="{{ __('seo-content-ai::filament.projects.active_month_jump') }}"
            >
                @foreach ($allOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-select>
        </div>
    @endif
</div>
