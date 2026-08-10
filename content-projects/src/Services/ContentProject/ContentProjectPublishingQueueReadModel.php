<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusBadgePresenter;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStatusLabelBuilder;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue read model — Summary ≡ List via PublishingQueueStateClassifier.
 */
final class ContentProjectPublishingQueueReadModel
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function forProject(SeoProject $project, array $filters = []): array
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->inPublishingQueue()
            ->with([
                'article.articleMetas' => static fn ($q) => $q->whereIn('meta_key', [
                    'wp_featured_image_url',
                    'wp_permalink',
                ]),
            ])
            ->orderBy('id')
            ->get();

        return $this->buildPayload($tasks, $filters, includeProjectMeta: false);
    }

    /**
     * Independent Publishing Queue hub — all accessible sites, optionally scoped to one project.
     *
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function forHub(?int $projectId, array $filters = []): array
    {
        if ($projectId !== null && $projectId > 0) {
            $project = SeoProjectResource::getRecordRouteBindingEloquentQuery()->find($projectId);
            if ($project instanceof SeoProject) {
                return $this->forProject($project, $filters);
            }

            return ['stats' => PublishingQueueStateClassifier::countSummary([]), 'rows' => []];
        }

        $siteIds = SeoAccessControl::accessibleSiteIds();
        $projectQuery = SeoProject::query();
        if ($siteIds !== []) {
            $projectQuery->whereIn('site_id', $siteIds);
        }
        $projectIds = $projectQuery->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($projectIds === []) {
            return ['stats' => PublishingQueueStateClassifier::countSummary([]), 'rows' => []];
        }

        $tasks = SeoProjectTask::query()
            ->whereIn('project_id', $projectIds)
            ->inPublishingQueue()
            ->with([
                'article.articleMetas' => static fn ($q) => $q->whereIn('meta_key', [
                    'wp_featured_image_url',
                    'wp_permalink',
                ]),
                'project',
            ])
            ->orderBy('id')
            ->get();

        return $this->buildPayload($tasks, $filters, includeProjectMeta: true);
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, int>, rows: list<array<string, mixed>>}
     */
    private function buildPayload(Collection $tasks, array $filters, bool $includeProjectMeta): array
    {
        $rows = $tasks->map(function (SeoProjectTask $task) use ($includeProjectMeta): array {
            $article = $task->article;
            $queue = (string) ($task->publish_queue_status ?? 'none');
            $title = (string) ($article?->title ?? $task->keyword ?? ('#'.$task->getKey()));
            $slug = (string) ($article?->slug ?? '');
            $keyword = trim((string) ($task->keyword ?? ''));
            if ($keyword === '') {
                $keyword = $slug;
            }

            $thumbnailUrl = null;
            $wpPermalink = null;
            if ($article !== null && $article->relationLoaded('articleMetas')) {
                $raw = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'wp_featured_image_url')?->meta_value ?? ''
                ));
                $thumbnailUrl = $raw !== '' ? $raw : null;
                $permalinkRaw = trim((string) (
                    $article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''
                ));
                $wpPermalink = $permalinkRaw !== '' ? $permalinkRaw : null;
            }

            $queuedAt = $task->publishing_queued_at;
            $activityAt = $task->scheduled_publish_at ?? $queuedAt;
            $lastActivity = SystemDateTime::formatRelative($activityAt) ?? '—';
            $lastActivityFull = SystemDateTime::formatDateTime($activityAt) ?? '';
            $scheduleParts = SystemDateTime::formatScheduleParts($task->scheduled_publish_at);
            $scheduledAtDisplay = $scheduleParts['display'] ?? '—';
            $scheduledUtcDebug = SystemDateTime::formatUtcDebug($task->scheduled_publish_at);

            $row = [
                'task_id' => (int) $task->getKey(),
                'project_id' => (int) ($task->project_id ?? 0),
                'item_type' => SeoProjectTask::normalizeType($task->type ?? null),
                'article_id' => (int) ($task->article_id ?? 0) ?: null,
                'primary_label' => $title,
                'title' => $title,
                'keyword' => $keyword !== '' ? $keyword : '—',
                'slug' => $slug,
                'thumbnail_url' => $thumbnailUrl,
                'has_featured_image' => $thumbnailUrl !== null,
                'wp_permalink' => $wpPermalink,
                'scheduled_publish_at' => $task->scheduled_publish_at,
                'scheduled_raw' => $task->scheduled_publish_at?->toIso8601String(),
                'scheduled_at' => $scheduledAtDisplay,
                'scheduled_at_date' => $scheduleParts['date'] ?? null,
                'scheduled_at_time' => $scheduleParts['time'] ?? null,
                'scheduled_utc_debug' => $scheduledUtcDebug,
                'publish_queue_status' => $queue,
                'queue_status' => $queue,
                'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                'lifecycle' => '',
                'last_publish_error' => (string) ($task->last_publish_error ?? ''),
                'last_publish_error_code' => (string) ($task->last_publish_error_code ?? ''),
                'last_publish_error_message' => (string) ($task->last_publish_error_message ?? $task->last_publish_error ?? ''),
                'last_publish_http_status' => (int) ($task->last_publish_http_status ?? 0) ?: null,
                'message' => '',
                'publishing_queued_at' => $queuedAt?->toIso8601String(),
                'last_publish_attempt_at' => $task->last_publish_attempt_at?->toIso8601String(),
                'publishing_started_at' => $task->publishing_started_at?->toIso8601String(),
                'publisher_started_at' => $this->optionalIso($task, 'publisher_started_at'),
                'delivery_dispatched_at' => $this->optionalIso($task, 'delivery_dispatched_at'),
                'publish_attempt_token' => Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_attempt_token')
                    ? (string) ($task->publish_attempt_token ?? '')
                    : '',
                'publish_lease_expires_at' => $task->publish_lease_expires_at?->toIso8601String(),
                'next_publish_retry_at' => $task->next_publish_retry_at?->toIso8601String(),
                'publish_attempt_count' => (int) ($task->publish_attempt_count ?? 0),
                'publish_operation_key' => (string) ($task->publish_operation_key ?? ''),
                'last_publish_failed_at' => $task->last_publish_failed_at?->toIso8601String(),
                'last_activity' => $lastActivity,
                'last_activity_full' => $lastActivityFull,
                'is_recently_completed' => false,
                'article_edit_url' => $task->article_id
                    ? ArticleResource::getUrl('edit', ['record' => (int) $task->article_id])
                    : null,
            ];

            if ($includeProjectMeta) {
                $project = $task->relationLoaded('project') ? $task->project : null;
                $row['project_name'] = $project instanceof SeoProject ? (string) $project->name : '';
                $row['project_url'] = $project instanceof SeoProject
                    ? SeoProjectResource::getProjectWorkspaceUrl($project)
                    : null;
                if ($row['project_name'] !== '') {
                    $row['type_label'] = $row['project_name'];
                }
            }

            $classified = PublishingQueueStateClassifier::classify($row);
            $row['publish_state'] = $classified['state'];
            $row['publish_state_label'] = $classified['label'];
            $row['publish_status_detail'] = PublishingQueueStatusLabelBuilder::label($row);
            $row['publish_badge'] = ContentProjectStatusBadgePresenter::publishQueueState((string) $classified['state']);
            // Keep badge label in sync with attempt/retry detail.
            if (is_array($row['publish_badge'])) {
                $row['publish_badge']['label'] = $classified['label'];
            }

            // Reconciliation "no matching post" is NOT a user-facing error before Published.
            $row['message'] = $this->visiblePublishMessage($row);

            return $row;
        })->values();

        $state = trim((string) ($filters['state'] ?? ''));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $filtered = $rows->filter(static function (array $row) use ($state, $search): bool {
            if ($state !== '' && ! PublishingQueueStateClassifier::matchesFilter($row, $state)) {
                return false;
            }
            if ($search !== '') {
                $hay = strtolower($row['title'].' '.$row['slug'].' '.$row['task_id'].' '.($row['project_name'] ?? ''));
                if (! str_contains($hay, $search)) {
                    return false;
                }
            }

            return true;
        })->values();

        /** @var list<array<string, mixed>> $list */
        $list = $filtered->all();

        return [
            'stats' => PublishingQueueStateClassifier::countSummary($rows->all()),
            'rows' => $list,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function visiblePublishMessage(array $row): string
    {
        $state = (string) ($row['publish_state'] ?? '');
        $code = strtoupper(trim((string) ($row['last_publish_error_code'] ?? '')));
        $msg = trim((string) ($row['last_publish_error_message'] ?? $row['last_publish_error'] ?? ''));

        if ($msg === '') {
            return '';
        }

        $isMissingPost = $code === 'WP_PUBLISHED_POST_NOT_FOUND'
            || str_contains(strtolower($msg), 'wordpress has no matching published post');

        // Never show reconcile-missing-post as a row error before Published evidence exists.
        if ($isMissingPost && ! in_array($state, [
            PublishingQueueStateClassifier::PUBLISHED,
            PublishingQueueStateClassifier::FAILED,
        ], true)) {
            return '';
        }

        if (in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
            PublishingQueueStateClassifier::PUBLISHING,
            PublishingQueueStateClassifier::RETRY_WAIT,
        ], true) && $isMissingPost) {
            return '';
        }

        // Scheduled / unscheduled / publishing: hide stale errors entirely.
        if (in_array($state, [
            PublishingQueueStateClassifier::UNSCHEDULED,
            PublishingQueueStateClassifier::SCHEDULED,
            PublishingQueueStateClassifier::PUBLISHING,
            PublishingQueueStateClassifier::AWAITING_DELIVERY,
        ], true)) {
            return '';
        }

        return $msg;
    }

    private function optionalIso(SeoProjectTask $task, string $column): ?string
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', $column)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $raw = $task->getAttribute($column);
        if ($raw instanceof \Carbon\CarbonInterface) {
            return $raw->toIso8601String();
        }
        if (is_string($raw) && trim($raw) !== '') {
            return $raw;
        }

        return null;
    }
}
