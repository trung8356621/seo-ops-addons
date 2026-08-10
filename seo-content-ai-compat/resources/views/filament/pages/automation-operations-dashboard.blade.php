<x-filament-panels::page>
    <div
        wire:poll.10s="refreshCounters"
        class="space-y-6"
        x-data="{
            clearMenuOpen: false,
            confirmOpen: false,
            confirmScope: null,
            confirmSubmitting: false,
            openConfirm(scope) {
                this.clearMenuOpen = false;
                this.confirmScope = scope;
                this.confirmOpen = true;
            },
            closeConfirm() {
                this.confirmOpen = false;
                this.confirmScope = null;
                this.confirmSubmitting = false;
            },
            submitClear() {
                if (!this.confirmScope || this.confirmSubmitting) {
                    return;
                }
                this.confirmSubmitting = true;
                $wire.clearLogs(this.confirmScope)
                    .then(() => this.closeConfirm())
                    .catch(() => { this.confirmSubmitting = false; });
            },
        }"
        @keydown.escape.window="closeConfirm()"
    >
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Automation operations</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Executions monitor — failed, stale, dead letter</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button
                        type="button"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        wire:click="refreshCounters"
                        wire:loading.attr="disabled"
                        wire:target="refreshCounters,clearLogs"
                    >
                        {{ __('seo-content-ai::filament.automation.refresh') }}
                    </x-filament::button>

                    @if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canClearAutomationLogs())
                        <div class="relative">
                            <x-filament::button
                                type="button"
                                color="danger"
                                icon="heroicon-o-trash"
                                class="!inline-flex !items-center"
                                x-on:click="clearMenuOpen = !clearMenuOpen"
                            >
                                <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                    {{ __('seo-content-ai::filament.automation.clear_logs') }}
                                    <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                            </x-filament::button>
                            <div
                                x-show="clearMenuOpen"
                                x-cloak
                                @click.outside="clearMenuOpen = false"
                                class="absolute right-0 z-20 mt-2 w-52 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                            >
                                <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="openConfirm('completed')">
                                    {{ __('seo-content-ai::filament.automation.clear_completed') }}
                                </button>
                                <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="openConfirm('failed')">
                                    {{ __('seo-content-ai::filament.automation.clear_failed') }}
                                </button>
                                <button type="button" class="block w-full px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800" @click="openConfirm('partial')">
                                    {{ __('seo-content-ai::filament.automation.clear_partial') }}
                                </button>
                                <button type="button" class="block w-full px-4 py-2 text-left text-sm text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30" @click="openConfirm('all')">
                                    {{ __('seo-content-ai::filament.automation.clear_all') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    @if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canRetryAutomationExecution())
                        <x-filament::button
                            type="button"
                            color="warning"
                            icon="heroicon-o-wrench"
                            wire:click="recoverStale"
                            wire:loading.attr="disabled"
                            wire:target="recoverStale"
                        >
                            Recover stale
                        </x-filament::button>
                    @endif
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'all' => 'All',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'partial' => 'Partial',
                    'processing' => 'Processing',
                    'cancelled' => 'Cancelled',
                    'dead_letter' => 'Dead letter',
                    'stale' => 'Stale',
                ] as $key => $label)
                    <button
                        type="button"
                        wire:click="setFilter('{{ $key }}')"
                        class="rounded-lg border px-4 py-3 text-left transition {{ $filter === $key ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/30' : 'border-gray-200 dark:border-gray-700' }}"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $counters[$key] ?? 0 }}</p>
                    </button>
                @endforeach
            </div>
        </section>

        {{ $this->table }}

        <div
            x-show="confirmOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="absolute inset-0 bg-gray-950/50" @click="closeConfirm()"></div>
            <div class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ __('seo-content-ai::filament.automation.clear_execution_logs_title') }}
                </h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('seo-content-ai::filament.automation.clear_execution_logs_body') }}
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <x-filament::button type="button" color="gray" @click="closeConfirm()" x-bind:disabled="confirmSubmitting">
                        {{ __('seo-content-ai::filament.automation.clear_cancel') }}
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="danger"
                        @click="submitClear()"
                        x-bind:disabled="confirmSubmitting"
                        x-bind:class="confirmSubmitting ? 'opacity-50 pointer-events-none' : ''"
                    >
                        <span x-show="!confirmSubmitting">{{ __('seo-content-ai::filament.automation.clear_delete') }}</span>
                        <span x-show="confirmSubmitting" x-cloak class="inline-flex items-center gap-2">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            {{ __('seo-content-ai::filament.automation.clear_delete') }}
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
