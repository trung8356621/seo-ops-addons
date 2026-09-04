@php
    $clusterKey = (string) ($clusterKey ?? '');
    $mcpExcluded = (bool) ($mcpExcluded ?? false);
    $seoExcluded = (bool) ($seoExcluded ?? false);
    $canDissolve = (bool) ($canDissolve ?? false);
    $mcpGrouped = (bool) ($mcpGrouped ?? false);
@endphp

<div
    x-data="{
        open: false,
        dissolving: false,
        async dissolveTopic() {
            if (this.dissolving) return;
            this.dissolving = true;
            this.open = false;
            const row = this.$root.closest('.cluster-index-row');
            if (row) {
                row.classList.add('is-dissolving');
            }
            try {
                const result = await $wire.dissolveTopicCluster(@js($clusterKey));
                if (result && result.ok) {
                    row?.remove();
                    return;
                }
            } catch (e) {
                // keep row; toast comes from Livewire
            } finally {
                this.dissolving = false;
                row?.classList.remove('is-dissolving');
            }
        }
    }"
    class="relative"
    :class="{ 'pointer-events-none opacity-50': dissolving }"
>
    <button
        type="button"
        class="topic-index-detail-btn"
        @click.stop="open = !open"
        :disabled="dissolving"
        aria-label="{{ __('seo-content-ai::filament.keyword.keyword_item_actions') }}"
    >
        <span x-show="dissolving" class="inline-flex" x-cloak>
            <x-filament::loading-indicator class="h-4 w-4" />
        </span>
        <span x-show="!dissolving" class="inline-flex">
            <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-4 w-4" />
        </span>
    </button>
    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        class="keyword-item__menu"
    >
        @if ($seoExcluded)
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="restoreClusterSeo({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="restoreClusterSeo"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="restoreClusterSeo">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_seo') }}
                </span>
                <span wire:loading wire:target="restoreClusterSeo" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_seo') }}
                </span>
            </button>
        @elseif ($mcpExcluded)
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="restoreClusterMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="restoreClusterMcp"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="restoreClusterMcp">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_mcp') }}
                </span>
                <span wire:loading wire:target="restoreClusterMcp" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_mcp') }}
                </span>
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="excludeClusterFromSeo({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="excludeClusterFromSeo"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="excludeClusterFromSeo">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
                </span>
                <span wire:loading wire:target="excludeClusterFromSeo" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
                </span>
            </button>
        @else
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="skipClusterFromMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="skipClusterFromMcp"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="skipClusterFromMcp">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp') }}
                </span>
                <span wire:loading wire:target="skipClusterFromMcp" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp') }}
                </span>
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="excludeClusterFromSeo({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="excludeClusterFromSeo"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="excludeClusterFromSeo">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
                </span>
                <span wire:loading wire:target="excludeClusterFromSeo" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
                </span>
            </button>
        @endif
        @if ($mcpGrouped)
            <button
                type="button"
                class="keyword-item__menu-item"
                @click="open = false; $dispatch('mcp-group-modal-open', { clusterKey: {{ \Illuminate\Support\Js::from($clusterKey) }} })"
            >
                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_manage_action') }}
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="ungroupMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                wire:loading.attr="disabled"
                wire:target="ungroupMcp"
                @click="open = false"
            >
                <span wire:loading.remove wire:target="ungroupMcp">
                    {{ __('seo-content-ai::filament.keyword.topic_mcp_ungroup_action') }}
                </span>
                <span wire:loading wire:target="ungroupMcp" class="inline-flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.topic_mcp_ungroup_action') }}
                </span>
            </button>
        @else
            <button
                type="button"
                class="keyword-item__menu-item"
                @click="open = false; $dispatch('mcp-group-modal-open', { clusterKey: {{ \Illuminate\Support\Js::from($clusterKey) }} })"
            >
                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_action') }}
            </button>
        @endif
        @if ($canDissolve)
            <button
                type="button"
                class="keyword-item__menu-item keyword-item__menu-item--danger"
                @click.stop="dissolveTopic()"
                :disabled="dissolving"
            >
                {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
            </button>
        @endif
    </div>
</div>
