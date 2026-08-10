@php
    $overviewCss = base_path('addons/content/resources/css/domain-overview.css');
    $paginator = $this->getListPaginator();
    $isKeywords = $this->activeTab === 'keywords';
@endphp

<div>
    @if(is_readable($overviewCss))
        <style>{!! file_get_contents($overviewCss) !!}</style>
    @endif

    <x-filament-panels::page>
        <div class="mb-4">
            <x-filament::button
                tag="a"
                :href="$this->getOverviewUrl()"
                size="sm"
                color="gray"
                icon="heroicon-o-arrow-left"
            >
                {{ __('Quay lại tổng quan') }}
            </x-filament::button>
        </div>

        <div class="seo-internal-tabs mb-4">
            <a href="{{ $this->getTabUrl('links') }}" class="{{ $this->activeTab === 'links' ? 'is-active' : '' }}">
                {{ __('Link') }}
            </a>
            <a href="{{ $this->getTabUrl('keywords') }}" class="{{ $this->activeTab === 'keywords' ? 'is-active' : '' }}">
                {{ __('Từ khóa') }}
            </a>
        </div>

        <x-filament::section>
            @if($paginator->total() === 0)
                <p class="text-sm text-gray-500 italic">
                    @if($isKeywords)
                        {{ __('Chưa có từ khóa gắn bài viết.') }}
                    @else
                        {{ __('Chưa có link được trích xuất. Chạy đồng bộ và chấm điểm SEO trước.') }}
                    @endif
                </p>
            @else
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="seo-internal-table w-full text-sm text-left">
                        <thead class="bg-gray-50 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                            <tr>
                                @if($isKeywords)
                                    <th class="px-4 py-3 font-semibold">{{ __('Từ khóa') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('Loại') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('Viết chính') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('Bài liên kết') }}</th>
                                @else
                                    <th class="px-4 py-3 font-semibold">{{ __('URL') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('Loại') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('Số bài viết') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($paginator as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                    @if($isKeywords)
                                        <td class="px-4 py-3 break-words">{{ $row->phrase }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $row->type === 'internal' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' }}">
                                                {{ $row->type === 'internal' ? __('Internal Link') : __('SEO Keyword') }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                            @if(($row->main_articles_count ?? 0) > 0)
                                                <a href="{{ app(\Omnichannel\Addons\Seo\Services\DomainOverviewService::class)->buildArticlesFilterUrlForMainKeyword((int) $this->getRecord()->getKey(), (int) $row->id) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                                    {{ $row->main_articles_count }}
                                                </a>
                                            @else
                                                0
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                            @if(($row->linked_articles_count ?? 0) > 0)
                                                <a href="{{ app(\Omnichannel\Addons\Seo\Services\DomainOverviewService::class)->buildArticlesFilterUrlForInternalAnchorKeyword((int) $this->getRecord()->getKey(), (int) $row->id) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                                    {{ $row->linked_articles_count }}
                                                </a>
                                            @else
                                                0
                                            @endif
                                        </td>
                                    @else
                                        <td class="px-4 py-3 break-words">
                                            <a href="{{ $row->url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $row->url }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $row->type === 'internal' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' }}">
                                                {{ $row->type === 'internal' ? __('Nội bộ') : __('Ngoài') }}
                                            </span>
                                        </td>
                                    @endif
                                    @if(! $isKeywords)
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                        <a
                                            href="{{ $this->getArticlesFilterUrlForLink($row) }}"
                                            class="text-primary-600 hover:underline dark:text-primary-400"
                                            title="{{ __('Xem danh sách bài viết') }}"
                                        >
                                            {{ $row->articles_count }}
                                        </a>
                                    </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $paginator->links() }}
                </div>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
