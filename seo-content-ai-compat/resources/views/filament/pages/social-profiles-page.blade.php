<x-filament-panels::page>
    @php
        $profiles = $this->profiles();
        $domain = $this->currentSiteDomain();
    @endphp

    <div class="space-y-4" x-data="{ modalOpen: @entangle('modalOpen') }">
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    @unless ($this->hasLockedGlobalSite())
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="social-site">
                            {{ __('seo-content-ai::filament.article_list.domain') }}
                        </label>
                        <x-select
                            id="social-site"
                            wire:model.live="siteId"
                            class="min-w-[220px] rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        >
                            <option value="">—</option>
                            @foreach ($this->sites() as $site)
                                <option value="{{ $site->id }}">{{ $site->domain }}</option>
                            @endforeach
                        </x-select>
                    @else
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            {{ $domain ?? ('Site #'.(int) ($siteId ?? 0)) }}
                        </span>
                    @endunless
                </div>

                @if ((int) ($siteId ?? 0) > 0)
                    <x-filament::button
                        color="primary"
                        size="sm"
                        wire:click="openCreateModal"
                    >
                        {{ __('seo-content-ai::filament.social.add_profile') }}
                    </x-filament::button>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.social.hint') }}
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($profiles as $profile)
                    @php
                        $active = (bool) ($profile['is_active'] ?? false);
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3" wire:key="social-profile-{{ $profile['id'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $profile['platform_label'] ?? '' }}
                            </div>
                            <div class="mt-0.5 text-sm text-gray-700 dark:text-gray-200">
                                {{ $profile['display_name'] ?? '' }}
                            </div>
                            <a
                                href="{{ $profile['profile_url'] ?? '#' }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-0.5 break-all text-xs text-primary-600 hover:underline dark:text-primary-400"
                            >{{ $profile['profile_url'] ?? '' }}</a>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                wire:click="toggleActive({{ (int) $profile['id'] }})"
                                class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $active ? 'bg-success-50 text-success-800 ring-success-200 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-500/30' : 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' }}"
                            >
                                {{ $active ? __('seo-content-ai::filament.social.status_active') : __('seo-content-ai::filament.social.status_inactive') }}
                            </button>
                            <x-filament::button color="gray" size="sm" wire:click="openEditModal({{ (int) $profile['id'] }})">
                                {{ __('seo-content-ai::filament.social.edit') }}
                            </x-filament::button>
                            <x-filament::button
                                color="danger"
                                size="sm"
                                wire:click="deleteProfile({{ (int) $profile['id'] }})"
                                wire:confirm="{{ __('seo-content-ai::filament.social.delete_confirm') }}"
                            >
                                {{ __('seo-content-ai::filament.social.delete') }}
                            </x-filament::button>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.social.empty') }}
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- Client-side modal — không gọi Livewire chỉ để mở/đóng --}}
        <div
            x-cloak
            x-show="modalOpen"
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4"
            @keydown.escape.window="modalOpen = false; $wire.closeModal()"
        >
            <div
                class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900 p-4"
                @click.outside="modalOpen = false; $wire.closeModal()"
            >
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $editingId ? __('seo-content-ai::filament.social.edit_heading') : __('seo-content-ai::filament.social.create_heading') }}
                </h3>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.social.field_platform') }}
                        </label>
                        <x-select wire:model="formPlatform" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            @foreach ($this->platformOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.social.field_display_name') }}
                        </label>
                        <input
                            type="text"
                            wire:model="formDisplayName"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                            {{ __('seo-content-ai::filament.social.field_url') }}
                        </label>
                        <input
                            type="url"
                            wire:model="formProfileUrl"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            placeholder="https://"
                        />
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="formIsActive" class="rounded border-gray-300" />
                        {{ __('seo-content-ai::filament.social.field_active') }}
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <x-filament::button color="gray" size="sm" @click="modalOpen = false; $wire.closeModal()">
                        {{ __('seo-content-ai::filament.social.cancel') }}
                    </x-filament::button>
                    <x-filament::button
                        color="primary"
                        size="sm"
                        wire:click="saveProfile"
                        wire:loading.attr="disabled"
                        wire:target="saveProfile"
                    >
                        <span wire:loading.remove wire:target="saveProfile">{{ __('seo-content-ai::filament.social.save') }}</span>
                        <span wire:loading wire:target="saveProfile" class="inline-flex items-center gap-1">
                            <x-filament::loading-indicator class="h-3.5 w-3.5" />
                            {{ __('seo-content-ai::filament.social.saving') }}
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
