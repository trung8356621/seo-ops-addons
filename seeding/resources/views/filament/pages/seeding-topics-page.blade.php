<x-filament-panels::page>
    @php
        $props = $this->workspaceProps();
        $domain = $this->currentSiteDomain();
    @endphp

    <div class="space-y-3">
        @unless ($this->hasLockedGlobalSite())
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-xs font-semibold text-gray-600 dark:text-gray-300" for="seeding-workspace-site">
                    Domain
                </label>
                <x-select
                    id="seeding-workspace-site"
                    wire:model.live="siteId"
                    class="min-w-[200px] rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                >
                    <option value="">—</option>
                    @foreach ($this->sites() as $site)
                        <option value="{{ $site->id }}">{{ $site->domain }}</option>
                    @endforeach
                </x-select>
                @if ($domain)
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $domain }}</span>
                @endif
            </div>
        @endunless

        <div
            wire:ignore
            id="seeding-workspace-root"
            data-props='@json($props, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)'
            class="seeding-workspace-shell min-h-[70vh]"
        ></div>
    </div>

    @push('scripts')
        @viteReactRefresh
        @vite(['addons/seeding/resources/js/seeding-workspace.jsx', 'addons/seeding/resources/css/seeding-workspace.css'])
    @endpush
</x-filament-panels::page>
