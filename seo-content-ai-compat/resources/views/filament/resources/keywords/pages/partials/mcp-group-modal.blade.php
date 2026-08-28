@if ($this->mcpGroupModalOpen)
    @php
        $preview = $this->mcpGroupPreview;
        $canConfirm = trim($this->mcpGroupMaskName) !== ''
            && count($this->mcpGroupMemberKeys) >= 2;
        $memberCards = $this->mcpGroupMemberCards;
        usort($memberCards, static function (array $a, array $b): int {
            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });
    @endphp
    {{-- Teleport to body so overlay sits above Filament sidebar / stacking contexts. --}}
    <div
        wire:key="mcp-group-modal-host-{{ $this->mcpGroupAnchorKey }}-{{ $this->clusterDataEpoch }}"
        x-data
        class="hidden"
        aria-hidden="true"
    >
        <template x-teleport="body">
            <div
                class="topic-mcp-group-modal"
                role="dialog"
                aria-modal="true"
                x-on:keydown.escape.window="$wire.closeMcpGroupModal()"
                x-on:click.self="$wire.closeMcpGroupModal()"
            >
                <div class="topic-mcp-group-modal__panel" @click.stop>
                    <div class="topic-mcp-group-modal__header">
                        <h3 class="topic-mcp-group-modal__title">
                            {{ $this->mcpGroupMode === 'manage'
                                ? __('seo-content-ai::filament.keyword.topic_mcp_group_modal_manage_title')
                                : __('seo-content-ai::filament.keyword.topic_mcp_group_modal_title') }}
                        </h3>
                        <button
                            type="button"
                            class="topic-mcp-group-modal__close"
                            wire:click="closeMcpGroupModal"
                            aria-label="{{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}"
                        >×</button>
                    </div>

                    <div class="topic-mcp-group-modal__body">
                        <div class="topic-mcp-group-modal__field">
                            <div class="topic-mcp-group-modal__label-row">
                                <label class="topic-mcp-group-modal__label">
                                    {{ __('seo-content-ai::filament.keyword.topic_mcp_group_mask_label') }}
                                </label>
                                <button
                                    type="button"
                                    class="topic-mcp-group-modal__suggest"
                                    wire:click="resuggestMcpGroupMask"
                                >
                                    {{ __('seo-content-ai::filament.keyword.topic_mcp_group_mask_resuggest') }}
                                </button>
                            </div>
                            <input
                                type="text"
                                wire:model.live.debounce.200ms="mcpGroupMaskName"
                                wire:change="markMcpGroupMaskManual"
                                class="topic-mcp-group-search"
                                placeholder="{{ __('seo-content-ai::filament.keyword.topic_mcp_group_mask_placeholder') }}"
                                autocomplete="off"
                            />
                        </div>

                        <div class="topic-mcp-group-modal__field">
                            <div class="topic-mcp-group-modal__label">
                                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_members_label') }}
                            </div>
                            <div class="topic-mcp-group-modal__chips">
                                @forelse ($memberCards as $card)
                                    <div class="topic-mcp-group-chip">
                                        <span class="topic-mcp-group-chip__label">
                                            <span>{{ $card['label'] }}</span>
                                        </span>
                                        <button
                                            type="button"
                                            class="topic-mcp-group-chip__remove"
                                            wire:click="removeMcpGroupMember({{ \Illuminate\Support\Js::from($card['cluster_key']) }})"
                                            aria-label="{{ __('seo-content-ai::filament.keyword.topic_mcp_group_remove_member') }}"
                                        >×</button>
                                    </div>
                                @empty
                                    <span class="topic-mcp-group-modal__empty">—</span>
                                @endforelse
                            </div>
                        </div>

                        <div class="topic-mcp-group-modal__field topic-mcp-group-modal__field--suggest">
                            <label class="topic-mcp-group-modal__label">
                                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_search_label') }}
                            </label>
                            <input
                                type="search"
                                wire:model.live.debounce.250ms="mcpGroupSearch"
                                class="topic-mcp-group-search"
                                placeholder="{{ __('seo-content-ai::filament.keyword.topic_mcp_group_search_placeholder') }}"
                                autocomplete="off"
                            />
                            @if (trim($this->mcpGroupSearch) !== '')
                                <div class="topic-mcp-group-suggest" wire:loading.class="opacity-60" wire:target="mcpGroupSearch">
                                    @forelse ($this->mcpGroupSuggestions as $suggestion)
                                        <button
                                            type="button"
                                            class="topic-mcp-group-suggest__item"
                                            wire:click="selectMcpGroupSuggestion({{ \Illuminate\Support\Js::from($suggestion['cluster_key']) }})"
                                        >
                                            <span class="topic-mcp-group-suggest__name">{{ $suggestion['label'] }}</span>
                                            <span class="topic-mcp-group-suggest__meta">
                                                {{ number_format((int) ($suggestion['article_count'] ?? 0)) }}
                                                {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                                                ·
                                                {{ number_format((int) ($suggestion['internal_link_count'] ?? 0)) }}
                                                {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}
                                                @if (! empty($suggestion['mcp_group_label']))
                                                    · MCP: {{ $suggestion['mcp_group_label'] }}
                                                @endif
                                            </span>
                                        </button>
                                    @empty
                                        <div class="topic-mcp-group-suggest__empty">
                                            {{ __('seo-content-ai::filament.keyword.topic_mcp_group_search_empty') }}
                                        </div>
                                    @endforelse
                                </div>
                            @endif
                        </div>

                        <div class="topic-mcp-group-preview">
                            <div class="topic-mcp-group-modal__label">
                                {{ __('seo-content-ai::filament.keyword.topic_mcp_group_preview_label') }}
                            </div>
                            @if (($preview['ready'] ?? false) && $canConfirm)
                                <div class="topic-mcp-group-preview__name">
                                    {{ $preview['name'] !== '' ? $preview['name'] : $this->mcpGroupMaskName }}
                                </div>
                                <div class="topic-mcp-group-preview__meta">
                                    {{ number_format((int) $preview['article_count']) }}
                                    {{ __('seo-content-ai::filament.keyword.topic_row_articles_short') }}
                                    ·
                                    {{ number_format((int) $preview['internal_link_count']) }}
                                    {{ __('seo-content-ai::filament.keyword.topic_internal_links_short') }}
                                    ·
                                    {{ $preview['weight_display'] }}%
                                </div>
                            @else
                                <div class="topic-mcp-group-preview__empty">
                                    {{ __('seo-content-ai::filament.keyword.topic_mcp_group_preview_empty') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="topic-mcp-group-modal__footer">
                        <div class="topic-mcp-group-modal__footer-start">
                            @if ($this->mcpGroupMode === 'manage')
                                <x-filament::button
                                    type="button"
                                    color="danger"
                                    size="sm"
                                    outlined
                                    wire:click="dissolveMcpGroupFromModal"
                                    wire:loading.attr="disabled"
                                    wire:target="dissolveMcpGroupFromModal"
                                >
                                    {{ __('seo-content-ai::filament.keyword.topic_mcp_ungroup_action') }}
                                </x-filament::button>
                            @endif
                        </div>
                        <div class="topic-mcp-group-modal__footer-end">
                            <x-filament::button
                                type="button"
                                color="gray"
                                size="sm"
                                wire:click="closeMcpGroupModal"
                            >
                                {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                color="primary"
                                size="sm"
                                wire:click="confirmMcpGroup"
                                wire:loading.attr="disabled"
                                wire:target="confirmMcpGroup"
                                :disabled="! $canConfirm"
                            >
                                <span wire:loading.remove wire:target="confirmMcpGroup">
                                    {{ __('seo-content-ai::filament.keyword.topic_mcp_group_action') }}
                                </span>
                                <span wire:loading wire:target="confirmMcpGroup">
                                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_working') }}
                                </span>
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
