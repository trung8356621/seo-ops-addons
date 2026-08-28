@php
    $clusterKey = (string) ($clusterKey ?? '');
    $mcpExcluded = (bool) ($mcpExcluded ?? false);
    $seoExcluded = (bool) ($seoExcluded ?? false);
    $canDissolve = (bool) ($canDissolve ?? false);
    $mcpGrouped = (bool) ($mcpGrouped ?? false);
@endphp

<div x-data="{ open: false }" class="relative">
    <button
        type="button"
        class="topic-index-detail-btn"
        @click.stop="open = !open"
        aria-label="{{ __('seo-content-ai::filament.keyword.keyword_item_actions') }}"
    >
        <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-4 w-4" />
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
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.keyword_item_restore_seo') }}
            </button>
        @elseif ($mcpExcluded)
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="restoreClusterMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.keyword_item_restore_mcp') }}
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="excludeClusterFromSeo({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
            </button>
        @else
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="skipClusterFromMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp') }}
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="excludeClusterFromSeo({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
            </button>
        @endif
        @if ($mcpGrouped)
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="openMcpGroupModal({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_manage_action') }}
            </button>
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="ungroupMcp({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.topic_mcp_ungroup_action') }}
            </button>
        @else
            <button
                type="button"
                class="keyword-item__menu-item"
                wire:click="openMcpGroupModal({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_action') }}
            </button>
        @endif
        @if ($canDissolve)
            <button
                type="button"
                class="keyword-item__menu-item keyword-item__menu-item--danger"
                wire:click="openDissolveConfirm({{ \Illuminate\Support\Js::from($clusterKey) }})"
                @click="open = false"
            >
                {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
            </button>
        @endif
    </div>
</div>
