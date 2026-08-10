@php
    $canViewStaff = $this->canViewUnassignedStaff();
    $payload = $canViewStaff ? $this->getUnassignedStaffPayload() : ['total' => 0, 'staff' => [], 'month_display' => '', 'month' => $this->planningMonth];
    $total = (int) ($payload['total'] ?? 0);
    $staff = is_array($payload['staff'] ?? null) ? $payload['staff'] : [];
    $monthDisplay = (string) ($payload['month_display'] ?? '');
    $monthOptions = $this->getPlanningMonthOptions();
    $createUrl = $this->createProjectUrl();
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    @if ($canViewStaff)
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <label class="sr-only" for="planning-month">{{ __('seo-content-ai::filament.projects.planning_month') }}</label>
                <x-select
                    id="planning-month"
                    wire:model.live="planningMonth"
                    size="inline"
                    class="min-w-[8.5rem] text-sm"
                >
                    @foreach ($monthOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </x-select>

                <span class="inline-flex items-center gap-1.5 rounded-md bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                    <x-filament::icon icon="heroicon-m-user-group" class="h-3.5 w-3.5" />
                    {{ __('seo-content-ai::filament.projects.unassigned_staff_badge', ['count' => $total]) }}
                </span>

                <div
                    class="relative"
                    x-data="{ open: false }"
                    @keydown.escape.window="open = false"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="open = !open"
                        :aria-expanded="open.toString()"
                    >
                        {{ __('seo-content-ai::filament.projects.unassigned_staff_view') }}
                        <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5" />
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition.opacity.duration.150ms
                        @click.outside="open = false"
                        class="absolute left-0 z-30 mt-2 w-[min(100vw-2rem,26rem)] rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900 sm:w-[26rem]"
                        role="dialog"
                        aria-label="{{ __('seo-content-ai::filament.projects.unassigned_staff_heading') }}"
                    >
                        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-2.5 dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('seo-content-ai::filament.projects.unassigned_staff_heading') }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $monthDisplay }} · {{ $total }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                                @click="open = false"
                            >
                                <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                            </button>
                        </div>

                        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-800">
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="staffSearch"
                                placeholder="{{ __('seo-content-ai::filament.projects.unassigned_staff_search') }}"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            />
                        </div>

                        <div class="max-h-72 overflow-y-auto p-2">
                            @if ($staff === [])
                                <p class="px-2 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.unassigned_staff_empty', ['month' => $monthDisplay]) }}
                                </p>
                            @else
                                <ul class="space-y-1">
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
                                                class="flex items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-primary-50 dark:hover:bg-primary-500/10"
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
                                                <span class="shrink-0 text-xs font-medium text-primary-600 dark:text-primary-400">
                                                    {{ __('seo-content-ai::filament.projects.unassigned_staff_create') }}
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>

                <a
                    href="{{ $createUrl }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-primary-500"
                >
                    {{ __('seo-content-ai::filament.projects.unassigned_staff_create') }}
                </a>
            </div>
        </div>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

    {{ $this->table }}

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
</x-filament-panels::page>
