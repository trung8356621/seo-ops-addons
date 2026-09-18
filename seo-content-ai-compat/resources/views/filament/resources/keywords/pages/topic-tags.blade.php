@php
    $cssPath = base_path('addons/seo/resources/css/keyword-workspace.css');
    $shortcuts = $this->getBuiltinShortcuts();
    $paginator = $this->getCustomTagsPaginator();
@endphp

<x-filament-panels::page class="keyword-workspace-page topic-tags-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="keyword-workspace-shell mt-5 space-y-6">
        <section class="space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('seo-content-ai::filament.keyword.topic_tags_builtin_heading') }}
                </h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.topic_tags_builtin_hint') }}
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($shortcuts as $shortcut)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/40">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $shortcut['label'] }}
                        </div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.topic_tags_topic_count', ['count' => $shortcut['topic_count']]) }}
                        </div>
                        <div class="mt-3">
                            <a
                                href="{{ $this->topicsUrlForBuiltin($shortcut['filter']) }}"
                                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                {{ __('seo-content-ai::filament.keyword.topic_tags_view_topics') }}
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.keyword.topic_tags_custom_heading') }}
                    </h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.keyword.topic_tags_custom_hint') }}
                    </p>
                </div>
                <form wire:submit.prevent="applyTagSearch" class="flex items-center gap-2">
                    <input
                        type="search"
                        wire:model="tagSearchInput"
                        class="topic-index-cluster-edit min-w-[14rem]"
                        placeholder="{{ __('seo-content-ai::filament.keyword.topic_tags_search_placeholder') }}"
                    />
                    <x-filament::button type="submit" size="sm" color="gray">
                        {{ __('seo-content-ai::filament.keyword.topic_tags_search_action') }}
                    </x-filament::button>
                    @if ($tagSearch !== '')
                        <x-filament::button type="button" size="sm" color="gray" wire:click="clearTagSearch">
                            {{ __('seo-content-ai::filament.keyword.topic_tags_search_clear') }}
                        </x-filament::button>
                    @endif
                </form>
            </div>

            <x-seo-content-ai::list-table-loading-shell
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/40"
                preset="livewire-page"
                targets="applyTagSearch,clearTagSearch,deleteCustomTag,tagSearch,keywordLanguageFilter,keywordWorkspaceSiteId,onKeywordWorkspaceSiteFilterChanged"
            >
                @if ($paginator->isEmpty())
                    <div class="px-6 py-14 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('seo-content-ai::filament.keyword.topic_tags_empty') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ __('seo-content-ai::filament.keyword.topic_tags_col_tag') }}
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ __('seo-content-ai::filament.keyword.topic_tags_col_topics') }}
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        {{ __('seo-content-ai::filament.keyword.topic_tags_col_actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10" wire:key="tags-body-{{ $this->tagsDataEpoch }}">
                                @foreach ($paginator as $tag)
                                    @php
                                        $tagId = (int) ($tag['id'] ?? 0);
                                        $tagName = (string) ($tag['name'] ?? '');
                                        $topicCount = (int) ($tag['topic_count'] ?? 0);
                                    @endphp
                                    <tr wire:key="topic-tag-{{ $tagId }}">
                                        <td class="px-4 py-3 text-sm">
                                            <a
                                                href="{{ $this->topicsUrlForCustomTag($tagId) }}"
                                                class="font-medium text-gray-900 hover:underline dark:text-gray-100"
                                            >
                                                {{ $tagName }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                                            {{ $topicCount }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-sm">
                                            <a
                                                href="{{ $this->topicsUrlForCustomTag($tagId) }}"
                                                class="mr-3 text-primary-600 hover:underline dark:text-primary-400"
                                            >
                                                {{ __('seo-content-ai::filament.keyword.topic_tags_view_topics') }}
                                            </a>
                                            <button
                                                type="button"
                                                class="text-danger-600 hover:underline dark:text-danger-400"
                                                wire:click="deleteCustomTag({{ $tagId }})"
                                                wire:confirm="{{ __('seo-content-ai::filament.keyword.topic_tags_delete_confirm', ['name' => $tagName]) }}"
                                                wire:loading.attr="disabled"
                                                wire:target="deleteCustomTag({{ $tagId }})"
                                            >
                                                {{ __('seo-content-ai::filament.keyword.topic_tags_delete') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($paginator->hasPages())
                        <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10">
                            {{ $paginator->links() }}
                        </div>
                    @endif
                @endif
            </x-seo-content-ai::list-table-loading-shell>
        </section>
    </div>
</x-filament-panels::page>
