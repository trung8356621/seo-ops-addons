<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\UpdateContentProjectItemCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskStatusNormalizer;
use InvalidArgumentException;
use RuntimeException;

final class UpdateContentProjectItemHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UpdateContentProjectItemCommand) {
            throw new InvalidArgumentException('Expected UpdateContentProjectItemCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $itemId = ContentProjectPublicRef::resolveItemId($command->itemRef);
            $task = SeoProjectTask::query()->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'Item not found.',
                );
            }

            $project = SeoProject::query()->find((int) $task->project_id);
            if (! $project instanceof SeoProject) {
                throw new RuntimeException('Project không tồn tại.');
            }

            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Cannot update item on archived project.',
                    $projectId,
                );
            }

            $allowed = array_intersect_key(
                $command->attributes,
                array_flip(['keyword', 'title', 'target_date', 'status', 'post_type']),
            );
            if ($allowed === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'No updatable attributes.',
                    $projectId,
                    affectedItemIds: [$itemId],
                );
            }

            if (array_key_exists('post_type', $allowed)) {
                $postTypeResult = $this->applyPlannerPostTypeUpdate($task, $allowed['post_type'], $projectId, $itemId);
                if ($postTypeResult !== null) {
                    return $postTypeResult;
                }
                unset($allowed['post_type']);
                if ($allowed === []) {
                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_UPDATED,
                        'Item updated.',
                        $projectId,
                        [$itemId],
                        metadata: ['updated' => ['post_type']],
                    );
                }
            }

            if (array_key_exists('keyword', $allowed) || array_key_exists('title', $allowed)) {
                $nextKeyword = array_key_exists('keyword', $allowed)
                    ? ContentProjectItemIdentity::normalize(
                        $allowed['keyword'] !== null ? (string) $allowed['keyword'] : null,
                    )
                    : ContentProjectItemIdentity::normalize(
                        $task->keyword !== null ? (string) $task->keyword : null,
                    );
                $nextTitle = array_key_exists('title', $allowed)
                    ? ContentProjectItemIdentity::normalize(
                        $allowed['title'] !== null ? (string) $allowed['title'] : null,
                    )
                    : ContentProjectItemIdentity::normalize(
                        $task->title !== null ? (string) $task->title : null,
                    );

                $itemType = SeoProjectTask::normalizeType($task->type);
                if (in_array($itemType, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)
                    && ! ContentProjectItemIdentity::isValid($nextKeyword, $nextTitle)
                ) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        ContentProjectItemIdentity::failureMessage(),
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }

                if (array_key_exists('keyword', $allowed)) {
                    $allowed['keyword'] = $nextKeyword !== '' ? $nextKeyword : null;
                }
                if (array_key_exists('title', $allowed)) {
                    $allowed['title'] = $nextTitle !== '' ? $nextTitle : null;
                }
            }

            if (array_key_exists('status', $allowed)) {
                try {
                    $normalized = ContentProjectTaskStatusNormalizer::normalizeOrFail(
                        is_string($allowed['status'] ?? null) ? (string) $allowed['status'] : null,
                    );
                } catch (InvalidArgumentException $e) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        $e->getMessage(),
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }

                // Manual status writes limited to non-terminal workflow labels — archive/cancel via dedicated commands.
                if (in_array($normalized, [SeoProjectTaskStatus::Archived, SeoProjectTaskStatus::Cancelled], true)) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::VALIDATION_FAILED,
                        'Use archive/cancel commands — cannot set status='.$normalized->value.' via item update.',
                        $projectId,
                        affectedItemIds: [$itemId],
                    );
                }

                $allowed['status'] = $normalized->value;
            }

            $task->update($allowed);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_UPDATED,
                'Item updated.',
                $projectId,
                [$itemId],
                metadata: ['updated' => array_keys($allowed)],
            );
        });
    }

    /**
     * Planner post_type correction for Draft CREATE items only.
     * Accepts article|post→article|product; rejects page/category/etc. Does not wipe product fields.
     *
     * @return ContentProjectActionResult|null null when post_type was applied on $task (caller may continue)
     */
    private function applyPlannerPostTypeUpdate(
        SeoProjectTask $task,
        mixed $rawValue,
        int $projectId,
        int $itemId,
    ): ?ContentProjectActionResult {
        $normalized = self::normalizeEditablePlannerPostType(
            is_scalar($rawValue) || $rawValue === null ? (string) $rawValue : '',
        );
        if ($normalized === null) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Invalid post_type. Allowed: article, post, product.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if (SeoProjectTask::normalizeType($task->type) !== SeoProjectTask::TYPE_CREATE) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'post_type can only be changed on Create planning items.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ((int) ($task->article_id ?? 0) > 0) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Cannot change post_type after an article is linked.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ($task->archived_at !== null) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Cannot change post_type on archived items.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        $status = strtolower(trim((string) ($task->status ?? '')));
        if (! in_array($status, [SeoProjectTask::STATUS_PENDING, SeoProjectTask::STATUS_DRAFT], true)) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                'Cannot change post_type after execution has started.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        $previous = SeoProjectTask::normalizePostType($task->post_type ?? null);

        if ($previous === $normalized) {
            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_UPDATED,
                'Item updated.',
                $projectId,
                [$itemId],
                metadata: ['updated' => ['post_type'], 'unchanged' => true],
            );
        }

        $task->post_type = $normalized;
        // Material planning change — invalidate Draft planning review (no signature service yet).
        if ($task->planning_reviewed_at !== null || $task->planning_reviewed_by !== null) {
            $task->planning_reviewed_at = null;
            $task->planning_reviewed_by = null;
        }
        $task->save();

        return null;
    }

    /**
     * Strict planner editable post_type: article|post → post, product. Null = reject.
     * Persists canonical {@see SeoProjectTask::POST_TYPE_POST} (never legacy `article`).
     */
    public static function normalizeEditablePlannerPostType(string $raw): ?string
    {
        $key = strtolower(trim($raw));

        return match ($key) {
            SeoProjectTask::POST_TYPE_ARTICLE, SeoProjectTask::POST_TYPE_POST => SeoProjectTask::POST_TYPE_POST,
            SeoProjectTask::POST_TYPE_PRODUCT => SeoProjectTask::POST_TYPE_PRODUCT,
            default => null,
        };
    }
}
