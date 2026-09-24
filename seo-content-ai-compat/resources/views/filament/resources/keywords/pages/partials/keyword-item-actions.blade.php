@php
    use Omnichannel\Addons\SearchFoundation\Models\Keyword;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;

    /** @var Keyword|null $keyword */
    $keyword = $keyword ?? null;
    $siteId = isset($siteId) ? (int) $siteId : null;

    if ($keyword === null) {
        return;
    }

    $item = app(KeywordItemPresenter::class)->present(
        keyword: $keyword,
        context: KeywordItemPresenter::CONTEXT_DICTIONARY,
        siteId: $siteId,
    );

    if (! ($item['can_mutate'] ?? false)) {
        return;
    }
@endphp

<div
    class="relative"
    x-data="{
        menuOpen: false,
        menuStyle: '',
        toggleMenu() {
            this.menuOpen = !this.menuOpen;
            if (this.menuOpen) {
                this.$nextTick(() => this.repositionMenu());
            } else {
                this.menuStyle = '';
            }
        },
        repositionMenu() {
            const panel = this.$refs.menu;
            const btn = this.$refs.menuTrigger;
            if (!panel || !btn) return;
            const br = btn.getBoundingClientRect();
            const pw = Math.min(280, Math.max(192, panel.offsetWidth || 192));
            const ph = panel.offsetHeight || 160;
            const spaceBelow = window.innerHeight - br.bottom;
            const spaceAbove = br.top;
            const flipUp = spaceBelow < ph + 12 && spaceAbove > spaceBelow;
            let top = flipUp ? (br.top - ph - 4) : (br.bottom + 4);
            let left = br.right - pw;
            if (left < 12) left = 12;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            if (top < 12) top = 12;
            if (top + ph > window.innerHeight - 12) {
                top = Math.max(12, window.innerHeight - ph - 12);
            }
            this.menuStyle = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;z-index:80;';
        },
    }"
    @keydown.escape.window="menuOpen = false; menuStyle = ''"
    @resize.window="menuOpen && repositionMenu()"
    @scroll.window="menuOpen && repositionMenu()"
>
    <button
        type="button"
        x-ref="menuTrigger"
        class="keyword-row-action keyword-row-action--menu fi-icon-btn"
        @click.stop="toggleMenu()"
        :aria-expanded="menuOpen.toString()"
        aria-haspopup="menu"
        title="{{ __('seo-content-ai::filament.keyword.keyword_item_actions') }}"
        aria-label="{{ __('seo-content-ai::filament.keyword.keyword_item_actions') }}"
    >
        <x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-5 w-5" />
    </button>
    <template x-teleport="body">
        <div
            x-ref="menu"
            x-show="menuOpen"
            x-cloak
            x-transition
            @click.outside="if (!$refs.menuTrigger?.contains($event.target)) { menuOpen = false; menuStyle = '' }"
            role="menu"
            class="keyword-item__menu keyword-item__menu--portal"
            :style="menuStyle"
        >
            <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="openKeywordEdit({{ $item['keyword_id'] }})" @click="menuOpen = false">
                {{ __('seo-content-ai::filament.keyword.edit') }}
            </button>
            <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="openKeywordLinkedArticles({{ $item['keyword_id'] }})" @click="menuOpen = false">
                {{ __('seo-content-ai::filament.keyword.keyword_item_view_linked_articles') }}
            </button>
            @if ($item['can_skip_mcp'])
                <button
                    type="button"
                    role="menuitem"
                    class="keyword-item__menu-item"
                    wire:click="skipKeywordFromMcp({{ $item['keyword_id'] }})"
                    wire:confirm="{{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp_confirm_heading_named', ['phrase' => $item['display_phrase']]) }}\n\n{{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp_confirm_body') }}"
                    @click="menuOpen = false"
                >
                    {{ __('seo-content-ai::filament.keyword.keyword_item_skip_mcp') }}
                </button>
            @endif
            @if ($item['can_restore_mcp'])
                <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="restoreKeywordMcp({{ $item['keyword_id'] }})" @click="menuOpen = false">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_mcp') }}
                </button>
            @endif
            @if ($item['can_hide'])
                <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="hideKeywordFromSeo({{ $item['keyword_id'] }})" @click="menuOpen = false">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_exclude_seo') }}
                </button>
            @endif
            @if ($item['can_restore'])
                <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="restoreHiddenKeyword({{ $item['keyword_id'] }})" @click="menuOpen = false">
                    {{ __('seo-content-ai::filament.keyword.keyword_item_restore_seo') }}
                </button>
            @endif
        </div>
    </template>
</div>
