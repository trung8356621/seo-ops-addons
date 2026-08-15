@props([
    'active' => false,
    'collapsible' => true,
    'icon' => null,
    'items' => [],
    'label' => null,
    'sidebarCollapsible' => true,
    'subNavigation' => false,
])

@php
    $sidebarCollapsible = $sidebarCollapsible && filament()->isSidebarCollapsibleOnDesktop();

    $countNavLeaves = static function (iterable $navItems) use (&$countNavLeaves): int {
        $total = 0;

        foreach ($navItems as $navItem) {
            $nested = $navItem->getChildItems();
            if (filled($nested)) {
                $total += $countNavLeaves($nested);
                if (filled($navItem->getUrl())) {
                    $total++;
                }

                continue;
            }

            if (filled($navItem->getUrl())) {
                $total++;
            }
        }

        return $total;
    };

    $firstNavUrl = static function (iterable $navItems) use (&$firstNavUrl): ?string {
        foreach ($navItems as $navItem) {
            if (filled($navItem->getUrl())) {
                return $navItem->getUrl();
            }

            $nested = $navItem->getChildItems();
            if (filled($nested)) {
                $found = $firstNavUrl($nested);
                if (filled($found)) {
                    return $found;
                }
            }
        }

        return null;
    };

    $firstNavIcon = static function (iterable $navItems) use (&$firstNavIcon): mixed {
        foreach ($navItems as $navItem) {
            if (filled($navItem->getIcon())) {
                return $navItem->getIcon();
            }

            $nested = $navItem->getChildItems();
            if (filled($nested)) {
                $found = $firstNavIcon($nested);
                if (filled($found)) {
                    return $found;
                }
            }
        }

        return null;
    };

    $destinationCount = filled($items) ? $countNavLeaves($items) : 0;
    $hasDropdown = filled($label) && $sidebarCollapsible && $destinationCount >= 2;
    $singleDestinationUrl = (filled($label) && $sidebarCollapsible && $destinationCount === 1)
        ? $firstNavUrl($items)
        : null;
    $collapsedIcon = $icon ?: $firstNavIcon($items);
@endphp

<li
    x-data="{ label: @js($subNavigation ? "sub_navigation_{$label}" : $label) }"
    data-group-label="{{ $subNavigation ? "sub_navigation_{$label}" : $label }}"
    {{
        $attributes->class([
            'fi-sidebar-group flex flex-col gap-y-1',
            'fi-active' => $active,
            'fi-sidebar-group-has-collapsed-flyout' => $hasDropdown,
        ])
    }}
>
    @if ($label)
        <div
            @if ($collapsible)
                x-on:click="$store.sidebar.toggleCollapsedGroup(label)"
            @endif
            @if ($sidebarCollapsible)
                x-show="$store.sidebar.isOpen"
                x-transition:enter="delay-100 lg:transition"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
            @endif
            @class([
                'fi-sidebar-group-button flex items-center gap-x-3 px-2 py-2',
                'cursor-pointer' => $collapsible,
            ])
        >
            @if ($icon)
                <x-filament::icon
                    :icon="$icon"
                    class="fi-sidebar-group-icon h-6 w-6 text-gray-400 dark:text-gray-500"
                />
            @endif

            <span
                class="fi-sidebar-group-label flex-1 text-sm font-medium leading-6 text-gray-500 dark:text-gray-400"
            >
                {{ $label }}
            </span>

            @if ($collapsible)
                <x-filament::icon-button
                    color="gray"
                    icon="heroicon-m-chevron-up"
                    icon-alias="panels::sidebar.group.collapse-button"
                    :label="$label"
                    x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(label)"
                    x-on:click.stop="$store.sidebar.toggleCollapsedGroup(label)"
                    class="fi-sidebar-group-collapse-button"
                    x-bind:class="{ '-rotate-180': $store.sidebar.groupIsCollapsed(label) }"
                />
            @endif
        </div>
    @endif

    @if ($hasDropdown)
        <x-filament-panels::sidebar.collapsed-flyout
            :icon="$collapsedIcon"
            :items="$items"
            :title="$label"
            :title-active="$active"
        />
    @elseif (filled($singleDestinationUrl))
        <a
            {{ \Filament\Support\generate_href_html($singleDestinationUrl) }}
            x-show="! $store.sidebar.isOpen"
            x-cloak
            x-data="{ tooltip: false }"
            x-effect="
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($label),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            "
            x-tooltip.html="tooltip"
            x-on:click="window.matchMedia(`(max-width: 1024px)`).matches && $store.sidebar.close()"
            data-fi-sidebar-single-child-root
            @class([
                'relative flex flex-1 items-center justify-center gap-x-3 rounded-lg px-2 py-2 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:hover:bg-white/5 dark:focus-visible:bg-white/5',
                'bg-gray-100 dark:bg-white/5' => $active,
            ])
        >
            <x-filament::icon
                :icon="$collapsedIcon ?: 'heroicon-o-squares-2x2'"
                @class([
                    'h-6 w-6',
                    'text-gray-400 dark:text-gray-500' => ! $active,
                    'text-primary-600 dark:text-primary-400' => $active,
                ])
            />
        </a>
    @endif

    <ul
        @if (filled($label))
            @if ($sidebarCollapsible)
                x-show="$store.sidebar.isOpen ? ! $store.sidebar.groupIsCollapsed(label) : ! @js($hasDropdown || filled($singleDestinationUrl))"
            @else
                x-show="! $store.sidebar.groupIsCollapsed(label)"
            @endif
            x-collapse.duration.200ms
        @endif
        @if ($sidebarCollapsible)
            x-transition:enter="delay-100 lg:transition"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
        @endif
        class="fi-sidebar-group-items flex flex-col gap-y-1"
    >
        @foreach ($items as $item)
            @php
                $itemIcon = $item->getIcon();
                $itemActiveIcon = $item->getActiveIcon();

                if ($icon) {
                    if ($hasDropdown || (blank($itemIcon) && blank($itemActiveIcon))) {
                        $itemIcon = null;
                        $itemActiveIcon = null;
                    } else {
                        throw new \Exception('Navigation group [' . $label . '] has an icon but one or more of its items also have icons. Either the group or its items can have icons, but not both. This is to ensure a proper user experience.');
                    }
                }
            @endphp

            <x-filament-panels::sidebar.item
                :active="$item->isActive()"
                :active-child-items="$item->isChildItemsActive()"
                :active-icon="$itemActiveIcon"
                :badge="$item->getBadge()"
                :badge-color="$item->getBadgeColor()"
                :badge-tooltip="$item->getBadgeTooltip()"
                :child-items="$item->getChildItems()"
                :first="$loop->first"
                :grouped="filled($label)"
                :icon="$itemIcon"
                :last="$loop->last"
                :should-open-url-in-new-tab="$item->shouldOpenUrlInNewTab()"
                :sidebar-collapsible="$sidebarCollapsible && ! $hasDropdown"
                :url="$item->getUrl()"
            >
                {{ $item->getLabel() }}

                @if ($itemIcon instanceof \Illuminate\Contracts\Support\Htmlable)
                    <x-slot name="icon">
                        {{ $itemIcon }}
                    </x-slot>
                @endif

                @if ($itemActiveIcon instanceof \Illuminate\Contracts\Support\Htmlable)
                    <x-slot name="activeIcon">
                        {{ $itemActiveIcon }}
                    </x-slot>
                @endif
            </x-filament-panels::sidebar.item>
        @endforeach
    </ul>
</li>
