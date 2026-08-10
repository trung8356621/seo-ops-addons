<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ApproveKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class ApproveKeywordsHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ApproveKeywordsCommand) {
            throw new InvalidArgumentException('Expected ApproveKeywordsCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $updated = [];
            $missing = [];

            foreach ($command->keywordRefs as $ref) {
                try {
                    $id = KeywordIntelligencePublicRef::resolveKeywordIdStrict((string) $ref);
                } catch (InvalidArgumentException) {
                    $missing[] = $ref;

                    continue;
                }

                $keyword = SeoKiKeyword::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('id', $id)
                    ->first();

                if (! $keyword instanceof SeoKiKeyword) {
                    $missing[] = $ref;

                    continue;
                }

                $keyword->review_status = $command->approve
                    ? KeywordReviewStatus::Approved->value
                    : KeywordReviewStatus::Rejected->value;
                $keyword->save();
                $updated[] = $keyword->public_ref;
            }

            if ($updated === []) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::NOT_FOUND,
                    'No matching keywords found.',
                    errors: $missing !== [] ? ['keyword_refs' => $missing] : [],
                );
            }

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::KEYWORDS_REVIEWED,
                $command->approve ? 'Keywords approved.' : 'Keywords rejected.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'updated_keyword_refs' => $updated,
                    'missing_keyword_refs' => $missing,
                ],
            );
        });
    }
}
