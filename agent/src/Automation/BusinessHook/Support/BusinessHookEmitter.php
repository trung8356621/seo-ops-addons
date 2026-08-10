<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Helper emit domain business events từ application services.
 */
final class BusinessHookEmitter
{
    public function __construct(
        private readonly BusinessEventDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function emit(
        BusinessEventName|string $event,
        ?Model $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): void {
        $this->emitOutcomeSafely($event, $subject, $payload, $context, $eventUuid);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function emitWithOutcome(
        BusinessEventName|string $event,
        ?Model $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): \Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationEventDispatchResult {
        $name = $event instanceof BusinessEventName ? $event->value : $event;

        try {
            return $this->dispatcher->dispatchWithOutcome(
                eventName: $name,
                subject: $subject,
                payload: $payload,
                context: $context,
                eventUuid: $eventUuid,
            );
        } catch (\Throwable $e) {
            report($e);

            return new \Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationEventDispatchResult(
                outcome: \Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEventDispatchOutcome::FailedToDispatch,
                message: $e->getMessage(),
                errorCode: 'AUTOMATION_DISPATCH_EXCEPTION',
            );
        }
    }

    /**
     * Best-effort outcome/domain event. SKIPPED_NO_RULE / dispatcher lỗi không lan ra caller.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function emitOutcomeSafely(
        BusinessEventName|string $event,
        ?Model $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): void {
        $name = $event instanceof BusinessEventName ? $event->value : $event;
        $result = $this->emitWithOutcome($event, $subject, $payload, $context, $eventUuid);

        if ($result->isSkippedNoRule()) {
            return;
        }

        if ($result->isRejectedOrInvalid()) {
            Log::warning('automation.outcome_event_dispatch_failed', [
                'event_name' => $name,
                'outcome' => $result->outcome->value,
                'error_code' => $result->errorCode,
                'message' => $result->message,
            ]);
        }
    }

    public function articleCreated(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleCreated, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => (string) ($article->status ?? ''),
        ], $context);
    }

    public function articleContentUpdated(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleContentUpdated, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ], $context);
    }

    public function articleCompleted(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleCompleted, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'completed',
        ], $context);
    }

    public function articleDeleted(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleDeleted, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ], $context);
    }

    public function articleArchived(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleArchived, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'archived',
        ], $context);
    }

    public function articleRestored(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleRestored, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'restored',
        ], $context);
    }

    public function taskCreated(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskCreated, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'site_id' => (int) ($task->site_id ?? 0) ?: null,
        ], $context);
    }

    public function taskUpdated(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskUpdated, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'status' => (string) ($task->status ?? ''),
        ], $context);
    }

    public function taskCompleted(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskCompleted, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'site_id' => (int) ($task->site_id ?? 0) ?: null,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'status' => 'completed',
        ], $context);
    }

    public function taskFailed(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskFailed, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'site_id' => (int) ($task->site_id ?? 0) ?: null,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'status' => 'failed',
        ], $context);
    }

    public function taskArchived(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskArchived, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'status' => 'archived',
        ], $context);
    }

    public function runStarted(SeoProjectRun $run, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectRunStarted, $run, [
            'run_id' => (int) $run->id,
            'project_id' => (int) ($run->project_id ?? 0) ?: null,
            'status' => 'running',
        ], $context);
    }

    public function runCompleted(SeoProjectRun $run, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectRunCompleted, $run, [
            'run_id' => (int) $run->id,
            'project_id' => (int) ($run->project_id ?? 0) ?: null,
            'status' => 'completed',
        ], $context);
    }

    public function runFailed(SeoProjectRun $run, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectRunFailed, $run, [
            'run_id' => (int) $run->id,
            'project_id' => (int) ($run->project_id ?? 0) ?: null,
            'status' => 'failed',
        ], $context);
    }

    public function wordpressSynced(SeoArticle $article, array $result = [], array $context = []): void
    {
        $this->emit(BusinessEventName::WordpressSynced, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'wp_post_id' => $result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? null,
            'status' => 'synced',
            'origin' => $result['origin'] ?? $context['origin'] ?? 'automatic',
        ], $context);
    }

    public function wordpressSyncFailed(SeoArticle $article, string $error, array $context = []): void
    {
        $this->emit(BusinessEventName::WordpressSyncFailed, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'error' => $error,
            'status' => 'failed',
        ], $context);
    }

    public function wordpressSyncStarted(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::WordpressSyncStarted, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'started',
        ], $context);
    }

    public function mediaUploaded(SeoMedia $media, array $context = []): void
    {
        $this->emit(BusinessEventName::MediaUploaded, $media, [
            'media_id' => (int) $media->id,
            'site_id' => (int) ($media->site_id ?? 0) ?: null,
            'article_id' => (int) ($media->article_id ?? 0) ?: null,
        ], $context);
    }

    public function mediaProcessed(SeoMedia $media, array $context = []): void
    {
        $this->emit(BusinessEventName::MediaProcessed, $media, [
            'media_id' => (int) $media->id,
            'site_id' => (int) ($media->site_id ?? 0) ?: null,
            'article_id' => (int) ($media->article_id ?? 0) ?: null,
        ], $context);
    }

    public function mediaFailed(SeoMedia $media, string $error, array $context = []): void
    {
        $this->emit(BusinessEventName::MediaFailed, $media, [
            'media_id' => (int) $media->id,
            'site_id' => (int) ($media->site_id ?? 0) ?: null,
            'error' => $error,
        ], $context);
    }

    public function seoAnalysisStarted(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::SeoAnalysisStarted, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ], $context);
    }

    public function seoAnalysisCompleted(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::SeoAnalysisCompleted, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ], $context);
    }

    public function seoAnalysisFailed(SeoArticle $article, string $error, array $context = []): void
    {
        $this->emit(BusinessEventName::SeoAnalysisFailed, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'error' => $error,
        ], $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function notificationRequested(array $payload, array $context = []): void
    {
        $this->emit(BusinessEventName::NotificationRequested, null, $payload, $context);
    }

    public function keywordSaved(Keyword $keyword, array $payload = [], array $context = []): void
    {
        $this->emit(BusinessEventName::KeywordSaved, $keyword, array_merge([
            'keyword_id' => (int) $keyword->id,
            'phrase' => trim((string) ($keyword->phrase ?? '')),
            'site_id' => (int) ($payload['site_id'] ?? $keyword->resolveSiteId() ?? 0) ?: null,
        ], $payload), $context);
    }

    public function articleApproved(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleApproved, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
        ], $context);
    }

    /**
     * Outcome emit with stable operation identity (event_uuid dedupe).
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     */
    public function wordpressSyncedOnce(
        SeoArticle $article,
        string $syncOperationId,
        array $result = [],
        array $context = [],
    ): void {
        $syncOperationId = trim($syncOperationId);
        if ($syncOperationId === '') {
            $this->wordpressSynced($article, $result, $context);

            return;
        }

        $this->emitOutcomeSafely(
            BusinessEventName::WordpressSynced,
            $article,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'wp_post_id' => $result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? null,
                'status' => 'synced',
                'origin' => $result['origin'] ?? $context['origin'] ?? 'automatic',
                'sync_operation_id' => $syncOperationId,
            ],
            array_merge($context, ['sync_operation_id' => $syncOperationId]),
            $syncOperationId,
        );
    }
}
