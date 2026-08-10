@php
    /** @var \Omnichannel\Addons\Content\Models\SeoArticle $record */
    use Omnichannel\Addons\Content\Support\ArticleListSeoSummary;
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    $record = $getRecord();
    $seo = ArticleListSeoSummary::for($record);
    $canEditMainKeyword = SeoAccessControl::canAccessPlannerFeatures();

    if (! empty($seo['score_skipped'])) {
        $scoreLabel = __('seo-content-ai::filament.article_list.seo_score_skipped_label');
        $scoreTone = 'skipped';
    } elseif ($seo['score'] !== null) {
        $scoreLabel = $seo['score'] . ' / 100';
        $scoreTone = $seo['score_tone'];
        if (! empty($seo['score_stale'])) {
            $scoreLabel .= ' · '.__('seo-content-ai::filament.article_list.seo_score_stale');
        }
    } else {
        $scoreLabel = '— / 100';
        $scoreTone = 'muted';
    }

    $keywordLabel = filled($seo['keyword'])
        ? (string) $seo['keyword']
        : __('seo-content-ai::filament.article_list.seo_keyword_empty');
    $mainKeywordValue = filled($seo['keyword']) ? (string) $seo['keyword'] : '';
@endphp

<div
    class="article-seo-cell{{ $canEditMainKeyword ? ' article-seo-cell--planner' : '' }}"
    x-data="{
        open: false,
        keywordOriginal: @js($mainKeywordValue),
        normalizeKeyword(value) {
            return String(value ?? '').trim();
        },
        syncMainKeywordIfChanged(articleId, value) {
            const next = this.normalizeKeyword(value);
            const unchanged = next === this.normalizeKeyword(this.keywordOriginal);
            const scoreText = this.$el.querySelector('.article-seo-score')?.textContent ?? '';
            // Bài từng thiếu KW: điểm DB còn 0/100 dù input đã có keyword — blur vẫn chấm lại.
            const stuckAtZero = next !== '' && /\b0\s*\/\s*100\b/.test(scoreText);
            if (unchanged && !stuckAtZero) {
                return;
            }

            this.keywordOriginal = next;
            $wire.syncArticleMainKeyword(articleId, next);
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    onclick="event.stopPropagation()"
>
    @if($canEditMainKeyword)
        <div class="article-seo-dropdown__header">
            <span class="article-seo-score article-seo-score--{{ $scoreTone }}">
                {{ $scoreLabel }}
            </span>
            <input
                type="text"
                class="article-seo-main-keyword-input"
                value="{{ $mainKeywordValue }}"
                placeholder="{{ __('seo-content-ai::filament.article_list.main_keyword_placeholder') }}"
                x-on:blur.stop="syncMainKeywordIfChanged({{ $record->id }}, $event.target.value)"
                x-on:keydown.enter.stop="$event.target.blur()"
                wire:loading.attr="disabled"
                wire:target="syncArticleMainKeyword"
                x-on:click.stop
                x-on:keydown.stop
                title="{{ __('seo-content-ai::filament.article_list.main_keyword_placeholder') }}"
            />
            <button
                type="button"
                class="article-seo-dropdown__chevron-btn"
                x-on:click.stop="open = !open"
                :aria-expanded="open"
                aria-haspopup="true"
            >
                <span class="article-seo-dropdown__chevron-wrap" x-bind:class="{ 'is-open': open }">
                    <x-filament::icon icon="heroicon-m-chevron-down" class="article-seo-dropdown__chevron" />
                </span>
            </button>
        </div>
    @else
        <button
            type="button"
            class="article-seo-dropdown__trigger"
            x-on:click.stop="open = !open"
            :aria-expanded="open"
            aria-haspopup="true"
            title="{{ $keywordLabel }}"
        >
            <span class="article-seo-score article-seo-score--{{ $scoreTone }}">
                {{ $scoreLabel }}
            </span>
            <span class="article-seo-dropdown__keyword">{{ $keywordLabel }}</span>
            <span class="article-seo-dropdown__chevron-wrap" x-bind:class="{ 'is-open': open }">
                <x-filament::icon icon="heroicon-m-chevron-down" class="article-seo-dropdown__chevron" />
            </span>
        </button>
    @endif

    <div
        class="article-seo-dropdown__panel"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:click.stop
    >
        <p class="article-seo-line">
            <span class="article-seo-line__label">{{ __('seo-content-ai::filament.article_list.seo_type_label') }}:</span>
            <span class="article-seo-line__value">{{ $seo['schema'] }}</span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">{{ __('seo-content-ai::filament.article_list.seo_images_label') }}:</span>
            <span class="article-seo-line__value">{{ $seo['image_count'] }}</span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">FAQ:</span>
            <span class="article-seo-line__value">
                {{ $seo['faq_count'] }} {{ __('seo-content-ai::filament.article_list.seo_faq_unit') }}
                <span class="article-seo-line__sep" aria-hidden="true">·</span>
                {{ $seo['faq_points'] }}/10 {{ __('seo-content-ai::filament.article_list.seo_points_unit') }}
            </span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">{{ __('seo-content-ai::filament.article_list.seo_featured_snippet_label') }}:</span>
            <span class="article-seo-line__value">
                {{ $seo['featured_snippet_points'] }}/10 {{ __('seo-content-ai::filament.article_list.seo_points_unit') }}
            </span>
        </p>

        <p class="article-seo-line article-seo-line--links">
            <span class="article-seo-line__label">{{ __('seo-content-ai::filament.article_list.seo_links_label') }}:</span>
            <span class="article-seo-links">
                <span class="article-seo-links__item" title="{{ __('seo-content-ai::filament.article_list.seo_links_total') }}">
                    <x-filament::icon icon="heroicon-m-link" class="article-seo-links__icon" />
                    {{ $seo['links_total'] }}
                </span>
                <span class="article-seo-links__sep" aria-hidden="true">|</span>
                <span class="article-seo-links__item" title="{{ __('seo-content-ai::filament.article_list.seo_links_external') }}">
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="article-seo-links__icon" />
                    {{ $seo['links_external'] }}
                </span>
                <span class="article-seo-links__sep" aria-hidden="true">|</span>
                <span class="article-seo-links__item" title="{{ __('seo-content-ai::filament.article_list.seo_links_internal') }}">
                    <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="article-seo-links__icon" />
                    {{ $seo['links_internal'] }}
                </span>
            </span>
        </p>
    </div>
</div>

@once
    <style>
        td:has(.article-seo-cell),
        .fi-ta-cell:has(.article-seo-cell) {
            overflow: visible !important;
            position: relative;
        }

        .article-seo-cell {
            position: relative;
            min-width: 10rem;
            max-width: 18rem;
        }

        .article-seo-cell--planner {
            min-width: 14rem;
            max-width: 22rem;
        }

        .article-seo-dropdown__header {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            width: 100%;
            max-width: 100%;
            padding: 0.3rem 0.45rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            background: rgb(255 255 255);
        }

        .dark .article-seo-dropdown__header {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .article-seo-main-keyword-input {
            flex: 1 1 auto;
            min-width: 0;
            margin: 0;
            padding: 0.15rem 0.35rem;
            border: 1px solid rgb(209 213 219);
            border-radius: 0.375rem;
            background: rgb(249 250 251);
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.35;
            color: rgb(17 24 39);
        }

        .article-seo-main-keyword-input:focus {
            outline: none;
            border-color: rgb(59 130 246);
            box-shadow: 0 0 0 2px rgb(59 130 246 / 20%);
            background: rgb(255 255 255);
        }

        .article-seo-main-keyword-input:disabled {
            opacity: 0.65;
            cursor: wait;
        }

        .dark .article-seo-main-keyword-input {
            border-color: rgb(75 85 99);
            background: rgb(31 41 55);
            color: rgb(243 244 246);
        }

        .article-seo-dropdown__chevron-btn {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .article-seo-dropdown__chevron-btn:focus-visible {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 2px;
            border-radius: 0.25rem;
        }

        .article-seo-dropdown__trigger {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0.3rem 0.45rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            background: rgb(255 255 255);
            text-align: left;
            cursor: pointer;
            transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s;
        }

        .article-seo-dropdown__trigger:hover {
            border-color: rgb(191 219 254);
            background: rgb(239 246 255);
        }

        .article-seo-dropdown__trigger:focus-visible {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 2px;
        }

        .dark .article-seo-dropdown__trigger {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .dark .article-seo-dropdown__trigger:hover {
            border-color: rgb(37 99 235);
            background: rgb(30 41 59);
        }

        .article-seo-dropdown__keyword {
            flex: 1 1 auto;
            min-width: 0;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.35;
            color: rgb(17 24 39);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dark .article-seo-dropdown__keyword {
            color: rgb(243 244 246);
        }

        .article-seo-dropdown__chevron-wrap {
            display: inline-flex;
            flex-shrink: 0;
            transition: transform 0.15s ease;
        }

        .article-seo-dropdown__chevron-wrap.is-open {
            transform: rotate(180deg);
        }

        .article-seo-dropdown__chevron {
            width: 0.875rem;
            height: 0.875rem;
            color: rgb(107 114 128);
        }

        .dark .article-seo-dropdown__chevron {
            color: rgb(156 163 175);
        }

        .article-seo-dropdown__panel {
            position: absolute;
            top: calc(100% + 0.25rem);
            left: 0;
            z-index: 50;
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            min-width: 15rem;
            max-width: min(22rem, 90vw);
            padding: 0.5rem 0.625rem;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem;
            background: rgb(255 255 255);
            box-shadow: 0 10px 25px -12px rgb(0 0 0 / 25%);
        }

        .dark .article-seo-dropdown__panel {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
            box-shadow: 0 10px 25px -12px rgb(0 0 0 / 55%);
        }

        .article-seo-score {
            display: inline-block;
            flex-shrink: 0;
            padding: 0.125rem 0.4rem;
            border-radius: 0.375rem;
            font-size: 0.6875rem;
            font-weight: 700;
            line-height: 1.4;
        }

        .article-seo-score--success {
            background: rgb(220 252 231);
            color: rgb(21 128 61);
        }

        .article-seo-score--warning {
            background: rgb(254 243 199);
            color: rgb(146 64 14);
        }

        .article-seo-score--danger {
            background: rgb(254 226 226);
            color: rgb(185 28 28);
        }

        .article-seo-score--muted {
            background: rgb(243 244 246);
            color: rgb(107 114 128);
        }

        .article-seo-score--skipped {
            background: rgb(254 243 199);
            color: rgb(146 64 14);
            border: 1px dashed rgb(245 158 11);
        }

        .dark .article-seo-score--success {
            background: rgba(22, 163, 74, 0.2);
            color: rgb(134 239 172);
        }

        .dark .article-seo-score--warning {
            background: rgba(245, 158, 11, 0.2);
            color: rgb(252 211 77);
        }

        .dark .article-seo-score--danger {
            background: rgba(220, 38, 38, 0.2);
            color: rgb(252 165 165);
        }

        .dark .article-seo-score--muted {
            background: rgb(31 41 55);
            color: rgb(156 163 175);
        }

        .dark .article-seo-score--skipped {
            background: rgba(245, 158, 11, 0.15);
            color: rgb(252 211 77);
            border-color: rgb(180 83 9);
        }

        .article-seo-line {
            margin: 0;
            font-size: 0.75rem;
            line-height: 1.4;
            color: rgb(55 65 81);
            word-break: break-word;
        }

        .dark .article-seo-line {
            color: rgb(209 213 219);
        }

        .article-seo-line__label {
            font-weight: 600;
            color: rgb(17 24 39);
        }

        .dark .article-seo-line__label {
            color: rgb(243 244 246);
        }

        .article-seo-line__value {
            font-weight: 500;
        }

        .article-seo-line__sep {
            margin: 0 0.2rem;
            color: rgb(156 163 175);
            font-weight: 400;
        }

        .article-seo-line--links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.375rem;
        }

        .article-seo-links {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.375rem;
            font-weight: 600;
            color: rgb(55 65 81);
        }

        .dark .article-seo-links {
            color: rgb(209 213 219);
        }

        .article-seo-links__item {
            display: inline-flex;
            align-items: center;
            gap: 0.125rem;
        }

        .article-seo-links__icon {
            width: 0.875rem;
            height: 0.875rem;
            flex-shrink: 0;
        }

        .article-seo-links__sep {
            color: rgb(209 213 219);
            font-weight: 400;
            user-select: none;
        }

        .dark .article-seo-links__sep {
            color: rgb(75 85 99);
        }

        .seo-article-preview-modal-root {
            max-height: min(70vh, 640px);
            overflow-y: auto;
        }
    </style>
@endonce
