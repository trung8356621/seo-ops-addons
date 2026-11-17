<x-filament-panels::page>
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.domain.cta_manager_site_label') }}
                    </p>
                    <p class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ $this->data['site_domain'] ?? '' }}
                        <span class="ml-2 text-sm font-normal text-gray-500">#{{ $this->data['site_id'] ?? '' }}</span>
                    </p>
                </div>
                <div class="text-right text-sm text-gray-600 dark:text-gray-300">
                    @if (! empty($this->statusMessage))
                        <p wire:loading.remove wire:target="generateWithAi,saveTemplates,confirmProposals">{{ $this->statusMessage }}</p>
                    @endif
                    <p wire:loading wire:target="generateWithAi" class="font-medium text-primary-600">
                        {{ __('seo-content-ai::filament.domain.cta_manager_generating') }}
                    </p>
                    <p wire:loading wire:target="saveTemplates" class="font-medium text-primary-600">
                        {{ __('seo-content-ai::filament.domain.cta_manager_saving') }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400">
                        {{ __('seo-content-ai::filament.domain.cta_manager_pool_count') }}:
                        {{ (int) ($this->data['pool_count'] ?? 0) }}
                        @if (! empty($this->data['next_slot_seq']))
                            · next CTA_{{ (int) $this->data['next_slot_seq'] }}
                        @endif
                    </p>
                    @if (! empty($this->data['last_generated_at']))
                        <p class="mt-1 text-xs text-gray-400">
                            {{ __('seo-content-ai::filament.domain.cta_manager_last_generated') }}:
                            {{ $this->data['last_generated_at'] }}
                        </p>
                    @endif
                </div>
            </div>

            @if (! empty($this->warnings))
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    <p class="font-medium">{{ __('seo-content-ai::filament.domain.cta_manager_context_warnings') }}</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($this->warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if ($this->proposalOpen && count($this->proposals) > 0)
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 shadow-sm dark:border-primary-800 dark:bg-primary-950/30">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('seo-content-ai::filament.domain.cta_manager_proposal_title_append') }}
                </h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.domain.cta_manager_proposal_help_append') }}
                </p>
                <div class="mt-3 max-h-80 space-y-2 overflow-y-auto">
                    @foreach ($this->proposals as $proposal)
                        <div class="rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <div class="font-medium text-gray-800 dark:text-gray-100">
                                CTA_{{ (int) ($proposal['slot'] ?? 0) }}
                                @if (! empty($proposal['intent']))
                                    <span class="ml-2 text-xs font-normal text-gray-500">{{ $proposal['intent'] }}</span>
                                @endif
                                <span class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                    {{ __('seo-content-ai::filament.domain.cta_manager_will_append') }}
                                </span>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-200">{{ $proposal['content'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button wire:click="confirmProposals" color="primary" wire:loading.attr="disabled" wire:target="confirmProposals">
                        <span wire:loading.remove wire:target="confirmProposals">{{ __('seo-content-ai::filament.domain.cta_manager_confirm_append') }}</span>
                        <span wire:loading wire:target="confirmProposals">{{ __('seo-content-ai::filament.domain.cta_manager_saving') }}</span>
                    </x-filament::button>
                    <x-filament::button wire:click="discardProposals" color="gray">
                        {{ __('seo-content-ai::filament.domain.cta_manager_discard') }}
                    </x-filament::button>
                </div>
            </div>
        @endif

        <form wire:submit="saveTemplates" class="space-y-4">
            {{ $this->form }}
        </form>
    </div>
</x-filament-panels::page>
