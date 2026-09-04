<div
    x-data="{
        clientLoading: false,
        showLoading() {
            return this.clientLoading
                || $wire.moveClusterModalPhase === 'loading'
                || $wire.moveClusterModalPhase === 'idle';
        },
        showError() {
            return !this.clientLoading && $wire.moveClusterModalPhase === 'error';
        },
        showReady() {
            return !this.clientLoading && $wire.moveClusterModalPhase === 'ready';
        }
    }"
    x-on:open-modal.window="if ($event.detail?.id === 'keyword-move-cluster-modal') { clientLoading = true }"
    x-init="$watch(() => $wire.moveClusterModalPhase, (phase) => {
        if (phase === 'ready' || phase === 'error' || phase === 'idle') {
            clientLoading = false;
        }
    })"
>
    <x-filament::modal
        id="keyword-move-cluster-modal"
        width="md"
        x-on:close-modal.window="if ($event.detail?.id === 'keyword-move-cluster-modal') { clientLoading = false; $wire.resetMoveClusterModal() }"
    >
        <x-slot name="heading">
            {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster') }}
        </x-slot>

        <div class="space-y-3">
            <div x-show="showLoading()" x-cloak>
                <x-seo-content-ai::modal-loading-placeholder />
            </div>

            <div x-show="showError()" x-cloak class="space-y-3" role="alert">
                <p class="text-sm text-danger-600 dark:text-danger-400">
                    {{ $this->moveClusterModalError !== ''
                        ? $this->moveClusterModalError
                        : __('seo-content-ai::filament.keyword.keyword_item_move_cluster_loading_failed') }}
                </p>
                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    wire:click="retryMoveClusterModal"
                    wire:loading.attr="disabled"
                    wire:target="retryMoveClusterModal"
                >
                    {{ __('seo-content-ai::filament.keyword.modal_load_retry') }}
                </x-filament::button>
            </div>

            <div x-show="showReady()" x-cloak class="space-y-3">
                @if ($this->moveClusterModalPhase === 'ready')
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_target') }}
                    </label>
                    <x-select wire:model.live="moveClusterTargetKey" class="w-full">
                        <option value="">{{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_placeholder') }}</option>
                        @foreach ($this->moveClusterOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                @endif
            </div>
        </div>

        <x-slot name="footerActions">
            <x-filament::button
                color="gray"
                wire:click="$dispatch('close-modal', { id: 'keyword-move-cluster-modal' }); $wire.resetMoveClusterModal()"
            >
                {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
            </x-filament::button>
            <x-filament::button
                color="primary"
                wire:click="confirmMoveKeywordCluster"
                wire:loading.attr="disabled"
                wire:target="confirmMoveKeywordCluster"
                x-bind:disabled="!showReady() || {{ trim($moveClusterTargetKey ?? '') === '' ? 'true' : 'false' }}"
                :disabled="$this->moveClusterModalPhase !== 'ready' || trim($moveClusterTargetKey ?? '') === ''"
            >
                <span wire:loading.remove wire:target="confirmMoveKeywordCluster">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_confirm') }}
                </span>
                <span wire:loading wire:target="confirmMoveKeywordCluster" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_confirm') }}
                </span>
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
