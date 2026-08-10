<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpPageEvidence;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\FetchSerpPageEvidenceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpPageEvidenceExtractor;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpPageEvidenceFetcher;
use InvalidArgumentException;

final class FetchSerpPageEvidenceHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpPageEvidenceFetcher $fetcher,
        private readonly SerpPageEvidenceExtractor $extractor,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof FetchSerpPageEvidenceCommand) {
            throw new InvalidArgumentException('Expected FetchSerpPageEvidenceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $snapshot = $this->resolveSnapshot($command->snapshotRef);
            $resultQuery = SeoSerpResult::query()->where('snapshot_id', $snapshot->id);

            if ($command->resultRefs !== []) {
                $ids = array_map(
                    static fn (string $ref): int => KeywordIntelligencePublicRef::resolveSerpResultIdStrict($ref),
                    $command->resultRefs,
                );
                $resultQuery->whereIn('id', $ids);
            }

            $evidenceRefs = [];
            foreach ($resultQuery->limit(10)->get() as $result) {
                if (! $result instanceof SeoSerpResult) {
                    continue;
                }

                $validation = $this->fetcher->validateUrlForFetch((string) $result->url);
                if (($validation['allowed'] ?? false) !== true) {
                    continue;
                }

                $fetched = $this->fetcher->fetch((string) $result->url);
                $extracted = $this->extractor->extract(
                    isset($fetched['body']) ? (string) $fetched['body'] : null,
                    is_array($fetched['metadata'] ?? null) ? $fetched['metadata'] : null,
                );

                $evidence = new SeoSerpPageEvidence([
                    'tenant_id' => $snapshot->tenant_id,
                    'site_id' => $snapshot->site_id,
                    'snapshot_id' => $snapshot->id,
                    'serp_result_id' => $result->id,
                    'url' => $result->url,
                    'normalized_url' => $result->normalized_url,
                    'domain' => $result->domain,
                    'fetch_status' => ($fetched['success'] ?? false) ? 'completed' : 'failed',
                    'http_status' => $fetched['http_status'] ?? null,
                    'title' => $extracted['title'] ?? null,
                    'headings' => $extracted['headings'] ?? [],
                    'source' => 'serp_fetch',
                    'metadata' => ['fetch' => $fetched['metadata'] ?? []],
                ]);
                $evidence->save();
                $evidence->public_ref = KeywordIntelligencePublicRef::serpPageEvidence((int) $evidence->id);
                $evidence->save();
                $evidenceRefs[] = $evidence->public_ref;
            }

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::PAGE_EVIDENCE_FETCHED,
                'Page evidence fetched.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'snapshot_ref' => $snapshot->public_ref,
                    'page_evidence_refs' => $evidenceRefs,
                ],
            );
        });
    }
}
