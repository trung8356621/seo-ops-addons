<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpPageEvidence;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ReanalyzeSerpPageEvidenceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpPageEvidenceExtractor;
use InvalidArgumentException;

final class ReanalyzeSerpPageEvidenceHandler extends AbstractSerpIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly SerpPageEvidenceExtractor $extractor,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ReanalyzeSerpPageEvidenceCommand) {
            throw new InvalidArgumentException('Expected ReanalyzeSerpPageEvidenceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $id = KeywordIntelligencePublicRef::resolveSerpPageEvidenceIdStrict($command->pageEvidenceRef);
            $evidence = SeoSerpPageEvidence::query()->find($id);
            if (! $evidence instanceof SeoSerpPageEvidence) {
                throw new InvalidArgumentException('Page evidence not found.');
            }

            $metadata = is_array($evidence->metadata) ? $evidence->metadata : [];
            $html = isset($metadata['cached_html']) ? (string) $metadata['cached_html'] : null;
            $extracted = $this->extractor->extract($html, $metadata);
            $evidence->headings = $extracted['headings'] ?? [];
            $evidence->entities = $extracted['entities'] ?? [];
            $evidence->schema_types = $extracted['schema_types'] ?? [];
            $evidence->analyzed_at = now();
            $evidence->save();

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::PAGE_EVIDENCE_REANALYZED,
                'Page evidence reanalyzed.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'page_evidence_ref' => $evidence->public_ref,
                ],
            );
        });
    }
}
