<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentApproval;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlan;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\McpCapabilityMarkdownPresenter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanApplicationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTimelineService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAiCostAggregateService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAuditSearchService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectCommandBusMonitorService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectDailyReportService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectErrorCenterService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsDashboardService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsReplayService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectPublishAnalyticsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectWpAdapterMetricsService;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncComparisonReportCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncCutoverReadinessService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Content Project Operation Center — Admin/Manager only.
 * Path: /seo/{connection_hash}/content-operations (SEO panel; needs omi_seo_ai).
 * Admin alias: /admin/content-operations redirects here.
 *
 * @see docs/modules/OPERATIONS_AND_OBSERVABILITY.md
 */
final class ContentProjectOperationsCenter extends SeoPanelPage
{
    protected static ?string $slug = 'content-operations';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 7;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-operations-center';

    public string $activeTab = 'dashboard';

    /** @var array<string, mixed> */
    public array $dashboard = [];

    /** @var list<array<string, mixed>> */
    public array $operations = [];

    /** @var array<string, mixed> */
    public array $aiCost = [];

    /** @var array<string, mixed> */
    public array $publishAnalytics = [];

    /** @var array<string, mixed> */
    public array $wpMetrics = [];

    /** @var list<array<string, mixed>> */
    public array $errors = [];

    /** @var list<array<string, mixed>> */
    public array $healthChecks = [];

    /** @var list<array<string, mixed>> */
    public array $siteHealth = [];

    /** @var array<string, mixed> */
    public array $dailyReport = [];

    /** @var list<array<string, mixed>> */
    public array $timeline = [];

    /** @var list<array<string, mixed>> */
    public array $audits = [];

    /** @var list<array<string, mixed>> */
    public array $agentPlans = [];

    /** @var list<array<string, mixed>> */
    public array $agentApprovals = [];

    /** @var list<array<string, mixed>> */
    public array $runtimeRows = [];

    /** @var array<string, mixed> */
    public array $mcpCapabilityDoc = [];

    public ?int $siteSyncFilterSiteId = null;

    /** @var list<array<string, mixed>> */
    public array $siteSyncRuns = [];

    /** @var list<array<string, mixed>> */
    public array $siteSyncEvents = [];

    /** @var array<string, mixed>|null */
    public ?array $siteSyncCutover = null;

    /** @var array<string, mixed>|null */
    public ?array $siteSyncDiagnostic = null;

    public string $siteSyncCutoverMode = 'legacy_active';

    public string $filterCommand = '';

    public string $filterActor = '';

    public string $filterResultCode = '';

    public string $filterProjectRef = '';

    public string $filterTenant = '';

    public string $auditAction = '';

    public string $auditProjectRef = '';

    public string $timelineProjectId = '';

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.ops.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.ops.title');
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentOperations();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $this->refreshAll();
    }

    public function switchTab(string $tab): void
    {
        $allowed = ['dashboard', 'site_sync', 'health', 'runtime', 'timeline', 'audit', 'commands', 'analytics', 'report', 'plans', 'approvals'];
        if (! in_array($tab, $allowed, true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->refreshTab($tab);
    }

    public function refreshAll(): void
    {
        $this->refreshTab('dashboard');
        $this->refreshTab($this->activeTab);
    }

    public function refreshRuntimeHealth(): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);

        try {
            app(\Omnichannel\Addons\Agent\Extension\ExtensionHealthService::class)->runAll();
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_refreshed'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.ops.runtime_health']);
            Notification::make()
                ->title(__('seo-content-ai::filament.extensions.health_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->runtimeRows = SeoExtensions::buildRuntimeSnapshot();
        $this->loadMcpCapabilityDoc();
    }

    public function refreshTab(?string $tab = null): void
    {
        $tab ??= $this->activeTab;
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $sites = $siteIds !== [] ? $siteIds : null;

        try {
            match ($tab) {
                'dashboard' => $this->dashboard = app(ContentProjectOpsDashboardService::class)->snapshot($sites),
                'site_sync' => $this->loadSiteSync(),
                'commands' => $this->loadOperations(),
                'analytics' => $this->loadAnalytics($sites),
                'health' => $this->loadHealth($sites),
                'runtime' => $this->loadRuntimeTab(),
                'timeline' => $this->loadTimeline(),
                'report' => $this->dailyReport = app(ContentProjectDailyReportService::class)
                    ->buildForDate(Carbon::yesterday(), $sites),
                'audit' => $this->loadAudits(),
                'plans' => $this->loadAgentPlans(),
                'approvals' => $this->loadAgentApprovals(),
                default => null,
            };
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.ops.refresh', 'tab' => $tab]);
            Notification::make()
                ->title(__('seo-content-ai::filament.ops.refresh_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applyCommandFilters(): void
    {
        $this->loadOperations();
    }

    public function applyAuditFilters(): void
    {
        $this->loadAudits();
    }

    public function replayOperation(string $operationId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);

        $userId = auth()->id() !== null ? (int) auth()->id() : 0;
        if ($userId <= 0) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $result = app(ContentProjectOpsReplayService::class)->replay($operationId, $userId);
        app(ContentProjectActionResultNotifier::class)->send($result);
        $this->loadOperations();
    }

    public function refreshSiteSync(): void
    {
        $this->loadSiteSync();
    }

    public function resumeSiteSyncRun(int $runId): void
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return;
        }

        $this->dispatchSiteSyncCommand(new ResumeSiteSyncCommand((int) $run->site_id, $runId));
    }

    public function cancelSiteSyncRun(int $runId): void
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return;
        }

        $this->dispatchSiteSyncCommand(new CancelSiteSyncCommand((int) $run->site_id, $runId));
    }

    public function requeueSiteSyncEvent(int $eventId): void
    {
        $event = SeoSiteSyncInboundEvent::query()->find($eventId);
        if ($event === null) {
            return;
        }

        $this->dispatchSiteSyncCommand(new RequeueSiteSyncInboundEventCommand((int) $event->site_id, $eventId));
    }

    public function reconcileSiteSyncSite(int $siteId): void
    {
        $this->dispatchSiteSyncCommand(new ReconcileSiteSyncCommand($siteId, 'standard'));
    }

    public function runSiteSyncDiagnostic(int $siteId): void
    {
        $result = $this->dispatchSiteSyncCommandResult(new GenerateSiteSyncDiagnosticCommand($siteId));
        $this->siteSyncDiagnostic = is_array($result->metadata) ? $result->metadata : null;
        $this->loadSiteSync();
    }

    public function generateSiteSyncReport(int $siteId): void
    {
        $this->dispatchSiteSyncCommand(new GenerateSiteSyncComparisonReportCommand($siteId, 'summary'));
    }

    public function loadTimelineForProject(): void
    {
        $this->loadTimeline();
    }

    public function approveAgentAction(string $approvalRef, string $fingerprint, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->approve(
            $this->agentContextForSite($siteId),
            $approvalRef,
            $fingerprint,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentApprovals();
    }

    public function rejectAgentAction(string $approvalRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->rejectApproval(
            $this->agentContextForSite($siteId),
            $approvalRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentApprovals();
    }

    public function pauseAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->pausePlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function resumeAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->resumePlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function cancelAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->cancelPlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function retryAgentPlanStep(string $planRef, string $stepRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->retryStep(
            $this->agentContextForSite($siteId),
            $planRef,
            $stepRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    private function loadAgentPlans(): void
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $query = ContentProjectAgentPlan::query()->orderByDesc('id')->limit(50);
        if ($siteIds !== []) {
            $query->whereIn('site_id', $siteIds);
        }

        $this->agentPlans = $query->get()->map(static fn (ContentProjectAgentPlan $plan): array => [
            'plan_ref' => (string) $plan->public_ref,
            'site_id' => (int) ($plan->site_id ?? 0),
            'objective' => (string) $plan->objective,
            'status' => (string) $plan->status,
            'total_steps' => (int) $plan->total_steps,
            'current_step_index' => (int) $plan->current_step_index,
            'created_at' => $plan->created_at?->toIso8601String(),
        ])->all();
    }

    private function loadAgentApprovals(): void
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $query = ContentProjectAgentApproval::query()
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(50);

        if ($siteIds !== []) {
            $query->where(function ($q) use ($siteIds): void {
                $q->whereIn('site_id', $siteIds)->orWhereNull('site_id');
            });
        }

        $this->agentApprovals = $query->get()->map(static fn (ContentProjectAgentApproval $row): array => [
            'approval_ref' => (string) $row->public_ref,
            'plan_ref' => (string) $row->plan_ref,
            'step_ref' => $row->step_ref,
            'site_id' => (int) ($row->site_id ?? 0),
            'action' => (string) $row->action,
            'summary' => (string) $row->summary,
            'risk_level' => (string) $row->risk_level,
            'state_fingerprint' => (string) $row->state_fingerprint,
            'expires_at' => $row->expires_at?->toIso8601String(),
            'destroy_workspace' => str_contains((string) $row->action, 'archive'),
        ])->all();
    }

    private function agentContextForSite(int $siteId): AgentExecutionContext
    {
        $user = auth()->user();
        $scopes = $user instanceof \App\Models\User
            ? app(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceContextService::class)
                ->scopesForAuthenticatedUser($user)
            : [];

        return AgentExecutionContext::fromArray([
            'actor_ref' => 'agent:user:'.(int) auth()->id(),
            'actor_type' => 'agent',
            'tenant_ref' => 'tenant:'.$siteId,
            'site_ref' => ContentProjectPublicRef::site($siteId),
            'request_ref' => (string) Str::uuid(),
            'resolved_site_id' => $siteId,
            'resolved_actor_user_id' => auth()->id() !== null ? (int) auth()->id() : null,
            'scopes' => $scopes,
        ]);
    }

    /**
     * @param  array{success: bool, message: string}  $result
     */
    private function notifyAgentResult(array $result): void
    {
        $notification = Notification::make()->title($result['message'] ?? '');
        if ($result['success'] ?? false) {
            $notification->success();
        } else {
            $notification->danger();
        }
        $notification->send();
    }

    private function dispatchSiteSyncCommand(object $command): void
    {
        $this->dispatchSiteSyncCommandResult($command);
        $this->loadSiteSync();
    }

    private function dispatchSiteSyncCommandResult(object $command): \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult
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

    private function loadOperations(): void
    {
        $filters = array_filter([
            'command' => $this->filterCommand !== '' ? $this->filterCommand : null,
            'actor_type' => $this->filterActor !== '' ? $this->filterActor : null,
            'result_code' => $this->filterResultCode !== '' ? $this->filterResultCode : null,
            'project_ref' => $this->filterProjectRef !== '' ? $this->filterProjectRef : null,
            'tenant_ref' => $this->filterTenant !== '' ? $this->filterTenant : null,
            'limit' => 50,
        ], static fn ($v) => $v !== null);

        /** @var \Illuminate\Support\Collection<int, ContentProjectOperation> $rows */
        $rows = app(ContentProjectCommandBusMonitorService::class)->query($filters);

        $this->operations = $rows->map(static function (ContentProjectOperation $op): array {
            return [
                'operation_id' => (string) $op->operation_id,
                'request_id' => $op->request_id,
                'command' => (string) $op->command,
                'actor_type' => (string) $op->actor_type,
                'actor_id' => $op->actor_id,
                'started_at' => $op->started_at?->toIso8601String(),
                'finished_at' => $op->finished_at?->toIso8601String(),
                'duration_ms' => $op->duration_ms,
                'status' => (string) $op->status,
                'result_code' => $op->result_code,
                'success' => (bool) $op->success,
                'project_ref' => $op->project_ref,
                'item_ref' => $op->item_ref,
                'can_replay' => ! $op->success,
            ];
        })->all();
    }

    private function loadRuntimeTab(): void
    {
        $this->runtimeRows = SeoExtensions::buildRuntimeSnapshot();
        $this->loadMcpCapabilityDoc();
    }

    private function loadMcpCapabilityDoc(): void
    {
        try {
            $this->mcpCapabilityDoc = app(McpCapabilityMarkdownPresenter::class)->present(
                includeInternal: true,
                filter: McpCapabilityMarkdownPresenter::FILTER_ALL,
            );
        } catch (Throwable) {
            $this->mcpCapabilityDoc = [
                'title' => 'MCP Capabilities',
                'filter' => McpCapabilityMarkdownPresenter::FILTER_ALL,
                'filters' => [],
                'items' => [],
                'internal_items' => [],
                'markdown' => '',
                'include_internal' => true,
                'count' => 0,
            ];
        }
    }

    private function loadSiteSync(): void
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();

        $runsQuery = SeoSiteSyncRun::query()->orderByDesc('id')->limit(50);
        $eventsQuery = SeoSiteSyncInboundEvent::query()->orderByDesc('id')->limit(50);

        if ($siteIds !== []) {
            $runsQuery->whereIn('site_id', $siteIds);
            $eventsQuery->whereIn('site_id', $siteIds);
        }

        if ($this->siteSyncFilterSiteId !== null && $this->siteSyncFilterSiteId > 0) {
            $runsQuery->where('site_id', $this->siteSyncFilterSiteId);
            $eventsQuery->where('site_id', $this->siteSyncFilterSiteId);
        }

        $this->siteSyncRuns = $runsQuery->get()->map(function (SeoSiteSyncRun $run): array {
            $status = (string) $run->status;

            return [
                'id' => (int) $run->id,
                'site_id' => (int) $run->site_id,
                'public_ref' => (string) $run->public_ref,
                'status' => $status,
                'mode' => (string) $run->mode,
                'current_step' => (string) ($run->current_step ?? ''),
                'error' => (string) ($run->error_message ?? ''),
                'show_report' => in_array($status, ['completed', 'completed_with_warnings'], true),
                'show_resume' => in_array($status, ['failed', 'paused'], true),
                'show_cancel' => in_array($status, ['pending', 'running'], true),
                'show_reconcile' => in_array($status, ['completed', 'completed_with_warnings', 'failed', 'paused'], true),
                'show_restart' => in_array($status, ['canceled', 'cancelled', 'superseded'], true),
            ];
        })->all();

        $this->siteSyncEvents = $eventsQuery->get()->map(static fn (SeoSiteSyncInboundEvent $event): array => [
            'id' => (int) $event->id,
            'site_id' => (int) $event->site_id,
            'event_type' => (string) $event->event_type,
            'status' => (string) $event->status,
            'wordpress_id' => $event->wordpress_id,
            'error' => (string) ($event->last_error_message ?? ''),
        ])->all();

        if ($this->siteSyncFilterSiteId !== null && $this->siteSyncFilterSiteId > 0) {
            $site = \App\Models\Site::query()->find($this->siteSyncFilterSiteId);
            $this->siteSyncCutover = $site
                ? app(SiteSyncCutoverReadinessService::class)->evaluate($site)
                : null;
            $this->siteSyncCutoverMode = $site
                ? app(SiteSyncCutoverStateService::class)->modeFor($site)
                : 'legacy_active';
        } else {
            $this->siteSyncCutover = null;
            $this->siteSyncCutoverMode = 'legacy_active';
        }
    }

    /** @param list<int>|null $sites */
    private function loadAnalytics(?array $sites): void
    {
        $this->aiCost = app(ContentProjectAiCostAggregateService::class)->aggregate(Carbon::today(), $sites);
        $this->publishAnalytics = app(ContentProjectPublishAnalyticsService::class)->snapshot($sites);
        $this->wpMetrics = app(ContentProjectWpAdapterMetricsService::class)->snapshot($sites);
        $this->errors = app(ContentProjectErrorCenterService::class)->topErrors($sites, 20);
    }

    /** @param list<int>|null $sites */
    private function loadHealth(?array $sites): void
    {
        $this->healthChecks = app(ContentProjectOpsHealthService::class)->checks();
        $siteIds = is_array($sites) ? $sites : SeoAccessControl::accessibleSiteIds();
        $this->siteHealth = app(ContentProjectSiteHealthService::class)->snapshot($siteIds);
    }

    private function loadTimeline(): void
    {
        $projectId = (int) $this->timelineProjectId;
        if ($projectId <= 0) {
            $this->timeline = [];

            return;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            $this->timeline = [];

            return;
        }

        if (! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            $this->timeline = [];

            return;
        }

        $this->timeline = app(ContentProjectTimelineService::class)->forProject($project);
    }

    private function loadAudits(): void
    {
        $this->audits = app(ContentProjectAuditSearchService::class)->search([
            'project_ref' => $this->auditProjectRef !== '' ? $this->auditProjectRef : null,
            'action' => $this->auditAction !== '' ? $this->auditAction : null,
            'limit' => 50,
        ]);
    }
}
