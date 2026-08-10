<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordCannibalizationIssueStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCannibalizationIssue;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ReviewCannibalizationIssueCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use InvalidArgumentException;

final class ReviewCannibalizationIssueHandler extends AbstractKeywordIntelligenceHandler
{
    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ReviewCannibalizationIssueCommand) {
            throw new InvalidArgumentException('Expected ReviewCannibalizationIssueCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);

            if (! class_exists(SeoKeywordCannibalizationIssue::class)
                || ! method_exists(KeywordIntelligencePublicRef::class, 'resolveCannibalizationIssueIdStrict')) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::FAILED,
                    'Cannibalization issue model not available yet.',
                );
            }

            $id = KeywordIntelligencePublicRef::resolveCannibalizationIssueIdStrict($command->issueRef);
            $issue = SeoKeywordCannibalizationIssue::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $id)
                ->first();

            if (! $issue instanceof SeoKeywordCannibalizationIssue) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::NOT_FOUND,
                    'Issue not found.',
                );
            }

            $status = match ($command->action) {
                'ignored' => KeywordCannibalizationIssueStatus::Ignored->value,
                'resolved' => KeywordCannibalizationIssueStatus::Resolved->value,
                default => KeywordCannibalizationIssueStatus::Reviewed->value,
            };

            $issue->status = $status;
            $issue->reviewed_at = now();
            if ($command->action === 'resolved') {
                $issue->resolved_at = now();
                $issue->resolved_by = $actor->actorId;
                $issue->resolution_code = $command->resolutionCode;
            }
            $issue->save();

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::CANNIBALIZATION_REVIEWED,
                'Cannibalization issue updated.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'issue_ref' => $issue->public_ref,
                    'status' => $status,
                ],
            );
        });
    }
}
