<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages;

use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\SiteSync\Jobs\RunIncrementalDomainSyncJob;
use Omnichannel\Addons\SearchFoundation\Jobs\RunKeywordDomainResyncJob;
use Omnichannel\Addons\SiteSync\Jobs\RunMetadataDomainSyncJob;
use Omnichannel\Addons\Content\Services\ClearDomainArticlesService;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\SearchIntelligence\Services\IncrementalDomainSyncRunner;
use Omnichannel\Addons\Seo\Services\LinkMapStatusAuditService;
use Omnichannel\Addons\SearchIntelligence\Services\MetadataDomainSyncRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ForceFullSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\QueueMissingSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueAllSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RetryFailedSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Bootstrap\SiteSyncBootstrapService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncSourceLabelPresenter;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use Omnichannel\Addons\WordPress\Services\WordPressPluginUpdateService;
use Omnichannel\Addons\SearchFoundation\Support\IncrementalDomainSyncCache;
use Omnichannel\Addons\SearchFoundation\Support\KeywordDomainResyncCache;
use Omnichannel\Addons\SearchFoundation\Support\MetadataDomainSyncCache;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GeneralDomain extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.general-domain';

    public string $internalLinkTab = 'keywords';

    public bool $tokensUnlocked = false;

    public bool $readTokenVisible = false;

    public bool $migrationTokenVisible = false;

    public bool $showPasswordPrompt = false;

    public ?string $pendingRevealField = null;

    public string $tokenPassword = '';

    public int $incrementalSyncProgress = 0;

    public int $incrementalSyncTotal = 0;

    public bool $incrementalSyncRunning = false;

    public bool $incrementalSyncResumable = false;

    public int $metadataSyncProgress = 0;

    public int $metadataSyncTotal = 0;

    public bool $metadataSyncRunning = false;

    public bool $metadataSyncResumable = false;

    public bool $keywordResyncRunning = false;

    public string $incrementalSyncStatus = 'idle';

    public ?string $incrementalSyncStatusMessage = null;

    public string $metadataSyncStatus = 'idle';

    public ?string $metadataSyncStatusMessage = null;

    public string $keywordResyncStatus = 'idle';

    public ?string $keywordResyncStatusMessage = null;

    public string $auditLinkStatus = 'idle';

    public ?string $auditLinkStatusMessage = null;

    public bool $siteSyncV2Running = false;

    public bool $siteSyncV2Resumable = false;

    public bool $siteSyncV2Cancellable = false;

    public int $siteSyncV2Progress = 0;

    public int $siteSyncV2Total = 8;

    public string $siteSyncV2Status = 'idle';

    public ?string $siteSyncV2StatusMessage = null;

    /** @var list<string> */
    public array $siteSyncV2Warnings = [];

    public ?int $siteSyncV2RunId = null;

    /** @var array<string, mixed> */
    public array $siteSyncV2Sources = [];

    /** @var list<array{key: string, label: string}> */
    public array $siteSyncV2SourceChips = [];

    public bool $siteSyncForceFull = false;

    public ?string $siteSyncV2ModeLabel = null;

    /** @var array<string, int|string> */
    public array $siteSyncV2Counters = [];

    public ?string $siteSyncScoringContext = null;

    public ?string $siteSyncV2Phase = null;

    public ?string $siteSyncV2PhaseLabel = null;

    public ?string $siteSyncV2LastProgressAt = null;

    public bool $siteSyncV2Stuck = false;

    public bool $siteSyncV2Stopping = false;

    /** @var list<array{key: string, label: string, status: string, order: int}> */
    public array $siteSyncV2Steps = [];

    /** @var list<array<string, mixed>> */
    public array $siteSyncV2Substeps = [];

    public ?int $siteSyncV2Percentage = null;

    public ?string $siteSyncV2ElapsedLabel = null;

    public ?string $siteSyncV2LastActivityLabel = null;

    public ?string $siteSyncV2RetryLabel = null;

    /** @var array<string, mixed>|null */
    public ?array $siteSyncBootstrapPreview = null;

    public bool $siteSyncNeedsBootstrap = false;

    /** @var array<string, mixed>|null */
    public ?array $siteSyncPreflight = null;

    public bool $siteSyncPreflightOpen = false;

    public bool $siteSyncPreflightLoading = false;

    public string $wpPluginPhase = 'idle';

    public ?string $wpPluginFlash = null;

    public bool $wpPluginConfirmOpen = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $this->tokensUnlocked = $this->canRevealTokensWithoutPassword();

        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, ['keywords', 'links'], true)) {
            $this->internalLinkTab = $tab;
        }

        $this->restoreIncrementalSyncProgressFromCache();
        $this->restoreMetadataSyncProgressFromCache();
        $this->refreshKeywordResyncProgress();
        $this->refreshSiteSyncV2Progress();
    }

    public function refreshKeywordResyncProgress(): void
    {
        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();

        KeywordDomainResyncCache::clearIfStale($userId, $siteId);

        $progress = KeywordDomainResyncCache::progressFromState(
            KeywordDomainResyncCache::read($userId, $siteId),
        );

        $wasRunning = $this->keywordResyncRunning;
        $this->keywordResyncRunning = (bool) $progress['running'];
        $this->applyKeywordResyncStatus($progress);

        if ($wasRunning && ! $this->keywordResyncRunning) {
            if (($progress['status'] ?? '') === KeywordDomainResyncCache::STATUS_COMPLETED) {
                $this->dispatch('domain-sync-completed');
            } elseif (($progress['status'] ?? '') === KeywordDomainResyncCache::STATUS_FAILED) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                    ->body((string) ($progress['message'] ?? ''))
                    ->danger()
                    ->send();
            }
        }
    }

    public function refreshIncrementalSyncProgress(): void
    {
        $wasRunning = $this->incrementalSyncRunning;

        $this->applyIncrementalSyncProgress(
            app(IncrementalDomainSyncRunner::class)->readProgress(
                (int) auth()->id(),
                (int) $this->getRecord()->getKey(),
            ),
        );

        if ($wasRunning && ! $this->incrementalSyncRunning && $this->incrementalSyncTotal > 0) {
            $this->dispatch('domain-sync-completed');
        }
    }

    public function refreshSyncProgress(): void
    {
        $this->refreshIncrementalSyncProgress();
        $this->refreshMetadataSyncProgress();
        $this->refreshKeywordResyncProgress();
        $this->refreshSiteSyncV2Progress();
    }

    public function refreshSiteSyncV2Progress(): void
    {
        try {
            /** @var Site $site */
            $site = $this->getRecord();
            $status = app(SiteSyncStatusPresenter::class)->forSite($site);
            $wasRunning = $this->siteSyncV2Running;
            $this->siteSyncV2Running = (bool) $status['running'];
            $this->siteSyncV2Resumable = (bool) $status['resumable'];
            $this->siteSyncV2Cancellable = (bool) ($status['cancellable'] ?? false);
            $this->siteSyncV2Progress = (int) $status['progress'];
            $this->siteSyncV2Total = (int) $status['total'];
            $this->siteSyncV2Status = (string) $status['status'];
            $this->siteSyncV2StatusMessage = (string) $status['message'];
            $this->siteSyncV2RunId = isset($status['run_id']) ? (int) $status['run_id'] : null;
            $this->siteSyncV2Sources = is_array($status['capability_sources'] ?? null) ? $status['capability_sources'] : [];
            $this->siteSyncV2SourceChips = app(SiteSyncSourceLabelPresenter::class)->chips($this->siteSyncV2Sources);
            $this->siteSyncNeedsBootstrap = app(SiteSyncBootstrapService::class)->needsBootstrap($site);
            $this->siteSyncV2ModeLabel = isset($status['mode_label']) ? (string) $status['mode_label'] : null;
            $this->siteSyncV2Counters = is_array($status['counters'] ?? null)
                ? array_map(static fn (mixed $v): int|string => is_numeric($v) ? (int) $v : (string) $v, $status['counters'])
                : [];
            $this->siteSyncScoringContext = isset($status['scoring_context'])
                ? (string) $status['scoring_context']
                : null;
            $this->siteSyncV2Warnings = is_array($status['warnings'] ?? null)
                ? array_values(array_map('strval', $status['warnings']))
                : [];
            $this->siteSyncV2Phase = isset($status['phase']) ? (string) $status['phase'] : null;
            $this->siteSyncV2PhaseLabel = isset($status['phase_label']) ? (string) $status['phase_label'] : null;
            $this->siteSyncV2LastProgressAt = isset($status['last_progress_at'])
                ? (string) $status['last_progress_at']
                : null;
            $this->siteSyncV2Stuck = (bool) ($status['stuck'] ?? false);
            $this->siteSyncV2Stopping = (bool) ($status['stopping'] ?? false);
            $this->siteSyncV2Steps = is_array($status['steps'] ?? null) ? $status['steps'] : [];
            $this->siteSyncV2Substeps = is_array($status['substeps'] ?? null) ? $status['substeps'] : [];
            $this->siteSyncV2Percentage = isset($status['percentage']) && $status['percentage'] !== null
                ? (int) $status['percentage']
                : null;
            $this->siteSyncV2ElapsedLabel = isset($status['elapsed_label']) ? (string) $status['elapsed_label'] : null;
            $this->siteSyncV2LastActivityLabel = isset($status['last_activity_label']) ? (string) $status['last_activity_label'] : null;
            $this->siteSyncV2RetryLabel = isset($status['retry_label']) ? (string) $status['retry_label'] : null;

            if ($wasRunning && ! $this->siteSyncV2Running && $this->siteSyncV2Status === 'completed') {
                $this->dispatch('domain-sync-completed');
            }
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e, [
                'endpoint' => 'domain.refresh_site_sync_v2',
                'site_id' => (int) $this->getRecord()->getKey(),
            ]);
            $this->siteSyncV2Running = false;
            $this->siteSyncV2Resumable = false;
            $this->siteSyncV2Cancellable = false;
            $this->siteSyncV2Status = 'degraded';
            $this->siteSyncV2StatusMessage = 'Site Sync V2 lỗi: '.$e->getMessage();
            $this->siteSyncV2Warnings = ['status_refresh_failed'];
        }
    }

    public function openSiteSyncPreflight(): void
    {
        @set_time_limit(60);
        $this->siteSyncPreflightLoading = true;
        $this->siteSyncPreflight = null;

        try {
            $this->siteSyncPreflight = app(SiteSyncPreflightService::class)
                ->evaluate($this->getRecord());
            $this->siteSyncPreflightOpen = true;
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e, [
                'endpoint' => 'domain.site_sync_preflight',
                'site_id' => (int) $this->getRecord()->getKey(),
            ]);
            $this->notifySiteSyncResult('Site Health / Sync preflight lỗi', $e->getMessage(), false);
        } finally {
            $this->siteSyncPreflightLoading = false;
        }
    }

    public function closeSiteSyncPreflight(): void
    {
        $this->siteSyncPreflightOpen = false;
    }

    public function confirmSiteSyncPreflightNormal(): void
    {
        $this->siteSyncForceFull = false;
        $this->siteSyncPreflightOpen = false;
        $this->runSiteSyncV2Action();
    }

    public function confirmSiteSyncPreflightFull(): void
    {
        $this->siteSyncForceFull = true;
        $this->siteSyncPreflightOpen = false;
        $this->runForceFullSiteSyncAction();
    }

    public function runSiteSyncV2Action(): void
    {
        @set_time_limit(120);
        $siteId = (int) $this->getRecord()->getKey();

        try {
            if ($this->siteSyncForceFull) {
                $this->runForceFullSiteSyncAction();

                return;
            }

            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_click', [
                'site_id' => $siteId,
                'action' => 'runSiteSyncV2Action',
            ]);

            /** @var Site $site */
            $site = $this->getRecord();
            $flags = app(SiteSyncFeatureFlags::class);
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
                'site_id' => $siteId,
                'step' => 'flags_resolved',
                'orchestrator' => $flags->orchestratorEnabled(),
                'ui' => $flags->uiEnabled(),
            ]);

            if (! $flags->orchestratorEnabled() || ! $flags->uiEnabled()) {
                \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_blocked', [
                    'site_id' => $siteId,
                    'reason' => 'flags_disabled',
                ]);
                $notification = Notification::make()
                    ->title('Site Sync V2 đang tắt');
                $notification->warning();
                $notification->send();

                return;
            }

            $tablesReady = \Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure::tablesReady();
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
                'site_id' => $siteId,
                'step' => 'tables_checked',
                'ready' => $tablesReady,
            ]);
            if (! $tablesReady) {
                \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_blocked', [
                    'site_id' => $siteId,
                    'reason' => 'migrations_missing',
                ]);
                $this->notifySiteSyncResult(
                    'Chưa migrate Site Sync V2',
                    'Chạy migration trên connection omi_seo_ai rồi thử lại.',
                    false,
                );

                return;
            }

            // Priority after force_full: bootstrap if never synced, else incremental.
            $needsBootstrap = app(SiteSyncBootstrapService::class)->needsBootstrap($site);
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
                'site_id' => $siteId,
                'step' => 'bootstrap_checked',
                'needs_bootstrap' => $needsBootstrap,
            ]);

            if ($needsBootstrap) {
                \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
                    'site_id' => $siteId,
                    'step' => 'before_bootstrap_preview',
                ]);
                // Preview trực tiếp — tránh resolve CommandBus nặng trên first click.
                $preview = app(SiteSyncBootstrapService::class)->preview($site);
                $this->siteSyncBootstrapPreview = $preview;
                $this->siteSyncNeedsBootstrap = true;
                \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_outcome', [
                    'site_id' => $siteId,
                    'outcome' => 'bootstrap_preview',
                    'success' => true,
                    'articles_remote' => $preview['articles_remote'] ?? null,
                    'warnings' => $preview['warnings'] ?? [],
                    'bridge' => $preview['bridge_version'] ?? null,
                ]);
                $this->notifySiteSyncResult(
                    'Xác nhận đồng bộ lần đầu',
                    sprintf(
                        '~%s bài remote · %s batch · Provider: %s. Bấm “Xác nhận bootstrap” bên dưới.',
                        (string) ($preview['articles_remote'] ?? 0),
                        (string) ($preview['estimated_batches'] ?? '?'),
                        (string) ($preview['provider_label'] ?? 'Không phát hiện'),
                    ),
                    true,
                );
                $this->dispatch('open-site-sync-bootstrap-preview');

                return;
            }

            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
                'site_id' => $siteId,
                'step' => 'before_run_delta_bus',
            ]);
            $result = $this->dispatchSiteSyncBus(new RunSiteSyncCommand((int) $site->id, 'delta'));
            $this->refreshSiteSyncV2Progress();
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_outcome', [
                'site_id' => $siteId,
                'outcome' => 'run_delta',
                'success' => $result->success,
                'message' => $result->message,
                'run_id' => $result->metadata['run_id'] ?? null,
            ]);
            $this->notifySiteSyncResult(
                $result->success ? 'Đồng bộ & kiểm tra website' : 'Không chạy được sync',
                $result->message,
                $result->success,
            );
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e, [
                'endpoint' => 'domain.run_site_sync_v2',
                'site_id' => $siteId,
            ]);
            $this->notifySiteSyncResult('Site Sync V2 lỗi', $e->getMessage(), false);
            $this->refreshSiteSyncV2Progress();
        }
    }

    public function runForceFullSiteSyncAction(): void
    {
        @set_time_limit(120);
        $siteId = (int) $this->getRecord()->getKey();

        try {
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_click', [
                'site_id' => $siteId,
                'action' => 'runForceFullSiteSyncAction',
            ]);

            $flags = app(SiteSyncFeatureFlags::class);
            if (! $flags->orchestratorEnabled() || ! $flags->uiEnabled()) {
                $notification = Notification::make()->title('Site Sync V2 đang tắt');
                $notification->warning();
                $notification->send();

                return;
            }

            if (! \Omnichannel\Addons\SiteSync\Services\Support\SiteSyncInfrastructure::tablesReady()) {
                $this->notifySiteSyncResult(
                    'Chưa migrate Site Sync V2',
                    'Chạy migration trên connection omi_seo_ai rồi thử lại.',
                    false,
                );

                return;
            }

            /** @var Site $site */
            $site = $this->getRecord();
            $operationId = 'ff_'.bin2hex(random_bytes(8));
            $result = $this->dispatchSiteSyncBus(new ForceFullSiteSyncCommand(
                siteId: (int) $site->id,
                supersedeActive: true,
                idempotencyKey: $operationId,
                operationId: $operationId,
            ));
            $this->siteSyncForceFull = false;
            $this->refreshSiteSyncV2Progress();
            \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_outcome', [
                'site_id' => $siteId,
                'outcome' => 'force_full',
                'success' => $result->success,
                'message' => $result->message,
                'run_id' => $result->metadata['run_id'] ?? null,
            ]);
            $this->notifySiteSyncResult(
                $result->success ? 'Đồng bộ lại toàn bộ website' : 'Không chạy được force full',
                $result->message,
                $result->success,
            );
        } catch (\Throwable $e) {
            \App\Support\RuntimeLogger::report($e, [
                'endpoint' => 'domain.run_force_full_site_sync',
                'site_id' => $siteId,
            ]);
            $this->notifySiteSyncResult('Force full sync lỗi', $e->getMessage(), false);
            $this->refreshSiteSyncV2Progress();
        }
    }

    public function confirmSiteSyncBootstrapAction(): void
    {
        /** @var Site $site */
        $site = $this->getRecord();
        \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_step', [
            'site_id' => (int) $site->id,
            'step' => 'before_bootstrap_confirm',
        ]);
        $result = $this->dispatchSiteSyncBus(new BootstrapSiteSyncCommand((int) $site->id));
        $this->siteSyncBootstrapPreview = null;
        $this->refreshSiteSyncV2Progress();
        \App\Support\RuntimeLogger::warning('seo.site_sync.v2_ui_outcome', [
            'site_id' => (int) $site->id,
            'outcome' => 'bootstrap_confirm',
            'success' => $result->success,
            'message' => $result->message,
            'run_id' => $result->metadata['run_id'] ?? null,
        ]);
        $this->notifySiteSyncResult(
            $result->success ? 'Bootstrap đã xếp hàng' : 'Bootstrap thất bại',
            $result->message,
            $result->success,
        );
    }

    public function cancelSiteSyncBootstrapPreview(): void
    {
        $this->siteSyncBootstrapPreview = null;
    }

    public function cancelSiteSyncV2Action(): void
    {
        $runId = (int) ($this->siteSyncV2RunId ?? 0);
        if ($runId <= 0) {
            $status = app(SiteSyncStatusPresenter::class)->forSite($this->getRecord());
            $runId = (int) ($status['run_id'] ?? 0);
        }
        if ($runId <= 0) {
            return;
        }
        $result = $this->dispatchSiteSyncBus(new CancelSiteSyncCommand((int) $this->getRecord()->id, $runId));
        $this->refreshSiteSyncV2Progress();
        $this->notifySiteSyncResult(
            $result->success ? 'Đã hủy' : 'Không hủy được',
            $result->message,
            $result->success,
        );
    }

    public function resumeSiteSyncV2Action(): void
    {
        $status = app(SiteSyncStatusPresenter::class)->forSite($this->getRecord());
        $runId = (int) ($status['run_id'] ?? 0);
        if ($runId <= 0) {
            return;
        }
        $result = $this->dispatchSiteSyncBus(new ResumeSiteSyncCommand((int) $this->getRecord()->id, $runId));
        $this->refreshSiteSyncV2Progress();
        $this->notifySiteSyncResult(
            $result->success ? 'Tiếp tục sync' : 'Không resume được',
            $result->message,
            $result->success,
        );
    }

    public function siteSyncV2UiEnabled(): bool
    {
        return app(SiteSyncFeatureFlags::class)->uiEnabled();
    }

    public function siteSyncV2LegacyVisible(): bool
    {
        if (app(SiteSyncFeatureFlags::class)->legacyActionsVisible()) {
            return true;
        }
        $mode = app(\Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService::class)
            ->modeFor($this->getRecord());

        return $mode === \Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverModes::LEGACY_ACTIVE
            && app(SiteSyncFeatureFlags::class)->emergencyRollback();
    }

    private function dispatchSiteSyncBus(object $command): \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult
    {
        return app(ContentProjectCommandBus::class)->dispatch(
            $command,
            ActorContext::user((int) auth()->id(), (int) $this->getRecord()->id),
        );
    }

    private function notifySiteSyncResult(string $title, string $body, bool $success): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body);
        if ($success) {
            $notification->success();
        } else {
            $notification->danger();
        }
        $notification->send();
    }

    private function restoreMetadataSyncProgressFromCache(): void
    {
        $this->refreshMetadataSyncProgress();
    }

    public function refreshMetadataSyncProgress(): void
    {
        $wasRunning = $this->metadataSyncRunning;

        $this->applyMetadataSyncProgress(
            app(MetadataDomainSyncRunner::class)->readProgress(
                (int) auth()->id(),
                (int) $this->getRecord()->getKey(),
            ),
        );

        if ($wasRunning && ! $this->metadataSyncRunning && $this->metadataSyncTotal > 0) {
            $this->dispatch('domain-sync-completed');
        }
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     */
    private function applyMetadataSyncProgress(array $progress): void
    {
        $this->metadataSyncProgress = (int) $progress['done'];
        $this->metadataSyncTotal = (int) $progress['total'];
        $this->metadataSyncRunning = (bool) $progress['running'];

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $state = Cache::get(MetadataDomainSyncCache::cacheKey($userId, $siteId));
        $this->metadataSyncResumable = MetadataDomainSyncCache::isResumable(is_array($state) ? $state : null);
        $this->applyMetadataSyncStatus($progress, is_array($state) ? $state : null);

        $this->dispatch(
            'metadata-sync-progress',
            done: $this->metadataSyncProgress,
            total: $this->metadataSyncTotal,
            running: $this->metadataSyncRunning,
        );
    }

    private function restoreIncrementalSyncProgressFromCache(): void
    {
        $this->refreshIncrementalSyncProgress();
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     */
    private function applyIncrementalSyncProgress(array $progress): void
    {
        $this->incrementalSyncProgress = (int) $progress['done'];
        $this->incrementalSyncTotal = (int) $progress['total'];
        $this->incrementalSyncRunning = (bool) $progress['running'];

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey($userId, $siteId));
        $this->incrementalSyncResumable = IncrementalDomainSyncCache::isResumable(is_array($state) ? $state : null);
        $this->applyIncrementalSyncStatus($progress, is_array($state) ? $state : null);

        $this->dispatch(
            'incremental-sync-progress',
            done: $this->incrementalSyncProgress,
            total: $this->incrementalSyncTotal,
            running: $this->incrementalSyncRunning,
        );
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return __('Overview').': '.$site->domain;
    }

    public function getSite(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }

    public function isSiteSynced(): bool
    {
        return app(DomainOverviewService::class)->isSiteSynced((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getMetadataSyncCacheState(): ?array
    {
        $state = Cache::get(MetadataDomainSyncCache::cacheKey(
            (int) auth()->id(),
            (int) $this->getRecord()->getKey(),
        ));

        return is_array($state) ? $state : null;
    }

    private function anyDomainSyncJobRunning(int $userId, int $siteId): bool
    {
        return app(IncrementalDomainSyncRunner::class)->isRunning($userId, $siteId)
            || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)
            || KeywordDomainResyncCache::isRunning($userId, $siteId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getIncrementalSyncCacheState(): ?array
    {
        $state = Cache::get(IncrementalDomainSyncCache::cacheKey(
            (int) auth()->id(),
            (int) $this->getRecord()->getKey(),
        ));

        return is_array($state) ? $state : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiTokenSummary(): array
    {
        return app(DomainOverviewService::class)->getApiTokenSummary($this->getSite());
    }

    /**
     * @return array{read_token: string, migration_token: string}
     */
    public function getPlainTokens(): array
    {
        return app(DomainOverviewService::class)->getApiTokensPlain($this->getSite());
    }

    public function toggleTokenVisibility(string $field): void
    {
        if (! $this->tokensUnlocked) {
            $this->pendingRevealField = in_array($field, ['read', 'migration'], true) ? $field : 'read';
            $this->showPasswordPrompt = true;
            $this->tokenPassword = '';

            return;
        }

        if ($field === 'migration') {
            $this->migrationTokenVisible = ! $this->migrationTokenVisible;

            return;
        }

        $this->readTokenVisible = ! $this->readTokenVisible;
    }

    public function cancelPasswordPrompt(): void
    {
        $this->showPasswordPrompt = false;
        $this->tokenPassword = '';
        $this->pendingRevealField = null;
    }

    public function confirmRevealTokens(): void
    {
        $user = auth()->user();
        if ($user === null) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'You need to sign in to view tokens.',
            ]);
        }

        if (! Hash::check($this->tokenPassword, $user->password)) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'Incorrect password.',
            ]);
        }

        session(['seo_domain_tokens_verified' => true]);
        $this->tokensUnlocked = true;
        $this->showPasswordPrompt = false;
        $this->applyPendingTokenVisibility();
        $this->tokenPassword = '';
    }

    public function getInternalLinkTabUrl(string $tab): string
    {
        return static::getUrl(['record' => $this->getRecord()]).'?tab='.urlencode($tab);
    }

    public function getArticlesFilterUrl(string $band): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrl(
            (int) $this->getRecord()->getKey(),
            $band,
        );
    }

    public function getArticlesFilterUrlForLink(string $url, string $type): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForLink(
            (int) $this->getRecord()->getKey(),
            $url,
            $type,
        );
    }

    public function getArticlesFilterUrlForKeyword(int $keywordId): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForKeyword(
            (int) $this->getRecord()->getKey(),
            $keywordId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getWpPluginBridgeStatus(): array
    {
        return app(WordPressPluginUpdateService::class)->status($this->getSite());
    }

    public function checkWpPluginVersion(): void
    {
        $this->wpPluginPhase = 'checking';
        $this->wpPluginFlash = null;
        $result = app(WordPressPluginUpdateService::class)->check($this->getSite());
        $this->wpPluginPhase = 'idle';
        $this->wpPluginFlash = (string) ($result['message'] ?? '');
        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title($this->wpPluginFlash !== '' ? $this->wpPluginFlash : 'Không thể kiểm tra phiên bản mới.')
                ->danger()
                ->send();
        }
        $this->getSite()->unsetRelation('metas');
        $this->getSite()->load('metas');
    }

    public function installWpPlugin(): void
    {
        $this->wpPluginConfirmOpen = false;
        $this->wpPluginPhase = 'updating';
        $this->wpPluginFlash = 'Đang cập nhật plugin...';
        $result = app(WordPressPluginUpdateService::class)->update($this->getSite());
        $this->wpPluginPhase = 'idle';
        $this->wpPluginFlash = (string) ($result['message'] ?? '');
        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title($this->wpPluginFlash !== '' ? $this->wpPluginFlash : 'Cập nhật plugin thất bại.')
                ->danger()
                ->send();
        }
        $this->getSite()->unsetRelation('metas');
        $this->getSite()->load('metas');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteHealthCard(): array
    {
        return app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\SiteHealthCardPresenter::class)
            ->forSite($this->getSite());
    }

    public function reconcileSiteWordPressState(): void
    {
        $result = app(\Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService::class)
            ->poll($this->getSite());
        $this->getSite()->unsetRelation('metas');
        $this->getSite()->load('metas');
        $ok = (bool) ($result['ok'] ?? false);
        $notification = Notification::make()
            ->title('Kiểm tra lại trạng thái')
            ->body((string) ($result['message'] ?? ($ok ? 'Đã cập nhật heartbeat.' : 'WordPress offline/degraded')));
        if ($ok) {
            $notification->success()->send();
        } else {
            $notification->warning()->send();
        }
    }

    public function startLinkAnalysis(): void
    {
        $run = app(\Omnichannel\Addons\SiteSync\Services\LinkAnalysis\LinkAnalysisRunService::class)
            ->start($this->getSite());
        Notification::make()
            ->title('Phân tích lại')
            ->body('Đã xếp hàng Link Analysis #'.$run->id)
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoringStatistics(): array
    {
        return app(DomainOverviewService::class)->getScoringStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoreDistribution(): array
    {
        return app(DomainOverviewService::class)->getScoreDistribution((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getSyncStatistics(): array
    {
        return app(DomainOverviewService::class)->getSyncStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopKeywords(): Collection
    {
        return app(DomainOverviewService::class)->getTopKeywords((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopLinks(): Collection
    {
        return app(DomainOverviewService::class)->getTopLinks((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getTechnicalSeoSummary(): array
    {
        return app(DomainOverviewService::class)->getTechnicalSeoSummary($this->getSite());
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_mcp')
                ->label('MCP Markdown')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->url(DomainResource::getUrl('mcp', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => SeoAccessControl::canAccessManagerFeatures()),
            $this->deleteDomainAction(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getActions(): array
    {
        return [
            $this->deleteDomainAction(),
            $this->syncIncrementalAction(),
            $this->resyncKeywordsAction(),
            $this->testSyncDataAction(),
        ];
    }

    protected function deleteDomainAction(): Action
    {
        return Action::make('delete_domain')
            ->label(__('seo-content-ai::filament.domain.delete_domain'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.domain.delete_domain_heading'))
            ->modalDescription(__('seo-content-ai::filament.domain.delete_domain_description'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.domain.delete_domain_submit'))
            ->action(function (): void {
                /** @var Site $site */
                $site = $this->getRecord();

                app(ClearDomainArticlesService::class)->clear($site);

                $userId = (int) auth()->id();
                $siteId = (int) $site->getKey();
                Cache::forget(IncrementalDomainSyncCache::cacheKey($userId, $siteId));
                Cache::forget(IncrementalDomainSyncCache::fullItemsCacheKey(
                    IncrementalDomainSyncCache::cacheKey($userId, $siteId),
                ));
                Cache::forget(MetadataDomainSyncCache::cacheKey($userId, $siteId));
                Cache::forget(MetadataDomainSyncCache::fullItemsCacheKey(
                    MetadataDomainSyncCache::cacheKey($userId, $siteId),
                ));

                $site->delete();

                Notification::make()
                    ->title(__('seo-content-ai::filament.domain.delete_domain_success'))
                    ->success()
                    ->send();

                $this->redirect(DomainResource::getUrl('index'), navigate: false);
            });
    }

    protected function syncIncrementalAction(): Action
    {
        return Action::make('sync_incremental')
            ->label(__('seo-content-ai::filament.domain.sync_incremental'))
            ->color('success')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(function (): void {
                $this->runIncrementalSyncAction();
            });
    }

    protected function resyncKeywordsAction(): Action
    {
        return Action::make('resync_keywords')
            ->label(__('seo-content-ai::filament.keyword.resync_linked'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn (): bool => SeoAccessControl::canMutateInSeoPanel())
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.keyword.resync_linked'))
            ->modalDescription(__('seo-content-ai::filament.keyword.resync_linked_confirm'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.resync_linked_submit'))
            ->action(function (): void {
                $this->runRescrapeKeywordsAction();
            });
    }

    protected function testSyncDataAction(): Action
    {
        return Action::make('test_sync_data')
            ->label(__('seo-content-ai::filament.domain.test_sync_debug'))
            ->icon('heroicon-o-bug-ant')
            ->color('gray')
            ->visible(fn (): bool => SeoAccessControl::canAccessManagerFeatures()
                && SeoAccessControl::canMutateInSeoPanel())
            ->requiresConfirmation()
            ->modalDescription(__('seo-content-ai::filament.domain.test_sync_debug_description'))
            ->action(function (): void {
                $this->runDomainSyncTest();
            });
    }

    private function applyPendingTokenVisibility(): void
    {
        if ($this->pendingRevealField === 'migration') {
            $this->migrationTokenVisible = true;
        } elseif ($this->pendingRevealField === 'read') {
            $this->readTokenVisible = true;
        }

        $this->pendingRevealField = null;
    }

    private function canRevealTokensWithoutPassword(): bool
    {
        if (auth('sanctum')->check()) {
            return true;
        }

        return (bool) session('seo_domain_tokens_verified', false);
    }

    public function runIncrementalSyncAction(): void
    {
        @set_time_limit(120);

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $runner = app(IncrementalDomainSyncRunner::class);

        if ($runner->isRunning($userId, $siteId) || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_incremental_already_running'))
                ->warning()
                ->send();

            return;
        }

        $cacheKey = IncrementalDomainSyncCache::cacheKey($userId, $siteId);
        $cachedState = $this->getIncrementalSyncCacheState();

        if (IncrementalDomainSyncCache::isResumable($cachedState)) {
            $resumingState = IncrementalDomainSyncCache::markResuming($cachedState);
            Cache::put($cacheKey, $resumingState, now()->addHours(2));

            $progress = IncrementalDomainSyncCache::progressFromState($resumingState);
            $this->applyIncrementalSyncProgress($progress);

            RunIncrementalDomainSyncJob::dispatch($siteId, $userId);

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_incremental_resumed'))
                ->body(__('seo-content-ai::filament.domain.sync_incremental_resumed_hint', [
                    'done' => $progress['done'],
                    'total' => $progress['total'],
                ]))
                ->info()
                ->send();

            return;
        }

        /** @var Site $site */
        $site = $this->getRecord();
        $service = app(SyncDomainContentService::class);
        $prepared = $service->prepareIncrementalSync($site);

        if (! ($prepared['success'] ?? false)) {
            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_incremental_success'),
                __('seo-content-ai::filament.domain.sync_incremental_failed'),
            );

            return;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            $this->incrementalSyncStatus = 'completed';
            $this->incrementalSyncStatusMessage = (string) ($prepared['message'] ?? __('seo-content-ai::filament.domain.sync_incremental_success'));

            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_incremental_success'),
                __('seo-content-ai::filament.domain.sync_incremental_failed'),
            );

            return;
        }

        Cache::put($cacheKey, IncrementalDomainSyncCache::initialState($prepared, $refs), now()->addHours(2));
        Cache::forget(IncrementalDomainSyncCache::fullItemsCacheKey($cacheKey));

        $total = count($refs);
        $this->incrementalSyncTotal = $total;
        $this->incrementalSyncProgress = 0;
        $this->incrementalSyncRunning = true;
        $this->incrementalSyncResumable = false;
        $this->incrementalSyncStatus = 'running';
        $this->incrementalSyncStatusMessage = __('seo-content-ai::filament.domain.sync_incremental_progress', [
            'done' => 0,
            'total' => $total,
        ]);

        $this->dispatch('incremental-sync-progress', done: 0, total: $total, running: true);

        RunIncrementalDomainSyncJob::dispatch($siteId, $userId);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.sync_incremental_started'))
            ->body(__('seo-content-ai::filament.domain.sync_incremental_started_hint', [
                'total' => $total,
            ]))
            ->info()
            ->send();
    }

    public function runMetadataResyncAction(): void
    {
        @set_time_limit(120);

        $userId = (int) auth()->id();
        $siteId = (int) $this->getRecord()->getKey();
        $runner = app(MetadataDomainSyncRunner::class);

        if ($this->anyDomainSyncJobRunning($userId, $siteId) || $this->metadataSyncRunning) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_already_running'))
                ->warning()
                ->send();

            return;
        }

        $cacheKey = MetadataDomainSyncCache::cacheKey($userId, $siteId);
        $cachedState = $this->getMetadataSyncCacheState();

        if (MetadataDomainSyncCache::isResumable($cachedState)) {
            $resumingState = MetadataDomainSyncCache::markResuming($cachedState);
            Cache::put($cacheKey, $resumingState, now()->addHours(2));

            $progress = MetadataDomainSyncCache::progressFromState($resumingState);
            $this->applyMetadataSyncProgress($progress);

            RunMetadataDomainSyncJob::dispatch($siteId, $userId);

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_resumed'))
                ->body(__('seo-content-ai::filament.domain.sync_metadata_resumed_hint', [
                    'done' => $progress['done'],
                    'total' => $progress['total'],
                ]))
                ->info()
                ->send();

            return;
        }

        /** @var Site $site */
        $site = $this->getRecord();
        $service = app(SyncDomainContentService::class);
        $prepared = $service->prepareMetadataResync($site);

        if (! ($prepared['success'] ?? false)) {
            $this->notifySyncResult(
                $prepared,
                __('seo-content-ai::filament.domain.sync_metadata_success'),
                __('seo-content-ai::filament.domain.sync_metadata_failed'),
            );

            return;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            $this->metadataSyncStatus = 'completed';
            $this->metadataSyncStatusMessage = (string) ($prepared['message'] ?? __('seo-content-ai::filament.domain.sync_metadata_success'));

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.sync_metadata_success'))
                ->body((string) ($prepared['message'] ?? ''))
                ->success()
                ->send();

            return;
        }

        Cache::put($cacheKey, MetadataDomainSyncCache::initialState($prepared, $refs), now()->addHours(2));
        Cache::forget(MetadataDomainSyncCache::fullItemsCacheKey($cacheKey));

        $total = count($refs);
        $this->metadataSyncTotal = $total;
        $this->metadataSyncProgress = 0;
        $this->metadataSyncRunning = true;
        $this->metadataSyncResumable = false;
        $this->metadataSyncStatus = 'running';
        $this->metadataSyncStatusMessage = __('seo-content-ai::filament.domain.sync_metadata_progress', [
            'done' => 0,
            'total' => $total,
        ]);

        $this->dispatch('metadata-sync-progress', done: 0, total: $total, running: true);

        RunMetadataDomainSyncJob::dispatch($siteId, $userId);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.sync_metadata_started'))
            ->body(__('seo-content-ai::filament.domain.sync_metadata_started_hint', [
                'total' => $total,
            ]))
            ->info()
            ->send();
    }

    public function runRescrapeKeywordsAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        $userId = (int) auth()->id();

        if ($siteId <= 0 || $userId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_no_domain'))
                ->warning()
                ->send();

            return;
        }

        KeywordDomainResyncCache::clearIfStale($userId, $siteId);

        if (
            KeywordDomainResyncCache::isRunning($userId, $siteId)
            || $this->keywordResyncRunning
            || app(IncrementalDomainSyncRunner::class)->isRunning($userId, $siteId)
            || app(MetadataDomainSyncRunner::class)->isRunning($userId, $siteId)
        ) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_running'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_running_hint'))
                ->warning()
                ->send();

            return;
        }

        try {
            RunKeywordDomainResyncJob::dispatch($siteId, $userId);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        KeywordDomainResyncCache::markRunning($userId, $siteId);
        $this->keywordResyncRunning = true;
        $this->keywordResyncStatus = 'running';
        $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_running');

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.resync_linked_started'))
            ->body(__('seo-content-ai::filament.keyword.resync_linked_started_hint'))
            ->info()
            ->send();
    }

    public function getSeoScoringProgress(): array
    {
        return app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->domainProgress((int) $this->getRecord()->getKey());
    }

    public function runQueueMissingSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            $result = $this->dispatchSiteSyncBus(new QueueMissingSeoScoresCommand($siteId));
        } catch (\Throwable $exception) {
            \App\Support\RuntimeLogger::report($exception, [
                'endpoint' => 'domain.queue_missing_seo_scoring',
                'site_id' => $siteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_queue_missing'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_queue_missing'))
            ->body($result->message)
            ->success()
            ->send();
    }

    public function runRetryFailedSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            $result = $this->dispatchSiteSyncBus(new RetryFailedSeoScoresCommand($siteId));
        } catch (\Throwable $exception) {
            \App\Support\RuntimeLogger::report($exception, [
                'endpoint' => 'domain.retry_failed_seo_scoring',
                'site_id' => $siteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_retry_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_retry_failed'))
            ->body($result->message)
            ->success()
            ->send();
    }

    public function runRequeueAllSeoScoringAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();
        if ($siteId <= 0) {
            return;
        }

        try {
            $preview = $this->getSeoScoringProgress();
            $result = $this->dispatchSiteSyncBus(new RequeueAllSeoScoresCommand(
                siteId: $siteId,
                confirmed: true,
                operationId: 'requeue_all_'.bin2hex(random_bytes(6)),
            ));
        } catch (\Throwable $exception) {
            \App\Support\RuntimeLogger::report($exception, [
                'endpoint' => 'domain.requeue_all_seo_scoring',
                'site_id' => $siteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.seo_scoring_requeue_all'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.seo_scoring_requeue_all'))
            ->body($result->message.' (preview '.$preview['total'].' bài)')
            ->success()
            ->send();
    }

    public function runAuditLinkStatusAction(): void
    {
        $siteId = (int) $this->getRecord()->getKey();

        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_failed'))
                ->body(__('seo-content-ai::filament.keyword.resync_linked_no_domain'))
                ->warning()
                ->send();

            return;
        }

        try {
            app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            $queued = app(LinkMapStatusAuditService::class)->queueDomainAudit($siteId);
        } catch (\Throwable $exception) {
            report($exception);

            $this->auditLinkStatus = 'failed';
            $this->auditLinkStatusMessage = $exception->getMessage();

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($queued === 0) {
            $this->auditLinkStatus = 'failed';
            $this->auditLinkStatusMessage = __('seo-content-ai::filament.domain.audit_link_status_empty');

            Notification::make()
                ->title(__('seo-content-ai::filament.domain.audit_link_status_empty'))
                ->warning()
                ->send();

            return;
        }

        $this->auditLinkStatus = 'completed';
        $this->auditLinkStatusMessage = __('seo-content-ai::filament.domain.audit_link_status_started', [
            'count' => $queued,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.domain.audit_link_status_started', ['count' => $queued]))
            ->body(__('seo-content-ai::filament.domain.audit_link_status_started_hint'))
            ->info()
            ->send();
    }

    private function runDomainSyncTest(): void
    {
        /** @var Site $site */
        $site = $this->getRecord();

        $result = app(SyncDomainContentService::class)->sync($site, [
            'is_test' => true,
            'limit_per_type' => 2,
        ]);

        $this->notifySyncResult(
            $result,
            __('seo-content-ai::filament.domain.test_sync_debug_success'),
            __('seo-content-ai::filament.domain.test_sync_debug_failed'),
        );
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     */
    private function applyIncrementalSyncStatus(array $progress, ?array $state): void
    {
        [$status, $message] = $this->resolveChunkedJobStatus(
            progress: $progress,
            state: $state,
            isResumable: static fn (?array $cachedState): bool => IncrementalDomainSyncCache::isResumable($cachedState),
            progressLabel: __('seo-content-ai::filament.domain.sync_incremental_progress', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
            successLabel: __('seo-content-ai::filament.domain.sync_incremental_success'),
            failedLabel: __('seo-content-ai::filament.domain.sync_incremental_failed'),
            resumableLabel: __('seo-content-ai::filament.domain.sync_incremental_resumed_hint', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
        );

        $this->incrementalSyncStatus = $status;
        $this->incrementalSyncStatusMessage = $message;
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     */
    private function applyMetadataSyncStatus(array $progress, ?array $state): void
    {
        [$status, $message] = $this->resolveChunkedJobStatus(
            progress: $progress,
            state: $state,
            isResumable: static fn (?array $cachedState): bool => MetadataDomainSyncCache::isResumable($cachedState),
            progressLabel: __('seo-content-ai::filament.domain.sync_metadata_progress', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
            successLabel: __('seo-content-ai::filament.domain.sync_metadata_success'),
            failedLabel: __('seo-content-ai::filament.domain.sync_metadata_failed'),
            resumableLabel: __('seo-content-ai::filament.domain.sync_metadata_resumed_hint', [
                'done' => $progress['done'],
                'total' => $progress['total'],
            ]),
        );

        $this->metadataSyncStatus = $status;
        $this->metadataSyncStatusMessage = $message;
    }

    /**
     * @param  array{running: bool, status: string, message: ?string, result: ?array<string, mixed>}  $progress
     */
    private function applyKeywordResyncStatus(array $progress): void
    {
        if ($progress['running']) {
            $this->keywordResyncStatus = 'running';
            $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_running');

            return;
        }

        $status = (string) ($progress['status'] ?? '');

        if ($status === KeywordDomainResyncCache::STATUS_COMPLETED) {
            $this->keywordResyncStatus = 'completed';
            $this->keywordResyncStatusMessage = __('seo-content-ai::filament.keyword.resync_linked_completed');

            return;
        }

        if ($status === KeywordDomainResyncCache::STATUS_FAILED) {
            $this->keywordResyncStatus = 'failed';
            $this->keywordResyncStatusMessage = (string) ($progress['message'] ?? __('seo-content-ai::filament.keyword.resync_linked_failed'));

            return;
        }

        $this->keywordResyncStatus = 'idle';
        $this->keywordResyncStatusMessage = __('seo-content-ai::filament.domain.sync_action_status_ready');
    }

    /**
     * @param  array{done: int, total: int, status: string, running: bool, message: ?string}  $progress
     * @param  array<string, mixed>|null  $state
     * @return array{0: string, 1: ?string}
     */
    private function resolveChunkedJobStatus(
        array $progress,
        ?array $state,
        callable $isResumable,
        string $progressLabel,
        string $successLabel,
        string $failedLabel,
        string $resumableLabel,
    ): array {
        if ($progress['running']) {
            return ['running', $progressLabel];
        }

        if ($isResumable($state)) {
            return ['resumable', $resumableLabel];
        }

        $status = (string) ($progress['status'] ?? '');

        if ($status === IncrementalDomainSyncCache::STATUS_COMPLETED) {
            $message = trim((string) (($state['message'] ?? null) ?: $successLabel));

            return ['completed', $message !== '' ? $message : $successLabel];
        }

        if ($status === IncrementalDomainSyncCache::STATUS_FAILED) {
            $message = trim((string) ($progress['message'] ?? $state['message'] ?? $failedLabel));

            return ['failed', $message !== '' ? $message : $failedLabel];
        }

        return ['idle', __('seo-content-ai::filament.domain.sync_action_status_ready')];
    }

    /**
     * @param  array{success:bool,message:string}  $result
     */
    private function notifySyncResult(array $result, string $successTitle, string $failureTitle): void
    {
        if ($result['success']) {
            Notification::make()
                ->title($successTitle)
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->persistent()
                ->send();

            $this->dispatch('domain-sync-completed');

            return;
        }

        Notification::make()
            ->title($failureTitle)
            ->body((string) ($result['message'] ?? ''))
            ->danger()
            ->persistent()
            ->send();
    }
}
