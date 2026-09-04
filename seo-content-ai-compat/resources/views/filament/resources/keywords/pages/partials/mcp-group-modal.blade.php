{{-- Always mounted so Alpine can open the shell before Livewire prepare finishes. --}}
<div
    wire:key="mcp-group-modal-host"
    x-data="{
        shellOpen: false,
        opening: false,
        requestSeq: 0,
        async openShell(clusterKey) {
            if (this.opening) {
                return;
            }
            const seq = ++this.requestSeq;
            this.opening = true;
            this.shellOpen = true;
            try {
                await $wire.prepareMcpGroupModal(clusterKey);
            } catch (e) {
                // Server sets phase=error when possible; keep shell open.
            } finally {
                if (seq === this.requestSeq) {
                    this.opening = false;
                }
            }
        },
        closeShell() {
            this.requestSeq++;
            this.opening = false;
            this.shellOpen = false;
            $wire.closeMcpGroupModal();
        },
        syncFromServer(open) {
            if (open) {
                this.shellOpen = true;
            } else if (!this.opening) {
                this.shellOpen = false;
            }
        },
        showLoading() {
            return this.opening
                || $wire.mcpGroupModalPhase === 'loading'
                || $wire.mcpGroupModalPhase === 'idle';
        },
        showError() {
            return !this.opening && $wire.mcpGroupModalPhase === 'error';
        },
        showReady() {
            return !this.opening && $wire.mcpGroupModalPhase === 'ready';
        }
    }"
    x-init="
        syncFromServer($wire.mcpGroupModalOpen);
        $watch(() => $wire.mcpGroupModalOpen, (value) => syncFromServer(value));
    "
    x-on:mcp-group-modal-open.window="openShell($event.detail.clusterKey)"
    class="hidden"
    aria-hidden="true"
>
    <template x-teleport="body">
        <div
            x-show="shellOpen"
            x-cloak
            class="topic-mcp-group-modal"
            role="dialog"
            aria-modal="true"
            x-on:keydown.escape.window="if (shellOpen) closeShell()"
            x-on:click.self="closeShell()"
        >
            <div class="topic-mcp-group-modal__panel" @click.stop>
                <div class="topic-mcp-group-modal__header">
                    <h3 class="topic-mcp-group-modal__title">
                        <span x-show="showReady() && $wire.mcpGroupMode === 'manage'" x-cloak>
                            {{ __('seo-content-ai::filament.keyword.topic_mcp_group_modal_manage_title') }}
                        </span>
                        <span x-show="!(showReady() && $wire.mcpGroupMode === 'manage')" x-cloak>
                            {{ __('seo-content-ai::filament.keyword.topic_mcp_group_modal_title') }}
                        </span>
                    </h3>
                    <button
                        type="button"
                        class="topic-mcp-group-modal__close"
                        x-on:click="closeShell()"
                        aria-label="{{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}"
                    >×</button>
                </div>

                <div class="topic-mcp-group-modal__body">
                    <div x-show="showLoading()" x-cloak>
                        <x-seo-content-ai::modal-loading-placeholder />
                    </div>

                    <div x-show="showError()" x-cloak class="space-y-3" role="alert">
                        <p class="text-sm text-danger-600 dark:text-danger-400">
                            {{ $this->mcpGroupModalError !== ''
                                ? $this->mcpGroupModalError
                                : __('seo-content-ai::filament.keyword.topic_mcp_group_modal_load_failed') }}
                        </p>
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            wire:click="retryMcpGroupModal"
                            wire:loading.attr="disabled"
                            wire:target="retryMcpGroupModal"
                        >
                            {{ __('seo-content-ai::filament.keyword.modal_load_retry') }}
                        </x-filament::button>
                    </div>

                    <div x-show="showReady()" x-cloak wire:key="mcp-group-modal-ready-{{ $this->mcpGroupAnchorKey }}-{{ $this->mcpGroupModalPhase }}">
                        @if ($this->mcpGroupModalPhase === 'ready')
                            @php
                                $preview = $this->mcpGroupPreview;
                                $canConfirm = trim($this->mcpGroupMaskName) !== ''
                                    && count($this->mcpGroupMemberKeys) >= 2;
                                $memberCards = $this->mcpGroupMemberCards;
                                usort($memberCards, static function (array $a, array $b): int {
                                    return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                                });
                            @endphp

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
                        @endif
                    </div>
                </div>

                <div class="topic-mcp-group-modal__footer">
                    <div class="topic-mcp-group-modal__footer-start">
                        <div x-show="showReady() && $wire.mcpGroupMode === 'manage'" x-cloak>
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
                        </div>
                    </div>
                    <div class="topic-mcp-group-modal__footer-end">
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            x-on:click="closeShell()"
                        >
                            {{ __('seo-content-ai::filament.keyword.topic_dissolve_cancel') }}
                        </x-filament::button>
                        @php
                            $canConfirmReady = $this->mcpGroupModalPhase === 'ready'
                                && trim($this->mcpGroupMaskName) !== ''
                                && count($this->mcpGroupMemberKeys) >= 2;
                        @endphp
                        <x-filament::button
                            type="button"
                            color="primary"
                            size="sm"
                            wire:click="confirmMcpGroup"
                            wire:loading.attr="disabled"
                            wire:target="confirmMcpGroup"
                            x-bind:disabled="!showReady() || @js(! $canConfirmReady)"
                            :disabled="! $canConfirmReady"
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
