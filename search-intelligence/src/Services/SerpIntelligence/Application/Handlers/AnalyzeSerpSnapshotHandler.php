<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpSnapshotStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\AnalyzeSerpSnapshotCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpContentGapAnalyzer;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpIntentEvidenceService;
use InvalidArgumentException;

final class AnalyzeSerpSnapshotHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpIntentEvidenceService $intentEvidence,
        private readonly SerpContentGapAnalyzer $gapAnalyzer,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof AnalyzeSerpSnapshotCommand) {
            throw new InvalidArgumentException('Expected AnalyzeSerpSnapshotCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $snapshot = $this->resolveSnapshot($command->snapshotRef);
            if ($snapshot->status !== SerpSnapshotStatus::Completed) {
                return ContentProjectActionResult::fail(
                    SerpIntelligenceActionCodes::VALIDATION_FAILED,
                    'Snapshot must be completed before analysis.',
                );
            }

            $results = $snapshot->results()->get()->map(fn ($r) => $r->toArray())->all();
            $features = $snapshot->features()->get()->map(fn ($f) => $f->toArray())->all();
            $intent = $this->intentEvidence->analyze($results, $features);
            $gaps = $this->gapAnalyzer->analyze([], $results);

            $snapshot->analysis_summary = ['intent' => $intent, 'gap_count' => count($gaps)];
            $snapshot->status = SerpSnapshotStatus::Completed;
            $snapshot->save();

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::SNAPSHOT_ANALYZED,
                'Snapshot analyzed.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'snapshot_ref' => $snapshot->public_ref,
                    'intent' => $intent,
                    'gaps_detected' => count($gaps),
                ],
            );
        });
    }
}
