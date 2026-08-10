<x-filament-panels::page>
    <div class="mx-auto max-w-3xl">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.article_list.domain_mismatch_heading') }}
            </x-slot>

            <x-slot name="description">
                {{ __('seo-content-ai::filament.article_list.domain_mismatch_description') }}
            </x-slot>

            <div class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.article_list.current_domain') }}
                        </p>
                        <p class="mt-1 font-semibold text-gray-950 dark:text-white">
                            {{ $this->currentSite?->domain ?? '—' }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                        <p class="text-sm text-warning-700 dark:text-warning-400">
                            {{ __('seo-content-ai::filament.article_list.article_domain') }}
                        </p>
                        <p class="mt-1 font-semibold text-gray-950 dark:text-white">
                            {{ $this->article?->site?->domain ?? '—' }}
                        </p>
                    </div>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.article_list.article') }}
                    </p>
                    <p class="mt-1 font-medium text-gray-950 dark:text-white">
                        #{{ $this->article?->id }} — {{ $this->article?->title }}
                    </p>
                </div>

                <x-filament::button
                    icon="heroicon-o-arrow-path"
                    wire:click="switchDomainAndContinue"
                    wire:loading.attr="disabled"
                    wire:target="switchDomainAndContinue"
                >
                    {{ __('seo-content-ai::filament.article_list.switch_domain_and_continue') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
