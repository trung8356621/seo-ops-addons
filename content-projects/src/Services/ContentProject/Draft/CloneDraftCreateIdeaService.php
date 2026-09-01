<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One-click Clone idea for Plan=Create Draft items.
 * New item: site_id NULL, title blank, Unreviewed — keyword/brief/post_type kept.
 */
final class CloneDraftCreateIdeaService
{
    /**
     * @return array{source_id: int, clone_id: int}
     */
    public function clone(SeoProject $draft, int $sourceTaskId, ?int $actorId = null): array
    {
        if (! $draft->isDraftPlanning()) {
            throw new InvalidArgumentException('Clone idea is only available on Planning Draft.');
        }

        return DB::connection('omi_seo_ai')->transaction(function () use ($draft, $sourceTaskId, $actorId): array {
            /** @var SeoProjectTask|null $source */
            $source = SeoProjectTask::query()
                ->where('project_id', (int) $draft->getKey())
                ->whereKey($sourceTaskId)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if (! $source instanceof SeoProjectTask) {
                throw new InvalidArgumentException('Source Draft item not found.');
            }

            if (SeoProjectTask::normalizeType($source->type) !== SeoProjectTask::TYPE_CREATE) {
                throw new InvalidArgumentException('Clone idea is only available for Create plan items.');
            }

            $clone = SeoProjectTask::query()->create([
                'project_id' => (int) $draft->getKey(),
                'site_id' => null,
                'article_id' => null,
                'type' => SeoProjectTask::TYPE_CREATE,
                'post_type' => SeoProjectTask::normalizePostType($source->post_type ?? SeoProjectTask::POST_TYPE_POST),
                'loai_san_pham' => $source->loai_san_pham,
                'source_content' => $source->source_content,
                'keyword' => $source->keyword,
                'generation_keyword_override' => $source->generation_keyword_override,
                'title' => null,
                'source_key' => null,
                'rewrite_mode' => $source->rewrite_mode,
                'rewrite_notes' => null,
                'description' => $source->description,
                'secondary_description' => $source->secondary_description,
                'target_date' => $draft->monthCarbon()->format('Y-m-d'),
                'status' => SeoProjectTask::STATUS_PENDING,
                'planning_reviewed_at' => null,
                'planning_reviewed_by' => null,
                'archived_at' => null,
                'archived_from_project_id' => null,
            ]);

            $origin = SeoContentProjectItemOrigin::query()
                ->where('project_task_id', (int) $source->getKey())
                ->first();

            SeoContentProjectItemOrigin::query()->create([
                'project_id' => (int) $draft->getKey(),
                'project_task_id' => (int) $clone->getKey(),
                'planner_run_id' => null,
                'source_type' => $origin instanceof SeoContentProjectItemOrigin
                    ? (string) ($origin->source_type ?: SeoContentProjectItemOrigin::SOURCE_MANUAL)
                    : SeoContentProjectItemOrigin::SOURCE_MANUAL,
                'source_article_id' => null,
                'source_finding_ids' => null,
                'reason_codes' => [
                    'cloned_from_task_id' => (int) $source->getKey(),
                    'actor_id' => $actorId,
                ],
                'created_at' => now(),
            ]);

            $draft->syncTotalTasksCounter();

            return [
                'source_id' => (int) $source->getKey(),
                'clone_id' => (int) $clone->getKey(),
            ];
        });
    }
}
