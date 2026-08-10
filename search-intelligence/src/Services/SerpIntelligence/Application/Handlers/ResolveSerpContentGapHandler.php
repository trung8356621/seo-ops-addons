<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapStatus;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpContentGap;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Commands\ResolveSerpContentGapCommand;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;

final class ResolveSerpContentGapHandler extends AbstractSerpIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ResolveSerpContentGapCommand) {
            throw new InvalidArgumentException('Expected ResolveSerpContentGapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            $gap = $this->resolveGap($command->gapRef);
            $gap->status = SerpContentGapStatus::Resolved;
            $gap->resolved_at = now();
            $gap->save();

            return ContentProjectActionResult::ok(
                SerpIntelligenceActionCodes::GAP_RESOLVED,
                'Content gap resolved.',
                metadata: ['workspace_ref' => $workspace->public_ref, 'gap_ref' => $gap->public_ref],
            );
        });
    }

    protected function resolveGap(string $gapRef): SeoSerpContentGap
    {
        $id = KeywordIntelligencePublicRef::resolveSerpContentGapIdStrict($gapRef);
        $gap = SeoSerpContentGap::query()->find($id);
        if (! $gap instanceof SeoSerpContentGap) {
            throw new InvalidArgumentException('Content gap not found.');
        }

        return $gap;
    }
}
