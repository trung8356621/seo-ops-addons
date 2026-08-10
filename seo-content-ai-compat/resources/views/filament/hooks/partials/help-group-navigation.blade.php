<nav class="help-navigation" data-help-navigation aria-label="{{ __('seo-content-ai::filament.help.groups_nav') }}">
    <template x-for="group in $store.help.filteredGroups" :key="group.id">
        <div
            class="help-group"
            data-help-group
            x-bind:data-help-group-id="group.id"
            x-bind:class="{ 'is-active': $store.help.activeGroupId === group.id }"
        >
            <button
                type="button"
                class="help-group-trigger"
                data-help-group-trigger
                x-bind:aria-current="$store.help.activeGroupId === group.id ? 'true' : 'false'"
                x-on:click="$store.help.selectGroup(group.id)"
                x-text="group.title"
            ></button>
        </div>
    </template>
    <p class="help-empty" x-show="$store.help.filteredGroups.length === 0">
        {{ __('seo-content-ai::filament.help.no_results') }}
    </p>
</nav>
