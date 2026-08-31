<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Handlers;

use Omnichannel\Addons\SiteSync\Jobs\SiteSync\ProcessSiteSyncInboundEventJob;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncInboundEvent;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\AcceptSiteProfileSuggestionCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\BackfillSiteSyncV2Command;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteContactsCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ForceFullSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\QueueMissingSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RefreshSiteSnapshotCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RejectSiteProfileSuggestionCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueAllSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RetryFailedSeoScoresCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RetrySiteSyncStepCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunLinkHealthCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteKeywordsCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteLinksCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ValidateSiteSyncHandshakeCommand;
use Omnichannel\Addons\SiteSync\Services\Backfill\SiteSyncV2BackfillService;
use Omnichannel\Addons\SiteSync\Services\Bootstrap\SiteSyncBootstrapService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Diagnostics\SiteSyncDiagnosticService;
use Omnichannel\Addons\SiteSync\Services\Handshake\SiteSyncHandshakeService;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncOrchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncFeatureFlags;
use Omnichannel\Addons\SiteSync\Services\Orchestration\SiteSyncProtocolRouter;
use Omnichannel\Addons\SiteSync\Services\Profile\SiteProfileSuggestionService;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncReconciliationService;
use App\Models\Site;

final class SiteSyncCommandHandler implements ContentProjectCommandHandler
{
    public function __construct() {}

    private function orchestrator(): RunSiteSyncOrchestrator
    {
        return app(RunSiteSyncOrchestrator::class);
    }

    private function orchestratorV3(): RunSiteSyncV3Orchestrator
    {
        return app(RunSiteSyncV3Orchestrator::class);
    }

    private function flags(): SiteSyncFeatureFlags
    {
        return app(SiteSyncFeatureFlags::class);
    }

    private function isV3Run(int $runId): bool
    {
        $run = SeoSiteSyncRun::query()->find($runId);
        if ($run === null) {
            return false;
        }

        return (int) ($run->protocol_version ?? 2) === SiteSyncV3Schema::PROTOCOL;
    }

    private function reconciliation(): SiteSyncReconciliationService
    {
        return app(SiteSyncReconciliationService::class);
    }

    private function bootstrap(): SiteSyncBootstrapService
    {
        return app(SiteSyncBootstrapService::class);
    }

    private function backfill(): SiteSyncV2BackfillService
    {
        return app(SiteSyncV2BackfillService::class);
    }

    private function handshake(): SiteSyncHandshakeService
    {
        return app(SiteSyncHandshakeService::class);
    }

    private function diagnostic(): SiteSyncDiagnosticService
    {
        return app(SiteSyncDiagnosticService::class);
    }

    private function profileSuggestions(): SiteProfileSuggestionService
    {
        return app(SiteProfileSuggestionService::class);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        return match (true) {
            $command instanceof PreviewBootstrapSiteSyncCommand => $this->previewBootstrap($command),
            $command instanceof BootstrapSiteSyncCommand => $this->doBootstrap($command, $actor),
            $command instanceof BackfillSiteSyncV2Command => $this->doBackfill($command, $actor),
            $command instanceof ValidateSiteSyncHandshakeCommand => $this->doHandshake($command),
            $command instanceof GenerateSiteSyncDiagnosticCommand => $this->doDiagnostic($command),
            $command instanceof AcceptSiteProfileSuggestionCommand => $this->acceptSuggestion($command),
            $command instanceof RejectSiteProfileSuggestionCommand => $this->rejectSuggestion($command),
            $command instanceof ForceFullSiteSyncCommand => $this->forceFull($command, $actor),
            $command instanceof QueueMissingSeoScoresCommand => $this->queueMissingScores($command),
            $command instanceof RetryFailedSeoScoresCommand => $this->retryFailedScores($command),
            $command instanceof RequeueAllSeoScoresCommand => $this->requeueAllScores($command),
            $command instanceof RunSiteSyncCommand => $this->runSmart($command, $actor),
            $command instanceof DiscoverSiteCommand => $this->run($command->siteId, [
                'steps' => ['detect_capability', 'sync_site_profile', 'finalize'],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof SyncSiteKeywordsCommand => $this->run($command->siteId, [
                'steps' => [
                    'detect_capability',
                    'request_snapshot_delta',
                    'sync_provider_keywords',
                    'missing_capability_fallback',
                    'finalize',
                ],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof SyncSiteLinksCommand => $this->startLinkHealth($command->siteId),
            $command instanceof RunLinkHealthCommand => $this->startLinkHealth($command->siteId),
            $command instanceof DiscoverSiteContactsCommand => $this->run($command->siteId, [
                'steps' => ['detect_capability', 'sync_site_profile', 'finalize'],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof RefreshSiteSnapshotCommand => $this->run($command->siteId, [
                'mode' => SiteSyncSchema::MODE_SNAPSHOT,
                'force_snapshot' => true,
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof ResumeSiteSyncCommand => $this->mapOrchestrator(
                $this->isV3Run($command->runId)
                    ? $this->orchestratorV3()->resume($command->runId)
                    : $this->orchestrator()->resume($command->runId)
            ),
            $command instanceof RetrySiteSyncStepCommand => $this->mapOrchestrator(
                $this->isV3Run($command->runId)
                    ? $this->orchestratorV3()->resume($command->runId)
                    : $this->orchestrator()->retryStep($command->runId, $command->stepKey)
            ),
            $command instanceof CancelSiteSyncCommand => $this->mapOrchestrator(
                $this->isV3Run($command->runId)
                    ? $this->orchestratorV3()->cancel($command->runId)
                    : $this->orchestrator()->cancel($command->runId)
            ),
            $command instanceof ReconcileSiteSyncCommand => $this->reconcile($command),
            $command instanceof RequeueSiteSyncInboundEventCommand => $this->requeue($command),
            default => ContentProjectActionResult::fail('site.unsupported', 'Unsupported site sync command.'),
        };
    }

    private function previewBootstrap(PreviewBootstrapSiteSyncCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $preview = $this->bootstrap()->preview($site);

        return ContentProjectActionResult::ok('site.preview_bootstrap_ok', 'Bootstrap preview', metadata: $preview);
    }

    private function startLinkHealth(int $siteId): ContentProjectActionResult
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        $run = app(\Omnichannel\Addons\SiteSync\Services\LinkHealth\LinkHealthRunService::class)->start($site);

        return ContentProjectActionResult::ok(
            'site.link_health_queued',
            'Link health run queued.',
            metadata: [
                'run_id' => (int) $run->id,
                'status' => (string) $run->status,
                'site_id' => (int) $site->id,
            ],
        );
    }

    private function doBootstrap(BootstrapSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        return $this->mapOrchestrator($this->bootstrap()->start($site, [
            'force' => $command->force,
            'trigger_source' => 'command_bus',
            'triggered_by' => $actor->actorId,
        ]));
    }

    private function doBackfill(BackfillSiteSyncV2Command $command, ActorContext $actor): ContentProjectActionResult
    {
        if ($command->force && $actor->actorType !== 'user' && $actor->actorType !== 'system') {
            return ContentProjectActionResult::fail('site.backfill_forbidden', 'Force backfill requires admin user.');
        }
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $report = $this->backfill()->run(
            $site,
            $command->only,
            $command->dryRun,
            $command->batch,
            $command->resumeId,
        );

        return ContentProjectActionResult::ok(
            'site.backfill_ok',
            $command->dryRun ? 'Backfill dry-run report' : 'Backfill applied',
            metadata: $report,
        );
    }

    private function doHandshake(ValidateSiteSyncHandshakeCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->handshake()->validate($site);

        return ContentProjectActionResult::ok('site.handshake_ok', (string) $result['message'], metadata: $result);
    }

    private function doDiagnostic(GenerateSiteSyncDiagnosticCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        return ContentProjectActionResult::ok(
            'site.diagnostic_ok',
            'Diagnostic report (readonly)',
            metadata: $this->diagnostic()->generate($site),
        );
    }

    private function acceptSuggestion(AcceptSiteProfileSuggestionCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->profileSuggestions()->accept($site, $command->suggestionHash);
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail('site.suggestion_missing', (string) ($result['message'] ?? ''));
        }

        return ContentProjectActionResult::ok('site.suggestion_accepted', (string) $result['message'], metadata: $result);
    }

    private function rejectSuggestion(RejectSiteProfileSuggestionCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->profileSuggestions()->reject($site, $command->suggestionHash);

        return ContentProjectActionResult::ok('site.suggestion_rejected', (string) $result['message'], metadata: $result);
    }

    private function forceFull(ForceFullSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        return $this->run($command->siteId, [
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'force_full' => true,
            'supersede_active' => $command->supersedeActive,
            'trigger_source' => 'ui',
            'triggered_by' => $actor->actorId,
            'meta' => array_filter([
                'operation_id' => $command->operationId,
                'idempotency_key' => $command->idempotencyKey,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ]);
    }

    private function queueMissingScores(QueueMissingSeoScoresCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $result = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->queueMissingOrStaleForSite($command->siteId, [
                'run_id' => $command->runId,
                'operation_id' => $command->operationId,
            ]);

        return ContentProjectActionResult::ok(
            'site.score_missing_ok',
            sprintf('Đã xếp hàng chấm SEO: %d bài.', (int) ($result['queued'] ?? 0)),
            metadata: $result,
        );
    }

    private function retryFailedScores(RetryFailedSeoScoresCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $result = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->queueFailedForSite($command->siteId);

        return ContentProjectActionResult::ok(
            'site.score_retry_ok',
            sprintf('Đã xếp hàng retry: %d bài.', (int) ($result['queued'] ?? 0)),
            metadata: $result,
        );
    }

    private function requeueAllScores(RequeueAllSeoScoresCommand $command): ContentProjectActionResult
    {
        if (! $command->confirmed) {
            return ContentProjectActionResult::fail(
                'site.score_requeue_needs_confirm',
                'Cần xác nhận trước khi chấm lại toàn bộ bài viết.',
            );
        }
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $preview = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->domainProgress($command->siteId);
        $result = app(\Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService::class)
            ->queueAllForSite($command->siteId);

        return ContentProjectActionResult::ok(
            'site.score_requeue_all_ok',
            sprintf(
                'Đã xếp hàng chấm lại toàn bộ: %d/%d bài (Workspace score only).',
                (int) ($result['queued'] ?? 0),
                (int) ($preview['total'] ?? 0),
            ),
            metadata: array_merge($result, ['preview_total' => (int) ($preview['total'] ?? 0)]),
        );
    }

    private function runSmart(RunSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        // Priority: force_full → bootstrap (never synced) → incremental.
        // Agent default mode is delta — never auto-promote to force_full.
        if ($command->mode === SiteSyncSchema::MODE_FORCE_FULL) {
            return $this->run($command->siteId, [
                'mode' => SiteSyncSchema::MODE_FORCE_FULL,
                'force_full' => true,
                'supersede_active' => true,
                'force_snapshot' => $command->forceSnapshot,
                'steps' => $command->steps,
                'trigger_source' => 'agent_explicit_force_full',
                'triggered_by' => $actor->actorId,
            ]);
        }

        if ($this->bootstrap()->needsBootstrap($site)) {
            return $this->mapOrchestrator($this->bootstrap()->start($site, [
                'trigger_source' => 'agent_auto_bootstrap',
                'triggered_by' => $actor->actorId,
                'force' => $command->forceSnapshot,
            ]));
        }

        return $this->run($command->siteId, [
            'mode' => $command->mode,
            'force_snapshot' => $command->forceSnapshot,
            'steps' => $command->steps,
            'trigger_source' => 'agent',
            'triggered_by' => $actor->actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function run(int $siteId, array $options): ContentProjectActionResult
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        if ($this->flags()->protocolV3Enabled()) {
            return $this->mapOrchestrator(app(SiteSyncProtocolRouter::class)->start($site, $options));
        }

        return $this->mapOrchestrator($this->orchestrator()->start($site, $options));
    }

    /**
     * @param  array{success: bool, message: string, run_id?: int, public_ref?: string}  $result
     */
    private function mapOrchestrator(array $result): ContentProjectActionResult
    {
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail(
                'site.sync_failed',
                (string) ($result['message'] ?? 'Site sync failed'),
                metadata: $result,
            );
        }

        return ContentProjectActionResult::ok(
            'site.sync_ok',
            (string) ($result['message'] ?? 'ok'),
            metadata: $result,
        );
    }

    private function reconcile(ReconcileSiteSyncCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->reconciliation()->reconcile($site, $command->mode);
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail('site.reconcile_failed', (string) ($result['message'] ?? ''), metadata: $result);
        }

        return ContentProjectActionResult::ok('site.reconcile_ok', (string) ($result['message'] ?? ''), metadata: $result);
    }

    private function requeue(RequeueSiteSyncInboundEventCommand $command): ContentProjectActionResult
    {
        $event = SeoSiteSyncInboundEvent::query()
            ->where('site_id', $command->siteId)
            ->whereKey($command->eventId)
            ->first();
        if ($event === null) {
            return ContentProjectActionResult::fail('site.event_not_found', 'Inbound event not found.');
        }
        $event->forceFill([
            'status' => SeoSiteSyncInboundEvent::STATUS_QUEUED,
            'retry_after' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        ProcessSiteSyncInboundEventJob::dispatch((int) $event->id);

        return ContentProjectActionResult::ok('site.requeue_ok', 'Inbound event requeued.');
    }
}
