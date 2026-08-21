@if ($this->dissolveClusterKey !== null)
    <div
        wire:key="dissolve-cluster-modal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
        x-data
        x-on:keydown.escape.window="$wire.cancelDissolveConfirm()"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900" @click.stop>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                @if ($this->dissolveClusterLabel !== '')
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_heading_named', ['label' => $this->dissolveClusterLabel]) }}
                @else
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_heading') }}
                @endif
            </h3>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                {{ trans_choice('seo-content-ai::filament.keyword.topic_dissolve_description_count', $this->dissolveClusterKeywordCount, ['count' => number_format($this->dissolveClusterKeywordCount)]) }}
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.topic_dissolve_description_preserve') }}
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    wire:click="cancelDissolveConfirm"
                    wire:loading.attr="disabled"
                    wire:target="confirmDissolveCluster"
                >
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                </x-filament::button>
                <x-filament::button
                    type="button"
                    color="danger"
                    size="sm"
                    wire:click="confirmDissolveCluster"
                    wire:loading.attr="disabled"
                    wire:target="confirmDissolveCluster"
                >
                    <span wire:loading.remove wire:target="confirmDissolveCluster">
                        {{ __('seo-content-ai::filament.keyword.topic_dissolve_confirm') }}
                    </span>
                    <span wire:loading wire:target="confirmDissolveCluster">
                        {{ __('seo-content-ai::filament.keyword.topic_dissolve_working') }}
                    </span>
                </x-filament::button>
            </div>
        </div>
    </div>
@endif
