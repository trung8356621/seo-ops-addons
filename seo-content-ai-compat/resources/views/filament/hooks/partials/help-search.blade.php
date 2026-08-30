<div class="help-search" data-help-search>
    <label class="sr-only" for="global-help-search-input">{{ __('seo-content-ai::filament.help.search_label') }}</label>
    <input
        id="global-help-search-input"
        class="help-search__input"
        data-help-search-input
        type="search"
        autocomplete="off"
        placeholder="{{ __('seo-content-ai::filament.help.search_placeholder') }}"
        x-model="$store.help.search"
        x-on:input="$store.help.onSearchInput ? $store.help.onSearchInput() : $store.help.ensureSelectionAfterSearch()"
    />
</div>
