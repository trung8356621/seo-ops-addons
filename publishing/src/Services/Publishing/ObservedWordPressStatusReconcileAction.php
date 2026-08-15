<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Services\WordPressObservedReconcileService;
use Omnichannel\Addons\WordPress\Services\WordPressObservedStateService;
use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;
use Carbon\Carbon;

/**
 * Human "Kiểm tra lại trạng thái": persist observed WP facts, repair Laravel queue only when safe.
 */
final class ObservedWordPressStatusReconcileAction
{
    public const PROCESSING_STALE_MINUTES = 45;

    public function __construct(
        private readonly WordPressObservedReconcileService $observe,
        private readonly PublishingQueueRemoteReconcileService $queueReconcile,
        private readonly WordPressObservedStateService $observedState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forArticle(SeoArticle $article): array
    {
        $observed = $this->observe->observeArticle($article);
        $task = SeoProjectTask::query()
            ->where('article_id', (int) $article->getKey())
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->first();

        $repair = null;
        if ($task instanceof SeoProjectTask) {
            $repair = $this->repairTask($task, $article, $observed);
        }

        return [
            'ok' => (bool) ($observed['ok'] ?? false),
            'observed' => $observed,
            'desired_queue_status' => $task instanceof SeoProjectTask
                ? (string) ($task->publish_queue_status ?? '')
                : null,
            'repair' => $repair,
            'message' => (string) ($repair['message'] ?? $observed['message'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forTask(SeoProjectTask $task): array
    {
        $task->loadMissing('article');
        $article = $task->article;
        if (! $article instanceof SeoArticle) {
            return ['ok' => false, 'message' => 'Task missing article.'];
        }

        $observed = $this->observe->observeArticle($article);
        $repair = $this->repairTask($task, $article, $observed);

        return [
            'ok' => (bool) ($observed['ok'] ?? false),
            'observed' => $observed,
            'desired_queue_status' => (string) ($task->fresh()?->publish_queue_status ?? $task->publish_queue_status ?? ''),
            'repair' => $repair,
            'message' => (string) ($repair['message'] ?? $observed['message'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function repairTask(SeoProjectTask $task, SeoArticle $article, array $observed): array
    {
        $desired = (string) ($task->publish_queue_status ?? '');
        $wpStatus = (string) ($observed['observed_post_status'] ?? '');
        $timeout = (bool) ($observed['timeout'] ?? false);

        if ($timeout) {
            $this->markAttention($article, $task);

            return [
                'applied' => false,
                'action' => 'needs_attention',
                'message' => 'Timeout while observing WordPress; not auto-repairing.',
            ];
        }

        $failedOrStuck = in_array($desired, [
            ContentProjectPublishQueueStatus::Failed->value,
            ContentProjectPublishQueueStatus::Processing->value,
            ContentProjectPublishQueueStatus::Retrying->value,
            ContentProjectPublishQueueStatus::QueuedForDelivery->value,
        ], true);

        if ($failedOrStuck && ObservedWordPressPostStatus::isLiveOnSite($wpStatus)) {
            $classified = $this->queueReconcile->classifyOne((int) $task->getKey(), false);

            return [
                'applied' => (bool) ($classified['applied'] ?? false),
                'action' => (string) ($classified['action'] ?? 'mark_published'),
                'message' => 'Laravel queue repaired from observed WordPress publish.',
                'classification' => $classified['classification'] ?? null,
            ];
        }

        if ($desired === ContentProjectPublishQueueStatus::Processing->value && $this->processingIsStale($task)) {
            if ($wpStatus === ObservedWordPressPostStatus::MISSING
                || in_array($wpStatus, [ObservedWordPressPostStatus::DRAFT, ObservedWordPressPostStatus::PENDING], true)) {
                $this->markAttention($article, $task);

                return [
                    'applied' => false,
                    'action' => 'needs_attention',
                    'message' => 'Processing too long and WordPress is not published.',
                ];
            }
        }

        if ($wpStatus === ObservedWordPressPostStatus::MISSING && $failedOrStuck) {
            $this->markAttention($article, $task);

            return [
                'applied' => false,
                'action' => 'needs_attention',
                'message' => 'WordPress post missing.',
            ];
        }

        if (($observed['permalink_changed'] ?? false) === true) {
            return [
                'applied' => true,
                'action' => 'permalink_updated',
                'message' => 'Observed permalink updated.',
            ];
        }

        return [
            'applied' => false,
            'action' => 'none',
            'message' => (string) ($observed['message'] ?? 'Observed state stored.'),
        ];
    }

    private function processingIsStale(SeoProjectTask $task): bool
    {
        $started = $task->publisher_started_at ?? $task->publishing_started_at ?? $task->last_publish_attempt_at;
        if ($started === null) {
            return false;
        }

        return Carbon::parse($started)->lt(now()->subMinutes(self::PROCESSING_STALE_MINUTES));
    }

    private function markAttention(SeoArticle $article, SeoProjectTask $task): void
    {
        $this->observedState->persist($article, [
            'reconcile_status' => WordPressObservedStateService::RECONCILE_NEEDS_ATTENTION,
        ]);
        unset($task);
    }
}
