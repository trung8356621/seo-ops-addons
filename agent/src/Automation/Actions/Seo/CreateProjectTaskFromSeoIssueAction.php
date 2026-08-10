<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Seo;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Illuminate\Support\Collection;

final class CreateProjectTaskFromSeoIssueAction implements BusinessAction
{
    public function __construct(
        private readonly SeoIssueProjectTaskAssignmentService $assignment,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'seo.project_task.create_from_issue',
            name: 'Create project task from SEO issue',
            description: 'Assign audited article into a content project task. Does not fix SEO or publish WP.',
            module: 'seo',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'project_id' => ['type' => 'integer', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => true],
                'type' => ['type' => 'string', 'required' => false],
                'rewrite_mode' => ['type' => 'string', 'required' => false],
                'rewrite_notes' => ['type' => 'string', 'required' => false],
                'keyword' => ['type' => 'string', 'required' => false],
                'title' => ['type' => 'string', 'required' => false],
                'ignore_monthly_capacity' => ['type' => 'boolean', 'required' => false],
            ],
            outputSchema: [
                'project_id' => ['type' => 'integer'],
                'article_id' => ['type' => 'integer'],
                'added' => ['type' => 'integer'],
                'deduplicated' => ['type' => 'boolean'],
            ],
            idempotent: true,
            supportsDryRun: true,
            emittedEvents: ['project.task_created'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        $articleId = (int) ($input['article_id'] ?? 0);
        $article = ActionSupport::findArticle($articleId);
        if ($article === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        $taskType = trim((string) ($input['type'] ?? SeoProjectTask::TYPE_REWRITE));
        $summary = $this->assignment->assignFromFormData(
            Collection::make([$article]),
            $projectId,
            [
                'type' => $taskType,
                'rewrite_mode' => $input['rewrite_mode'] ?? null,
                'rewrite_notes' => $input['rewrite_notes'] ?? null,
                'keyword' => $input['keyword'] ?? null,
                'title' => $input['title'] ?? null,
                'ignore_monthly_capacity' => (bool) ($input['ignore_monthly_capacity'] ?? false),
            ],
            dryRun: $context->dryRun,
        );

        $added = (int) ($summary['added'] ?? 0);
        $duplicate = (int) ($summary['duplicate'] ?? 0);
        if ($added <= 0 && $duplicate <= 0) {
            return ActionResult::failure(
                'assign_failed',
                $this->assignment->buildSummaryMessage($summary),
                error: ['summary' => $summary],
            );
        }

        $deduplicated = $added <= 0 && $duplicate > 0;

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'project_id' => $projectId,
                    'article_id' => $articleId,
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
                eventKey: 'project.task_created',
                entity: ['type' => 'article', 'id' => $articleId],
                context: [
                    'correlation_id' => $context->correlationId,
                    'origin' => $context->origin,
                    'site_id' => $context->siteId ?? (int) ($article->site_id ?? 0),
                    'actor_id' => $context->actorId,
                ],
                payload: [
                    'project_id' => $projectId,
                    'source' => 'seo_issue',
                ],
            );
        }

        return ActionResult::success(
            output: [
                'project_id' => $projectId,
                'article_id' => $articleId,
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
