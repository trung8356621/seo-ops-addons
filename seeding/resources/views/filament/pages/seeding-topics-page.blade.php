<x-filament-panels::page>
    @php
        $topics = $this->topics();
        $domain = $this->currentSiteDomain();
    @endphp

    <div class="space-y-4">
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    @unless ($this->hasLockedGlobalSite())
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300" for="seeding-site">
                            Domain
                        </label>
                        <x-select
                            id="seeding-site"
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
                    <x-filament::button color="primary" size="sm" tag="a" :href="$this->createUrl()">
                        {{ __('seeding::filament.topics.create') }}
                    </x-filament::button>
                @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($topics as $topic)
                    <li
                        class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                        wire:key="seeding-topic-{{ $topic['id'] }}"
                        x-data="{ copied: false }"
                    >
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                                    'bg-warning-50 text-warning-800 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-200 dark:ring-warning-500/30' => $topic['is_draft'],
                                    'bg-success-50 text-success-800 ring-success-200 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-500/30' => $topic['is_active'],
                                ])>
                                    {{ $topic['status_label'] }}
                                </span>
                                @if (! empty($topic['social_platform_label']))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $topic['social_platform_label'] }}
                                    </span>
                                @endif
                            </div>
                            <div class="text-sm text-gray-900 dark:text-gray-100">
                                {{ $topic['preview'] }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('seeding::filament.topics.links_detected') }}: {{ $topic['links_count'] }}
                                ·
                                {{ __('seeding::filament.topics.social_section') }}:
                                {{ $topic['social_url'] ? $topic['social_url'] : __('seeding::filament.topics.social_not_posted') }}
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray fi-size-sm fi-btn-size-sm gap-1 px-2.5 py-1.5 text-sm inline-grid shadow-sm bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
                                data-copy="{{ e(json_encode($topic['full_text'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}"
                                x-on:click="
                                    navigator.clipboard.writeText(JSON.parse($el.getAttribute('data-copy') || '\"\"'));
                                    copied = true;
                                    setTimeout(() => copied = false, 1500);
                                "
                            >
                                <span x-show="!copied">{{ __('seeding::filament.topics.copy_content') }}</span>
                                <span x-cloak x-show="copied">{{ __('seeding::filament.topics.copied') }}</span>
                            </button>

                            <x-filament::button color="gray" size="sm" tag="a" :href="$topic['manage_url']">
                                {{ __('seeding::filament.topics.update') }}
                            </x-filament::button>

                            @if ($topic['is_draft'])
                                <x-filament::button
                                    color="danger"
                                    size="sm"
                                    wire:click="deleteTopic({{ (int) $topic['id'] }})"
                                    wire:confirm="{{ __('seeding::filament.topics.delete_confirm') }}"
                                >
                                    <span wire:loading.remove wire:target="deleteTopic({{ (int) $topic['id'] }})">
                                        {{ __('seeding::filament.topics.delete') }}
                                    </span>
                                    <span wire:loading wire:target="deleteTopic({{ (int) $topic['id'] }})" class="inline-flex items-center gap-1">
                                        <x-filament::loading-indicator class="h-4 w-4" />
                                    </span>
                                </x-filament::button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seeding::filament.topics.empty') }}
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</x-filament-panels::page>
