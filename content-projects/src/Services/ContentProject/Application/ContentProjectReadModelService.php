<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\BusinessTimelineEntryDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectItemDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectRuntimeDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\ContentProjectSummaryDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Dtos\PublishingQueueItemDto;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDashboardStatsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTimelineService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;

/**
 * Read models cho API — không leak Run internals.
 */
final class ContentProjectReadModelService
{
    public function __construct(
        private readonly ContentProjectTenantGuard $tenantGuard,
        private readonly ContentProjectDashboardStatsService $stats,
        private readonly ContentProjectTimelineService $timeline,
        private readonly ContentProjectLifecycle $lifecycle,
    ) {}

    public function project(SeoProject $project, ActorContext $actor): ContentProjectDto
    {
        $this->tenantGuard->assertCanAccessProject($project, $actor);
        $stats = $this->stats->forProject($project);

        return new ContentProjectDto(
            projectRef: ContentProjectPublicRef::project((int) $project->getKey()),
            name: (string) ($project->name ?? ''),
            siteId: (int) ($project->site_id ?? 0) ?: null,
            month: $project->month?->format('Y-m-d'),
            archived: $project->archived_at !== null,
            stats: $stats,
            createdAt: $project->created_at?->toIso8601String(),
            archivedAt: $project->archived_at?->toIso8601String(),
        );
    }

    public function summary(SeoProject $project, ActorContext $actor): ContentProjectSummaryDto
    {
        $this->tenantGuard->assertCanAccessProject($project, $actor);

        return new ContentProjectSummaryDto(
            ContentProjectPublicRef::project((int) $project->getKey()),
            $this->stats->forProject($project),
        );
    }

    /**
     * @return list<ContentProjectItemDto>
     */
    public function items(SeoProject $project, ActorContext $actor): array
    {
        $this->tenantGuard->assertCanAccessProject($project, $actor);

        return SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->active()
            ->with(['article:id,title,status,review_status'])
            ->orderBy('id')
            ->get()
            ->map(function (SeoProjectTask $task) use ($project): ContentProjectItemDto {
                $articleId = (int) ($task->article_id ?? 0);
                $article = $task->article;
                $state = $this->lifecycle->resolveState(
                    $task,
                    $article instanceof \Omnichannel\Addons\Content\Models\SeoArticle ? $article : null,
                );

                return new ContentProjectItemDto(
                    itemRef: ContentProjectPublicRef::item((int) $task->id),
                    projectRef: ContentProjectPublicRef::project((int) $project->getKey()),
                    articleRef: $articleId > 0 ? ContentProjectPublicRef::article($articleId) : null,
                    lifecycle: $state->lifecycleState->value,
                    publishQueueStatus: (string) ($task->publish_queue_status ?? 'none'),
                    scheduledPublishAt: $task->scheduled_publish_at?->toIso8601String(),
                    publishRetryCount: (int) ($task->publish_retry_count ?? 0),
                    lastPublishAttemptAt: $task->last_publish_attempt_at?->toIso8601String(),
                    lastPublishError: $state->currentErrorSource === \Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource::Publish
                        ? $state->currentError
                        : null,
                    publishPublishedAt: $task->publish_published_at?->toIso8601String(),
                    title: $task->article?->title !== null ? (string) $task->article->title : null,
                    state: $state->toArray(),
                );
            })
            ->all();
    }

    /**
     * @return list<PublishingQueueItemDto>
     */
    public function publishingQueue(SeoProject $project, ActorContext $actor): array
    {
        return array_map(
            static fn (ContentProjectItemDto $item): PublishingQueueItemDto => new PublishingQueueItemDto(
                itemRef: $item->itemRef,
                projectRef: $item->projectRef,
                queueStatus: $item->publishQueueStatus,
                scheduledPublishAt: $item->scheduledPublishAt,
                retryCount: $item->publishRetryCount,
                lastAttemptAt: $item->lastPublishAttemptAt,
                lastError: $item->lastPublishError,
                publishedAt: $item->publishPublishedAt,
                lifecycle: $item->lifecycle,
                title: $item->title,
            ),
            $this->items($project, $actor),
        );
    }

    /**
     * @return list<BusinessTimelineEntryDto>
     */
    public function timeline(SeoProject $project, ActorContext $actor): array
    {
        $this->tenantGuard->assertCanAccessProject($project, $actor);

        return array_map(
            static fn (array $row): BusinessTimelineEntryDto => new BusinessTimelineEntryDto(
                key: (string) $row['key'],
                label: (string) $row['label'],
                at: $row['at'] ?? null,
                done: (bool) $row['done'],
            ),
            $this->timeline->forProject($project),
        );
    }

    public function runtime(SeoProject $project, ActorContext $actor): ContentProjectRuntimeDto
    {
        $this->tenantGuard->assertCanAccessProject($project, $actor);

        $active = SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->orderByDesc('id')
            ->first();

        $summary = [
            'has_active_execution' => $active instanceof SeoProjectRun,
            'execution_ref' => $active instanceof SeoProjectRun
                ? ContentProjectPublicRef::execution((int) $active->id)
                : null,
            'execution_status' => $active instanceof SeoProjectRun ? (string) $active->status : 'idle',
            'total' => $active instanceof SeoProjectRun ? (int) ($active->total ?? 0) : 0,
            'succeeded' => $active instanceof SeoProjectRun ? (int) ($active->succeeded ?? 0) : 0,
            'failed' => $active instanceof SeoProjectRun ? (int) ($active->failed ?? 0) : 0,
        ];

        return new ContentProjectRuntimeDto(
            ContentProjectPublicRef::project((int) $project->getKey()),
            (string) ($summary['execution_status'] ?? 'idle'),
            $summary,
        );
    }
}
