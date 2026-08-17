<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use Omnichannel\Addons\AiPrompt\Console\BackfillPromptResultLinksCommand;
use Omnichannel\Addons\SearchFoundation\Console\CleanCtaKeywordsCommand;
use Omnichannel\Addons\Content\Console\ExtractOldArticleTocsCommand;
use Omnichannel\Addons\Publishing\Console\PublishScheduledArticlesCommand;
use Omnichannel\Addons\SearchFoundation\Console\AuditSeoDatabaseConnectionsCommand;
use Omnichannel\Addons\Publishing\Console\QueueRuntimeCheckCommand;
use Omnichannel\Addons\SearchFoundation\Http\Middleware\SetDynamicSeoDatabase;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\Content\Observers\SeoArticleObserver;
use Omnichannel\Addons\ContentProjects\Observers\SeoProjectObserver;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
use App\Contracts\DeclaresDatabaseTableOwnership;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * COMPATIBILITY BOOTSTRAP ONLY.
 *
 * Registers routes, views, schedule, and container bindings for code that now
 * lives under peer addons (`addons/{content,seo,media,wordpress,publishing,
 * content-projects,search-intelligence,ai-prompt}`, plus related peers).
 * Do not add new domain ownership here — see README.md and
 * docs/architecture/SEOCONTENTAI_CUTOVER_INVENTORY.json.
 */
class SeoContentAiServiceProvider extends ServiceProvider implements DeclaresDatabaseTableOwnership
{
    public const DB_CONNECTION = 'omi_seo_ai';

    private static bool $booted = false;

    public function register(): void
    {
        $this->app->singleton(PromptMediaStorageService::class);
        $this->app->singleton(SeoDatabaseConnectionService::class);
        $this->app->singleton(\Omnichannel\Addons\Content\Support\ArticleFeaturedImageResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection::class);
        $this->app->singleton(\Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseBackupService::class);
        $this->app->singleton(TeamChatAttachmentService::class);

        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Entities\PromptHookEntityResolverRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader::class, function (): \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader {
            $failFast = (bool) $this->app->environment(['local', 'testing']);

            return new \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader(
                \Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader::defaultDirectory(),
                $failFast,
            );
        });
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader::class, function () {
            return new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader(
                \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultV01Directory(),
                \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader::defaultPhase1Directory(),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptBudgetStore::class, \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptBudgetStore::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBudgetGuard::class, function ($app): \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookBudgetGuard {
            return new \Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\InMemoryPromptHookBudgetGuard(
                $app->make(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptBudgetStore::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptCostEstimator::class, \Omnichannel\Addons\AiPrompt\PromptHooks\Provider\ConfigPromptCostEstimator::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderUsageNormalizer::class);
        $this->app->singleton(
            \Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderAdapter::class,
            \Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptRunnerProviderAdapter::class,
        );
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptProviderCapabilityResolver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Output\PromptHookRuntimeOutputPipeline::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookAuditRecorder::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEnvelopeValidator::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeLocaleResolver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookLiveShadowGate::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionThresholds::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookModeTransitionPolicy::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRollbackPolicy::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionGate::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeEngine::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog::class, function (): \Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog {
            return new \Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog(
                \Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog::defaultDirectory(),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptHookRuntime::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptsDoctorService::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookUiFailureMapper::class);

        $this->app->bind(
            \Omnichannel\Addons\Seo\Contracts\ProductGalleryParentChildAiPort::class,
            function ($app) {
                if (\Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature::enabled()) {
                    return $app->make(\Omnichannel\Addons\Commerce\Services\ProductGallery\GeminiProductGalleryParentChildAiAdapter::class);
                }

                return $app->make(\Omnichannel\Addons\Commerce\Services\ProductGallery\NullProductGalleryParentChildAiPort::class);
            },
        );
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ImageProviderCapabilityResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryGenerationModeResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryModeOrchestrator::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryReferenceImageResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryParentChildDispatchService::class);
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPlanParser::class, function () {
            return \Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPlanParser::fromConfig();
        });
        $this->app->singleton(\Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGallerySerialChildLoop::class);
        $this->app->singleton(\Omnichannel\Addons\Seo\Contracts\PromptResultAttacher::class, \Omnichannel\Addons\AiPrompt\Services\PromptResultAttachService::class);
        $this->app->singleton(
            \Omnichannel\Addons\Seo\Contracts\SeoProjectWorkflowStepCatalogContract::class,
            \Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepCatalogService::class,
        );
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\PromptResultAttachService::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService::class);

        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper::class);
        $this->app->singleton(
            \Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEventPublisher::class,
            \Omnichannel\Addons\ContentProjects\Services\RunEngine\LoggingContentProjectRunEventPublisher::class,
        );
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\RunEngine\RunCancellationGuard::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectTaskExecutionService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectArticleRunner::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine::class);

        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectWorkspaceSaveService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionStalenessPolicy::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationRecoveryService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDashboardStatsService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTimelineService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner::class);
        $this->app->singleton(\Omnichannel\Addons\Publishing\Services\Publishing\PublishDueItemService::class);
        $this->app->singleton(\Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisherRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Publishing\Application\Publishing\PublisherResolver::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessAuditor::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsDashboardService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectCommandBusMonitorService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAiCostAggregateService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectPublishAnalyticsService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectErrorCenterService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsHealthService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectSiteHealthService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectDailyReportService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsReplayService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectWpAdapterMetricsService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectAuditSearchService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Quotas\ContentProjectQuotaGuard::class);

        // Keyword Intelligence — services + application layer.
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNormalizationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordIntentClassifier::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordScoringService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordManualOverrideGuard::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordCandidateBucketer::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDuplicateResolver::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordNearDuplicateDetector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordExistingContentIndex::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterPrimaryKeywordSelector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterValidator::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterMutationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordImportService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordExistingContentMapper::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordCannibalizationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordWorkspaceAnalysisLock::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapBuildLock::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapHierarchyValidator::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalCoverageService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalInternalLinkSuggestionService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapConflictDetector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapVersionDiffService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterContentActionResolver::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\PillarTopicSelector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapBuilder::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapMutationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapToContentProjectConverter::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordWorkspaceAnalysisService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordToContentProjectConverter::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Agent\KeywordIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpSnapshotPersistService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpImportSnapshotService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionOperationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpEvidenceApplyService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Agent\SerpIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry::class, function ($app): \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry {
            $registry = new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderRegistry;
            $registry->register($app->make(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\ManualImportSerpProvider::class));
            $registry->register(new \Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers\FakeLocalSerpProvider);

            return $registry;
        });
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpProviderResolver::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryNormalizationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageNormalizationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscFactHashService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscImportPreviewService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncLockService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncDateRangeService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscDailyMetricPersistService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSuggestedMappingPersistService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPageArticleMapper::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryKeywordMapper::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscBrandQueryClassifier::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPerformanceAggregationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscExpectedCtrModel::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityDetectionService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscQueryCannibalizationDetector::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\SerpGscEvidenceReconciler::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscContentActionRecommendationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscContentProjectPreviewBuilder::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscManualImportService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProjectItemPerformanceDeriver::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscKeywordWorkspaceQueryPreviewService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncOperationService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscManualImportService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscOpportunityContentProjectConverter::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Agent\GscIntelligenceReadService::class);
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry::class, function ($app): \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry {
            $registry = new \Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts\GscIntelligenceProviderRegistry;
            $registry->register($app->make(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\ManualImportGscProvider::class));
            $registry->register($app->make(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Providers\FakeLocalGscProvider::class));

            return $registry;
        });
        $this->app->singleton(\Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscProviderResolver::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry::class, function ($app): \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry {
            return new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry(
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\ExtensionStateStore::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectReadModelService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus::class, function ($app) {
            $bus = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus(
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessAuditor::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger::class),
            );
            $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar::class)->register($bus);

            return $bus;
        });
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentSchemaValidator::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentRateLimiter::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentSessionService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentGateway::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCache::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackEventEmitter::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackDiscoveryService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackLoader::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackStateService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCompatibilityService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackManifestValidator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackSafeSchemaValidator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackSafeMappingValidator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCapabilityBinder::class, function ($app) {
            $caps = $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry::class);

            return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCapabilityBinder(
                static fn (string $name): ?array => $caps->get($name),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCompiler::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackOrchestrator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackImportExportService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentCapabilityCoverageAuditService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentV1ReadinessService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentSkillGroupCatalog::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry::class, function ($app) {
            return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry(
                null,
                $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentChatTemplateRegistry::class, function ($app) {
            return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentChatTemplateRegistry(
                null,
                $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceQuotaService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentExecutionPlanService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentIntentRouter::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRecommendationService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentConversationService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillInputResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentErrorPresentation::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceContextService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionStateMachine::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentConfirmationTokenService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionIdempotencyFactory::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionContextUpdater::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanOutputBinder::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering\AgentResultRendererRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanStepRunner::class);

        // Phase 6 — Observability / evaluation / governance (side-channel)
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityRedactor::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityEventBus::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPlanningVersionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricAggregator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentCostUsageTracker::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentGovernancePolicyService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentReviewService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentFeedbackService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPolicyViolationDetector::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentRetentionService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityExportService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentOperationsDashboardService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentPlanningEvaluator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentExecutionOutcomeEvaluator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentGroundingEvaluator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentAutomationHealthEvaluator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentQualityGateService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner::class);

        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionOrchestrator::class,
            function ($app) {
                $inner = $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\DefaultAgentExecutionOrchestrator::class);

                return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentExecutionOrchestrator(
                    $inner,
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder::class),
                );
            },
        );
        // Phase 3 — AI planning / guarded copilot
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentUntrustedContentMarker::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningInputSanitizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningOutputSanitizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentContextBudgetManager::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentSkillCatalogPresenter::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentPlanningContextAssembler::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DeterministicAgentPlanRepairer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentPlanningPersistence::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanValidator::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentPlanValidator::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelRouter::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\RegistryAgentModelRouter::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelGateway::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\ProviderAgentModelGateway::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentConversationSummarizer::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentConversationSummarizer::class,
        );
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentPlanningOrchestrator::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanningOrchestrator::class,
            function ($app) {
                return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentPlanningOrchestrator(
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentPlanningOrchestrator::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPolicyViolationDetector::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentCostUsageTracker::class),
                );
            },
        );

        // Phase 4 — scoped knowledge & memory
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security\AgentKnowledgeContentSanitizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeChunker::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeConflictResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeFreshnessService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeCitationPresenter::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryCandidateExtractor::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryProposalService::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeSourceRegistry::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeSourceRegistry::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRepository::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\EloquentAgentKnowledgeRepository::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeIndex::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DatabaseAgentKnowledgeIndex::class,
        );
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeRetriever::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeRetriever::class,
            function ($app) {
                return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentKnowledgeRetriever(
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeRetriever::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder::class),
                );
            },
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentGroundingContextProvider::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentGroundingContextProvider::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeOrchestrator::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeOrchestrator::class,
        );

        // Phase 5 — Agent Workspace scheduled automations / proactive monitoring
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationQuotaService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationLockService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationRunStateMachine::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationApprovalTokenService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDefinitionValidator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\AgentAutomationDispatcher::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationScheduleResolver::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationScheduleResolver::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationConditionEvaluator::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationConditionEvaluator::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRepository::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\EloquentAgentAutomationRepository::class,
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationNotificationService::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationNotificationService::class,
        );
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationRunner::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRunner::class,
            function ($app) {
                return new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators\ObservingAgentAutomationRunner(
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationRunner::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService::class),
                    $app->make(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder::class),
                );
            },
        );
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationOrchestrator::class,
            \Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationOrchestrator::class,
        );

        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentCapabilityDiagnosticsService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectPlanTemplateRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\RuleBasedContentProjectPlanGenerator::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\LlmContentProjectPlanGenerator::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectCanonicalPlanValidator::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAutomationPolicyService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentConditionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentBudgetGuard::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanLock::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentApprovalService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanRevalidator::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanner::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanApplicationService::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanExecutor::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanGateway::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\McpCapabilityMarkdownPresenter::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpServer::class);
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry::class, function ($app) {
            return new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry([
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\ExecutionWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PromptWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\RuntimeWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\LocalMediaWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\GalleryExecutionWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\EditorRevisionWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PendingArtifactsWorkspaceCleaner::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\CacheLockWorkspaceCleaner::class),
            ]);
        });
        $this->app->singleton(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer::class);

        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher::class, \Omnichannel\Addons\Agent\Automation\BusinessHook\Events\BridgingAutomationEventDispatcher::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor::class);

        // Business Hook / Automation Rule Engine
        $this->mergeConfigFrom(__DIR__.'/config/automation-modules.php', 'seo-content-ai.automation_modules');
        $this->mergeConfigFrom(__DIR__.'/config/content_project_agent.php', 'seo-content-ai.content_project_agent');
        $this->mergeConfigFrom(__DIR__.'/config/extension_sdk.php', 'seo-content-ai.extension_sdk');
        $this->mergeConfigFrom(__DIR__.'/config/seo_architecture.php', 'seo-content-ai.seo_architecture');
        $this->mergeConfigFrom(__DIR__.'/config/keyword_intelligence.php', 'seo-content-ai.keyword_intelligence');
        $this->mergeConfigFrom(__DIR__.'/config/gsc_intelligence.php', 'seo-content-ai.gsc_intelligence');
        $this->mergeConfigFrom(__DIR__.'/config/article_editor.php', 'seo-content-ai.article_editor');
        $this->mergeConfigFrom(__DIR__.'/config/article_list.php', 'seo-content-ai.article_list');
        $this->registerExtensionSdk();
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationMenuRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationPermissionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationSettingsRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleRegistry::class, function ($app): \Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleRegistry {
            return \Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleRegistry::fromConfig($app);
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Platform\AutomationPlatformKernel::class, function ($app): \Omnichannel\Addons\Agent\Automation\Platform\AutomationPlatformKernel {
            return \Omnichannel\Addons\Agent\Automation\Platform\AutomationPlatformKernel::bootOnce($app);
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry::class, function ($app): \Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry {
            return $app->make(\Omnichannel\Addons\Agent\Automation\Platform\AutomationPlatformKernel::class)->context->events;
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry::class, function ($app): \Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry {
            return $app->make(\Omnichannel\Addons\Agent\Automation\Platform\AutomationPlatformKernel::class)->context->actions;
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine::class, function ($app): \Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine {
            return new \Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine(
                $app->make(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper::class),
                $app->make(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventBootstrap::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionBootstrap::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotSanitizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotRedactor::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSubjectLoader::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectGuard::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectLedger::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class);

        config([
            'logging.channels.wordpress-side-effect' => [
                'driver' => 'single',
                'path' => storage_path('logs/wordpress-side-effect.log'),
                'level' => 'info',
                'replace_placeholders' => true,
            ],
        ]);

        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleMatcher::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSettingsService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\ExecutionCleanupService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphValidator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationGraphEdgeResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConcurrencyGuard::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationRateLimitGuard::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\LinearRuleGraphAdapter::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphRuleService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphExecutionService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationVersionService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationWorkflowTestService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationImportExportService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationHealthService::class, function ($app): \Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationHealthService {
            return new \Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationHealthService(
                $app->make(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService::class),
                $app->make(\Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleVersionMigrationService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationStaleRecoveryService::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Runtime\ActionExecutionLogger::class);
        $this->app->bind(
            \Omnichannel\Addons\Agent\Automation\Contracts\ActionExecutionLoggerContract::class,
            \Omnichannel\Addons\Agent\Automation\Runtime\ActionExecutionLogger::class,
        );
        $this->app->bind(
            \Omnichannel\Addons\Content\Contracts\SeoCreateArticleSettingsReader::class,
            \Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class,
        );
        $this->app->bind(
            \Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook::class,
            \Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver::class,
        );
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Runtime\AutomationSiteContextResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Registry\ActionCatalogBootstrap::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry::class, function ($app): \Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry {
            $registry = new \Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry($app);
            $app->make(\Omnichannel\Addons\Agent\Automation\Registry\ActionCatalogBootstrap::class)->register($registry);
            $app->make(\Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar::class)->register($registry);

            return $registry;
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner::class);
        $this->app->singleton(
            \Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher::class,
            \Omnichannel\Addons\Agent\Automation\Runtime\CatalogBusinessActionDispatcher::class,
        );
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AutomationParitySampleRecorder::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AutomationParityLogger::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AutomationCallerMigrator::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ParitySnapshotNormalizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ArticleActionOutputNormalizer::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AutomationActionPromotionGate::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\AssignmentCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ProjectTaskCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleCreateParityPlanner::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleContentUpdateParityPlanner::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\Planners\ArticleSeoMetaUpdateParityPlanner::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleCreateCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Support\ArticleCreateOriginResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard::class);

        // Đăng ký console ở register() — không phụ thuộc $booted guard trong boot().
        if ($this->app->runningInConsole()) {
            $commands = [
                BackfillPromptResultLinksCommand::class,
                CleanCtaKeywordsCommand::class,
                ExtractOldArticleTocsCommand::class,
                PublishScheduledArticlesCommand::class,
                \Omnichannel\Addons\Publishing\Console\ReconcileStuckPublishingCommand::class,
                \Omnichannel\Addons\Publishing\Console\ReconcilePublishingQueueTasksCommand::class,
                AuditSeoDatabaseConnectionsCommand::class,
                \Omnichannel\Addons\SiteSync\Console\RunSiteSyncCommand::class,
                \Omnichannel\Addons\SiteSync\Console\RunLinkHealthCommand::class,
                \Omnichannel\Addons\SiteSync\Console\RunLinkAnalysisCommand::class,
                \Omnichannel\Addons\SiteSync\Console\PollWordPressHeartbeatCommand::class,
                \Omnichannel\Addons\SearchIntelligence\Console\ClassifyKeywordsCommand::class,
                \Omnichannel\Addons\SearchIntelligence\Console\KeywordIntelligenceReportCommand::class,
                \Omnichannel\Addons\SiteSync\Console\ReconcileSiteSyncCommand::class,
                \Omnichannel\Addons\SiteSync\Console\BackfillSiteSyncV2Command::class,
                \Omnichannel\Addons\Seo\Console\BackfillSiteManualLinksCommand::class,
                \Omnichannel\Addons\AiPrompt\Console\ClearPromptHookDefinitionCacheCommand::class,
                \Omnichannel\Addons\AiPrompt\Console\PromptHookStatusCommand::class,
                \Omnichannel\Addons\AiPrompt\Console\PromptHookParityReportCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\BackfillContentProjectRunItemsCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectArchiveCommand::class,
                \Omnichannel\Addons\SiteSync\Console\DiagnoseContentProjectSyncCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\ContentProjectRunStatusCommand::class,
                QueueRuntimeCheckCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\ContentProjectRunRecoverCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairContentProjectActiveExecutionsCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RecoverContentProjectStaleGenerationCommand::class,
                \Omnichannel\Addons\Media\Console\ProductGalleryParentChildCanaryCommand::class,
                \Omnichannel\Addons\Media\Console\ProductGalleryPromptsDoctorCommand::class,
                \Omnichannel\Addons\Media\Console\InstallDefaultProductGalleryPromptsCommand::class,
                \Omnichannel\Addons\Media\Console\ProductGalleryCanaryFixtureCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairContentProjectCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairContentProjectSiteLinksCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\DiagnoseContentProjectTaskHistoryCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RecoverLegacyContentProjectTaskCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairLegacyContentProjectGenerationCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\CleanupContentProjectAgentSessionsCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\CleanupContentProjectAgentPlansCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairContentProjectMonthDriftCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationListEventsCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationListActionsCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationDispatchCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\AutomationRunRuleCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationRetryCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationDiagnoseCommand::class,
                \Omnichannel\Addons\WordPress\Console\AutomationAuditWordpressCouplingCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationAuditCouplingCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationAuditDirectBusinessActionsCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationAuditEntryPointsCommand::class,
                \Omnichannel\Addons\WordPress\Console\QueueInspectWordpressCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationSeedRulesCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationDisableAllRulesCommand::class,
                \Omnichannel\Addons\WordPress\Console\AutomationRepairWordpressExecutionsCommand::class,
                \Omnichannel\Addons\Content\Console\NormalizeArticleInlineLinksCommand::class,
                \Omnichannel\Addons\WordPress\Console\ProductReviewsAuditWordpressStatusCommand::class,
                \Omnichannel\Addons\Publishing\Console\ProductReviewsQueuePendingCommand::class,
                \Omnichannel\Addons\Commerce\Console\ProductReviewsReconcilePendingCommand::class,
                \Omnichannel\Addons\Commerce\Console\ProductReviewsDiagnoseStuckCommand::class,
                \Omnichannel\Addons\SearchFoundation\Console\ProductReviewsMigrateLegacyMetaCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationMigrateCommand::class,
                \Omnichannel\Addons\Publishing\Console\AutomationDispatchScheduledCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationRecoverStaleCommand::class,
                \Omnichannel\Addons\SearchIntelligence\Console\AutomationCleanupExecutionLogsCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationMigrateLinearToGraphCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationMigrateRuleVersionsCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationExportCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationImportCommand::class,
                \Omnichannel\Addons\Agent\Console\AutomationHealthCommand::class,
                \Omnichannel\Addons\WordPress\Console\WordpressSyncLeaseWatchdogCommand::class,
                \Omnichannel\Addons\Content\Console\MigrateSeoArticleReviewsCommand::class,
                \Omnichannel\Addons\Commerce\Console\ReportIsReviewedCutoverCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\ReportSeoProjectTaskStatusCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\RepairArchivedArticleActiveTasksCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\AssignWorkflowExecutionRolesCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\WorkflowDoctorCommand::class,
                \Omnichannel\Addons\AiPrompt\Console\InstallDefaultImprovePromptCommand::class,
                \Omnichannel\Addons\AiPrompt\Console\EnsureOpenRouterTextRoutingCommand::class,
                \Omnichannel\Addons\Content\Console\ArticleEditorDocumentBackfillCommand::class,
                \Omnichannel\Addons\Media\Console\BackfillArticleFeaturedImageProjectionCommand::class,
                \Omnichannel\Addons\Content\Console\ArticleMetaAuditCommand::class,
                \Omnichannel\Addons\Content\Console\ArticleMetaCleanupCommand::class,
                \Omnichannel\Addons\Seo\Console\ReconcileActiveOperationalNotificationsCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\CheckOperationalRunnerHealthCommand::class,
                \Omnichannel\Addons\Publishing\Console\RequeueOverduePublishingCommand::class,
                \Omnichannel\Addons\Publishing\Console\RepairUnprojectedPublishingCommand::class,
            ];

            // Agent Workspace commands — optional so partial deploy never kills publish cron (exit 255).
            foreach ([
                \Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand::class,
                \Omnichannel\Addons\Agent\Console\AgentEvaluateCommand::class,
                \Omnichannel\Addons\Agent\Console\InstallBuiltinAgentEvaluationsCommand::class,
                \Omnichannel\Addons\Agent\Console\AgentCapabilitiesAuditCommand::class,
                \Omnichannel\Addons\Agent\Console\AgentV1DoctorCommand::class,
                \Omnichannel\Addons\Agent\Console\AgentMetricsAggregateCommand::class,
                \Omnichannel\Addons\ContentProjects\Console\AgentObservabilityPruneCommand::class,
            ] as $optionalCommand) {
                if (class_exists($optionalCommand)) {
                    $commands[] = $optionalCommand;
                }
            }

            $this->commands($commands);
        }
    }

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $this->loadViewsFrom(__DIR__.'/resources/views', 'seo-content-ai');
        // Override Filament sidebar item: caret expand/collapse cho nested parent (v3 không có sẵn).
        \Illuminate\Support\Facades\View::prependNamespace(
            'filament-panels',
            __DIR__.'/resources/views/overrides/filament-panels',
        );
        // Migrations owned by peer addons — loaded via ClientCore AddonMigrationRegistrar.
        // Keep empty directory for legacy path references / tooling.
        $this->bootstrapDefaultSeoConnection();

        \Omnichannel\Addons\SearchFoundation\Models\Keyword::observe(
            \Omnichannel\Addons\SearchFoundation\Observers\KeywordLinkListSyncObserver::class,
        );
        SeoProject::observe(SeoProjectObserver::class);
        SeoArticle::observe(SeoArticleObserver::class);

        $this->app->booted(function (): void {
            /** @var Router $router */
            $router = $this->app->make(Router::class);
            $router->pushMiddlewareToGroup('web', SetDynamicSeoDatabase::class);

            $schedule = app(Schedule::class);
            $name = 'seo-content-ai:cleanup-old-notifications';
            $cleanupRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $name);
            if (! $cleanupRegistered) {
                $schedule
                    ->call(static fn (): int => DatabaseNotification::query()
                        ->where('created_at', '<', now()->startOfMonth())
                        ->delete())
                    ->monthlyOn(1, '00:10')
                    ->name($name)
                    ->withoutOverlapping();
            }

            // Sole scheduled publishing dispatcher (canonical CP queue + legacy non-project).
            $publishScheduledName = 'seo-content-ai:publish-scheduled-articles';
            $publishScheduledRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $publishScheduledName);
            if (! $publishScheduledRegistered) {
                $schedule
                    ->command(PublishScheduledArticlesCommand::class)
                    ->everyMinute()
                    ->name($publishScheduledName)
                    ->withoutOverlapping();
            }

            $siteSyncReconcileName = 'seo-content-ai:site-sync-reconcile-quick';
            $siteSyncReconcileRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $siteSyncReconcileName);
            if (! $siteSyncReconcileRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\SiteSync\Console\ReconcileSiteSyncCommand::class, ['--mode' => 'quick', '--limit' => 30])
                    ->hourly()
                    ->name($siteSyncReconcileName)
                    ->withoutOverlapping(50);
            }

            $heartbeatName = 'seo-content-ai:wp-heartbeat-poll';
            $heartbeatRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $heartbeatName);
            if (! $heartbeatRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\SiteSync\Console\PollWordPressHeartbeatCommand::class, ['--limit' => 40])
                    ->everyThirtyMinutes()
                    ->name($heartbeatName)
                    ->withoutOverlapping(25);
            }

            // Three automation owners — distinct tables, must not claim same occurrence:
            // 1) automation:dispatch-scheduled → automation_rules (Business Hook)
            // 2) agent:automations:dispatch-due → seo_agent_automations (Agent Workspace)
            // 3) seo-content-ai:dispatch-automation-policies → seo_content_project_automation_policies (CP plans)
            $automationScheduleName = 'seo-content-ai:automation-dispatch-scheduled';
            $automationScheduleRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationScheduleName);
            if (! $automationScheduleRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\Publishing\Console\AutomationDispatchScheduledCommand::class)
                    ->everyMinute()
                    ->name($automationScheduleName)
                    ->withoutOverlapping();
            }

            $agentAutomationDispatchName = 'seo-content-ai:agent-automations-dispatch-due';
            $agentAutomationDispatchRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $agentAutomationDispatchName);
            if (
                ! $agentAutomationDispatchRegistered
                && class_exists(\Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand::class)
            ) {
                $schedule
                    ->command(\Omnichannel\Addons\Agent\Console\DispatchDueAgentAutomationsCommand::class)
                    ->everyMinute()
                    ->name($agentAutomationDispatchName)
                    ->withoutOverlapping();
            }

            $agentMetricsAggName = 'seo-content-ai:agent-metrics-aggregate';
            $agentMetricsAggRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $agentMetricsAggName);
            if (
                ! $agentMetricsAggRegistered
                && class_exists(\Omnichannel\Addons\Agent\Console\AgentMetricsAggregateCommand::class)
            ) {
                // Flag options are VALUE_NONE — do not pass ['--sync' => true] (becomes --sync=1 and fails).
                $schedule
                    ->command('agent:metrics:aggregate --sync')
                    ->hourly()
                    ->name($agentMetricsAggName)
                    ->withoutOverlapping();
            }

            $agentObsPruneName = 'seo-content-ai:agent-observability-prune';
            $agentObsPruneRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $agentObsPruneName);
            if (
                ! $agentObsPruneRegistered
                && class_exists(\Omnichannel\Addons\ContentProjects\Console\AgentObservabilityPruneCommand::class)
            ) {
                $schedule
                    ->command('agent:observability:prune --sync')
                    ->dailyAt('03:40')
                    ->name($agentObsPruneName)
                    ->withoutOverlapping();
            }

            $automationRecoverName = 'seo-content-ai:automation-recover-stale';
            $automationRecoverRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationRecoverName);
            if (! $automationRecoverRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\Agent\Console\AutomationRecoverStaleCommand::class)
                    ->everyFiveMinutes()
                    ->name($automationRecoverName)
                    ->withoutOverlapping();
            }

            $cpStaleGenName = 'seo-content-ai:content-project-recover-stale-generation';
            $cpStaleGenRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $cpStaleGenName);
            if (
                ! $cpStaleGenRegistered
                && class_exists(\Omnichannel\Addons\ContentProjects\Console\RecoverContentProjectStaleGenerationCommand::class)
            ) {
                $schedule
                    ->command('seo:content-project:recover-stale-generation --apply')
                    ->everyTenMinutes()
                    ->name($cpStaleGenName)
                    ->withoutOverlapping();
            }

            $runnerHealthName = 'seo-content-ai:operational-runner-health';
            $runnerHealthRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $runnerHealthName);
            if (
                ! $runnerHealthRegistered
                && class_exists(\Omnichannel\Addons\ContentProjects\Console\CheckOperationalRunnerHealthCommand::class)
            ) {
                $schedule
                    ->command(\Omnichannel\Addons\ContentProjects\Console\CheckOperationalRunnerHealthCommand::class)
                    ->everyFiveMinutes()
                    ->name($runnerHealthName)
                    ->withoutOverlapping();
            }

            $automationCleanupName = 'seo-content-ai:automation-cleanup-execution-logs';
            $automationCleanupRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $automationCleanupName);
            if (! $automationCleanupRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\SearchIntelligence\Console\AutomationCleanupExecutionLogsCommand::class)
                    ->dailyAt('02:20')
                    ->name($automationCleanupName)
                    ->withoutOverlapping();
            }

            $wpSyncWatchdogName = 'seo-content-ai:wordpress-sync-lease-watchdog';
            $wpSyncWatchdogRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $wpSyncWatchdogName);
            if (! $wpSyncWatchdogRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\WordPress\Console\WordpressSyncLeaseWatchdogCommand::class)
                    ->everyMinute()
                    ->name($wpSyncWatchdogName)
                    ->withoutOverlapping();
            }

            $agentPlanCleanupName = 'seo-content-ai:cleanup-agent-plans';
            $agentPlanCleanupRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $agentPlanCleanupName);
            if (! $agentPlanCleanupRegistered) {
                $schedule
                    ->command(\Omnichannel\Addons\ContentProjects\Console\CleanupContentProjectAgentPlansCommand::class)
                    ->dailyAt('03:10')
                    ->name($agentPlanCleanupName)
                    ->withoutOverlapping();
            }

            $agentPolicyDispatchName = 'seo-content-ai:dispatch-automation-policies';
            $agentPolicyDispatchRegistered = collect($schedule->events())
                ->contains(static fn ($event): bool => $event->description === $agentPolicyDispatchName);
            if (! $agentPolicyDispatchRegistered) {
                // Schedule::job() second arg = queue (CallbackEvent has no onQueue()).
                $schedule
                    ->job(
                        new \Omnichannel\Addons\ContentProjects\Jobs\DispatchContentProjectAutomationPoliciesJob,
                        \Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName::Policy->value,
                    )
                    ->hourly()
                    ->name($agentPolicyDispatchName)
                    ->withoutOverlapping();
            }
        });

        $this->app->booted(function (): void {
            try {
                $discovery = $this->app->make(\Omnichannel\Addons\Agent\Extension\ExtensionDiscovery::class);
                $discovery->discoverAndRegister();
                $discovery->bootExtensions();
            } catch (\Throwable $exception) {
                // Extension SDK không được phá boot addon — nhưng phải surface nguyên nhân
                // (vd. type mismatch registry) thay vì nuốt im và để ai_provider.not_registered.
                \App\Support\RuntimeLogger::report($exception, [
                    'source' => 'SeoContentAiServiceProvider::bootExtensions',
                ]);
            }
        });
    }

    private function registerExtensionSdk(): void
    {
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionEventBus::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionStateStore::class);
        $this->app->singleton(\Omnichannel\Addons\Publishing\Extension\Registry\PublisherRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Seo\Extension\Registry\SeoProviderRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Registry\PipelineRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Registry\PromptHookExtensionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Media\Extension\Registry\MediaProcessorRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Registry\WorkflowExtensionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Registry\ContentPlatformRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionContext::class, function ($app): \Omnichannel\Addons\Agent\Extension\ExtensionContext {
            return new \Omnichannel\Addons\Agent\Extension\ExtensionContext(
                $app->make(\Omnichannel\Addons\Publishing\Extension\Registry\PublisherRegistry::class),
                $app->make(\Omnichannel\Addons\Publishing\Application\Publishing\ContentPublisherRegistry::class),
                $app->make(\Omnichannel\Addons\AiPrompt\Extension\Registry\AiProviderRegistry::class),
                $app->make(\Omnichannel\Addons\Seo\Extension\Registry\SeoProviderRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\Registry\PipelineRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry::class),
                $app->make(\Omnichannel\Addons\AiPrompt\Extension\Registry\PromptHookExtensionRegistry::class),
                $app->make(\Omnichannel\Addons\Media\Extension\Registry\MediaProcessorRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\Registry\WorkflowExtensionRegistry::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\ExtensionEventBus::class),
                $app->make(\Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry::class),
            );
        });
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionCompatibilityChecker::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionDiscovery::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\ExtensionHealthService::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Extension\WordPressPublisher::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Extension\WordpressPublisherDriver::class);
        $this->app->singleton(\Omnichannel\Addons\WordPress\Extension\WordpressExtensionProvider::class);

        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Resolvers\PipelineResolver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\Ai\GeminiGenerateContentClient::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\GeminiAiTextProvider::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\ClaudeAiTextProvider::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\DeepSeekAiTextProvider::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\AiProvidersHealthDriver::class);
        $this->app->singleton(\Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders\AiProvidersExtensionProvider::class);

        $this->app->singleton(\Omnichannel\Addons\Content\Extension\Builtin\ContentPipelines\Definitions\ArticlePipelineDefinition::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\RewritePipelineDefinition::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ImprovePipelineDefinition::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\TranslatePipelineDefinition::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\Definitions\ProductPipelineDefinition::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\ContentPipelinesHealthDriver::class);
        $this->app->singleton(\Omnichannel\Addons\Agent\Extension\Builtin\ContentPipelines\ContentPipelinesExtensionProvider::class);

        $this->app->singleton(\Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo\LocalSeoProvider::class);
        $this->app->singleton(\Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo\LocalSeoHealthDriver::class);
        $this->app->singleton(\Omnichannel\Addons\Seo\Extension\Builtin\LocalSeo\LocalSeoExtensionProvider::class);
    }

    private function bootstrapDefaultSeoConnection(): void
    {
        try {
            app(SeoDatabaseConnectionService::class)->bootstrapLegacySharedConnection();
        } catch (\Throwable $exception) {
            \App\Support\RuntimeLogger::report($exception, [
                'source' => 'SeoContentAiServiceProvider::bootstrapDefaultSeoConnection',
            ]);
        }
    }

    /**
     * Table thuộc connection `omi_seo_ai` (không gồm GSC/SERP credentials — những bảng đó ở core/mysql).
     *
     * @return array{connection: string, tables: list<string>, patterns: list<string>}
     */
    public function databaseTableOwnership(): array
    {
        return [
            'connection' => self::DB_CONNECTION,
            'tables' => [
                'articles',
                'article_keyword',
                'article_meta',
                'article_product_reviews',
                // Dropped / core-owned (do not list): entities, entity_results, tags,
                // seo_settings, seo_domain_metas, domain_global_cta_settings,
                // user_workspace_settings, seo_prompt_templates, seo_links, keyword_link,
                // seo_generated_images, seo_connection_sites, automation_*, business_events.
                'keyword_group_metric_snapshots',
                'keyword_meta',
                'keyword_rank_check_runs',
                'keyword_rank_snapshots',
                'keyword_review_histories',
                'keyword_review_reasons',
                'keyword_site_meta',
                'keyword_tag',
                'keyword_tags',
                'keywords',
                'prompt_parts',
                'prompt_results',
                'prompts',
                'seo_article_headings',
                'seo_article_links',
                'seo_article_reviews',
                'seo_article_revisions',
                'seo_article_wp_sync_jobs',
                'seo_content_archive_items',
                'seo_content_project_agent_sessions',
                'seo_content_project_agent_plans',
                'seo_content_project_agent_plan_steps',
                'seo_content_project_automation_policies',
                'seo_content_project_agent_approvals',
                'seo_content_project_business_audits',
                'seo_content_project_idempotency_keys',
                'seo_content_project_operations',
                'seo_content_project_ops_metrics',
                'seo_content_project_publish_attempts',
                'seo_extension_states',
                'seo_faqs',
                'seo_image_optimization_settings',
                'seo_keyword_analysis_operations',
                'seo_keyword_article_mappings',
                'seo_keyword_clusters',
                'seo_keyword_relationships',
                'seo_keyword_workspaces',
                'seo_keywords',
                'seo_link_audits',
                'seo_link_maps',
                'seo_media',
                'seo_media_meta',
                'seo_media_processing_histories',
                'seo_pending_internal_links',
                'seo_project_archive_items',
                'seo_project_archives',
                'seo_project_run_items',
                'seo_project_runs',
                'seo_project_task_events',
                'seo_project_tasks',
                'seo_projects',
                'seo_prompt_result_links',
                'seo_prompt_resultables',
                'seo_rank_keyword_group_items',
                'seo_rank_keyword_groups',
                'seo_tasks',
                'seo_topic_cluster_links',
                'seo_topical_map_versions',
                'seo_topics',
                'seo_watermark_settings',
                'seo_wp_media_backups',
                'seo_wp_media_edited_pending',
                'task_test_results',
                'wordpress_side_effect_attempts',
            ],
            'patterns' => [
                // Live SEO plane tables only; dead domain_global_* / user_workspace_* / seo_domain_* dropped.
            ],
        ];
    }
}
