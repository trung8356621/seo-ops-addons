@if ($this->canDissolveCluster())
    <x-seo-content-ai::content-project-action-menu-shell aria-label="{{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}">
        <button
            type="button"
            role="menuitem"
            class="cp-ops-menu__item cp-ops-menu__item--danger w-full text-left"
            wire:click="openDissolveConfirm({{ \Illuminate\Support\Js::from($clusterKey) }})"
            wire:loading.attr="disabled"
            wire:target="openDissolveConfirm,confirmDissolveCluster"
            @click.stop="open = false"
        >
            {{ __('seo-content-ai::filament.keyword.topic_dissolve_action') }}
        </button>
    </x-seo-content-ai::content-project-action-menu-shell>
@endif
