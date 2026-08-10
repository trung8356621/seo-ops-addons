<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Events;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Bridges ActionRunner EventEnvelope → Business Hook dispatcher + log.
 * Resolve BusinessEventDispatcher lazily để tránh circular DI với ActionRunner.
 */
final class BridgingAutomationEventDispatcher implements AutomationEventDispatcher
{
    /** @var array<string, string> */
    private const KEY_MAP = [
        'article.created' => 'article.created',
        'article.content_updated' => 'article.content_updated',
        'article.seo_meta_updated' => 'article.seo_meta_updated',
        'article.approved' => 'article.approved',
        'keyword.saved' => 'keyword.saved',
        'project.task_created' => 'content_project.task.created',
        'project.task_completed' => 'content_project.task.completed',
        'project.task_failed' => 'content_project.task.failed',
        'project.task_archived' => 'content_project.task.archived',
        'content_project.run.completed' => 'content_project.run.completed',
        'wordpress.synced' => 'wordpress.synced',
        'wordpress.sync_failed' => 'wordpress.sync_failed',
        'article.completed' => 'article.completed',
        'article.archived' => 'article.archived',
        'article.restored' => 'article.restored',
        'seo.audit_completed' => 'seo.analysis_completed',
        'seo.analysis_started' => 'seo.analysis_started',
        'seo.analysis_completed' => 'seo.analysis_completed',
        'seo.analysis_failed' => 'seo.analysis_failed',
        'media.uploaded' => 'media.uploaded',
        'media.processed' => 'media.processed',
        'media.failed' => 'media.failed',
        'notification.requested' => 'notification.requested',
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function dispatch(EventEnvelope $event): void
    {
        Log::info('automation.event', $event->toArray());

        $mapped = self::KEY_MAP[$event->eventKey] ?? null;
        if ($mapped === null) {
            return;
        }

        try {
            $businessEvents = $this->container->make(BusinessEventDispatcher::class);
            $subject = $this->resolveSubject($event);
            $payload = $this->normalizePayload($mapped, $event);

            $businessEvents->dispatch(
                eventName: $mapped,
                subject: $subject,
                payload: $payload,
                context: $event->context,
                eventUuid: $event->eventId,
            );

            // Acceptance path: task completed with article → also emit article.completed
            // Content Project Run defers WordPress sync to manual "Sync all".
            if ($mapped === BusinessEventName::ContentProjectTaskCompleted->value) {
                $articleId = (int) ($payload['article_id'] ?? 0);
                if ($articleId > 0 && $this->shouldBridgeArticleCompleted($event)) {
                    $article = SeoArticle::query()->find($articleId);
                    $businessEvents->dispatch(
                        eventName: BusinessEventName::ArticleCompleted->value,
                        subject: $article instanceof SeoArticle ? $article : SeoArticle::class,
                        payload: [
                            'article_id' => $articleId,
                            'project_id' => $payload['project_id'] ?? null,
                            'site_id' => $payload['site_id'] ?? ($event->context['site_id'] ?? null),
                            'task_id' => $payload['task_id'] ?? $event->entity['id'] ?? null,
                            'status' => 'completed',
                            'post_type' => $payload['post_type'] ?? null,
                        ],
                        context: array_merge($event->context, [
                            'from_event' => $mapped,
                            'automation_depth' => (int) ($event->context['automation_depth'] ?? 0),
                        ]),
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('automation.business_event_bridge_failed', [
                'event_key' => $event->eventKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldBridgeArticleCompleted(EventEnvelope $event): bool
    {
        if (filter_var($event->context['suppress_article_completed_bridge'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $origin = (string) ($event->context['origin'] ?? '');

        return $origin !== 'content_project_run';
    }

    private function resolveSubject(EventEnvelope $event): Model|string|null
    {
        $type = (string) ($event->entity['type'] ?? '');
        $id = isset($event->entity['id']) ? (int) $event->entity['id'] : 0;

        return match ($type) {
            'article' => $id > 0 ? (SeoArticle::query()->find($id) ?? SeoArticle::class) : SeoArticle::class,
            'project_task' => $id > 0 ? (SeoProjectTask::query()->find($id) ?? SeoProjectTask::class) : SeoProjectTask::class,
            'project_run' => $id > 0 ? (SeoProjectRun::query()->find($id) ?? SeoProjectRun::class) : SeoProjectRun::class,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(string $mapped, EventEnvelope $event): array
    {
        $payload = $event->payload;
        $entityId = $event->entity['id'] ?? null;

        return match ($mapped) {
            'article.created', 'article.content_updated', 'article.seo_meta_updated', 'article.approved', 'article.completed', 'article.archived', 'article.restored' => array_merge([
                'article_id' => $payload['article_id'] ?? $entityId,
                'site_id' => $payload['site_id'] ?? ($event->context['site_id'] ?? null),
            ], $payload),
            'keyword.saved' => array_merge([
                'keyword_id' => $payload['keyword_id'] ?? $entityId,
                'site_id' => $payload['site_id'] ?? ($event->context['site_id'] ?? null),
            ], $payload),
            'content_project.task.created', 'content_project.task.completed', 'content_project.task.failed', 'content_project.task.archived' => array_merge([
                'task_id' => $payload['task_id'] ?? $entityId,
                'project_id' => $payload['project_id'] ?? ($event->context['project_id'] ?? null),
                'site_id' => $payload['site_id'] ?? ($event->context['site_id'] ?? null),
            ], $payload),
            'content_project.run.completed' => array_merge([
                'run_id' => $payload['run_id'] ?? $entityId,
                'project_id' => $payload['project_id'] ?? null,
            ], $payload),
            'wordpress.synced', 'wordpress.sync_failed' => array_merge([
                'article_id' => $payload['article_id'] ?? $entityId,
                'site_id' => $payload['site_id'] ?? null,
            ], $payload),
            default => $payload,
        };
    }
}
