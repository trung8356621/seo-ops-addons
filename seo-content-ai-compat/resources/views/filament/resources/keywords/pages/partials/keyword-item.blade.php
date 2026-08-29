@php
    use Omnichannel\Addons\SearchFoundation\Models\Keyword;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;

    /** @var Keyword|null $keyword */
    $keyword = $keyword ?? null;
    $context = (string) ($context ?? KeywordItemPresenter::CONTEXT_DICTIONARY);
    $siteId = isset($siteId) ? (int) $siteId : null;
    $dnaValues = is_array($dnaValues ?? null) ? $dnaValues : null;
    $clusterKey = (string) ($clusterKey ?? '');
    $showCheckbox = (bool) ($showCheckbox ?? false);
    $showActions = (bool) ($showActions ?? true);

    if ($keyword === null) {
        return;
    }

    $item = app(KeywordItemPresenter::class)->present(
        keyword: $keyword,
        context: $context,
        siteId: $siteId,
        dnaValues: $dnaValues,
        clusterKey: $clusterKey,
    );
@endphp

<div
    @class([
        'keyword-item',
        'keyword-item--seo-excluded' => (bool) ($item['is_hidden'] ?? false),
    ])
    wire:key="keyword-item-{{ $item['keyword_id'] }}-{{ $item['context'] }}"
    x-data="{
        menuOpen: false,
        menuStyle: '',
        editing: false,
        saving: false,
        value: @js($item['display_phrase']),
        original: @js($item['display_phrase']),
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
            // Fixed + body teleport escapes table/card overflow clipping.
            this.menuStyle = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;z-index:80;';
        },
        startEdit() {
            if (this.saving || !@js($item['can_edit_phrase'])) return;
            this.editing = true;
            this.$nextTick(() => this.$refs.phraseInput?.focus());
        },
        cancel() {
            this.value = this.original;
            this.editing = false;
        },
        async save() {
            const next = (this.value || '').trim().replace(/\s+/g, ' ');
            if (next === '' || next === this.original) {
                this.cancel();
                return;
            }
            this.saving = true;
            this.editing = false;
            try {
                const saved = await $wire.saveKeywordPhraseInline(@js($item['keyword_id']), next);
                if (saved) {
                    this.original = saved;
                    this.value = saved;
                } else {
                    this.value = this.original;
                }
            } catch (e) {
                this.value = this.original;
            } finally {
                this.saving = false;
            }
        }
    }"
    @keydown.escape.window="menuOpen = false; menuStyle = ''"
    @resize.window="menuOpen && repositionMenu()"
    @scroll.window="menuOpen && repositionMenu()"
>
    @if ($showCheckbox)
        <div class="keyword-item__checkbox">
            <input
                type="checkbox"
                class="fi-checkbox-input rounded border-none bg-white shadow-sm ring-1 ring-gray-950/10 checked:ring-0 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:ring-white/20 dark:checked:bg-primary-500 dark:focus:ring-primary-500"
                wire:loading.attr="disabled"
                @checked(false)
                x-on:click.stop
                disabled
            />
        </div>
    @endif

    <div class="keyword-item__body">
        <div
            x-show="!editing"
            @dblclick.prevent="startEdit()"
            class="keyword-item__phrase"
            :class="{ 'opacity-60': saving }"
            title="{{ __('seo-content-ai::filament.keyword.keyword_item_phrase_edit_hint') }}"
            x-text="value"
        ></div>
        <input
            x-show="editing"
            x-cloak
            x-ref="phraseInput"
            type="text"
            class="keyword-item__phrase-input"
            x-model="value"
            @keydown.enter.prevent.stop="save()"
            @keydown.escape.prevent.stop="cancel()"
            @blur="if (editing && !saving) save()"
            :disabled="saving"
        />

        @if ($item['semantic_tags'] !== [])
            <div class="keyword-item__semantic">
                @foreach ($item['semantic_tags'] as $tag)
                    <span class="semantic-tag semantic-tag--{{ $tag['tone'] }}">{{ $tag['label'] }}</span>
                @endforeach
            </div>
        @endif

        @if ($item['planning_tags'] !== [])
            <div class="keyword-item__planning">
                @foreach ($item['planning_tags'] as $tag)
                    <span class="{{ $tag['badge_class'] }}">{{ $tag['label'] }}</span>
                @endforeach
            </div>
        @endif

        @if ($item['operational_tags'] !== [])
            <div class="keyword-item__operational">
                @foreach ($item['operational_tags'] as $tag)
                    <span class="{{ $tag['badge_class'] }}">{{ $tag['label'] }}</span>
                @endforeach
            </div>
        @endif

        <div class="keyword-item__footer">
            @if ($item['show_cluster'] && $item['cluster_label'] !== '—')
                <span class="keyword-item__cluster">
                    {{ __('seo-content-ai::filament.keyword.cluster_label') }}:
                    @if ($item['cluster_url'])
                        <a href="{{ $item['cluster_url'] }}" class="keyword-item__cluster-link">{{ $item['cluster_label'] }}</a>
                    @else
                        {{ $item['cluster_label'] }}
                    @endif
                </span>
            @endif

            <span class="keyword-item__meta-line">
                @if ($item['intent_label'] !== '')
                    <span class="keyword-item__intent">{{ $item['intent_label'] }}</span>
                    <span class="keyword-item__sep">·</span>
                @endif
                @if ((int) $item['article_count'] > 0)
                    <button
                        type="button"
                        class="keyword-item__articles-btn"
                        wire:click="openKeywordLinkedArticles({{ $item['keyword_id'] }})"
                        wire:loading.attr="disabled"
                    >
                        {{ $item['article_count_label'] }}
                    </button>
                @else
                    <span class="keyword-item__articles">{{ $item['article_count_label'] }}</span>
                @endif
            </span>
        </div>
    </div>

    @if ($showActions && $item['can_mutate'])
        <div class="keyword-item__actions">
            <div class="relative">
                <button
                    type="button"
                    x-ref="menuTrigger"
                    class="keyword-item__menu-btn"
                    @click.stop="toggleMenu()"
                    :aria-expanded="menuOpen.toString()"
                    aria-haspopup="menu"
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
                        <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="openMoveClusterModal({{ $item['keyword_id'] }})" @click="menuOpen = false">
                            {{ __('seo-content-ai::filament.keyword.keyword_item_move_cluster') }}
                        </button>
                        @if ($item['can_skip_mcp'])
                            <button type="button" role="menuitem" class="keyword-item__menu-item" wire:click="skipKeywordFromMcp({{ $item['keyword_id'] }})" @click="menuOpen = false">
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
        </div>
    @endif
</div>
