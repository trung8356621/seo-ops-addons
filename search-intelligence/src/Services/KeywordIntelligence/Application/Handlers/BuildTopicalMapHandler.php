<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\BuildTopicalMapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\TopicalMapBuildRequest;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapBuildLock;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\TopicalMapBuilder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BuildTopicalMapHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly TopicalMapBuilder $builder,
        private readonly KeywordTopicalMapBuildLock $buildLock,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof BuildTopicalMapCommand) {
            throw new InvalidArgumentException('Expected BuildTopicalMapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            try {
                $this->buildLock->assertAnalysisNotRunning($workspace);
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_ANALYSIS_RUNNING,
                    $e->getMessage(),
                );
            }

            $mode = trim((string) ($command->mode ?: config('seo-content-ai.keyword_intelligence.topical_map.default_mode', 'balanced')));
            if ($mode === '') {
                $mode = TopicalMapBuildRequest::MODE_BALANCED;
            }

            $request = new TopicalMapBuildRequest(
                workspaceId: (int) $workspace->id,
                workspaceRef: (string) $workspace->public_ref,
                mode: $mode,
                maxDepth: $command->maxDepth,
                preserveManualTopics: $command->preserveManualTopics,
                includeReviewedClusters: $command->includeReviewedClusters,
                rebuildDraftAssignments: true,
                approvedClusterRefs: $command->approvedClusterRefs,
                actorId: $actor->actorId,
            );

            $this->buildLock->clearCancel($workspace->public_ref);

            try {
                // Builder owns lock (acquire/release).
                $result = $this->builder->buildFromRequest($request, $workspace);
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                if (str_starts_with($message, 'topical_map.already_building')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_ALREADY_BUILDING,
                        $message,
                    );
                }
                if (str_starts_with($message, 'topical_map.no_approved_clusters')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_NO_APPROVED_CLUSTERS,
                        $message,
                    );
                }
                if (str_starts_with($message, 'topical_map.hierarchy_invalid')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_HIERARCHY_INVALID,
                        $message,
                    );
                }
                if (str_starts_with($message, 'topical_map.keyword_analysis_running')) {
                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_ANALYSIS_RUNNING,
                        $message,
                    );
                }
                if (str_contains($message, 'topical_map.build_cancelled') || $this->buildLock->cancelRequested($workspace->public_ref)) {
                    return ContentProjectActionResult::ok(
                        KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_CANCELLED,
                        'Topical map build cancelled.',
                        metadata: ['workspace_ref' => $workspace->public_ref],
                    );
                }

                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_FAILED,
                    'Topical map build failed: '.$message,
                );
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_FAILED,
                    'Topical map build failed: '.$e->getMessage(),
                );
            }

            $mapVersion = SeoTopicalMapVersion::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('version')
                ->first();

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPICAL_MAP_BUILD_COMPLETED,
                'Topical map built.',
                metadata: array_merge($result->toArray(), [
                    'workspace_ref' => $workspace->public_ref,
                    'map_version_ref' => $mapVersion?->public_ref,
                    'version' => $mapVersion?->version,
                    'status' => $mapVersion?->status,
                    'mode' => $mapVersion?->mode ?? $mode,
                    'summary' => $mapVersion?->summary,
                ]),
                warnings: $result->warnings,
            );
        });
    }
}
