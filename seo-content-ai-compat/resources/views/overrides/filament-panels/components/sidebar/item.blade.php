@props([
    'active' => false,
    'activeChildItems' => false,
    'activeIcon' => null,
    'badge' => null,
    'badgeColor' => null,
    'badgeTooltip' => null,
    'childItems' => [],
    'first' => false,
    'grouped' => false,
    'icon' => null,
    'last' => false,
    'shouldOpenUrlInNewTab' => false,
    'sidebarCollapsible' => true,
    'subGrouped' => false,
    'url',
])

@php
    $sidebarCollapsible = $sidebarCollapsible && filament()->isSidebarCollapsibleOnDesktop();
    $hasChildren = filled($childItems);
    $childrenOpenByDefault = $active || $activeChildItems;
@endphp

<li
    @if ($hasChildren)
        x-data="{ openChildren: @js($childrenOpenByDefault) }"
    @endif
    {{
        $attributes->class([
            'fi-sidebar-item',
            // @deprecated `fi-sidebar-item-active` has been replaced by `fi-active`.
            'fi-active fi-sidebar-item-active' => $active,
            'flex flex-col gap-y-1' => $hasChildren || $active || $activeChildItems,
        ])
    }}
>
    <div @class([
        'relative flex items-center gap-x-1',
    ])>
        <a
            {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab) }}
            x-on:click="window.matchMedia(`(max-width: 1024px)`).matches && $store.sidebar.close()"
            @if ($sidebarCollapsible)
                x-data="{ tooltip: false }"
                x-effect="
                    tooltip = $store.sidebar.isOpen
                        ? false
                        : {
                              content: @js($slot->toHtml()),
                              placement: document.dir === 'rtl' ? 'left' : 'right',
                              theme: $store.theme,
                          }
                "
                x-tooltip.html="tooltip"
            @endif
            @class([
                'fi-sidebar-item-button relative flex min-w-0 flex-1 items-center justify-center gap-x-3 rounded-lg px-2 py-2 outline-none transition duration-75',
                'hover:bg-gray-100 focus-visible:bg-gray-100 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => filled($url),
                'bg-gray-100 dark:bg-white/5' => $active,
            ])
        >
            @if (filled($icon) && ((! $subGrouped) || $sidebarCollapsible))
                <x-filament::icon
                    :icon="($active && $activeIcon) ? $activeIcon : $icon"
                    :x-show="($subGrouped && $sidebarCollapsible) ? '! $store.sidebar.isOpen' : false"
                    @class([
                        'fi-sidebar-item-icon h-6 w-6 shrink-0',
                        'text-gray-400 dark:text-gray-500' => ! $active,
                        'text-primary-600 dark:text-primary-400' => $active,
                    ])
                />
            @endif

            @if ((blank($icon) && $grouped) || $subGrouped)
                <div
                    @if (filled($icon) && $subGrouped && $sidebarCollapsible)
                        x-show="$store.sidebar.isOpen"
                    @endif
                    class="fi-sidebar-item-grouped-border relative flex h-6 w-6 items-center justify-center"
                >
                    @if (! $first)
                        <div
                            class="absolute -top-1/2 bottom-1/2 w-px bg-gray-300 dark:bg-gray-600"
                        ></div>
                    @endif

                    @if (! $last)
                        <div
                            class="absolute -bottom-1/2 top-1/2 w-px bg-gray-300 dark:bg-gray-600"
                        ></div>
                    @endif

                    <div
                        @class([
                            'relative h-1.5 w-1.5 rounded-full',
                            'bg-gray-400 dark:bg-gray-500' => ! $active,
                            'bg-primary-600 dark:bg-primary-400' => $active,
                        ])
                    ></div>
                </div>
            @endif

            <span
                @if ($sidebarCollapsible)
                    x-show="$store.sidebar.isOpen"
                    x-transition:enter="lg:transition lg:delay-100"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                @endif
                @class([
                    'fi-sidebar-item-label flex-1 truncate text-sm font-medium',
                    'text-gray-700 dark:text-gray-200' => ! $active,
                    'text-primary-600 dark:text-primary-400' => $active,
                ])
            >
                {{ $slot }}
            </span>

            @if (filled($badge))
                <span
                    @if ($sidebarCollapsible)
                        x-show="$store.sidebar.isOpen"
                        x-transition:enter="lg:transition lg:delay-100"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                    @endif
                >
                    <x-filament::badge
                        :color="$badgeColor"
                        :tooltip="$badgeTooltip"
                    >
                        {{ $badge }}
                    </x-filament::badge>
                </span>
            @endif
        </a>

        @if ($hasChildren)
            <button
                type="button"
                x-on:click.stop="openChildren = ! openChildren"
                x-bind:aria-expanded="openChildren.toString()"
                @if ($sidebarCollapsible)
                    x-show="$store.sidebar.isOpen"
                @endif
                @class([
                    'fi-sidebar-item-collapse-button flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 outline-none transition duration-75 hover:bg-gray-100 hover:text-gray-500 focus-visible:bg-gray-100 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-400 dark:focus-visible:bg-white/5',
                ])
            >
                <x-filament::icon
                    icon="heroicon-m-chevron-down"
                    class="h-4 w-4 transition-transform duration-200"
                    x-bind:class="{ 'rotate-180': openChildren }"
                />
            </button>
        @endif
    </div>

    @if ($hasChildren)
        <ul
            x-show="openChildren"
            x-collapse
            class="fi-sidebar-sub-group-items flex flex-col gap-y-1"
        >
            @foreach ($childItems as $childItem)
                <x-filament-panels::sidebar.item
                    :active="$childItem->isActive()"
                    :active-child-items="$childItem->isChildItemsActive()"
                    :active-icon="$childItem->getActiveIcon()"
                    :badge="$childItem->getBadge()"
                    :badge-color="$childItem->getBadgeColor()"
                    :badge-tooltip="$childItem->getBadgeTooltip()"
                    :first="$loop->first"
                    grouped
                    :icon="$childItem->getIcon()"
                    :last="$loop->last"
                    :should-open-url-in-new-tab="$childItem->shouldOpenUrlInNewTab()"
                    sub-grouped
                    :url="$childItem->getUrl()"
                >
                    {{ $childItem->getLabel() }}
                </x-filament-panels::sidebar.item>
            @endforeach
        </ul>
    @endif
</li>
