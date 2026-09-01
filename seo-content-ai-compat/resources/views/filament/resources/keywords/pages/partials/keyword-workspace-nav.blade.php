@php
    $languageOptions = $languageOptions ?? ($this->getKeywordLanguageFilterOptions() ?? []);
    $languageSiteId = $languageSiteId ?? ($this->resolveKeywordWorkspaceSiteId() ?? 0);
    $primaryLanguage = $primaryLanguage ?? ($this->resolveKeywordWorkspacePrimaryLanguage() ?? '');
    $selectedLanguage = $selectedLanguage ?? ($this->keywordLanguageFilter ?? $primaryLanguage);
    $moduleHeading = $moduleHeading ?? (method_exists($this, 'getKeywordModuleHeading')
        ? $this->getKeywordModuleHeading()
        : (string) __('seo-content-ai::filament.keyword.module_heading_fallback'));
    $totalKeywords = method_exists($this, 'getKeywordWorkspaceTotalKeywords')
        ? (int) $this->getKeywordWorkspaceTotalKeywords()
        : null;
@endphp

<div class="keyword-module-chrome">
    <header class="keyword-module-header">
        <h1 class="keyword-module-header__title">
            <span class="keyword-module-header__heading">{{ $moduleHeading }}</span>
            @if ($totalKeywords !== null)
                <span
                    class="keyword-module-header__total-badge"
                    title="{{ __('seo-content-ai::filament.keyword.module_total_tooltip') }}"
                >{{ __('seo-content-ai::filament.keyword.module_total_badge', ['count' => number_format($totalKeywords)]) }}</span>
            @endif
        </h1>
    </header>

    <div class="keyword-workspace-tabs-bar">
        <x-seo-content-ai::workspace-tabs
            :active-key="$activeKey ?? ''"
            :items="$navItems ?? []"
            class="keyword-workspace-tabs"
        />

        @if ($languageOptions !== [])
            <div
                class="keyword-workspace-tabs-bar__filter"
                wire:ignore.self
                x-data="keywordWorkspaceLanguageFilter({
                    siteId: @js((int) $languageSiteId),
                    primaryLanguage: @js($primaryLanguage),
                    optionCodes: @js(array_keys($languageOptions)),
                    storageKeyPrefix: 'keywordWorkspace.language.',
                })"
                x-init="init()"
                @keyword-workspace-language-site-changed.window="onSiteChanged($event.detail)"
            >
                <label class="sr-only" for="keyword-workspace-language-filter">
                    {{ __('seo-content-ai::filament.keyword.workspace_language_filter') }}
                </label>
                <x-select
                    id="keyword-workspace-language-filter"
                    wire:model.live="keywordLanguageFilter"
                    wrapClass="x-select-wrap x-select-wrap--narrow keyword-workspace-language-select-wrap"
                    class="keyword-workspace-language-select"
                    x-on:change="persist($event.target.value)"
                >
                    @foreach ($languageOptions as $code => $label)
                        <option value="{{ $code }}" @selected($selectedLanguage === $code)>{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
        @endif
    </div>
</div>

@once
    <script>
        window.keywordWorkspaceLanguageFilter = window.keywordWorkspaceLanguageFilter || function (config) {
            return {
                siteId: config.siteId ?? 0,
                primaryLanguage: config.primaryLanguage ?? '',
                optionCodes: Array.isArray(config.optionCodes) ? config.optionCodes : [],
                storageKeyPrefix: config.storageKeyPrefix ?? 'keywordWorkspace.language.',
                init() {
                    this.$nextTick(() => this.applyStoredSelection());
                },
                storageKey() {
                    return `${this.storageKeyPrefix}${this.siteId || 0}`;
                },
                readStored() {
                    try {
                        return window.localStorage.getItem(this.storageKey()) || '';
                    } catch (error) {
                        return '';
                    }
                },
                persist(code) {
                    const normalized = String(code || '').trim();
                    if (normalized === '' || !this.optionCodes.includes(normalized)) {
                        return;
                    }

                    try {
                        window.localStorage.setItem(this.storageKey(), normalized);
                    } catch (error) {
                        // ignore storage failures
                    }
                },
                applyStoredSelection() {
                    const stored = this.readStored();
                    const candidate = stored !== '' && this.optionCodes.includes(stored)
                        ? stored
                        : (this.optionCodes.includes(this.primaryLanguage) ? this.primaryLanguage : (this.optionCodes[0] || ''));

                    if (candidate === '') {
                        return;
                    }

                    const select = this.$root.querySelector('#keyword-workspace-language-filter');
                    if (select instanceof HTMLSelectElement && select.value !== candidate) {
                        select.value = candidate;
                    }

                    if (this.$wire && typeof this.$wire.set === 'function') {
                        this.$wire.set('keywordLanguageFilter', candidate);
                    }
                },
                onSiteChanged(detail) {
                    this.siteId = Number(detail?.siteId ?? 0);
                    this.primaryLanguage = String(detail?.primaryLanguage ?? '');
                    this.optionCodes = Array.isArray(detail?.optionCodes) ? detail.optionCodes : [];
                    this.$nextTick(() => this.applyStoredSelection());
                },
            };
        };
    </script>
@endonce
