<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Carbon\Carbon;

/**
 * Business timeline — không dùng Prompt/Execution history.
 * Sống sót sau Archive vì derive từ business fields (project/tasks/articles).
 */
final class ContentProjectTimelineService
{
    /**
     * @return list<array{key: string, label: string, at: string|null, done: bool}>
     */
    public function forProject(SeoProject $project): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->with(['article:id,status,review_status,reviewed_at,published_at'])
            ->get();

        $aiFinishedAt = $tasks
            ->filter(static fn (SeoProjectTask $t): bool => in_array((string) $t->status, [
                SeoProjectTask::STATUS_COMPLETED,
                SeoProjectTask::STATUS_REVIEWING,
            ], true) && $t->completed_at !== null)
            ->max('completed_at');

        $cmReviewedAt = $tasks
            ->filter(static fn (SeoProjectTask $t): bool => $t->content_manager_reviewed_at !== null)
            ->max('content_manager_reviewed_at');

        $reviewCompletedAt = $tasks
            ->map(static function (SeoProjectTask $t) {
                $article = $t->article;
                if ($article
                    && ArticleReviewStatus::tryFromString((string) ($article->review_status ?? '')) === ArticleReviewStatus::Approved
                    && $article->reviewed_at) {
                    return $article->reviewed_at;
                }

                return null;
            })
            ->filter()
            ->max();

        $scheduledAt = $tasks
            ->filter(static fn (SeoProjectTask $t): bool => $t->scheduled_publish_at !== null
                || in_array((string) ($t->publish_queue_status ?? ''), ContentProjectPublishQueueStatus::activeValues(), true)
                || (string) ($t->publish_queue_status ?? '') === ContentProjectPublishQueueStatus::Published->value)
            ->min('scheduled_publish_at');

        // first schedule marker: earliest scheduled or earliest publish_published
        if ($scheduledAt === null) {
            $scheduledAt = $tasks->min('publish_published_at');
        }

        $publishedAt = $tasks
            ->map(static function (SeoProjectTask $t) {
                if ($t->publish_published_at !== null) {
                    return $t->publish_published_at;
                }
                $article = $t->article;
                if ($article && $article->publishingState?->published_at) {
                    return $article->publishingState->published_at;
                }

                return null;
            })
            ->filter()
            ->min();

        $steps = [
            [
                'key' => 'project_created',
                'label' => __('seo-content-ai::filament.projects.timeline_project_created'),
                'at' => $this->iso($project->created_at),
                'done' => $project->created_at !== null,
            ],
            [
                'key' => 'ai_finished',
                'label' => __('seo-content-ai::filament.projects.timeline_ai_finished'),
                'at' => $this->iso($aiFinishedAt),
                'done' => $aiFinishedAt !== null,
            ],
            [
                'key' => 'content_manager_reviewed',
                'label' => __('seo-content-ai::filament.projects.timeline_content_manager_reviewed'),
                'at' => $this->iso($cmReviewedAt),
                'done' => $cmReviewedAt !== null,
            ],
            [
                'key' => 'review_completed',
                'label' => __('seo-content-ai::filament.projects.timeline_review_completed'),
                'at' => $this->iso($reviewCompletedAt),
                'done' => $reviewCompletedAt !== null,
            ],
            [
                'key' => 'scheduled',
                'label' => __('seo-content-ai::filament.projects.timeline_scheduled'),
                'at' => $this->iso($scheduledAt),
                'done' => $scheduledAt !== null,
            ],
            [
                'key' => 'published',
                'label' => __('seo-content-ai::filament.projects.timeline_published'),
                'at' => $this->iso($publishedAt),
                'done' => $publishedAt !== null,
            ],
            [
                'key' => 'archived',
                'label' => __('seo-content-ai::filament.projects.timeline_archived'),
                'at' => $this->iso($project->archived_at),
                'done' => $project->archived_at !== null,
            ],
        ];

        return $steps;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
