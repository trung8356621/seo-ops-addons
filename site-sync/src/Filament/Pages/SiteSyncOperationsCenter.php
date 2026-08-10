<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ActivateSiteSyncV2Command;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\EnterSiteSyncShadowModeCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ExitSiteSyncShadowModeCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncComparisonReportCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewSiteSyncCutoverCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RollbackSiteSyncToLegacyCommand;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncCutoverReadinessService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Site Sync V2 Operations — Admin/Manager readonly monitor + CommandBus actions.
 */
final class SiteSyncOperationsCenter extends SeoPanelPage
{
    protected static ?string $slug = 'site-sync-operations';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 8;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.site-sync-operations-center';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?int $filterSiteId = null;

    #[Url(as: 'run_id')]
    public ?int $focusRunId = null;

    /** @var list<array<string, mixed>> */
    public array $runs = [];

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var array<string, mixed>|null */
    public ?array $cutover = null;

    /** @var array<string, mixed>|null */
    public ?array $diagnostic = null;

    /** @var array<string, mixed>|null */
    public ?array $cutoverPreview = null;

    public string $cutoverMode = 'legacy_active';

    public string $confirmationToken = '';

    public static function getNavigationLabel(): string
    {
        return 'Site Sync Ops';
    }

    public function getTitle(): string
    {
        return 'Site Sync Operations';
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentOperations();
    }

    public function mount(): void
    {
        if ($this->focusRunId !== null && $this->focusRunId > 0) {
            $run = SeoSiteSyncRun::query()->find($this->focusRunId);
            if ($run instanceof SeoSiteSyncRun) {
                $this->filterSiteId = (int) $run->site_id;
            }
        }

        $this->refreshData();
    }

    public function refreshData(): void
    {
        $runsQuery = SeoSiteSyncRun::query()->orderByDesc('id')->limit(50);
        $eventsQuery = SeoSiteSyncInboundEvent::query()->orderByDesc('id')->limit(50);
        if ($this->filterSiteId) {
            $runsQuery->where('site_id', $this->filterSiteId);
            $eventsQuery->where('site_id', $this->filterSiteId);
        }

        $this->runs = $runsQuery->get()->map(function (SeoSiteSyncRun $run): array {
            return [
                'id' => (int) $run->id,
                'site_id' => (int) $run->site_id,
                'public_ref' => (string) $run->public_ref,
                'status' => (string) $run->status,
                'mode' => (string) $run->mode,
                'current_step' => (string) ($run->current_step ?? ''),
                'error' => (string) ($run->error_message ?? ''),
                'focused' => $this->focusRunId !== null && (int) $run->id === (int) $this->focusRunId,
            ];
        })->all();

        $this->events = $eventsQuery->get()->map(static fn (SeoSiteSyncInboundEvent $event): array => [
            'id' => (int) $event->id,
            'site_id' => (int) $event->site_id,
            'event_type' => (string) $event->event_type,
            'status' => (string) $event->status,
            'wordpress_id' => $event->wordpress_id,
            'error' => (string) ($event->last_error_message ?? ''),
        ])->all();

        if ($this->filterSiteId) {
            $site = Site::query()->find($this->filterSiteId);
            $this->cutover = $site
                ? app(SiteSyncCutoverReadinessService::class)->evaluate($site)
                : null;
            $this->cutoverMode = $site
                ? app(SiteSyncCutoverStateService::class)->modeFor($site)
                : 'legacy_active';
        } else {
            $this->cutover = null;
            $this->cutoverMode = 'legacy_active';
        }
    }

    public function resumeRun(int $runId): void
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return;
        }
        $this->dispatchBus(new ResumeSiteSyncCommand((int) $run->site_id, $runId));
    }

    public function cancelRun(int $runId): void
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return;
        }
        $this->dispatchBus(new CancelSiteSyncCommand((int) $run->site_id, $runId));
    }

    public function requeueEvent(int $eventId): void
    {
        $event = SeoSiteSyncInboundEvent::query()->find($eventId);
        if ($event === null) {
            return;
        }
        $this->dispatchBus(new RequeueSiteSyncInboundEventCommand((int) $event->site_id, $eventId));
    }

    public function reconcileSite(int $siteId): void
    {
        $this->dispatchBus(new ReconcileSiteSyncCommand($siteId, 'standard'));
    }

    public function runDiagnostic(int $siteId): void
    {
        $result = $this->dispatchBusResult(new GenerateSiteSyncDiagnosticCommand($siteId));
        $this->diagnostic = is_array($result->metadata) ? $result->metadata : null;
        $this->refreshData();
    }

    public function previewCutover(int $siteId): void
    {
        if (! app(SiteSyncFeatureFlags::class)->cutoverUiEnabled()) {
            return;
        }
        $result = $this->dispatchBusResult(new PreviewSiteSyncCutoverCommand($siteId));
        $this->cutoverPreview = is_array($result->metadata) ? $result->metadata : null;
        $this->refreshData();
    }

    public function enterShadow(int $siteId): void
    {
        $this->dispatchBus(new EnterSiteSyncShadowModeCommand($siteId, 'ops panel', $this->confirmationToken ?: 'ops-shadow'));
    }

    public function activateV2(int $siteId): void
    {
        $token = trim($this->confirmationToken);
        if ($token === '') {
            return;
        }
        $this->dispatchBus(new ActivateSiteSyncV2Command($siteId, 'ops activate', $token));
    }

    public function rollbackLegacy(int $siteId): void
    {
        $token = trim($this->confirmationToken);
        if ($token === '') {
            return;
        }
        $this->dispatchBus(new RollbackSiteSyncToLegacyCommand($siteId, 'ops rollback', $token));
    }

    public function exitShadow(int $siteId): void
    {
        $this->dispatchBus(new ExitSiteSyncShadowModeCommand($siteId, 'ops exit shadow'));
    }

    public function generateComparison(int $siteId): void
    {
        $this->dispatchBus(new GenerateSiteSyncComparisonReportCommand($siteId, 'summary'));
    }

    private function dispatchBus(object $command): void
    {
        $this->dispatchBusResult($command);
        $this->refreshData();
    }

    private function dispatchBusResult(object $command): \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult
    {
        $user = Auth::user();
        $actor = ActorContext::user($user !== null ? (int) $user->id : null);
        $result = app(ContentProjectCommandBus::class)->dispatch($command, $actor);
        $notification = Notification::make()
            ->title($result->success ? 'OK' : 'Failed')
            ->body($result->message);
        if ($result->success) {
            $notification->success();
        } else {
            $notification->danger();
        }
        $notification->send();

        return $result;
    }
}
