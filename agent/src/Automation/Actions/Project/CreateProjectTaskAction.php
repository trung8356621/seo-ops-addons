<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\Project;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LocalArticleAssociationGuard;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskUniqueWriter;
use Illuminate\Support\Facades\DB;

final class CreateProjectTaskAction implements BusinessAction
{
    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'project.task.create',
            name: 'Create project task',
            description: 'Create a SeoProjectTask row (local).',
            module: 'project',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'project_id' => ['type' => 'integer', 'required' => true],
                'type' => ['type' => 'string', 'required' => false],
                'source_content' => ['type' => 'string', 'required' => false],
                'article_id' => ['type' => 'integer', 'required' => false],
                'post_type' => ['type' => 'string', 'required' => false],
                'rewrite_mode' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'task_id' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
            ],
            emittedEvents: ['project.task_created'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $projectId = (int) ($input['project_id'] ?? 0);
        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return ActionResult::failure('project_not_found', "Project [{$projectId}] not found.");
        }

        if (! $project->canRegisterMoreTasks()) {
            return ActionResult::failure('project_capacity_full', 'Project has no remaining task capacity.');
        }

        $type = SeoProjectTask::normalizeType($input['type'] ?? SeoProjectTask::TYPE_CREATE);

        $keyword = trim((string) ($input['keyword'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $secondaryDescription = trim((string) ($input['secondary_description'] ?? ''));
        $sourceContent = trim((string) ($input['source_content'] ?? ''));
        if ($sourceContent === '') {
            $sourceContent = SeoProjectTask::deriveSourceContent($type, $keyword, $title, $sourceContent);
        }
        $articleId = (int) ($input['article_id'] ?? 0);
        $siteId = (int) ($project->site_id ?? 0);
        if ($articleId > 0) {
            $localArticleId = LocalArticleAssociationGuard::resolveLocalArticleId(
                $articleId,
                $siteId > 0 ? $siteId : null,
            );
            if ($localArticleId === null) {
                return ActionResult::failure(
                    'article_not_found',
                    "Local article [{$articleId}] not found for project site — refusing non-local article_id.",
                );
            }
            $articleId = $localArticleId;
        }

        $output = DB::connection($project->getConnectionName())->transaction(function () use (
            $project,
            $type,
            $sourceContent,
            $keyword,
            $title,
            $secondaryDescription,
            $articleId,
            $siteId,
            $input,
        ): array {
            $payload = [
                'project_id' => (int) $project->id,
                'site_id' => $siteId > 0 ? $siteId : null,
                'type' => $type,
                'source_content' => $sourceContent,
                'keyword' => $keyword !== '' ? $keyword : null,
                'title' => $title !== '' ? $title : null,
                'secondary_description' => $secondaryDescription !== '' ? $secondaryDescription : null,
                'description' => null,
                'target_date' => $project->monthCarbon()->format('Y-m-d'),
                'status' => SeoProjectTask::STATUS_PENDING,
                'article_id' => $articleId > 0 ? $articleId : null,
            ];

            if (SeoProjectTask::isNewArticleType($type)) {
                $payload['post_type'] = SeoProjectTask::normalizePostType(
                    (string) ($input['post_type'] ?? SeoProjectTask::POST_TYPE_ARTICLE),
                );
                $payload['article_id'] = null;
            }

            if ($type === SeoProjectTask::TYPE_REWRITE) {
                $payload['rewrite_mode'] = SeoProjectTask::REWRITE_MODE_CONTENT;
            }

            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                $notes = trim((string) ($input['rewrite_notes'] ?? $input['improve_instruction'] ?? ''));
                $payload['rewrite_notes'] = $notes !== '' ? $notes : null;
            }

            $payload['source_key'] = app(\Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator::class)->generate(
                (int) $project->id,
                $type,
                isset($payload['post_type']) ? (string) $payload['post_type'] : null,
                $sourceContent,
            );

            $task = app(SeoProjectTaskUniqueWriter::class)->createOrReturnExisting($payload);
            if ($task->wasRecentlyCreated) {
                $project->syncTotalTasksCounter();
            }

            return [
                'task_id' => (int) $task->id,
                'project_id' => (int) $project->id,
                'type' => $type,
                'article_id' => $payload['article_id'] ?? $task->article_id,
                'created' => $task->wasRecentlyCreated,
            ];
        });

        return ActionResult::success(
            output: $output,
            events: [
                EventEnvelope::make(
                    eventKey: 'project.task_created',
                    entity: ['type' => 'project_task', 'id' => $output['task_id']],
                    context: [
                        'correlation_id' => $context->correlationId,
                        'origin' => $context->origin,
                        'site_id' => $context->siteId ?? $siteId,
                        'actor_id' => $context->actorId,
                    ],
                    payload: [
                        'project_id' => $output['project_id'],
                        'type' => $output['type'],
                    ],
                ),
            ],
            changed: ['project_task'],
        );
    }
}
