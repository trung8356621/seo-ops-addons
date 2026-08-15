@php
    use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;

    $site = $this->getSite();
    $synced = $this->isSiteSynced();
    $api = $this->getApiTokenSummary();
    $plainTokens = $this->getPlainTokens();
    $scoring = $this->getScoringStatistics();
    $distribution = $this->getScoreDistribution();
    $stats = $this->getSyncStatistics();
    $technical = $this->getTechnicalSeoSummary();
    $internalLinksUrl = DomainResource::getUrl('internal-links', ['record' => $site]);
    $technicalUrl = DomainResource::getUrl('edit', ['record' => $site]);
    $editDomainUrl = DomainResource::getUrl('edit', ['record' => $site]);
    $keywordsTabUrl = $this->getInternalLinkTabUrl('keywords');
    $linksTabUrl = $this->getInternalLinkTabUrl('links');
    $wpPluginStatus = $this->getWpPluginBridgeStatus();

    $scoreSegments = collect($distribution['segments'] ?? [])
        ->map(function (array $segment): array {
            if (($segment['count'] ?? 0) > 0) {
                $segment['filter_url'] = $this->getArticlesFilterUrl((string) ($segment['key'] ?? ''));
            }

            return $segment;
        })
        ->all();

    $chartSegments = array_values(array_filter(
        $scoreSegments,
        static fn (array $segment): bool => ($segment['count'] ?? 0) > 0,
    ));
    $donutGradient = '';
    $donutTotal = array_sum(array_column($chartSegments, 'count'));
    if ($donutTotal > 0) {
        $cursor = 0;
        $parts = [];
        foreach ($chartSegments as $seg) {
            $pct = ($seg['count'] / $donutTotal) * 100;
            $start = $cursor;
            $cursor += $pct;
            $parts[] = $seg['color'] . ' ' . $start . '% ' . $cursor . '%';
        }
        $donutGradient = 'conic-gradient(' . implode(', ', $parts) . ')';
    }
@endphp

@php
    $overviewCss = base_path('addons/content/resources/css/domain-overview.css');
@endphp

{{-- Livewire 3 yêu cầu MỘT phần tử gốc - bọc toàn bộ view trong div này. --}}
<div @if($incrementalSyncRunning || $metadataSyncRunning || $keywordResyncRunning || ($siteSyncV2Running ?? false) || ($siteSyncV2Stuck ?? false)) wire:poll.3s="refreshSyncProgress" @endif>
    @if(is_readable($overviewCss))
        <style>{!! file_get_contents($overviewCss) !!}</style>
    @endif

    <x-filament-panels::page>
    <div class="seo-domain-overview">
        {{-- API Key --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('API Key') }}</x-slot>
            <x-slot name="description">
                {{ __('Read token & Migration token. Bấm icon mắt để hiển thị; focus ô input để tự copy.') }}
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::button
                    tag="a"
                    :href="$editDomainUrl"
                    size="sm"
                    color="gray"
                    icon="heroicon-o-pencil-square"
                >
                    {{ __('Chỉnh sửa') }}
                </x-filament::button>
            </x-slot>

            @if(($api['platform'] ?? '') !== 'wordpress')
                <p class="text-sm text-gray-500">{{ __('Nền tảng không dùng token WordPress.') }}</p>
            @else
                <div class="seo-api-key-layout">
                    <div class="seo-api-key-layout__tokens space-y-4">
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.api-token-field', [
                            'label' => __('Read token'),
                            'plain' => ($this->tokensUnlocked && $this->readTokenVisible) ? $plainTokens['read_token'] : '',
                            'visible' => $this->readTokenVisible,
                            'unlocked' => $this->tokensUnlocked,
                            'field' => 'read',
                        ])
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.api-token-field', [
                            'label' => __('Migration token'),
                            'plain' => ($this->tokensUnlocked && $this->migrationTokenVisible) ? $plainTokens['migration_token'] : '',
                            'visible' => $this->migrationTokenVisible,
                            'unlocked' => $this->tokensUnlocked,
                            'field' => 'migration',
                        ])
                    </div>

                    <div class="seo-api-key-layout__aside items-start content-start">
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.wp-plugin-bridge-status', [
                            'status' => $wpPluginStatus,
                            'site' => $site,
                        ])
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.site-health-card')
                    </div>
                </div>

                @if($this->showPasswordPrompt)
                    <div class="mt-4 max-w-md rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('Nhập mật khẩu tài khoản để hiển thị token.') }}
                        </p>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-[12rem] flex-1">
                                <label class="mb-1 block text-sm font-medium">{{ __('Mật khẩu') }}</label>
                                <input
                                    type="password"
                                    wire:model="tokenPassword"
                                    wire:keydown.enter="confirmRevealTokens"
                                    class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800"
                                    autocomplete="current-password"
                                />
                                @error('tokenPassword')
                                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="button"
                                wire:click="confirmRevealTokens"
                                class="fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                            >
                                {{ __('Xác nhận') }}
                            </button>
                            <button
                                type="button"
                                wire:click="cancelPasswordPrompt"
                                class="fi-btn fi-btn-size-sm inline-flex items-center justify-center gap-1 rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200"
                            >
                                {{ __('Hủy') }}
                            </button>
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>

        @if(! $synced)
            <x-filament::section class="border-amber-200 dark:border-amber-500/40">
                <x-slot name="heading">{{ __('Đồng bộ') }}</x-slot>
                <x-slot name="description">
                    {{ __('Website chưa có dữ liệu trong kho SEO. Chạy đồng bộ từ WordPress.') }}
                </x-slot>
                @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-actions', [
                    'showTest' => auth()->user()?->role === 'admin'
                        && ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isSeoPanelReadOnly(),
                ])
            </x-filament::section>
        @else
            <div class="seo-domain-overview__grid seo-domain-overview__grid--2">
                {{-- Chấm điểm SEO --}}
                <x-filament::section>
                    <x-slot name="heading">{{ __('Chấm điểm SEO') }}</x-slot>
                    <x-slot name="description">
                        {{ __('Phân bố điểm sau đồng bộ (Rank Math / Yoast + rule nội bộ).') }}
                    </x-slot>

                    @if($scoring['scored'] === 0)
                        <p class="text-sm text-amber-700 dark:text-amber-300">
                            {{ __('Chưa có bài được chấm. Cần Focus Keyword trên WordPress.') }}
                        </p>
                    @else
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.seo-score-donut-block', [
                            'scoring' => $scoring,
                            'segments' => $scoreSegments,
                            'donutGradient' => $donutGradient,
                        ])
                    @endif
                </x-filament::section>

                {{-- Thống kê đồng bộ + nút --}}
                <x-filament::section>
                    <x-slot name="heading">{{ __('Thống kê đồng bộ') }}</x-slot>
                    <x-slot name="description">{{ __('Số lượng theo type trên kho nội dung SEO.') }}</x-slot>

                    <div class="grid gap-2 text-sm sm:grid-cols-2">
                        <p><span class="font-semibold">{{ __('Bài viết') }}:</span> {{ $stats['articles'] }}</p>
                        @if(($stats['wp_articles_total'] ?? 0) > 0)
                            <p class="text-xs text-gray-500 sm:col-span-2">
                                {{ __('WP') }}: {{ $stats['wp_posts'] }} post + {{ $stats['wp_pages'] }} page
                                @if(($stats['article_gap'] ?? 0) > 0)
                                    <span class="font-semibold text-warning-600 dark:text-warning-400">
                                        — {{ __('thiếu') }} {{ $stats['article_gap'] }} {{ __('bài so với plugin') }}
                                    </span>
                                @endif
                            </p>
                        @endif
                        <p><span class="font-semibold">{{ __('Sản phẩm') }}:</span> {{ $stats['products'] }}</p>
                        <p><span class="font-semibold">{{ __('Danh mục') }}:</span> {{ $stats['categories'] }}</p>
                        <p><span class="font-semibold">{{ __('Danh mục SP') }}:</span> {{ $stats['product_categories'] }}</p>
                        @if($stats['other'] > 0)
                            <p class="sm:col-span-2"><span class="font-semibold">{{ __('Khác') }}:</span> {{ $stats['other'] }}</p>
                        @endif
                        <p class="sm:col-span-2 text-gray-500">{{ __('Tổng') }}: {{ $stats['total'] }} {{ __('bản ghi') }}</p>
                    </div>

                    @php $seoScoring = $this->getSeoScoringProgress(); @endphp
                    <div class="mt-4 space-y-1 border-t border-gray-200 pt-4 text-sm dark:border-gray-700">
                        <p class="font-semibold text-gray-700 dark:text-gray-200">
                            {{ __('seo-content-ai::filament.domain.seo_scoring_progress', [
                                'completed' => number_format((int) ($seoScoring['completed'] ?? 0)),
                                'total' => number_format((int) ($seoScoring['total'] ?? 0)),
                            ]) }}
                        </p>
                        @if (((int) ($seoScoring['pending'] ?? 0)) > 0 || ((int) ($seoScoring['processing'] ?? 0)) > 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Chờ: {{ number_format((int) ($seoScoring['pending'] ?? 0)) }}
                                · Đang xử lý: {{ number_format((int) ($seoScoring['processing'] ?? 0)) }}
                            </p>
                        @endif
                        <p class="text-gray-600 dark:text-gray-300">
                            {{ $siteSyncScoringContext ?: 'Chấm SEO gắn vào nút Đồng bộ & kiểm tra website.' }}
                        </p>
                        @if (($seoScoring['failed'] ?? 0) > 0)
                            <p class="text-warning-600 dark:text-warning-400">
                                {{ __('seo-content-ai::filament.domain.seo_scoring_failed', ['count' => number_format((int) $seoScoring['failed'])]) }}
                            </p>
                        @endif
                    </div>

                    @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-sync-actions', [
                        'showTest' => auth()->user()?->role === 'admin'
                        && ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isSeoPanelReadOnly(),
                    ])
                </x-filament::section>
            </div>

            {{-- Internal link --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Internal link') }}</x-slot>
                <x-slot name="description">{{ __('Từ khóa và URL được trích xuất từ nội dung bài viết đã chấm SEO.') }}</x-slot>

                <div class="seo-internal-tabs">
                    <a href="{{ $keywordsTabUrl }}" class="{{ $this->internalLinkTab === 'keywords' ? 'is-active' : '' }}">
                        {{ __('Từ khóa') }}
                    </a>
                    <a href="{{ $linksTabUrl }}" class="{{ $this->internalLinkTab === 'links' ? 'is-active' : '' }}">
                        {{ __('Link') }}
                    </a>
                </div>

                <div>
                    @if($this->internalLinkTab === 'keywords')
                        @php $topKeywords = $this->getTopKeywords(); @endphp
                        @if($topKeywords->isEmpty())
                            <p class="text-sm text-gray-500 italic">{{ __('Chưa có từ khóa gắn bài viết.') }}</p>
                        @else
                            <ul class="seo-rank-list">
                                @foreach($topKeywords as $row)
                                    <li>
                                        <span class="seo-rank-list__label">{{ $row->phrase }}</span>
                                        <a
                                            href="{{ $this->getArticlesFilterUrlForKeyword((int) $row->id) }}"
                                            class="seo-rank-list__count text-primary-600 hover:underline dark:text-primary-400"
                                            title="{{ __('Xem danh sách bài viết') }}"
                                        >
                                            {{ $row->articles_count }} {{ __('bài') }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        @php $topLinks = $this->getTopLinks(); @endphp
                        @if($topLinks->isEmpty())
                            <p class="text-sm text-gray-500 italic">{{ __('Chưa có link được trích xuất. Chạy chấm SEO sau đồng bộ.') }}</p>
                        @else
                            <ul class="seo-rank-list">
                                @foreach($topLinks as $row)
                                    <li>
                                        <span class="seo-rank-list__label">
                                            <span class="text-xs uppercase text-gray-400">{{ $row->type === 'internal' ? 'Nội bộ' : 'Ngoài' }}</span>
                                            {{ $row->url }}
                                        </span>
                                        <a
                                            href="{{ $this->getArticlesFilterUrlForLink($row->url, $row->type) }}"
                                            class="seo-rank-list__count text-primary-600 hover:underline dark:text-primary-400"
                                            title="{{ __('Xem danh sách bài viết') }}"
                                        >
                                            {{ $row->articles_count }} {{ __('bài') }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>

                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        :href="$internalLinksUrl . '?tab=' . $this->internalLinkTab"
                        size="sm"
                        color="gray"
                        icon="heroicon-o-arrow-right"
                    >
                        {{ __('Xem thêm') }}
                    </x-filament::button>
                </div>
            </x-filament::section>

            {{-- Official Site MCP summary — draft generator lives on Edit Domain --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Site MCP — Knowledge Profile') }}</x-slot>
                <x-slot name="description">
                    {{ __('Official Knowledge Profile (tone, mô tả, CTA, links). Generate draft + so sánh nằm ở trang Edit Domain.') }}
                    (@{{site_short_description}}, @{{site_cta}}).
                </x-slot>

                @if(! $technical['has_content'])
                    <p class="text-sm text-gray-500">{{ __('Chưa cấu hình official Site MCP.') }}</p>
                @else
                    <div class="space-y-2 text-sm">
                        @if($technical['short_description_preview'] !== '')
                            <p><span class="font-semibold">{{ __('Mô tả') }}:</span> {{ $technical['short_description_preview'] }}</p>
                        @endif
                        <p>
                            <span class="font-semibold">{{ __('CTA') }}:</span> {{ $technical['cta_count'] }} {{ __('mục') }}
                            ·
                            <span class="font-semibold">{{ __('Link list') }}:</span> {{ $technical['links_count'] }} {{ __('mục') }}
                        </p>
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$technicalUrl" size="sm" icon="heroicon-o-cog-6-tooth">
                        {{ __('Chỉnh sửa / Generate draft') }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
    </x-filament-panels::page>

    <script>
        window.seoCopyTokenField = function (input) {
            if (! input || ! input.classList.contains('seo-token-field__input--revealed')) {
                return;
            }
            const value = input.value;
            if (! value || value === '****************************************') {
                return;
            }
            navigator.clipboard.writeText(value).then(function () {
                let tip = document.getElementById('seo-token-copy-tip');
                if (! tip) {
                    tip = document.createElement('div');
                    tip.id = 'seo-token-copy-tip';
                    tip.className = 'seo-token-copy-tip';
                    document.body.appendChild(tip);
                }
                tip.textContent = 'Đã copy token vào clipboard';
                tip.style.display = 'block';
                clearTimeout(window.seoTokenCopyTipTimer);
                window.seoTokenCopyTipTimer = setTimeout(function () {
                    tip.style.display = 'none';
                }, 2200);
            });
        };
    </script>
</div>
