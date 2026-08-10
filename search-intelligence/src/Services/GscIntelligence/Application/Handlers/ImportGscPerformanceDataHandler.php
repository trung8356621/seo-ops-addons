<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscSyncRunStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscSyncRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\ImportGscPerformanceDataCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscManualImportService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class ImportGscPerformanceDataHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly GscManualImportService $importer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ImportGscPerformanceDataCommand) {
            throw new InvalidArgumentException('Expected ImportGscPerformanceDataCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            if (trim($command->payload) === '') {
                return ContentProjectActionResult::fail(
                    GscIntelligenceActionCodes::VALIDATION_FAILED,
                    'Import payload is required.',
                );
            }

            if ($command->preview) {
                $preview = $this->importer->preview($command->payload, $property->public_ref);

                return ContentProjectActionResult::ok(
                    GscIntelligenceActionCodes::IMPORT_PREVIEW,
                    'Import preview generated.',
                    metadata: [
                        'property_ref' => $property->public_ref,
                        'preview' => true,
                        'summary' => $preview->summary,
                        'valid_count' => count($preview->validRows),
                        'invalid_count' => count($preview->invalidRows),
                        'duplicate_count' => count($preview->duplicateRows),
                    ],
                );
            }

            $result = $this->importer->import($command->payload, $property->public_ref, [
                'property_id' => (int) $property->id,
                'tenant_id' => $property->tenant_id,
                'site_id' => (int) $property->site_id,
                'source' => 'manual_import',
            ]);
            $preview = $result['preview'];
            $persisted = $result['persisted'];

            if ($preview->validRows === []) {
                return ContentProjectActionResult::fail(
                    GscIntelligenceActionCodes::VALIDATION_FAILED,
                    'No valid rows to import.',
                    metadata: ['summary' => $preview->summary],
                );
            }

            $syncRun = new SeoGscSyncRun([
                'public_ref' => 'pending',
                'tenant_id' => $property->tenant_id,
                'site_id' => $property->site_id,
                'property_id' => $property->id,
                'provider_key' => 'manual_import',
                'date_from' => $preview->validRows[0]['date'] ?? null,
                'date_to' => $preview->validRows[count($preview->validRows) - 1]['date'] ?? null,
                'status' => GscSyncRunStatus::Completed,
                'received_rows' => count($preview->validRows),
                'persisted_rows' => (int) ($persisted['inserted'] ?? 0) + (int) ($persisted['updated'] ?? 0),
                'started_at' => now(),
                'completed_at' => now(),
                'created_by' => $actor->actorId,
            ]);
            $syncRun->save();
            $syncRun->public_ref = KeywordIntelligencePublicRef::gscSyncRun((int) $syncRun->id);
            $syncRun->save();

            $property->last_synced_at = now();
            $property->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::IMPORT_COMPLETED,
                'GSC performance data imported.',
                metadata: [
                    'property_ref' => $property->public_ref,
                    'sync_run_ref' => $syncRun->public_ref,
                    'summary' => $preview->summary,
                    'persisted' => $persisted,
                ],
            );
        });
    }
}
