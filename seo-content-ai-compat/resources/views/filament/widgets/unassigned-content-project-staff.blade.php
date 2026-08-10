@php
    $total = (int) ($total ?? 0);
    $staff = is_array($staff ?? null) ? $staff : [];
    $showAll = (bool) ($showAll ?? false);
    $createUrl = (string) ($createUrl ?? '#');
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('seo-content-ai::filament.projects.unassigned_staff_heading')"
        icon="heroicon-o-user-group"
        compact
    >
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                {{ $total }}
            </span>
            <a
                href="{{ $createUrl }}"
                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('seo-content-ai::filament.projects.unassigned_staff_create') }}
            </a>
        </div>

        @if ($staff === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.projects.unassigned_staff_empty') }}
            </p>
        @else
            <ul class="space-y-2">
                @foreach ($staff as $row)
                    @php
                        $name = (string) ($row['name'] ?? '');
                        $email = (string) ($row['email'] ?? '');
                        $initials = (string) ($row['initials'] ?? '?');
                        $url = (string) ($row['create_url'] ?? $createUrl);
                    @endphp
                    <li>
                        <a
                            href="{{ $url }}"
                            class="flex items-center gap-2 rounded-lg border border-gray-200 px-2 py-1.5 transition hover:border-primary-300 hover:bg-primary-50/50 dark:border-gray-700 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/5"
                            title="{{ __('seo-content-ai::filament.projects.unassigned_staff_create_for', ['name' => $name]) }}"
                        >
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                {{ e($initials) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ e($name) }}</span>
                                @if ($email !== '')
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ e($email) }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($total > count($staff) || $showAll)
                <div class="mt-3">
                    <button
                        type="button"
                        wire:click="toggleShowAll"
                        class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ $showAll
                            ? __('seo-content-ai::filament.projects.unassigned_staff_show_less')
                            : __('seo-content-ai::filament.projects.unassigned_staff_view_all') }}
                    </button>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
