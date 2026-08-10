<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Keyword;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Illuminate\Support\Collection;

final class AssignKeywordToProjectAction implements BusinessAction
{
    public function __construct(
        private readonly KeywordProjectAssignmentService $assignment,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'keyword.assign_to_project',
            name: 'Assign keyword to project',
            description: 'Assign keyword into content project task (local).',
            module: 'keyword',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'project_id' => ['type' => 'integer', 'required' => true],
                'keyword_id' => ['type' => 'integer', 'required' => true],
                'site_id' => ['type' => 'integer', 'required' => true],
            ],
            outputSchema: [
                'project_id' => ['type' => 'integer'],
                'keyword_id' => ['type' => 'integer'],
                'added' => ['type' => 'integer'],
                'deduplicated' => ['type' => 'boolean'],
            ],
            idempotent: true,
            supportsDryRun: true,
            emittedEvents: ['keyword.assigned_to_project'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        $keywordId = (int) ($input['keyword_id'] ?? 0);
        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return ActionResult::failure('keyword_not_found', "Keyword [{$keywordId}] not found.");
        }

        if ($siteId <= 0) {
            return ActionResult::failure('invalid_input', 'site_id is required.');
        }

        $keyword->loadCount('mainArticles');

        $summary = $this->assignment->assignKeywords(
            Collection::make([$keyword]),
            $projectId,
            $siteId,
            dryRun: $context->dryRun,
        );

        $added = (int) ($summary['added'] ?? 0);
        $duplicate = (int) ($summary['duplicate'] ?? 0);
        if ($added <= 0 && $duplicate <= 0) {
            return ActionResult::failure(
                'assign_failed',
                'Keyword was not assigned to project.',
                error: ['summary' => $summary],
            );
        }

        $deduplicated = $added <= 0 && $duplicate > 0;

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'project_id' => $projectId,
                    'keyword_id' => $keywordId,
                    'site_id' => $siteId,
                    'added' => $added,
                    'duplicate' => $duplicate,
                    'deduplicated' => $deduplicated,
                    'summary' => $summary,
                    'dry_run' => true,
                ],
                status: \Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus::DryRun,
            );
        }
        $events = [];
        if ($added > 0) {
            $events[] = EventEnvelope::make(
                eventKey: 'keyword.assigned_to_project',
                entity: ['type' => 'keyword', 'id' => $keywordId],
                context: [
                    'correlation_id' => $context->correlationId,
                    'origin' => $context->origin,
                    'site_id' => $siteId,
                    'actor_id' => $context->actorId,
                ],
                payload: ['project_id' => $projectId],
            );
        }

        return ActionResult::success(
            output: [
                'project_id' => $projectId,
                'keyword_id' => $keywordId,
                'site_id' => $siteId,
                'added' => $added,
                'duplicate' => $duplicate,
                'deduplicated' => $deduplicated,
                'summary' => $summary,
            ],
            events: $events,
            changed: $added > 0 ? ['project_task'] : [],
        );
    }
}
