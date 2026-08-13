@php
    use Omnichannel\Addons\Content\Filament\Resources\TagResource;
    use Omnichannel\Addons\SearchIntelligence\Filament\Pages\AiKeywordDiscovery;
    use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub;
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    $contentEditorUrl = KeywordResource::getUrl('index');
    $aiDiscoveryUrl = AiKeywordDiscovery::getUrl();
    $performanceHubUrl = SeoPerformanceHub::getUrl();
    $tagsUrl = TagResource::getUrl('index');
    $isKeywordsActive = request()->routeIs([
        'filament.seo.resources.keywords.*',
        'filament.seo.pages.ai-keyword-discovery',
        'filament.seo.pages.performance-hub',
        'seo.keywords.*',
        'seo.performance.*',
    ]);
    $openKeywords = $isKeywordsActive;
@endphp

@if (SeoAccessControl::canAccessPlannerFeatures())
    <li
        class="fi-sidebar-item seo-keywords-dropdown"
        data-seo-keywords-nav
        x-data="{ openKeywords: @js($openKeywords) }"
        x-cloak
    >
        <button
            type="button"
            class="fi-sidebar-item-button group flex w-full items-center gap-x-3 rounded-lg px-2 py-2 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
            x-on:click="openKeywords = ! openKeywords"
            x-bind:aria-expanded="openKeywords.toString()"
        >
            <x-filament::icon
                icon="heroicon-o-key"
                class="fi-sidebar-item-icon h-6 w-6 shrink-0 text-gray-400 group-hover:text-gray-500 group-focus-visible:text-gray-500 dark:text-gray-500 dark:group-hover:text-gray-400 dark:group-focus-visible:text-gray-400"
            />
            <span
                x-show="$store.sidebar.isOpen"
                x-transition:enter="lg:transition lg:delay-100"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="fi-sidebar-item-label flex-1 truncate text-start text-sm font-medium text-gray-700 dark:text-gray-200"
            >
                {{ __('seo-content-ai::filament.nav.keywords') }}
            </span>
            <x-filament::icon
                icon="heroicon-m-chevron-down"
                class="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200 dark:text-gray-500"
                x-bind:class="{ 'rotate-180': openKeywords }"
                x-show="$store.sidebar.isOpen"
            />
        </button>

        <ul
            x-show="openKeywords && $store.sidebar.isOpen"
            x-collapse
            class="seo-keywords-dropdown__list mt-1 space-y-1 ps-9"
        >
            <li>
                <a
                    href="{{ $contentEditorUrl }}"
                    @class([
                        'seo-keywords-dropdown__link',
                        'is-active' => request()->routeIs(
                            'filament.seo.resources.keywords.index',
                            'filament.seo.resources.keywords.focus',
                            'filament.seo.resources.keywords.anchor-audit',
                            'filament.seo.resources.keywords.workspace-2',
                            'filament.seo.resources.keywords.cannibalization',
                        ),
                    ])
                >
                    {{ __('seo-content-ai::filament.performance_hub.nav_content_editor') }}
                </a>
            </li>
            <li>
                <a
                    href="{{ $aiDiscoveryUrl }}"
                    @class([
                        'seo-keywords-dropdown__link',
                        'is-active' => request()->routeIs('filament.seo.pages.ai-keyword-discovery'),
                    ])
                >
                    {{ __('seo-content-ai::filament.keyword.ai_discovery_nav') }}
                </a>
            </li>
            <li>
                <a
                    href="{{ $performanceHubUrl }}"
                    @class([
                        'seo-keywords-dropdown__link',
                        'is-active' => request()->routeIs('filament.seo.pages.performance-hub', 'seo.performance.*'),
                    ])
                >
                    {{ __('seo-content-ai::filament.performance_hub.nav_seo_performance') }}
                </a>
            </li>
            <li>
                <a
                    href="{{ $tagsUrl }}"
                    @class([
                        'seo-keywords-dropdown__link',
                        'is-active' => request()->routeIs('filament.seo.resources.keywords.tags.*'),
                    ])
                >
                    {{ __('seo-content-ai::filament.nav.tags') }}
                </a>
            </li>
        </ul>
    </li>

    <style>
        .fi-sidebar-nav li:has(> .fi-sidebar-item-button[href*="/keywords"]:not([data-seo-keywords-nav] *)) {
            display: none !important;
        }

        .fi-sidebar-nav .fi-sidebar-item[data-seo-keywords-nav] + .fi-sidebar-item[data-seo-keywords-nav] {
            display: none;
        }

        .fi-sidebar-nav .fi-sidebar-item:has(> .fi-sidebar-item-button[href*="/performance-hub"]) {
            display: none !important;
        }

        .seo-keywords-dropdown__link {
            display: block;
            border-radius: 0.5rem;
            padding: 0.375rem 0.5rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: rgb(107 114 128);
            transition: background-color 150ms, color 150ms;
        }

        .dark .seo-keywords-dropdown__link {
            color: rgb(156 163 175);
        }

        .seo-keywords-dropdown__link:hover,
        .seo-keywords-dropdown__link.is-active {
            background-color: rgb(243 244 246);
            color: rgb(17 24 39);
        }

        .dark .seo-keywords-dropdown__link:hover,
        .dark .seo-keywords-dropdown__link.is-active {
            background-color: rgb(255 255 255 / 0.05);
            color: rgb(243 244 246);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dropdown = document.querySelector('[data-seo-keywords-nav]');
            if (! dropdown) {
                return;
            }

            const groups = document.querySelectorAll('.fi-sidebar-group');
            for (const group of groups) {
                const label = group.querySelector('.fi-sidebar-group-label');
                if (! label) {
                    continue;
                }

                if (label.textContent?.trim().includes('SEO Workspace')) {
                    const list = group.querySelector('.fi-sidebar-group-items');
                    if (list) {
                        list.appendChild(dropdown);
                    }

                    break;
                }
            }
        });
    </script>
@endif
