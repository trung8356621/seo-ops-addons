<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Presentation;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncCutoverReadinessService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Throwable;

final class SiteSyncStatusPresenter
{
    public function __construct(
        private readonly SiteCapabilityResolver $capabilities,
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteSyncCutoverReadinessService $cutover,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSite(Site $site): array
    {
        try {
            return $this->buildForSite($site);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.status_presenter',
                'site_id' => (int) $site->id,
            ]);

            return $this->degradedPayload(
                'Site Sync V2 lỗi đọc trạng thái: '.$e->getMessage(),
                ['tables_or_query_failed'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForSite(Site $site): array
    {
        if (! SiteSyncInfrastructure::tablesReady()) {
            return $this->degradedPayload(
                'Chưa migrate bảng Site Sync V2 (omi_seo_ai). Chạy migration rồi thử lại.',
                ['migrations_missing'],
            );
        }

        $run = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->orderByDesc('id')
            ->first();

        $manifest = $this->capabilities->forSite($site);
        $sources = $this->sourceTransparency($site, $manifest?->capabilities ?? []);

        $articleCount = SeoArticle::query()->where('site_id', (int) $site->id)->count();
        $wpLinks = SeoSiteLinkCatalog::query()
            ->forSite((int) $site->id)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->count();
        $manualLinks = SiteSyncInfrastructure::hasTable('seo_site_manual_links')
            ? SeoSiteManualLink::query()->where('site_id', (int) $site->id)->count()
            : 0;
        $deadLetters = SiteSyncInfrastructure::hasTable('seo_site_sync_inbound_events')
            ? SeoSiteSyncInboundEvent::query()
                ->where('site_id', (int) $site->id)
                ->where('status', SeoSiteSyncInboundEvent::STATUS_DEAD_LETTER)
                ->count()
            : 0;

        if ($run === null) {
            $scoringProgress = $this->safeScoringProgress((int) $site->id);

            return [
                'running' => false,
                'resumable' => false,
                'cancellable' => false,
                'status' => 'idle',
                'message' => $this->idleMessage($articleCount, $wpLinks, $manualLinks, $sources),
                'scoring_context' => $this->scoringContextMessage('idle', '', $scoringProgress, []),
                'scoring_progress' => $scoringProgress,
                'progress' => 0,
                'total' => 9,
                'public_ref' => null,
                'run_id' => null,
                'counters' => [],
                'warnings' => $deadLetters > 0 ? ["{$deadLetters} sự kiện callback lỗi cần xử lý"] : [],
                'capability_sources' => $sources,
                'summary_cards' => [
                    'articles' => $articleCount,
                    'wordpress_links' => $wpLinks,
                    'manual_links' => $manualLinks,
                ],
                'cutover' => $this->safeCutover($site),
                'last_synced_at' => null,
            ];
        }

        $steps = $run->steps()->orderBy('step_order')->get();
        $total = max(1, $steps->count());
        $done = $steps->whereIn('status', ['completed', 'skipped'])->count();
        $counters = is_array($run->counters) ? $run->counters : [];
        $warnings = is_array($run->warnings) ? $run->warnings : [];
        if ($deadLetters > 0) {
            $warnings[] = "{$deadLetters} sự kiện callback lỗi cần xử lý";
        }

        $forceFull = (string) $run->mode === SiteSyncSchema::MODE_FORCE_FULL;
        $checked = (int) ($counters['checked'] ?? $counters['fetched'] ?? 0);
        $totalToCheck = (int) ($counters['total_to_check'] ?? 0);
        if ($forceFull && $totalToCheck > 0) {
            $progress = $checked;
            $progressTotal = $totalToCheck;
        } else {
            $progress = $done;
            $progressTotal = $total;
        }

        $errorMessage = trim((string) ($run->error_message ?? ''));
        $meta = is_array($run->meta) ? $run->meta : [];
        $scoringProgress = $this->safeScoringProgress((int) $site->id);
        $runStatus = (string) $run->status;
        $currentStep = (string) ($run->current_step ?? '');
        $lastProgressAt = (string) ($meta['last_progress_at'] ?? optional($run->updated_at)?->toIso8601String() ?? '');
        $stuck = $this->isRunStuck($runStatus, $lastProgressAt, $meta);
        if ($stuck) {
            $warnings[] = 'Run đồng bộ không còn heartbeat tiến trình — bấm «Tiếp tục» để reclaim/resume theo policy hiện có.';
        }
        $scoringContext = $this->scoringContextMessage(
            $runStatus,
            $currentStep,
            $scoringProgress,
            is_array($run->counters) ? $run->counters : [],
            $stuck,
        );

        $isTerminal = in_array($runStatus, ['completed', 'completed_with_warnings', 'canceled', 'cancelled'], true);
        $isActive = in_array($runStatus, ['pending', 'running'], true);

        return [
            'running' => $isActive && ! $stuck,
            'stuck' => $stuck,
            'resumable' => ($runStatus === 'failed' && (bool) $run->resumable) || $stuck,
            'cancellable' => ! $isTerminal && in_array($runStatus, ['pending', 'running', 'failed'], true),
            'status' => $stuck ? 'stuck' : $runStatus,
            'mode' => (string) $run->mode,
            'mode_label' => $forceFull ? 'Đồng bộ lại toàn bộ website' : null,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
            'message' => $stuck
                ? 'Đồng bộ bị kẹt (không còn tiến trình). Bấm «Tiếp tục đồng bộ & kiểm tra» để resume.'
                : $this->buildMessage(
                    $runStatus,
                    $counters,
                    $warnings,
                    $sources,
                    $forceFull,
                    $errorMessage,
                ),
            'scoring_context' => $scoringContext,
            'scoring_progress' => $scoringProgress,
            'progress' => $progress,
            'total' => $progressTotal,
            'phase' => $currentStep,
            'phase_label' => $this->phaseLabel($currentStep),
            'public_ref' => (string) $run->public_ref,
            'run_id' => (int) $run->id,
            'last_progress_at' => $lastProgressAt,
            'counters' => $counters,
            'warnings' => array_values(array_unique($warnings)),
            'capability_sources' => $sources,
            'summary_cards' => [
                'articles' => $articleCount,
                'wordpress_links' => $wpLinks,
                'manual_links' => $manualLinks,
            ],
            'cutover' => $this->safeCutover($site),
            'last_synced_at' => optional($run->finished_at ?? $run->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function degradedPayload(string $message, array $warnings): array
    {
        return [
            'running' => false,
            'resumable' => false,
            'cancellable' => false,
            'status' => 'degraded',
            'message' => $message,
            'progress' => 0,
            'total' => 8,
            'public_ref' => null,
            'run_id' => null,
            'counters' => [],
            'warnings' => $warnings,
            'capability_sources' => [
                'provider' => 'none',
                'seo_score' => ['sources' => ['unavailable'], 'warning' => ''],
                'keyword' => ['provider' => 'unavailable', 'workspace_fallback' => false],
                'http_404' => ['source' => 'unavailable'],
            ],
            'summary_cards' => [
                'articles' => 0,
                'wordpress_links' => 0,
                'manual_links' => 0,
            ],
            'cutover' => [
                'status' => 'not_ready',
                'score' => 0,
                'checks' => [],
                'recommendation' => 'not_ready',
            ],
            'last_synced_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeCutover(Site $site): array
    {
        try {
            return $this->cutover->evaluate($site);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'site_sync.cutover_readiness',
                'site_id' => (int) $site->id,
            ]);

            return [
                'status' => 'not_ready',
                'score' => 0,
                'checks' => [],
                'recommendation' => 'not_ready',
            ];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $capabilities
     * @return array<string, mixed>
     */
    private function sourceTransparency(Site $site, array $capabilities): array
    {
        $scoreSources = [];
        if (SiteSyncInfrastructure::hasTable('seo_article_score_sources')) {
            $scoreSources = SeoArticleScoreSource::query()
                ->where('site_id', (int) $site->id)
                ->select('source')
                ->distinct()
                ->pluck('source')
                ->all();
        }

        $seoScore = [];
        foreach ($scoreSources as $source) {
            $seoScore[] = (string) $source;
        }
        if ($seoScore === [] && isset($capabilities['seo_score']['provider'])) {
            $seoScore[] = (string) $capabilities['seo_score']['provider'];
        }

        $keywordProvider = (string) ($capabilities['focus_keyword']['provider'] ?? 'unavailable');
        $http404 = ! empty($capabilities['http_404']['available'])
            ? (string) ($capabilities['http_404']['provider'] ?? 'provider')
            : ($this->flags->workspaceFallbackEnabled() ? 'Workspace fallback' : 'unavailable');

        return [
            'seo_score' => [
                'sources' => $seoScore !== [] ? $seoScore : ['unavailable'],
                'warning' => 'Điểm SEO giữa các plugin sử dụng công thức khác nhau và không thể so sánh trực tiếp.',
            ],
            'keyword' => [
                'provider' => $keywordProvider,
                'workspace_fallback' => $this->flags->workspaceFallbackEnabled()
                    && empty($capabilities['focus_keyword']['available']),
                'manual_override' => 'Manual override ưu tiên cao nhất',
            ],
            'http_404' => [
                'source' => $http404,
            ],
            'provider' => (string) ($capabilities['seo_metadata']['provider'] ?? 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private function idleMessage(int $articles, int $wpLinks, int $manualLinks, array $sources): string
    {
        $provider = (string) ($sources['provider'] ?? 'none');

        return "Sẵn sàng đồng bộ · {$articles} bài · {$wpLinks} URL WP · {$manualLinks} manual · Provider: {$provider}";
    }

    /**
     * @param  array<string, mixed>  $counters
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $sources
     */
    /**
     * @return array{total: int, completed: int, pending: int, processing: int, failed: int, remaining: int}
     */
    private function safeScoringProgress(int $siteId): array
    {
        try {
            return app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
                ->domainProgress($siteId);
        } catch (Throwable) {
            return [
                'total' => 0,
                'completed' => 0,
                'pending' => 0,
                'processing' => 0,
                'failed' => 0,
                'remaining' => 0,
            ];
        }
    }

    /**
     * @param  array{total?: int, completed?: int, pending?: int, processing?: int, failed?: int, remaining?: int}  $scoring
     * @param  array<string, mixed>  $counters
     */
    private function scoringContextMessage(
        string $runStatus,
        string $currentStep,
        array $scoring,
        array $counters,
        bool $stuck = false,
    ): string {
        $pending = (int) ($scoring['pending'] ?? 0);
        $processing = (int) ($scoring['processing'] ?? 0);
        $completed = (int) ($scoring['completed'] ?? $counters['workspace_scores_generated'] ?? 0);
        $failed = (int) ($scoring['failed'] ?? $counters['scoring_failed'] ?? 0);
        $total = (int) ($scoring['total'] ?? 0);
        $remaining = $pending + (int) ($scoring['remaining'] ?? 0);

        if ($stuck) {
            return sprintf(
                'SEO scoring: %s / %s · pending %s · processing %s · failed %s (run kẹt — resume để tiếp tục lifecycle)',
                number_format($completed),
                number_format($total),
                number_format($pending),
                number_format($processing),
                number_format($failed),
            );
        }

        if (in_array($runStatus, ['pending', 'running'], true) && $currentStep !== 'score_missing_articles' && $currentStep !== 'finalize') {
            return 'Chờ hoàn tất đồng bộ dữ liệu';
        }

        if ($currentStep === 'score_missing_articles' || ($pending + $processing) > 0) {
            if ($processing > 0) {
                return sprintf(
                    'Đang chấm SEO: %s bài · Còn lại: %s bài · Thất bại: %s',
                    number_format($processing),
                    number_format(max(0, $pending + $remaining)),
                    number_format($failed),
                );
            }
            if ($pending > 0) {
                if ((int) ($counters['scoring_waiting_worker'] ?? 0) === 1) {
                    return 'Chấm SEO đang chờ worker xử lý';
                }

                return sprintf(
                    'Đang chuẩn bị chấm SEO: %s bài · Hoàn tất: %s / %s · Thất bại: %s',
                    number_format($pending),
                    number_format($completed),
                    number_format($total),
                    number_format($failed),
                );
            }

            // Step còn mở nhưng queue đã drain — chờ finalize/terminal của orchestrator.
            if ($currentStep === 'score_missing_articles') {
                return sprintf(
                    'SEO scoring: %s / %s · đang hoàn tất bước score_missing_articles',
                    number_format($completed),
                    number_format($total),
                );
            }
        }

        if (in_array($runStatus, ['completed', 'completed_with_warnings'], true) || $currentStep === 'finalize') {
            $msg = sprintf('Đã chấm SEO: %s / %s bài', number_format($completed), number_format($total));
            if ($failed > 0) {
                $msg .= sprintf(' · Thất bại: %s', number_format($failed));
            }

            return $msg;
        }

        return '';
    }

    /**
     * Align with SiteSyncStepRunner stale reclaim window (10 minutes).
     *
     * @param  array<string, mixed>  $meta
     */
    private function isRunStuck(string $runStatus, string $lastProgressAt, array $meta): bool
    {
        if (! in_array($runStatus, ['pending', 'running'], true)) {
            return false;
        }

        // Deferred scoring polls update last_progress_at — not stuck while actively waiting.
        if (! empty($meta['scoring_deferred'])) {
            return false;
        }

        if ($lastProgressAt === '') {
            return false;
        }

        try {
            $last = \Illuminate\Support\Carbon::parse($lastProgressAt);
        } catch (Throwable) {
            return false;
        }

        return $last->lessThanOrEqualTo(now()->subMinutes(10));
    }

    private function phaseLabel(string $stepKey): string
    {
        return match ($stepKey) {
            'detect_capability' => 'Phát hiện capability',
            'request_snapshot_delta' => 'Snapshot / delta',
            'sync_site_profile' => 'Đồng bộ site profile',
            'sync_url_catalog' => 'Đồng bộ URL catalog',
            'sync_provider_keywords' => 'Đồng bộ provider keywords',
            'missing_capability_fallback' => 'Fallback thiếu capability',
            'validate_changed_links' => 'Kiểm tra link thay đổi',
            'score_missing_articles' => 'Chấm SEO thiếu / stale',
            'finalize' => 'Hoàn tất',
            default => $stepKey !== '' ? $stepKey : '—',
        };
    }

    private function buildMessage(
        string $status,
        array $counters,
        array $warnings,
        array $sources,
        bool $forceFull = false,
        string $errorMessage = '',
    ): string {
        if ($status === 'failed') {
            $prefix = $forceFull
                ? 'Đồng bộ lại toàn bộ thất bại'
                : 'Đồng bộ thất bại';
            $detail = $errorMessage !== '' ? $errorMessage : 'Lỗi không rõ — xem log site_sync.step_failed.';

            return $prefix.': '.$detail.' Bấm «Tiếp tục» để thử lại bước lỗi.';
        }

        if ($forceFull && ($status === 'running' || $status === 'pending')) {
            $total = (int) ($counters['total_to_check'] ?? 0);
            $checked = (int) ($counters['checked'] ?? $counters['fetched'] ?? 0);
            $updated = (int) ($counters['updated'] ?? 0);
            $unchanged = (int) ($counters['unchanged'] ?? 0);
            $failed = (int) ($counters['failed'] ?? 0);

            if ($total === 0 && $checked === 0) {
                return 'Đang kiểm tra dữ liệu WordPress…';
            }

            return sprintf(
                'Chế độ: Đồng bộ lại toàn bộ website · Tổng cần kiểm tra: %s · Đã kiểm tra: %s · Có thay đổi: %s · Không thay đổi: %s · Thất bại: %s',
                number_format($total),
                number_format($checked),
                number_format($updated),
                number_format($unchanged),
                number_format($failed),
            );
        }

        if ($status === 'running' || $status === 'pending') {
            return 'Đang đồng bộ & kiểm tra website…';
        }
        if ($status === 'canceled' || $status === 'cancelled') {
            return 'Đã hủy đồng bộ. Dữ liệu đã cập nhật vẫn được giữ.';
        }

        $parts = [];
        if ($forceFull) {
            $parts[] = 'Chế độ: Đồng bộ lại toàn bộ website';
            if (isset($counters['fetched']) || isset($counters['checked'])) {
                $parts[] = 'Đã tải lại: '.number_format((int) ($counters['fetched'] ?? $counters['checked'] ?? 0));
            }
            if (isset($counters['created'])) {
                $parts[] = 'Tạo mới: '.number_format((int) $counters['created']);
            }
            if (isset($counters['updated'])) {
                $parts[] = 'Cập nhật: '.number_format((int) $counters['updated']);
            }
            if (isset($counters['unchanged'])) {
                $parts[] = 'Không đổi: '.number_format((int) $counters['unchanged']);
            }
            if (isset($counters['failed'])) {
                $parts[] = 'Thất bại: '.number_format((int) $counters['failed']);
            }
        }
        if (isset($counters['articles']) && ! $forceFull) {
            $parts[] = ((int) $counters['articles']).' bài đã đối soát';
        }
        if (isset($counters['urls_changed'])) {
            $parts[] = ((int) $counters['urls_changed']).' bài thay đổi';
        }
        if (isset($counters['urls_synced'])) {
            $parts[] = ((int) $counters['urls_synced']).' URL đồng bộ';
        }
        if (isset($counters['provider_keywords'])) {
            $parts[] = ((int) $counters['provider_keywords']).' provider keyword cập nhật';
        }
        if (isset($counters['workspace_keywords'])) {
            $parts[] = ((int) $counters['workspace_keywords']).' workspace keyword tạo mới';
        }
        $http404 = (string) ($sources['http_404']['source'] ?? '');
        if ($http404 !== '' && ! $forceFull) {
            $parts[] = '404 monitor: '.$http404;
        }
        $scoreSources = is_array($sources['seo_score']['sources'] ?? null) ? $sources['seo_score']['sources'] : [];
        if ($scoreSources !== [] && ! $forceFull) {
            $parts[] = 'SEO score: '.implode(', ', $scoreSources);
        }

        $msg = $parts !== [] ? implode(' · ', $parts) : (
            $status === 'completed' || $status === 'completed_with_warnings'
                ? ($status === 'completed_with_warnings' ? 'Đồng bộ hoàn tất (có cảnh báo).' : 'Đồng bộ hoàn tất.')
                : $status
        );
        if ($warnings !== []) {
            $msg .= ' · '.count($warnings).' cảnh báo';
        }

        return $msg;
    }
}
