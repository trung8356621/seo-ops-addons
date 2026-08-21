@if ($this->canDissolveCluster())
    <x-content-project-action-menu-shell aria-label="{{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}">
        <button
            type="button"
            role="menuitem"
            class="cp-ops-menu__item cp-ops-menu__item--danger w-full text-left"
            wire:click="openDissolveConfirm({{ json_encode($clusterKey) }})"
            @click="open = false"
        >
            {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
        </button>
    </x-content-project-action-menu-shell>
@endif
