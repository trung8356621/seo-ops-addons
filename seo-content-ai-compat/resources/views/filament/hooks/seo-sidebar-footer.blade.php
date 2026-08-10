<div class="fi-seo-sidebar-footer mt-auto hidden border-t border-gray-200 px-3 py-3 dark:border-white/10 lg:block">
    <button
        type="button"
        class="flex w-full items-center justify-center gap-x-2 rounded-lg px-2 py-2 text-sm font-medium text-gray-600 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
        x-bind:aria-expanded="$store.sidebar.isOpen.toString()"
        aria-label="{{ __('seo-content-ai::filament.sidebar.toggle') }}"
    >
        <x-filament::icon
            icon="heroicon-o-chevron-left"
            class="h-5 w-5 shrink-0 transition-transform duration-200"
            x-bind:class="{ 'rotate-180': ! $store.sidebar.isOpen }"
        />
        <span
            x-show="$store.sidebar.isOpen"
            x-transition:enter="lg:transition lg:delay-100"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="truncate"
        >
            {{ __('seo-content-ai::filament.sidebar.collapse') }}
        </span>
    </button>
</div>
