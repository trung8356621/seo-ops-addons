@php
    $stats = $this->getDictionaryStats();
    $totalCount = (int) ($stats['total'] ?? 0);
@endphp

<header class="keyword-dictionary-header">
    <div class="keyword-dictionary-header__main">
        <div class="keyword-dictionary-header__copy">
            <div class="keyword-dictionary-header__title-row">
                <h1 class="keyword-dictionary-header__title">
                    {{ __('seo-content-ai::filament.keyword.focus_heading') }}
                </h1>
                <span class="keyword-dictionary-header__count-badge">
                    {{ number_format($totalCount) }}
                </span>
            </div>
            <p class="keyword-dictionary-header__subtitle">
                {{ __('seo-content-ai::filament.keyword.focus_subheading') }}
            </p>
        </div>

        @if (count($this->getCachedHeaderActions()))
            <div class="keyword-dictionary-header__actions">
                <x-filament::actions :actions="$this->getCachedHeaderActions()" />
            </div>
        @endif
    </div>
</header>
