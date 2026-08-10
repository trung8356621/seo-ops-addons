<x-filament-panels::page>
    <div class="space-y-6">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.tools.title') }}
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('seo-content-ai::filament.tools.description') }}
            </p>
        </header>

        {{ $this->form }}
    </div>
</x-filament-panels::page>
