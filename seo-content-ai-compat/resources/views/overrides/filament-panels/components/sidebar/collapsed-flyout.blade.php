@props([
    'activeIcon' => null,
    'icon' => null,
    'items' => [],
    'title',
    'titleActive' => false,
    'titleUrl' => null,
])

@php
    $titleHtml = $title instanceof \Illuminate\Contracts\Support\Htmlable
        ? $title->toHtml()
        : (string) $title;

    $flattenNavItems = static function (iterable $navItems, int $depth = 0) use (&$flattenNavItems): array {
        $rows = [];

        foreach ($navItems as $navItem) {
            $nested = $navItem->getChildItems();
            $hasNested = filled($nested);
            $url = $navItem->getUrl();
            $row = [
                'active' => $navItem->isActive() || $navItem->isChildItemsActive(),
                'label' => $navItem->getLabel(),
                'newTab' => $navItem->shouldOpenUrlInNewTab(),
                'type' => ($hasNested && $depth < 2) ? 'heading' : 'link',
                'url' => $url,
            ];

            $rows[] = $row;

            if ($hasNested) {
                $rows = array_merge($rows, $flattenNavItems($nested, $depth + 1));
            }
        }

        return $rows;
    };

    $flyoutRows = $flattenNavItems($items);
    $triggerIcon = $icon ?: 'heroicon-o-squares-2x2';
    $resolvedActiveIcon = ($titleActive && filled($activeIcon)) ? $activeIcon : $triggerIcon;
@endphp

<x-filament::dropdown
    :placement="(__('filament-panels::layout.direction') === 'rtl') ? 'left-start' : 'right-start'"
    shift
    teleport
    x-show="! $store.sidebar.isOpen"
    x-cloak
    x-on:keydown.escape.window="close()"
    x-on:click.window="
        const panel = $refs.panel
        if (! panel) {
            return
        }
        if ($el.contains($event.target) || panel.contains($event.target)) {
            return
        }
        close()
    "
    x-effect="if ($store.sidebar.isOpen) close()"
    data-fi-sidebar-collapsed-flyout
>
    <x-slot name="trigger">
        <button
            type="button"
            x-data="{ tooltip: false }"
            x-effect="
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($titleHtml),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            "
            x-tooltip.html="tooltip"
            aria-haspopup="menu"
            aria-label="{{ strip_tags($titleHtml) }}"
            @class([
                'fi-sidebar-collapsed-flyout-trigger relative flex flex-1 items-center justify-center gap-x-3 rounded-lg px-2 py-2 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:hover:bg-white/5 dark:focus-visible:bg-white/5',
                'bg-gray-100 dark:bg-white/5' => $titleActive,
            ])
        >
            <x-filament::icon
                :icon="$resolvedActiveIcon"
                @class([
                    'fi-sidebar-item-icon h-6 w-6',
                    'text-gray-400 dark:text-gray-500' => ! $titleActive,
                    'text-primary-600 dark:text-primary-400' => $titleActive,
                ])
            />
        </button>
    </x-slot>

    <div
        class="fi-sidebar-collapsed-flyout py-1"
        role="menu"
        aria-label="{{ strip_tags($titleHtml) }}"
        style="width: max-content; max-width: 18rem;"
    >
        <div class="border-b border-gray-100 px-3 py-2 dark:border-white/10">
            @if (filled($titleUrl))
                <a
                    {{ \Filament\Support\generate_href_html($titleUrl) }}
                    x-on:click="close()"
                    @class([
                        'block text-sm font-semibold',
                        'text-primary-600 dark:text-primary-400' => $titleActive,
                        'text-gray-950 dark:text-white' => ! $titleActive,
                    ])
                >
                    {{ $title }}
                </a>
            @else
                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $title }}
                </p>
            @endif
        </div>

        <div class="px-1 py-1">
            @foreach ($flyoutRows as $row)
                @if (($row['type'] ?? 'link') === 'heading')
                    @if (filled($row['url']))
                        <a
                            {{ \Filament\Support\generate_href_html($row['url'], $row['newTab']) }}
                            x-on:click="close()"
                            role="menuitem"
                            @class([
                                'mt-1 block rounded-md px-2 py-1.5 text-xs font-medium',
                                'text-primary-600 dark:text-primary-400' => $row['active'],
                                'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5' => ! $row['active'],
                            ])
                        >
                            {{ $row['label'] }}
                        </a>
                    @else
                        <p class="mt-1 px-2 py-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ $row['label'] }}
                        </p>
                    @endif

                    @continue
                @endif

                @if (blank($row['url']))
                    @continue
                @endif

                <a
                    {{ \Filament\Support\generate_href_html($row['url'], $row['newTab']) }}
                    x-on:click="close()"
                    role="menuitem"
                    @class([
                        'block rounded-md px-2 py-1.5 text-sm leading-5',
                        'bg-gray-100 font-medium text-primary-600 dark:bg-white/5 dark:text-primary-400' => $row['active'],
                        'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' => ! $row['active'],
                    ])
                >
                    {{ $row['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</x-filament::dropdown>
