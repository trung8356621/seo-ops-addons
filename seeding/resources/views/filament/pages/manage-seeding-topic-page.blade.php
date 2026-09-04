<x-filament-panels::page>
    @php
        $topic = $this->topicView();
        $copyText = $this->copyText();
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::button color="gray" size="sm" tag="a" :href="$this->listUrl()">
                {{ __('seeding::filament.topics.back_list') }}
            </x-filament::button>

            @if ($topic)
                <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                        'bg-warning-50 text-warning-800 ring-warning-200 dark:bg-warning-500/10 dark:text-warning-200 dark:ring-warning-500/30' => $topic['is_draft'],
                        'bg-success-50 text-success-800 ring-success-200 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-500/30' => $topic['is_active'],
                    ])>
                        {{ $topic['status_label'] }}
                    </span>

                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray fi-size-sm fi-btn-size-sm gap-1 px-2.5 py-1.5 text-sm inline-grid shadow-sm bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
                        data-copy="{{ e(json_encode($copyText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}"
                        x-on:click="
                            navigator.clipboard.writeText(JSON.parse($el.getAttribute('data-copy') || '\"\"'));
                            copied = true;
                            setTimeout(() => copied = false, 1500);
                        "
                    >
                        <span x-show="!copied">{{ __('seeding::filament.topics.copy_content') }}</span>
                        <span x-cloak x-show="copied">{{ __('seeding::filament.topics.copied') }}</span>
                    </button>

                    @if (! empty($topic['social_url']))
                        <x-filament::button
                            color="gray"
                            size="sm"
                            tag="a"
                            :href="$topic['social_url']"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('seeding::filament.topics.open_social') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>

        @if ($this->showActiveEditWarning())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                {{ __('seeding::filament.topics.active_edit_warning') }}
            </div>
        @endif

        <div class="fi-section space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200" for="seeding-full-text">
                    {{ __('seeding::filament.topics.content_required') }}
                </label>
                <textarea
                    id="seeding-full-text"
                    rows="10"
                    wire:model="fullText"
                    class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    x-data
                    x-on:paste="
                        const html = $event.clipboardData.getData('text/html');
                        if (html && html.trim() !== '') {
                            $wire.set('sourceHtml', html);
                        }
                    "
                ></textarea>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Paste giữ href thật từ clipboard HTML khi có.
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200" for="seeding-social-url-create">
                    {{ __('seeding::filament.topics.social_url_optional') }}
                </label>
                <input
                    id="seeding-social-url-create"
                    type="url"
                    wire:model="socialUrl"
                    placeholder="https://"
                    class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                />
            </div>

            <div class="flex flex-wrap gap-2">
                <x-filament::button
                    color="primary"
                    size="sm"
                    wire:click="saveTopic"
                    wire:loading.attr="disabled"
                    wire:target="saveTopic"
                    class="wire:loading:opacity-50"
                >
                    <span wire:loading.remove wire:target="saveTopic">{{ __('seeding::filament.topics.save_topic') }}</span>
                    <span wire:loading wire:target="saveTopic" class="inline-flex items-center gap-1">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        {{ __('seeding::filament.topics.saving') }}
                    </span>
                </x-filament::button>
            </div>
        </div>

        @if ($topic)
            <div class="fi-section space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('seeding::filament.topics.links_detected') }}: {{ $topic['links_count'] }}
                </div>
                @if ($topic['links_count'] > 0)
                    <ul class="space-y-1 text-xs text-gray-600 dark:text-gray-300">
                        @foreach ($topic['links'] as $link)
                            <li class="break-all">
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $link['normalized_url'] }}
                                </a>
                                <span class="text-gray-400">({{ $link['domain'] }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="fi-section space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('seeding::filament.topics.social_section') }}
                </div>

                @if (! empty($topic['social_platform_label']))
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seeding::filament.topics.platform') }}: {{ $topic['social_platform_label'] }}
                    </div>
                @else
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seeding::filament.topics.social_not_posted') }}
                    </div>
                @endif

                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-200" for="seeding-social-url">
                        {{ __('seeding::filament.topics.social_url_label') }}
                    </label>
                    <input
                        id="seeding-social-url"
                        type="url"
                        wire:model="socialUrl"
                        placeholder="https://"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    />
                </div>

                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="saveSocialUrl"
                    wire:loading.attr="disabled"
                    wire:target="saveSocialUrl"
                >
                    <span wire:loading.remove wire:target="saveSocialUrl">{{ __('seeding::filament.topics.update_social_url') }}</span>
                    <span wire:loading wire:target="saveSocialUrl" class="inline-flex items-center gap-1">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        {{ __('seeding::filament.topics.saving') }}
                    </span>
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
