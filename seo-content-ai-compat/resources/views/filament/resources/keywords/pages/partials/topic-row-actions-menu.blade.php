@php
    $topicId = (int) ($topicId ?? 0);
    $topicName = trim((string) ($topicName ?? ''));
    $canDissolve = (bool) ($canDissolve ?? false);
    $canMutateMcp = (bool) ($canMutateMcp ?? false);
    $mcpExcluded = (bool) ($mcpExcluded ?? false);
    $confirmExclude = __('seo-content-ai::filament.keyword.topic_mcp_exclude_confirm_named', [
        'label' => $topicName !== '' ? $topicName : (string) $topicId,
    ])."\n\n".__('seo-content-ai::filament.keyword.topic_mcp_exclude_confirm_body');
    $confirmRestore = __('seo-content-ai::filament.keyword.topic_mcp_restore_confirm_named', [
        'label' => $topicName !== '' ? $topicName : (string) $topicId,
    ]);
@endphp

@if ($topicId > 0)
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
                const result = await $wire.dissolveTopic({{ $topicId }});
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
        <a
            href="{{ $this->topicUrl($topicId) }}"
            class="keyword-item__menu-item"
            @click="open = false"
        >
            {{ __('seo-content-ai::filament.keyword.topic_view_cluster') }}
        </a>
        @if ($canMutateMcp)
            @if ($mcpExcluded)
                <button
                    type="button"
                    class="keyword-item__menu-item"
                    wire:click="restoreTopicMcp({{ $topicId }})"
                    wire:confirm="{{ $confirmRestore }}"
                    wire:loading.attr="disabled"
                    wire:target="restoreTopicMcp({{ $topicId }})"
                    @click="open = false"
                >
                    {{ __('seo-content-ai::filament.keyword.topic_mcp_restore_action') }}
                </button>
            @else
                <button
                    type="button"
                    class="keyword-item__menu-item"
                    wire:click="excludeTopicFromMcp({{ $topicId }})"
                    wire:confirm="{{ $confirmExclude }}"
                    wire:loading.attr="disabled"
                    wire:target="excludeTopicFromMcp({{ $topicId }})"
                    @click="open = false"
                >
                    {{ __('seo-content-ai::filament.keyword.topic_mcp_exclude_action') }}
                </button>
            @endif
        @endif
        @if ($canDissolve)
            <button
                type="button"
                class="keyword-item__menu-item keyword-item__menu-item--danger"
                @click.stop="dissolveTopic()"
                :disabled="dissolving"
            >
                <span x-show="dissolving" class="inline-flex items-center gap-1.5" x-cloak>
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_working') }}
                </span>
                <span x-show="!dissolving">
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                </span>
            </button>
        @endif
    </div>
</div>
@endif
