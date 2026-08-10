<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Event;

final class LoggingContentProjectRunEventPublisher implements ContentProjectRunEventPublisher
{
    public function publish(SeoProjectRun $run, string $event, array $payload = []): void
    {
        $body = array_merge([
            'event' => $event,
            'run_id' => (int) $run->id,
            'project_id' => (int) ($run->project_id ?? 0),
            'status' => (string) ($run->status ?? ''),
        ], $payload);

        RuntimeLogger::info('seo.content_project_run.event.'.$event, $body);

        Event::dispatch('seo.content_project_run.'.$event, [$run, $body]);
    }

    public function runStarted(SeoProjectRun $run): void
    {
        $this->publish($run, 'run_started', [
            'total' => (int) ($run->total ?? 0),
        ]);
    }

    public function runStopping(SeoProjectRun $run, ?string $reason = null): void
    {
        $this->publish($run, 'run_stopping', [
            'reason' => $reason,
        ]);
    }

    public function runCancelled(SeoProjectRun $run, ?string $reason = null): void
    {
        $this->publish($run, 'run_cancelled', [
            'reason' => $reason,
        ]);
    }

    public function articleStarted(SeoProjectRun $run, int $taskId, ?int $runItemId = null): void
    {
        $this->publish($run, 'article_started', [
            'task_id' => $taskId,
            'run_item_id' => $runItemId,
        ]);
    }

    public function articleCompleted(SeoProjectRun $run, ArticleExecutionResult $result): void
    {
        $this->publish($run, 'article_completed', [
            'task_id' => $result->taskId,
            'run_item_id' => $result->runItemId,
            'article_id' => $result->articleId,
            'message' => $result->message,
        ]);
    }

    public function articleFailed(SeoProjectRun $run, ArticleExecutionResult $result): void
    {
        $this->publish($run, 'article_failed', [
            'task_id' => $result->taskId,
            'run_item_id' => $result->runItemId,
            'article_id' => $result->articleId,
            'message' => $result->message,
            'error_code' => $result->errorCode,
        ]);
    }

    public function articleCancelled(SeoProjectRun $run, ArticleExecutionResult $result): void
    {
        $this->publish($run, 'article_cancelled', [
            'task_id' => $result->taskId,
            'run_item_id' => $result->runItemId,
            'message' => $result->message,
        ]);
    }

    public function runProgressUpdated(SeoProjectRun $run): void
    {
        $this->publish($run, 'run_progress_updated', [
            'total' => (int) ($run->total ?? 0),
            'succeeded' => (int) ($run->succeeded ?? 0),
            'failed' => (int) ($run->failed ?? 0),
        ]);
    }

    public function runCompleted(SeoProjectRun $run): void
    {
        $this->publish($run, 'run_completed', [
            'total' => (int) ($run->total ?? 0),
            'succeeded' => (int) ($run->succeeded ?? 0),
            'failed' => (int) ($run->failed ?? 0),
        ]);
    }

    public function runFailed(SeoProjectRun $run, string $message): void
    {
        $this->publish($run, 'run_failed', [
            'message' => $message,
        ]);
    }
}
