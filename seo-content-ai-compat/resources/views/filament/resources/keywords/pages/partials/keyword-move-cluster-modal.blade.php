<x-filament::modal id="keyword-move-cluster-modal" width="md">
    <x-slot name="heading">
        {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster') }}
    </x-slot>

    <div class="space-y-3">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_target') }}
        </label>
        <x-select wire:model.live="moveClusterTargetKey" class="w-full">
            <option value="">{{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_placeholder') }}</option>
            @foreach ($this->moveClusterOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </x-select>
    </div>

    <x-slot name="footerActions">
        <x-filament::button color="gray" wire:click="$dispatch('close-modal', { id: 'keyword-move-cluster-modal' })">
            {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
        </x-filament::button>
        <x-filament::button
            color="primary"
            wire:click="confirmMoveKeywordCluster"
            wire:loading.attr="disabled"
            :disabled="trim($moveClusterTargetKey ?? '') === ''"
        >
            {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster_confirm') }}
        </x-filament::button>
    </x-slot>
</x-filament::modal>
