<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncRunStatus;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncStage;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscSyncRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\SyncGscPerformanceDataCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSuggestedMappingPersistService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncDateRangeService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscSyncOperationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class SyncGscPerformanceDataHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscSyncOperationService $syncOperation,
        private readonly GscSyncDateRangeService $dateRangeService,
        private readonly GscSuggestedMappingPersistService $suggestedMappingPersist,
        private readonly ?\Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\GscKeywordIntelligenceIngestionService $keywordIngestion = null,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SyncGscPerformanceDataCommand) {
            throw new InvalidArgumentException('Expected SyncGscPerformanceDataCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $endDate = $command->dateTo ?? $this->dateRangeService->latestAvailableEnd()['end'];
            $startDate = $command->dateFrom
                ?? ($property->last_complete_date?->format('Y-m-d')
                    ?? (new \DateTimeImmutable($endDate))->modify('-27 days')->format('Y-m-d'));

            $providerKey = trim((string) ($command->providerKey ?? $property->provider_key ?? 'manual_import'));

            $syncRun = new SeoGscSyncRun([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'provider_key' => $providerKey,
                'date_from' => $startDate,
                'date_to' => $endDate,
                'search_type' => $property->default_search_type,
                'status' => GscSyncRunStatus::Processing,
                'started_at' => now(),
                'created_by' => $actor->actorId,
            ]);
            $syncRun->save();
            $syncRun->public_ref = KeywordIntelligencePublicRef::gscSyncRun((int) $syncRun->id);
            $syncRun->save();

            $request = new GscSearchAnalyticsRequest(
                tenantRef: null,
                siteRef: (string) $property->site_id,
                propertyRef: $property->public_ref,
                startDate: $startDate,
                endDate: $endDate,
                providerKey: $providerKey,
                options: $command->options,
            );

            $result = $this->syncOperation->sync($request, [
                'site_id' => (string) $property->site_id,
                'site_id_int' => (int) $property->site_id,
                'property_id' => (int) $property->id,
                'tenant_id' => $property->tenant_id,
                'search_type' => (string) ($property->default_search_type?->value ?? 'web'),
                'source' => $providerKey,
                'provider_context' => $command->options,
            ]);

            $syncRun->operation_ref = (string) ($result['operation_ref'] ?? null);
            $syncRun->received_rows = (int) ($result['row_count'] ?? 0);
            $syncRun->persisted_rows = (int) ($result['persisted']['inserted'] ?? 0) + (int) ($result['persisted']['updated'] ?? 0);
            $syncRun->completed_at = now();

            if (($result['success'] ?? false) !== true) {
                $syncRun->status = GscSyncRunStatus::Failed;
                $syncRun->error_code = (string) ($result['error_code'] ?? 'gsc.sync_failed');
                $syncRun->error_message = (string) ($result['error_message'] ?? '');
                $syncRun->save();

                return ContentProjectActionResult::fail(
                    (string) ($result['error_code'] ?? GscIntelligenceActionCodes::FAILED),
                    (string) ($result['error_message'] ?? 'GSC sync failed.'),
                    metadata: ['property_ref' => $property->public_ref, 'sync_run_ref' => $syncRun->public_ref],
                );
            }

            $mappingStats = $this->suggestedMappingPersist->persistFromSyncResult(
                $property,
                is_array($result['mappings'] ?? null) ? $result['mappings'] : [],
            );

            $kiIngest = ['ingested' => 0, 'skipped' => true];
            if ($this->keywordIngestion !== null) {
                $kiIngest = $this->keywordIngestion->ingestFromSyncMappingsSafe(
                    (int) $property->site_id,
                    is_array($result['mappings'] ?? null) ? $result['mappings'] : [],
                    [
                        'property_ref' => $property->public_ref,
                        'sync_run_ref' => $syncRun->public_ref,
                    ],
                );
            }

            $partial = ($result['stage'] ?? '') === GscSyncStage::PartiallyCompleted->value || ($result['partial'] ?? false) === true;
            $syncRun->status = $partial ? GscSyncRunStatus::PartiallyCompleted : GscSyncRunStatus::Completed;
            $syncRun->save();

            $property->last_synced_at = now();
            $property->last_complete_date = $endDate;
            $property->save();

            return ContentProjectActionResult::ok(
                $partial ? GscIntelligenceActionCodes::SYNC_PARTIAL : GscIntelligenceActionCodes::SYNC_STARTED,
                'GSC sync finished.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'sync_run_ref' => $syncRun->public_ref,
                    'operation_ref' => $result['operation_ref'] ?? null,
                    'mapping_persist' => $mappingStats,
                    'keyword_intelligence_ingest' => $kiIngest,
                    'result' => $result,
                ],
            );
        });
    }
}
