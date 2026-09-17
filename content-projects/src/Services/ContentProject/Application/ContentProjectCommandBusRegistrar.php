<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddIdeaCandidatesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AcknowledgeProjectItemGenerationErrorCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ApproveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AutoScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BlockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CancelProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\DebugOverrideProjectItemLifecycleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReconcilePublishingQueueRemoteTasksCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\DismissSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\MoveProjectItemScheduleCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestartGenerationWithKeywordCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ResumeProjectItemFromFailedStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RetryProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RerunProjectItemStepCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ScheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SelectExistingArticleForProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipProjectItemPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StartReviewCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\StopProjectExecutionCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncContentProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnblockProjectItemGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UnscheduleProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SetItemGenerationKeywordOverrideCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddContentProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddIdeaCandidatesHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AcknowledgeProjectItemGenerationErrorHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ApproveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AutoScheduleProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\BlockProjectItemGenerationHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\CancelProjectItemPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ReconcilePublishingQueueRemoteTasksHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\CreateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\DismissSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\FillSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateNewContentSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestoreNewContentSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestoreSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RecoverStuckPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\DebugOverrideProjectItemLifecycleHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\MoveProjectItemScheduleHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\PublishProjectItemsNowHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestoreContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestartGenerationWithKeywordHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectExecutionHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RetryProjectItemPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RerunProjectItemStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ResumeProjectItemFromFailedStepHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ScheduleProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SendToPublishingQueueHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SelectExistingArticleForProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ReturnToContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SkipProjectItemPublishingHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SkipSeoAuditArticlesHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SplitDraftContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\StartReviewHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\StopProjectExecutionHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SyncContentProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UnblockProjectItemGenerationHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UnscheduleProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SetItemGenerationKeywordOverrideHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
use App\Support\RuntimeLogger;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class ContentProjectCommandBusRegistrar
{
    public function __construct(private readonly Application $app) {}

    public function register(ContentProjectCommandBus $bus): void
    {
        $map = [
            CreateContentProjectCommand::class => CreateContentProjectHandler::class,
            UpdateContentProjectCommand::class => UpdateContentProjectHandler::class,
            SyncContentProjectItemsCommand::class => SyncContentProjectItemsHandler::class,
            AddContentProjectItemsCommand::class => AddContentProjectItemsHandler::class,
            AddIdeaCandidatesCommand::class => AddIdeaCandidatesHandler::class,
            AddSeoAuditSuggestionsCommand::class => AddSeoAuditSuggestionsHandler::class,
            FillSeoAuditSuggestionsCommand::class => FillSeoAuditSuggestionsHandler::class,
            GenerateNewContentSuggestionsCommand::class => GenerateNewContentSuggestionsHandler::class,
            DismissSeoAuditSuggestionsCommand::class => DismissSeoAuditSuggestionsHandler::class,
            RestoreSeoAuditSuggestionsCommand::class => RestoreSeoAuditSuggestionsHandler::class,
            RestoreNewContentSuggestionsCommand::class => RestoreNewContentSuggestionsHandler::class,
            SkipSeoAuditArticlesCommand::class => SkipSeoAuditArticlesHandler::class,
            SplitDraftContentProjectCommand::class => SplitDraftContentProjectHandler::class,
            UpdateContentProjectItemCommand::class => UpdateContentProjectItemHandler::class,
            SetItemGenerationKeywordOverrideCommand::class => SetItemGenerationKeywordOverrideHandler::class,
            GenerateProjectItemsCommand::class => GenerateProjectItemsHandler::class,
            RerunProjectItemsCommand::class => RerunProjectItemsHandler::class,
            RerunProjectItemStepCommand::class => RerunProjectItemStepHandler::class,
            RestartGenerationWithKeywordCommand::class => RestartGenerationWithKeywordHandler::class,
            ResumeProjectItemFromFailedStepCommand::class => ResumeProjectItemFromFailedStepHandler::class,
            AcknowledgeProjectItemGenerationErrorCommand::class => AcknowledgeProjectItemGenerationErrorHandler::class,
            SelectExistingArticleForProjectItemCommand::class => SelectExistingArticleForProjectItemHandler::class,
            BlockProjectItemGenerationCommand::class => BlockProjectItemGenerationHandler::class,
            UnblockProjectItemGenerationCommand::class => UnblockProjectItemGenerationHandler::class,
            StartReviewCommand::class => StartReviewHandler::class,
            ApproveProjectItemsCommand::class => ApproveProjectItemsHandler::class,
            ScheduleProjectItemsCommand::class => ScheduleProjectItemsHandler::class,
            AutoScheduleProjectItemsCommand::class => AutoScheduleProjectItemsHandler::class,
            SendToPublishingQueueCommand::class => SendToPublishingQueueHandler::class,
            ReturnToContentProjectCommand::class => ReturnToContentProjectHandler::class,
            UnscheduleProjectItemsCommand::class => UnscheduleProjectItemsHandler::class,
            MoveProjectItemScheduleCommand::class => MoveProjectItemScheduleHandler::class,
            PublishProjectItemsNowCommand::class => PublishProjectItemsNowHandler::class,
            ProcessScheduledProjectItemPublishCommand::class => ProcessScheduledProjectItemPublishHandler::class,
            StopProjectExecutionCommand::class => StopProjectExecutionHandler::class,
            ResumeProjectExecutionCommand::class => ResumeProjectExecutionHandler::class,
            RetryProjectItemPublishingCommand::class => RetryProjectItemPublishingHandler::class,
            SkipProjectItemPublishingCommand::class => SkipProjectItemPublishingHandler::class,
            CancelProjectItemPublishingCommand::class => CancelProjectItemPublishingHandler::class,
            ReconcilePublishingQueueRemoteTasksCommand::class => ReconcilePublishingQueueRemoteTasksHandler::class,
            RecoverStuckPublishingCommand::class => RecoverStuckPublishingHandler::class,
            DebugOverrideProjectItemLifecycleCommand::class => DebugOverrideProjectItemLifecycleHandler::class,
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncPublishedArticleToWordPressCommand::class
                => \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SyncPublishedArticleToWordPressHandler::class,
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\BulkResyncPublishedArticlesToWordPressCommand::class
                => \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\BulkResyncPublishedArticlesToWordPressHandler::class,
            ArchiveContentProjectCommand::class => ArchiveContentProjectHandler::class,
            ArchiveProjectItemsCommand::class => ArchiveProjectItemsHandler::class,
            RestoreContentProjectCommand::class => RestoreContentProjectHandler::class,

            // SERP Intelligence — additive.
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CreateSerpQueriesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\CreateSerpQueriesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\UpdateSerpQueryCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\UpdateSerpQueryHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ArchiveSerpQueriesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\ArchiveSerpQueriesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CollectSerpSnapshotsCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\CollectSerpSnapshotsHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\CancelSerpCollectionCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\CancelSerpCollectionHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ImportSerpSnapshotCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\ImportSerpSnapshotHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\AnalyzeSerpSnapshotHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\FetchSerpPageEvidenceHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ReanalyzeSerpPageEvidenceCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\ReanalyzeSerpPageEvidenceHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ReviewSerpContentGapCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\ReviewSerpContentGapHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AcceptSerpContentGapCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\AcceptSerpContentGapHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\IgnoreSerpContentGapCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\IgnoreSerpContentGapHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ResolveSerpContentGapCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\ResolveSerpContentGapHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AddSerpFeatureKeywordsCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers\AddSerpFeatureKeywordsHandler::class,

            // GSC Intelligence — additive Phase 5.
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateGscPropertyCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\CreateGscPropertyHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\UpdateGscPropertyHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PauseGscPropertyCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\PauseGscPropertyHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResumeGscPropertyCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\ResumeGscPropertyHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ArchiveGscPropertyCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\ArchiveGscPropertyHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\SyncGscPerformanceDataHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CancelGscSyncCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\CancelGscSyncHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\ImportGscPerformanceDataHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RepairGscDateRangeCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\RepairGscDateRangeHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscQueryCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\MapGscQueryHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscQueryCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\UnmapGscQueryHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\MapGscPageCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\MapGscPageHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\UnmapGscPageCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\UnmapGscPageHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RebuildGscAggregatesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\RebuildGscAggregatesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\DetectGscOpportunitiesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\DetectGscOpportunitiesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ApproveGscOpportunityCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\ApproveGscOpportunityHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\RejectGscOpportunityCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\RejectGscOpportunityHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\IgnoreGscOpportunityCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\IgnoreGscOpportunityHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ResolveGscOpportunityCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\ResolveGscOpportunityHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewCreateContentProjectFromGscOpportunitiesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\PreviewCreateContentProjectFromGscOpportunitiesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\CreateContentProjectFromGscOpportunitiesCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\CreateContentProjectFromGscOpportunitiesHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexWithGscCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\InspectArticleIndexWithGscHandler::class,
            \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexesWithGscCommand::class => \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers\InspectArticleIndexesWithGscHandler::class,

            // Site Sync V2 — additive; shared handler.
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RunSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ForceFullSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\QueueMissingSeoScoresCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RetryFailedSeoScoresCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueAllSeoScoresCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteKeywordsCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\SyncSiteLinksCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RunLinkHealthCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\DiscoverSiteContactsCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RefreshSiteSnapshotCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ResumeSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RetrySiteSyncStepCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\CancelSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ReconcileSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RequeueSiteSyncInboundEventCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewBootstrapSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\BootstrapSiteSyncCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\BackfillSiteSyncV2Command::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ValidateSiteSyncHandshakeCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncDiagnosticCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\AcceptSiteProfileSuggestionCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RejectSiteProfileSuggestionCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewSiteSyncCutoverCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\EnterSiteSyncShadowModeCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ExitSiteSyncShadowModeCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ActivateSiteSyncV2Command::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\RollbackSiteSyncToLegacyCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncComparisonReportCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewSiteSyncRepairCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
            \Omnichannel\Addons\SiteSync\Services\Application\Commands\ExecuteSiteSyncRepairCommand::class => \Omnichannel\Addons\SiteSync\Services\Application\Handlers\SiteSyncCutoverCommandHandler::class,
        ];

        foreach ($map as $command => $handler) {
            try {
                // Lazy proxy: tránh make() toàn bộ DI graph khi boot CommandBus
                // (Site Sync Cutover/Comparison từng Fatal giữa vòng register).
                $bus->register($command, new class($this->app, $handler) implements \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler
                {
                    /**
                     * @param  \Illuminate\Contracts\Foundation\Application  $app
                     * @param  class-string  $handlerClass
                     */
                    public function __construct(
                        private readonly mixed $app,
                        private readonly string $handlerClass,
                    ) {}

                    public function handle(
                        \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand $command,
                        \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext $actor,
                    ): \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult {
                        /** @var \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler $resolved */
                        $resolved = $this->app->make($this->handlerClass);

                        return $resolved->handle($command, $actor);
                    }
                });
            } catch (Throwable $e) {
                // One broken additive handler (KI/SERP/GSC/…) must not kill
                // Content Project publish scheduler DI (seo:publish-scheduled-articles).
                RuntimeLogger::warning('content_project_command_bus_handler_skipped', [
                    'command' => $command,
                    'handler' => $handler,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
