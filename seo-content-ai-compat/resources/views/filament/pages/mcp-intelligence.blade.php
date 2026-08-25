@php
    $state = $this->viewState();
    $presenter = $this->presenter();
    /** @var \Omnichannel\Addons\Seo\Models\SeoMcpPeriod|null $period */
    $period = $state['period'];
    $site = $state['site'];
    $report = $state['report'];
    $siteCard = $state['site_card'];
    $kwCard = $state['keyword_card'];
    $gscCard = is_array($state['gsc_card'] ?? null) ? $state['gsc_card'] : ['freshness' => 'missing', 'relative' => null, 'metrics' => []];
    $kwMetrics = is_array($kwCard['metrics'] ?? null) ? $kwCard['metrics'] : [];
    $siteMetrics = is_array($siteCard['metrics'] ?? null) ? $siteCard['metrics'] : [];
    $gscMetrics = is_array($gscCard['metrics'] ?? null) ? $gscCard['metrics'] : [];
    $kwSummary = is_array($state['keyword_snap']?->summary_json) ? $state['keyword_snap']->summary_json : [];
    $gscSummary = is_array($state['gsc_snap']?->summary_json) ? $state['gsc_snap']->summary_json : [];
    $groups = is_array($kwSummary['groups'] ?? null) ? $kwSummary['groups'] : [];
    $weakGroups = array_values(array_filter($groups, static fn ($g): bool => is_array($g) && (int) ($g['count'] ?? 0) > 0 && (int) ($g['count'] ?? 0) <= 6));
    $clusters = is_array($kwSummary['clusters'] ?? null) ? $kwSummary['clusters'] : [];
    $overview = is_array($report?->overview_json) ? $report->overview_json : [];
    $highlights = is_array($report?->highlights_json) ? $report->highlights_json : [];
    $risks = is_array($report?->risks_json) ? $report->risks_json : [];
    $opps = is_array($report?->opportunities_json) ? $report->opportunities_json : [];
    $actions = is_array($report?->action_plan_json) ? $report->action_plan_json : [];
    $periodLabel = sprintf('%02d/%d', $this->selectedMonth(), $this->selectedYear());
    $isOpen = $period?->isOpen() ?? true;
    $reportStatus = (string) ($report->status ?? 'missing');
    $siteSummary = is_array($state['site_summary'] ?? null) ? $state['site_summary'] : [];
    $contentDistribution = is_array($siteSummary['content_distribution'] ?? null) ? $siteSummary['content_distribution'] : [];
    $publishingStatus = is_array($siteSummary['publishing_status'] ?? null) ? $siteSummary['publishing_status'] : [];
    $linkedArticlesTotal = array_key_exists('linked_articles_total', $state) ? ($state['linked_articles_total'] ?? null) : null;
    $linkedArticlesRows = is_array($state['linked_articles_rows'] ?? null) ? $state['linked_articles_rows'] : [];
    $linkedPerPage = (int) ($this->linkedArticlesPerPage ?? 10);
    $linkedPage = max(1, (int) ($this->linkedArticlesPage ?? 1));
    $linkedTotalPages = $linkedArticlesTotal === null ? null : (int) max(1, ceil(((int) $linkedArticlesTotal) / max(1, $linkedPerPage)));
    $metricOrDash = static function (mixed $value): string {
        if ($value === null) {
            return '—';
        }
        if (is_float($value)) {
            return number_format($value, 1);
        }
        return number_format((int) $value);
    };
    $siteKpiPosts = array_key_exists('article_total', $siteMetrics) ? $siteMetrics['article_total'] : null;
    $siteKpiCategories = array_key_exists('categories', $siteMetrics) ? $siteMetrics['categories'] : null;
    $siteKpiInternalLinks = array_key_exists('internal_links', $siteMetrics) ? $siteMetrics['internal_links'] : null;
    $siteKpiLinkedArticles = array_key_exists('internally_linked_articles', $siteMetrics) ? $siteMetrics['internally_linked_articles'] : null;

    $kwKpiFocus = array_key_exists('focus', $kwMetrics) ? $kwMetrics['focus'] : null;
    $kwKpiError = array_key_exists('error', $kwMetrics) ? $kwMetrics['error'] : null;
    $kwKpiClusters = array_key_exists('clusters', $kwMetrics) ? $kwMetrics['clusters'] : null;
    $gscKpiClicks = array_key_exists('clicks', $gscMetrics) ? $gscMetrics['clicks'] : null;
    $gscKpiImpressions = array_key_exists('impressions', $gscMetrics) ? $gscMetrics['impressions'] : null;
    $gscKpiFalling = array_key_exists('falling_count', $gscMetrics) ? $gscMetrics['falling_count'] : null;
    $gscKpiCtrOpp = array_key_exists('ctr_opportunity_count', $gscMetrics) ? $gscMetrics['ctr_opportunity_count'] : null;
    $markdown = is_array($state['markdown'] ?? null) ? $state['markdown'] : [];
    $markdownPreview = is_array($markdown['preview'] ?? null) ? $markdown['preview'] : ['site' => '', 'keywords' => '', 'gsc' => '', 'combined' => '', 'ai_context' => ''];
    $markdownTokens = is_array($markdown['tokens'] ?? null) ? $markdown['tokens'] : ['characters' => 0, 'estimated_tokens' => 0];
    $markdownUpdatedAt = $markdown['updated_at'] ?? null;
    $cssPath = base_path('addons/seo/resources/css/mcp-intelligence.css');
@endphp

<div>
    <x-filament-panels::page class="mcp-intelligence-page">
        @if (is_readable($cssPath))
            <style>{!! file_get_contents($cssPath) !!}</style>
        @endif

        <div class="mcp-report space-y-5" x-data="{
            refreshOpen: false,
            markdownOpen: false,
            aiContextOpen: false,
            markdownTab: 'combined',
            markdownView: 'raw',
            aiContextView: 'raw',
            copied: false,
            copyText(text) {
                navigator.clipboard.writeText(text).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1800);
                });
            }
        }" @domain-context-changed.window="
            markdownOpen = false;
            aiContextOpen = false;
            markdownTab = 'combined';
            markdownView = 'raw';
            aiContextView = 'raw';
        ">
            <header class="mcp-report__header">
                <div class="mcp-report__toolbar">
                    <div class="mcp-report__filter-bar">
                        <div class="mcp-report__filter">
                            <span class="mcp-report__filter-label">{{ __('seo-content-ai::filament.mcp_intelligence.domain_label') }}</span>
                            <x-select wire:model.live="siteId" class="min-w-[14rem]">
                                @foreach ($this->siteOptions() as $siteOptionId => $siteDomain)
                                    <option value="{{ $siteOptionId }}">{{ $siteDomain }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="mcp-report__filter">
                            <span class="mcp-report__filter-label">{{ __('seo-content-ai::filament.mcp_intelligence.period_label') }}</span>
                            <x-select wire:model.live="periodKey" class="min-w-[8rem]">
                                @foreach ($this->periodOptions() as $opt)
                                    <option value="{{ sprintf('%04d-%02d', $opt['year'], $opt['month']) }}">
                                        {{ $opt['label'] }}
                                        @if ($opt['exists'] && $opt['status'] === 'finalized')
                                            · {{ __('seo-content-ai::filament.mcp_intelligence.status_finalized') }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>
                    <div class="mcp-report__controls">
                    @if ($period && $isOpen)
                        <div class="relative">
                            <button type="button" class="mcp-btn mcp-btn--primary" @click="refreshOpen = !refreshOpen">
                                {{ __('seo-content-ai::filament.mcp_intelligence.refresh_all') }}
                                <span class="ml-1">▼</span>
                            </button>
                            <div x-show="refreshOpen" x-cloak class="mcp-menu">
                                <button type="button" wire:click="refreshSiteSnapshot" wire:loading.attr="disabled" @click="refreshOpen = false">
                                    {{ __('seo-content-ai::filament.mcp_intelligence.refresh_site') }}
                                </button>
                                <button type="button" wire:click="refreshKeywordSnapshot" wire:loading.attr="disabled" @click="refreshOpen = false">
                                    {{ __('seo-content-ai::filament.mcp_intelligence.refresh_keywords') }}
                                </button>
                                <button type="button" wire:click="refreshGscSnapshot" wire:loading.attr="disabled" @click="refreshOpen = false">
                                    Refresh GSC
                                </button>
                                <button type="button" wire:click="refreshAll" wire:loading.attr="disabled" @click="refreshOpen = false">
                                    {{ __('seo-content-ai::filament.mcp_intelligence.refresh_all') }}
                                </button>
                            </div>
                        </div>
                        <button type="button" wire:click="requestFinalize" wire:loading.attr="disabled" class="mcp-btn">
                            {{ __('seo-content-ai::filament.mcp_intelligence.finalize') }}
                        </button>
                    @elseif ($period)
                        <button type="button" wire:click="reopenPeriod" wire:loading.attr="disabled" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.reopen') }}</button>
                    @endif
                    <button type="button" class="mcp-btn" @click="markdownOpen = true">{{ __('seo-content-ai::filament.mcp_intelligence.view_markdown') }}</button>
                    <button type="button" class="mcp-btn" @click="aiContextOpen = true">{{ __('seo-content-ai::filament.mcp_intelligence.view_ai_context') }}</button>
                    </div>
                </div>
                <div class="mcp-report__heading">
                    <h1 class="mcp-report__title">{{ __('seo-content-ai::filament.mcp_intelligence.title') }}</h1>
                    <p class="mcp-report__meta">
                        {{ $site?->domain ?? __('seo-content-ai::filament.mcp_intelligence.pick_site') }}
                        · {{ __('seo-content-ai::filament.mcp_intelligence.month_label', ['period' => $periodLabel]) }}
                        · {{ $period ? __('seo-content-ai::filament.mcp_intelligence.status_'.$period->status) : __('seo-content-ai::filament.mcp_intelligence.status_missing') }}
                    </p>
                </div>
            </header>

            <div class="mcp-loading-overlay" wire:loading>
                <div class="mcp-loading-box">
                    <div class="mcp-spinner" aria-hidden="true"></div>
                    <div class="mcp-loading-text">Đang tải MCP Intelligence...</div>
                </div>
            </div>

            @if ($site === null)
                @if (($state['load_error'] ?? false) === true)
                    <div class="mcp-banner mcp-banner--error">
                        <div class="font-semibold">Không thể tải MCP Intelligence.</div>
                        <div class="mt-2">
                            <button type="button" wire:click="refreshAll" class="mcp-btn mcp-btn--primary">
                                Thử lại
                            </button>
                        </div>
                    </div>
                @else
                    <p class="mcp-empty">{{ __('seo-content-ai::filament.mcp_intelligence.need_domain') }}</p>
                @endif
            @elseif ($state['changed_after_finalize'])
                <div class="mcp-banner mcp-banner--warn">{{ __('seo-content-ai::filament.mcp_intelligence.source_changed_after_finalize') }}</div>
            @endif

            @if ($site && ! $period)
                <div class="mcp-card">
                    <p>{{ __('seo-content-ai::filament.mcp_intelligence.empty_period', ['period' => $periodLabel]) }}</p>
                    <button type="button" wire:click="createPeriod" class="mcp-btn mcp-btn--primary mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.create_period', ['period' => $periodLabel]) }}</button>
                </div>
            @elseif ($site && $period)
                <div wire:key="mcp-dashboard-{{ (int) ($site->id ?? 0) }}-{{ $this->periodKey }}">
                <section class="mcp-status-strip">
                    <div class="mcp-source-card">
                        <div class="mcp-source-card__label">{{ __('seo-content-ai::filament.mcp_intelligence.site_intel') }}</div>
                        <div class="mcp-source-card__row">
                            <span class="mcp-dot {{ $presenter->freshnessClass($siteCard['freshness']) }}"></span>
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.$siteCard['freshness']) }}</span>
                        </div>
                        <div class="mcp-source-card__meta">
                            Cập nhật: {{ $siteCard['relative'] ?? $siteCard['absolute'] ?? '—' }}
                        </div>
                    </div>

                    <div class="mcp-source-card">
                        <div class="mcp-source-card__label">{{ __('seo-content-ai::filament.mcp_intelligence.keyword_intel') }}</div>
                        <div class="mcp-source-card__row">
                            <span class="mcp-dot {{ $presenter->freshnessClass($kwCard['freshness']) }}"></span>
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.$kwCard['freshness']) }}</span>
                        </div>
                        <div class="mcp-source-card__meta">
                            Cập nhật: {{ $kwCard['relative'] ?? $kwCard['absolute'] ?? '—' }}
                        </div>
                    </div>

                    <div class="mcp-source-card">
                        <div class="mcp-source-card__label">GSC</div>
                        <div class="mcp-source-card__row">
                            <span class="mcp-dot {{ $presenter->freshnessClass($gscCard['freshness'] ?? 'missing') }}"></span>
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.($gscCard['freshness'] ?? 'missing')) }}</span>
                        </div>
                        <div class="mcp-source-card__meta">
                            Cập nhật: {{ $gscCard['relative'] ?? $gscCard['absolute'] ?? '—' }}
                        </div>
                    </div>

                    @php
                        $siteReady = (bool) ($state['site_snap']?->isUsable() ?? false);
                        $kwReady = (bool) ($state['keyword_snap']?->isUsable() ?? false);
                        $gscReady = (bool) ($state['gsc_snap']?->isUsable() ?? false);
                    @endphp
                    <div class="mcp-source-card">
                        <div class="mcp-source-card__label">{{ __('seo-content-ai::filament.mcp_intelligence.card_coverage') }}</div>
                        <div class="mcp-source-card__big">{{ $state['source_ready'] }} / {{ $state['source_total'] }}</div>
                        <div class="mcp-source-card__meta">
                            Website {{ $siteReady ? '✓' : '—' }} · Keywords {{ $kwReady ? '✓' : '—' }} · GSC {{ $gscReady ? '✓' : '—' }}
                        </div>
                    </div>

                    <div class="mcp-source-card">
                        <div class="mcp-source-card__label">{{ __('seo-content-ai::filament.mcp_intelligence.card_report') }}</div>
                        <div class="mcp-source-card__row">
                            <span class="mcp-badge {{ $presenter->reportStatusClass($reportStatus) }}">{{ __('seo-content-ai::filament.mcp_intelligence.report_'.$reportStatus) }}</span>
                        </div>
                        <div class="mcp-source-card__meta">
                            Tổng hợp:
                            {{ $siteKpiPosts === null ? '—' : number_format((int) $siteKpiPosts) }} bài ·
                            {{ $kwKpiFocus === null ? '—' : number_format((int) $kwKpiFocus) }} focus
                        </div>
                    </div>
                </section>

                @if (! $report)
                    <div class="mcp-card mcp-empty-report">
                        <p class="mcp-empty-report__title">Chưa có MCP Intelligence cho kỳ {{ $periodLabel }}.</p>
                        @if ($isOpen)
                            <button type="button" wire:click="generateReport" class="mcp-btn mcp-btn--primary mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.create_report') }}</button>
                        @endif
                    </div>
                @else
                    <section class="mcp-month-kpis">
                        <h2 class="mcp-h2">TỔNG QUAN THÁNG {{ $periodLabel }}</h2>
                        <div class="mcp-kpi-groups">
                            <div class="mcp-kpi-group">
                                <div class="mcp-kpi-group__title mcp-kpi-group__title--site">WEBSITE</div>
                                <div class="mcp-kpi-grid">
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">{{ __('seo-content-ai::filament.mcp_intelligence.metric_articles') }}</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($siteKpiPosts) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">Danh mục</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($siteKpiCategories) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">{{ __('seo-content-ai::filament.mcp_intelligence.internal_links') }}</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($siteKpiInternalLinks) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">Bài viết liên kết</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($siteKpiLinkedArticles) }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="mcp-kpi-group">
                                <div class="mcp-kpi-group__title mcp-kpi-group__title--keywords">KEYWORDS</div>
                                <div class="mcp-kpi-grid">
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">{{ __('seo-content-ai::filament.mcp_intelligence.metric_focus') }}</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($kwKpiFocus) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">{{ __('seo-content-ai::filament.mcp_intelligence.metric_error') }}</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($kwKpiError) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">{{ __('seo-content-ai::filament.mcp_intelligence.metric_clusters') }}</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($kwKpiClusters) }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="mcp-kpi-group">
                                <div class="mcp-kpi-group__title">GSC</div>
                                <div class="mcp-kpi-grid">
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">Clicks</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($gscKpiClicks) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">GSC impressions</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($gscKpiImpressions) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">Falling</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($gscKpiFalling) }}</strong>
                                    </div>
                                    <div class="mcp-kpi-item">
                                        <span class="mcp-kpi-item__label">CTR opportunities</span>
                                        <strong class="mcp-kpi-item__value">{{ $metricOrDash($gscKpiCtrOpp) }}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                <div class="mcp-grid-2">
                    <section class="mcp-card">
                        <div class="mcp-card__head">
                            <div>
                                <h2 class="mcp-h2 mcp-h2--site">A. SITE OVERVIEW</h2>
                                <p class="mcp-card-sub">Hiệu suất & trạng thái website</p>
                            </div>
                            <button type="button" wire:click="openSourcePreview('site')" class="mcp-link">{{ __('seo-content-ai::filament.mcp_intelligence.view_source') }} →</button>
                        </div>

                        @php
                            $health = array_key_exists('health', $siteMetrics) ? $siteMetrics['health'] : null;
                            $healthVi = match ((string) $health) {
                                'healthy' => 'Tốt',
                                'degraded' => 'Cần cải thiện',
                                'unhealthy', 'error', 'failed' => 'Có vấn đề',
                                default => ($health !== null && $health !== '') ? (string) $health : '—',
                            };
                            $indexable = array_key_exists('indexable', $siteMetrics) ? $siteMetrics['indexable'] : null;
                            $noindex = array_key_exists('noindex', $siteMetrics) ? $siteMetrics['noindex'] : null;
                            $indexTotal = ($indexable !== null && $noindex !== null) ? ((int) $indexable + (int) $noindex) : null;
                            $indexPct = ($indexTotal !== null && $indexTotal > 0 && $indexable !== null)
                                ? round(((int) $indexable / (int) $indexTotal) * 100, 1)
                                : null;
                        @endphp

                        <div class="mcp-overview-stack">
                            @if (! ($state['site_snap']?->isUsable() ?? false))
                                <div class="mcp-publish-empty">Chưa có dữ liệu website cho kỳ này.</div>
                            @else
                            <div class="mcp-health-block">
                                <div class="mcp-overview-label">{{ __('seo-content-ai::filament.mcp_intelligence.site_health') }}</div>
                                <div class="mcp-health-pill">
                                    <span class="mcp-dot mcp-dot--health"></span>
                                    <span class="mcp-health-pill__text">● {{ $healthVi }}</span>
                                </div>
                            </div>

                            <div class="mcp-overview-stats">
                                <div class="mcp-overview-stat">
                                    <div class="mcp-overview-label">{{ __('seo-content-ai::filament.mcp_intelligence.indexability') }}</div>
                                    <div class="mcp-overview-value">
                                        {{ ($indexable !== null && $indexTotal !== null && $indexTotal > 0) ? (number_format((int) $indexable).' / '.number_format((int) $indexTotal)) : '—' }}
                                    </div>
                                    <div class="mcp-overview-muted">{{ $indexPct !== null ? $indexPct.'%' : '—' }}</div>
                                </div>

                                <div class="mcp-overview-stat">
                                    <div class="mcp-overview-label">{{ __('seo-content-ai::filament.mcp_intelligence.internal_links') }}</div>
                                    <div class="mcp-overview-value">{{ $metricOrDash($siteKpiInternalLinks) }}</div>
                                </div>

                                <div class="mcp-overview-stat">
                                    <div class="mcp-overview-label">Bài viết liên kết</div>
                                    <div class="mcp-overview-value">{{ $metricOrDash($siteKpiLinkedArticles) }}</div>
                                </div>
                            </div>

                            <div class="mcp-overview-section">
                                <div class="mcp-overview-section__title">Phân bố nội dung</div>
                                @php
                                    $posts = array_key_exists('posts', $contentDistribution) ? $contentDistribution['posts'] : null;
                                    $pages = array_key_exists('pages', $contentDistribution) ? $contentDistribution['pages'] : null;
                                    $categories = array_key_exists('categories', $contentDistribution) ? $contentDistribution['categories'] : null;
                                    $products = array_key_exists('products', $contentDistribution) ? $contentDistribution['products'] : null;
                                    $productCategories = array_key_exists('product_categories', $contentDistribution) ? $contentDistribution['product_categories'] : null;
                                    $other = array_key_exists('other', $contentDistribution) ? $contentDistribution['other'] : null;
                                    $distMax = max((int) ($posts ?? 0), (int) ($pages ?? 0), (int) ($categories ?? 0), (int) ($products ?? 0), (int) ($productCategories ?? 0), (int) ($other ?? 0));
                                @endphp
                                <div class="mcp-dist-list">
                                    @foreach ([
                                        ['label' => 'Bài viết', 'value' => $posts],
                                        ['label' => 'Trang', 'value' => $pages],
                                        ['label' => 'Danh mục', 'value' => $categories],
                                        ['label' => 'Sản phẩm', 'value' => $products],
                                        ['label' => 'Danh mục sản phẩm', 'value' => $productCategories],
                                        ['label' => 'Khác', 'value' => $other],
                                    ] as $row)
                                        @php
                                            $val = $row['value'];
                                            $valInt = $val === null ? 0 : (int) $val;
                                            $pct = $distMax > 0 ? round(($valInt / $distMax) * 100, 1) : 0;
                                        @endphp
                                        <div class="mcp-dist-row">
                                            <div class="mcp-dist-row__top">
                                                <span class="mcp-dist-row__label">{{ $row['label'] }}</span>
                                                <span class="mcp-dist-row__value">{{ $metricOrDash($val) }}</span>
                                            </div>
                                            <div class="mcp-dist-bar">
                                                <div class="mcp-dist-bar__fill" style="width: {{ $val === null ? 0 : $pct }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mcp-overview-section">
                                <div class="mcp-overview-section__title">Trạng thái nội dung</div>
                                <div class="mcp-publish-grid">
                                    @php
                                        $published = (int) ($publishingStatus['published'] ?? 0);
                                        $scheduled = (int) ($publishingStatus['scheduled'] ?? 0);
                                        $draft = (int) ($publishingStatus['draft'] ?? 0);
                                        $private = (int) ($publishingStatus['private'] ?? 0);
                                        $other = (int) ($publishingStatus['other'] ?? 0);
                                    @endphp
                                    @if ($published > 0)
                                        <div class="mcp-publish-item"><span>Đã xuất bản</span><strong>{{ number_format($published) }}</strong></div>
                                    @endif
                                    @if ($scheduled > 0)
                                        <div class="mcp-publish-item"><span>Chờ xuất bản</span><strong>{{ number_format($scheduled) }}</strong></div>
                                    @endif
                                    @if ($draft > 0)
                                        <div class="mcp-publish-item"><span>Bản nháp</span><strong>{{ number_format($draft) }}</strong></div>
                                    @endif
                                    @if ($private > 0)
                                        <div class="mcp-publish-item"><span>Riêng tư</span><strong>{{ number_format($private) }}</strong></div>
                                    @endif
                                    @if ($other > 0)
                                        <div class="mcp-publish-item"><span>Khác</span><strong>{{ number_format($other) }}</strong></div>
                                    @endif
                                    @if ($published === 0 && $scheduled === 0 && $draft === 0 && $private === 0 && $other === 0)
                                        <div class="mcp-publish-empty">Chưa có dữ liệu trạng thái nội dung.</div>
                                    @endif
                                </div>
                            </div>

                            <div class="mcp-overview-cta">
                                <button type="button" wire:click="openSourcePreview('site')" class="mcp-link mcp-link--cta">Xem chi tiết Site Health →</button>
                            </div>
                            @endif
                        </div>
                    </section>

                    <section class="mcp-card">
                        <div class="mcp-card__head">
                            <div>
                                <h2 class="mcp-h2 mcp-h2--keywords">B. KEYWORD OVERVIEW</h2>
                                <p class="mcp-card-sub">Hiệu suất từ khóa & Topic clusters</p>
                            </div>
                            <button type="button" wire:click="openSourcePreview('keywords')" class="mcp-link">{{ __('seo-content-ai::filament.mcp_intelligence.view_source') }} →</button>
                        </div>

                        @php
                            $kwTotal = array_key_exists('total', $kwMetrics) ? $kwMetrics['total'] : null;
                            $kwUnclustered = array_key_exists('unclustered', $kwMetrics) ? $kwMetrics['unclustered'] : null;
                            $kwClustered = ($kwTotal !== null && $kwUnclustered !== null) ? max(0, (int) $kwTotal - (int) $kwUnclustered) : null;

                            $cFocus = $kwKpiFocus ?? 0;
                            $cError = $kwKpiError ?? 0;
                            $cExcluded = array_key_exists('excluded', $kwMetrics) ? (int) $kwMetrics['excluded'] : 0;
                            $cNoise = $kwUnclustered ?? 0;
                            $cSum = (int) $cFocus + (int) $cError + (int) $cExcluded + (int) $cNoise;
                            $pFocus = $cSum > 0 ? round((($cFocus) / $cSum) * 360, 2) : 0;
                            $pError = $cSum > 0 ? round((($cError) / $cSum) * 360, 2) : 0;
                            $pExcluded = $cSum > 0 ? round((($cExcluded) / $cSum) * 360, 2) : 0;
                            $pNoise = $cSum > 0 ? round((($cNoise) / $cSum) * 360, 2) : 0;
                            $donutStyle = $cSum > 0
                                ? 'background: conic-gradient(rgb(124 58 237) 0deg '.($pFocus).'deg, rgb(239 68 68) '.($pFocus).'deg '.($pFocus + $pError).'deg, rgb(245 158 11) '.($pFocus + $pError).'deg '.($pFocus + $pError + $pExcluded).'deg, rgb(156 163 175) '.($pFocus + $pError + $pExcluded).'deg 360deg);'
                                : 'background: conic-gradient(rgb(156 163 175) 0deg 360deg);';
                        @endphp

                        @if (! ($state['keyword_snap']?->isUsable() ?? false))
                            <div class="mcp-publish-empty">Chưa có dữ liệu từ khóa cho kỳ này.</div>
                        @else
                        <div class="mcp-keyword-summary">
                            <div class="mcp-mini-stat">
                                <div class="mcp-mini-stat__label">{{ __('seo-content-ai::filament.mcp_intelligence.metric_focus') }}</div>
                                <div class="mcp-mini-stat__value">{{ $metricOrDash($kwKpiFocus) }}</div>
                            </div>
                            <div class="mcp-mini-stat">
                                <div class="mcp-mini-stat__label">SEO keywords</div>
                                <div class="mcp-mini-stat__value">{{ $metricOrDash($kwClustered) }}</div>
                            </div>
                            <div class="mcp-mini-stat">
                                <div class="mcp-mini-stat__label">Non-SEO / Noise</div>
                                <div class="mcp-mini-stat__value">{{ $metricOrDash($kwUnclustered) }}</div>
                            </div>
                        </div>

                        <div class="mcp-overview-section">
                            <div class="mcp-overview-section__title">Phân loại từ khóa</div>
                            <div class="mcp-classification">
                                <div class="mcp-donut" style="{{ $donutStyle }}"></div>
                                <div class="mcp-classification-legend">
                                    <div class="mcp-legend-row"><span class="mcp-legend-dot" style="background: rgb(124 58 237)"></span>Focus: {{ $metricOrDash($kwKpiFocus) }}</div>
                                    <div class="mcp-legend-row"><span class="mcp-legend-dot" style="background: rgb(239 68 68)"></span>Lỗi SEO: {{ $metricOrDash($kwKpiError) }}</div>
                                    <div class="mcp-legend-row"><span class="mcp-legend-dot" style="background: rgb(245 158 11)"></span>Loại SEO: {{ $metricOrDash(array_key_exists('excluded', $kwMetrics) ? $kwMetrics['excluded'] : null) }}</div>
                                    <div class="mcp-legend-row"><span class="mcp-legend-dot" style="background: rgb(156 163 175)"></span>Noise: {{ $metricOrDash($kwUnclustered) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="mcp-overview-section">
                            <div class="mcp-overview-section__title">Topic clusters</div>
                            @if ($clusters === [])
                                <div class="mcp-publish-empty">Chưa có dữ liệu topic clusters cho kỳ này.</div>
                            @else
                                <ul class="mcp-cluster-list">
                                    @foreach (array_slice($clusters, 0, 5) as $cluster)
                                        @php
                                            $cid = (string) ($cluster['cluster_id'] ?? '');
                                            $kwCount = (int) ($cluster['keyword_count'] ?? 0);
                                            $linkedCount = (int) ($cluster['linked_articles_count'] ?? $cluster['article_count'] ?? 0);
                                        @endphp
                                        <li class="mcp-cluster-row">
                                            <a class="mcp-cluster-row__title" href="{{ $this->clusterUrl($cid) }}">{{ $cluster['name'] ?? $cid }}</a>
                                            <div class="mcp-cluster-row__meta">
                                                {{ number_format($kwCount) }} {{ __('seo-content-ai::filament.mcp_intelligence.keywords_unit') }}
                                                · {{ number_format($linkedCount) }} {{ __('seo-content-ai::filament.mcp_intelligence.articles_unit') }}
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="mcp-overview-cta">
                                    <a class="mcp-link mcp-link--cta" href="{{ $this->keywordsUnclusteredUrl() }}">Xem tất cả clusters →</a>
                                </div>
                            @endif
                        </div>
                        @endif
                    </section>
                </div>

                    <div class="mcp-grid-3 mcp-insights">
                        <section class="mcp-card mcp-insight-card mcp-insight-card--highlights">
                            <div class="mcp-insight-head">
                                <span class="mcp-insight-icon mcp-insight-icon--highlights"></span>
                                <h2 class="mcp-insight-title">{{ __('seo-content-ai::filament.mcp_intelligence.highlights') }}</h2>
                            </div>
                            <ul class="mcp-list">
                                @forelse (array_slice($highlights, 0, 6) as $item)
                                    <li>{{ $presenter->highlightText($item) }}</li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                        <section class="mcp-card mcp-insight-card mcp-insight-card--risks">
                            <div class="mcp-insight-head">
                                <span class="mcp-insight-icon mcp-insight-icon--risks"></span>
                                <h2 class="mcp-insight-title">{{ __('seo-content-ai::filament.mcp_intelligence.risks') }}</h2>
                            </div>
                            <ul class="mcp-list">
                                @forelse (array_slice($risks, 0, 6) as $item)
                                    <li>
                                        @if (($item['key'] ?? '') === 'keyword_error')
                                            <a href="{{ $this->keywordsErrorUrl() }}" target="_blank" rel="noopener noreferrer">{{ $presenter->highlightText($item) }}</a>
                                        @elseif (($item['key'] ?? '') === 'unclustered_keywords')
                                            <a href="{{ $this->keywordsUnclusteredUrl() }}" target="_blank" rel="noopener noreferrer">{{ $presenter->highlightText($item) }}</a>
                                        @else
                                            {{ $presenter->highlightText($item) }}
                                        @endif
                                    </li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                        <section class="mcp-card mcp-insight-card mcp-insight-card--opportunities">
                            <div class="mcp-insight-head">
                                <span class="mcp-insight-icon mcp-insight-icon--opportunities"></span>
                                <h2 class="mcp-insight-title">{{ __('seo-content-ai::filament.mcp_intelligence.opportunities') }}</h2>
                            </div>
                            <ul class="mcp-list">
                                @forelse (array_slice($opps, 0, 6) as $item)
                                    <li>
                                        @if (($item['key'] ?? '') === 'weak_cluster' && ($item['cluster_id'] ?? '') !== '')
                                            <a href="{{ $this->clusterUrl((string) $item['cluster_id']) }}" target="_blank" rel="noopener noreferrer">{{ $presenter->highlightText($item) }}</a>
                                        @else
                                            {{ $presenter->highlightText($item) }}
                                        @endif
                                    </li>
                                @empty
                                    <li class="mcp-status__muted">{{ __('seo-content-ai::filament.mcp_intelligence.none') }}</li>
                                @endforelse
                            </ul>
                        </section>
                    </div>

                    <section class="mcp-card mcp-linked-section">
                        <div class="mcp-card__head">
                            <div>
                                <h2 class="mcp-h2 mcp-h2--linked">
                                    C. BÀI VIẾT LIÊN KẾT — {{ $linkedArticlesTotal === null ? '—' : number_format((int) $linkedArticlesTotal) }} BÀI
                                </h2>
                                <p class="mcp-card-sub">Các bài viết đang nhận internal link trong website</p>
                            </div>
                        </div>

                        @if ($linkedArticlesTotal === null)
                            <div class="mcp-empty">Chưa có dữ liệu bài viết liên kết trong snapshot hiện tại.</div>
                        @elseif ($linkedArticlesTotal === 0)
                            <div class="mcp-empty">Chưa có bài viết nào được nhận internal links cho kỳ này.</div>
                        @else
                            @php
                                $range = \Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::linkedArticlesRangeMeta((int) $linkedArticlesTotal, $linkedPage, $linkedPerPage);
                                $linkedStart = $range['start'];
                                $linkedEnd = $range['end'];
                                $pages = $linkedTotalPages ?? 1;
                                $window = \Omnichannel\Addons\Seo\Filament\Pages\McpIntelligence::linkedArticlesPageWindow($linkedPage, (int) $pages, 2);
                                $pageFirst = $window['first'];
                                $pageLast = $window['last'];
                            @endphp

                            <div class="mcp-table-wrap">
                                <div class="overflow-x-auto">
                                    <table class="mcp-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Bài viết</th>
                                                <th class="text-right">Internal links</th>
                                                <th class="text-right">Ngày cập nhật</th>
                                                <th class="text-right">Trạng thái</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($linkedArticlesRows as $idx => $row)
                                                @php
                                                    $rowIndex = $linkedStart + $idx;
                                                    $status = (string) ($row['status'] ?? '');
                                                    $statusLabel = match ($status) {
                                                        'published' => __('seo-content-ai::filament.article_list.status_published'),
                                                        'scheduled' => __('seo-content-ai::filament.article_list.status_scheduled'),
                                                        'draft' => __('seo-content-ai::filament.article_list.status_draft'),
                                                        'private' => __('seo-content-ai::filament.article_list.status_private'),
                                                        default => $status !== '' ? $status : '—',
                                                    };
                                                    $articleUrl = \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::getUrl('edit', ['record' => $row['id']]);
                                                @endphp
                                                <tr>
                                                    <td>{{ $rowIndex }}</td>
                                                    <td class="mcp-table__title"><a href="{{ $articleUrl }}">{{ $row['title'] ?? '—' }}</a></td>
                                                    <td class="text-right tabular-nums">{{ number_format((int) ($row['internal_links'] ?? 0)) }}</td>
                                                    <td class="text-right tabular-nums">
                                                        {{ $row['updated_at'] ? \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($row['updated_at']) : '—' }}
                                                    </td>
                                                    <td class="text-right">{{ $statusLabel }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="mcp-pagination-foot">
                                <div class="mcp-pagination-meta">Hiển thị {{ $linkedStart }}–{{ $linkedEnd }} / {{ number_format((int) $linkedArticlesTotal) }} bài</div>
                                <div class="mcp-pagination-controls">
                                    @if ($linkedPage > 1)
                                        <button type="button" class="mcp-btn" wire:click="$set('linkedArticlesPage', {{ $linkedPage - 1 }})" wire:loading.attr="disabled">‹</button>
                                    @endif
                                    @for ($p = $pageFirst; $p <= $pageLast; $p++)
                                        <button type="button"
                                            class="mcp-btn {{ $p === $linkedPage ? 'mcp-btn--primary' : '' }}"
                                            wire:click="$set('linkedArticlesPage', {{ $p }})"
                                            wire:loading.attr="disabled"
                                        >{{ $p }}</button>
                                    @endfor
                                    @if ($linkedPage < (int) $pages)
                                        <button type="button" class="mcp-btn" wire:click="$set('linkedArticlesPage', {{ $linkedPage + 1 }})" wire:loading.attr="disabled">›</button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
                </div>
            @endif

            @if ($this->showFinalizeConfirm)
                <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div class="w-full max-w-md rounded-xl bg-white p-4 dark:bg-gray-900">
                        <p class="text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.finalize_confirm', [
                            'available' => $state['coverage']['available'],
                            'expected' => $state['coverage']['expected'],
                        ]) }}</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" wire:click="$set('showFinalizeConfirm', false)" class="mcp-btn">{{ __('seo-content-ai::filament.mcp_intelligence.cancel') }}</button>
                            <button type="button" wire:click="confirmFinalize" class="mcp-btn mcp-btn--primary">{{ __('seo-content-ai::filament.mcp_intelligence.finalize') }}</button>
                        </div>
                    </div>
                </div>
            @endif

            <div
                wire:key="mcp-markdown-{{ (int) ($site?->id ?? 0) }}-{{ $this->periodKey }}"
                x-show="markdownOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3"
                @keydown.escape.window="markdownOpen = false"
                @click.self="markdownOpen = false"
            >
                <div class="mcp-md-modal" @click.stop>
                    <div class="mcp-md-modal__head">
                        <div>
                            <h2 class="mcp-md-modal__title">{{ __('seo-content-ai::filament.mcp_intelligence.markdown_title') }}</h2>
                            <p class="mcp-md-modal__meta">{{ $site?->domain ?? '—' }} · {{ $periodLabel }}</p>
                        </div>
                        <button type="button" class="mcp-btn" @click="markdownOpen = false">✕</button>
                    </div>
                    <div class="mcp-md-modal__tabs">
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownTab === 'site' }" @click="markdownTab = 'site'">{{ __('seo-content-ai::filament.mcp_intelligence.tab_site_mcp') }}</button>
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownTab === 'keywords' }" @click="markdownTab = 'keywords'">{{ __('seo-content-ai::filament.mcp_intelligence.tab_keywords_mcp') }}</button>
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownTab === 'gsc' }" @click="markdownTab = 'gsc'">GSC</button>
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownTab === 'combined' }" @click="markdownTab = 'combined'">{{ __('seo-content-ai::filament.mcp_intelligence.tab_combined') }}</button>
                        <div class="mcp-md-modal__view-toggle">
                            <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownView === 'raw' }" @click="markdownView = 'raw'">{{ __('seo-content-ai::filament.mcp_intelligence.raw_markdown') }}</button>
                            <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': markdownView === 'preview' }" @click="markdownView = 'preview'">{{ __('seo-content-ai::filament.mcp_intelligence.preview') }}</button>
                        </div>
                    </div>
                    <div class="mcp-md-modal__body">
                        <template x-if="markdownView === 'raw'">
                            <pre class="mcp-md-modal__raw"
                                x-text="markdownTab === 'site' ? @js($markdown['site'] ?? '') : (markdownTab === 'keywords' ? @js($markdown['keywords'] ?? '') : (markdownTab === 'gsc' ? @js($markdown['gsc'] ?? '') : @js($markdown['combined'] ?? '')))"></pre>
                        </template>
                        <template x-if="markdownView === 'preview'">
                            <div class="mcp-md-modal__preview prose prose-sm max-w-none dark:prose-invert"
                                x-html="markdownTab === 'site' ? @js($markdownPreview['site']) : (markdownTab === 'keywords' ? @js($markdownPreview['keywords']) : (markdownTab === 'gsc' ? @js($markdownPreview['gsc'] ?? '') : @js($markdownPreview['combined'])))"></div>
                        </template>
                    </div>
                    <div class="mcp-md-modal__foot">
                        <div class="mcp-md-modal__stats">
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.characters', ['count' => number_format((int) ($markdownTokens['characters'] ?? 0))]) }}</span>
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.estimated_tokens', ['count' => number_format((int) ($markdownTokens['estimated_tokens'] ?? 0))]) }}</span>
                            @if ($markdownUpdatedAt)
                                <span>{{ __('seo-content-ai::filament.mcp_intelligence.updated_at', ['time' => \Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($markdownUpdatedAt)]) }}</span>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="mcp-btn"
                                @click="copyText(markdownTab === 'site' ? @js($markdown['site'] ?? '') : (markdownTab === 'keywords' ? @js($markdown['keywords'] ?? '') : (markdownTab === 'gsc' ? @js($markdown['gsc'] ?? '') : @js($markdown['combined'] ?? ''))))">
                                <span x-show="!copied">{{ __('seo-content-ai::filament.mcp_intelligence.copy_markdown') }}</span>
                                <span x-show="copied" x-cloak>{{ __('seo-content-ai::filament.mcp_intelligence.copied') }}</span>
                            </button>
                            <button type="button" class="mcp-btn" @click="markdownOpen = false">{{ __('seo-content-ai::filament.mcp_intelligence.close') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                wire:key="mcp-ai-context-{{ (int) ($site?->id ?? 0) }}-{{ $this->periodKey }}"
                x-show="aiContextOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3"
                @keydown.escape.window="aiContextOpen = false"
                @click.self="aiContextOpen = false"
            >
                <div class="mcp-md-modal" @click.stop>
                    <div class="mcp-md-modal__head">
                        <div>
                            <h2 class="mcp-md-modal__title">{{ __('seo-content-ai::filament.mcp_intelligence.ai_context') }}</h2>
                            <p class="mcp-md-modal__meta">{{ __('seo-content-ai::filament.mcp_intelligence.ai_context_hint') }}</p>
                        </div>
                        <button type="button" class="mcp-btn" @click="aiContextOpen = false">✕</button>
                    </div>
                    <div class="mcp-md-modal__tabs">
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': aiContextView === 'raw' }" @click="aiContextView = 'raw'">{{ __('seo-content-ai::filament.mcp_intelligence.raw_markdown') }}</button>
                        <button type="button" class="mcp-btn" :class="{ 'mcp-btn--primary': aiContextView === 'preview' }" @click="aiContextView = 'preview'">{{ __('seo-content-ai::filament.mcp_intelligence.preview') }}</button>
                    </div>
                    <div class="mcp-md-modal__body">
                        <template x-if="aiContextView === 'raw'">
                            <pre class="mcp-md-modal__raw" x-text="@js($markdown['ai_context'] ?? '')"></pre>
                        </template>
                        <template x-if="aiContextView === 'preview'">
                            <div class="mcp-md-modal__preview prose prose-sm max-w-none dark:prose-invert"
                                x-html="@js($markdownPreview['ai_context'])"></div>
                        </template>
                    </div>
                    <div class="mcp-md-modal__foot">
                        <div class="mcp-md-modal__stats">
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.characters', ['count' => number_format((int) ($markdownTokens['characters'] ?? 0))]) }}</span>
                            <span>{{ __('seo-content-ai::filament.mcp_intelligence.estimated_tokens', ['count' => number_format((int) ($markdownTokens['estimated_tokens'] ?? 0))]) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="mcp-btn" @click="copyText(@js($markdown['ai_context'] ?? ''))">
                                <span x-show="!copied">{{ __('seo-content-ai::filament.mcp_intelligence.copy_markdown') }}</span>
                                <span x-show="copied" x-cloak>{{ __('seo-content-ai::filament.mcp_intelligence.copied') }}</span>
                            </button>
                            <button type="button" class="mcp-btn" @click="aiContextOpen = false">{{ __('seo-content-ai::filament.mcp_intelligence.close') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            @if (in_array($this->previewSource, ['site', 'keywords', 'gsc'], true))
                @php
                    $preview = match ($this->previewSource) {
                        'site' => $siteCard,
                        'gsc' => $gscCard,
                        default => $kwCard,
                    };
                    $previewJson = match ($this->previewSource) {
                        'site' => $state['site_preview_json'],
                        'gsc' => $state['gsc_preview_json'] ?? '',
                        default => $state['keyword_preview_json'],
                    };
                    $previewSnap = match ($this->previewSource) {
                        'site' => $state['site_snap'],
                        'gsc' => $state['gsc_snap'] ?? null,
                        default => $state['keyword_snap'],
                    };
                    $previewContext = is_array($previewSnap?->context_json) ? $previewSnap->context_json : [];
                    $previewSummary = is_array($previewSnap?->summary_json) ? $previewSnap->summary_json : [];
                    $previewTitle = match ($this->previewSource) {
                        'site' => __('seo-content-ai::filament.mcp_intelligence.site_intel'),
                        'gsc' => 'GSC Intelligence',
                        default => __('seo-content-ai::filament.mcp_intelligence.keyword_intel'),
                    };
                @endphp
                <div class="fixed inset-0 z-40 flex justify-end bg-black/40" wire:click="closeDrawers">
                    <div class="h-full w-full max-w-xl overflow-y-auto bg-white p-4 dark:bg-gray-900" wire:click.stop>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-semibold">{{ $previewTitle }}</h2>
                            <button type="button" wire:click="closeDrawers">✕</button>
                        </div>
                        <h3 class="mcp-sub">{{ __('seo-content-ai::filament.mcp_intelligence.source_summary') }}</h3>
                        <p class="text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.freshness_'.($preview['freshness'] ?? 'missing')) }} @if($preview['relative'] ?? null)· {{ $preview['relative'] }}@endif</p>
                        <h3 class="mcp-sub mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.source_metrics') }}</h3>
                        <div class="text-sm">
                            @foreach (($preview['metrics'] ?? []) as $k => $v)
                                @if (! is_array($v))
                                    <p>{{ $k }}: {{ $v }}</p>
                                @endif
                            @endforeach
                        </div>
                        @if ($previewContext !== [])
                            <h3 class="mcp-sub mt-3">{{ __('seo-content-ai::filament.mcp_intelligence.source_context') }}</h3>
                            <p class="text-sm text-gray-600">{{ implode(' · ', array_keys($previewContext)) }}</p>
                        @endif
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm font-semibold">{{ __('seo-content-ai::filament.mcp_intelligence.technical') }}</summary>
                            <p class="mt-2 text-xs text-gray-500">schema: {{ $preview['schema'] ?? '' }}</p>
                            <p class="text-xs text-gray-500">hash: {{ $preview['hash'] ?? '' }}</p>
                            <p class="text-xs text-gray-500">ISO: {{ $preview['generated_at'] ?? '—' }}</p>
                            <details class="mt-2">
                                <summary class="cursor-pointer text-sm">{{ __('seo-content-ai::filament.mcp_intelligence.raw_json') }}</summary>
                                <pre class="mcp-json mt-2">{{ $previewJson }}</pre>
                            </details>
                        </details>
                    </div>
                </div>
            @endif
        </div>
    </x-filament-panels::page>
</div>
