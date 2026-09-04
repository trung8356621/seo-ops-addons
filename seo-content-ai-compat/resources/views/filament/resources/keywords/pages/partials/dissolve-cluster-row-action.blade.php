@if ($this->canDissolveCluster())
    <div
        x-data="{
            dissolving: false,
            async dissolveTopic() {
                if (this.dissolving) return;
                this.dissolving = true;
                const row = this.$root.closest('.cluster-index-row');
                if (row) {
                    row.classList.add('is-dissolving');
                }
                try {
                    const result = await $wire.dissolveTopicCluster(@js((string) ($clusterKey ?? '')));
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
        class="inline-flex"
        :class="{ 'pointer-events-none opacity-50': dissolving }"
    >
        <x-seo-content-ai::content-project-action-menu-shell aria-label="{{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}">
            <button
                type="button"
                role="menuitem"
                class="cp-ops-menu__item cp-ops-menu__item--danger w-full text-left"
                @click.stop="dissolveTopic()"
                :disabled="dissolving"
            >
                <span class="inline-flex items-center gap-1.5" x-show="dissolving">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_working') }}
                </span>
                <span x-show="!dissolving">
                    {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
                </span>
            </button>
        </x-seo-content-ai::content-project-action-menu-shell>
    </div>
@endif
