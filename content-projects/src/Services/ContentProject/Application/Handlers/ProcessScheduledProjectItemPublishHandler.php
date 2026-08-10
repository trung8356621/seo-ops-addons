<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ProcessScheduledProjectItemPublishCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ArticlePublishFailed;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ArticlePublishRequested;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ArticlePublished;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use Omnichannel\Addons\Publishing\Application\Publishing\ArticlePublishPayload;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishAttemptRefs;
use Omnichannel\Addons\Publishing\Application\Publishing\PublisherResolutionException;
use Omnichannel\Addons\Publishing\Application\Publishing\PublisherResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyKeyFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategy;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategyResolver;
use Omnichannel\Addons\Publishing\Services\Publishing\DispatchClaimResult;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\WordPressSlugFixRequiredException;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class ProcessScheduledProjectItemPublishHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly ContentProjectQueueHealthService $health,
        private readonly PublisherResolver $publisherResolver,
        private readonly BusinessHookEmitter $emitter,
        private readonly ContentProjectDomainEvents $domainEvents,
        private readonly ContentProjectIdempotencyStore $idempotencyStore,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ContentPublishingStrategyResolver $strategyResolver = new ContentPublishingStrategyResolver,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ProcessScheduledProjectItemPublishCommand) {
            throw new InvalidArgumentException('Expected ProcessScheduledProjectItemPublishCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $itemId = $this->resolveItemIds([$command->itemRef])[0] ?? 0;
            if ($itemId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Invalid item ref.',
                );
            }

            $task = SeoProjectTask::query()->with(['article', 'project'])->find($itemId);
            if (! $task instanceof SeoProjectTask) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::ITEMS_NOT_FOUND,
                    'Task không tồn tại.',
                );
            }

            $project = $task->project;
            if (! $project instanceof SeoProject) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_NOT_FOUND,
                    'Project không tồn tại.',
                );
            }

            $projectId = (int) $project->getKey();

            if ($actor->actorType !== 'queue') {
                $this->tenantGuard->assertCanAccessProject($project, $actor);
            }

            $idemKey = $this->resolveCommandBusIdempotencyKey($task, $actor);
            $tenantKey = 'site:'.(string) ($project->site_id ?? 0).':queue';
            if ($idemKey !== '') {
                $replay = $this->idempotencyStore->begin($tenantKey, $command->name(), $idemKey);
                if ($replay instanceof ContentProjectActionResult) {
                    return $replay;
                }
            }

            $result = $this->businessLock->withLock(
                $this->businessLock->itemPublish($itemId),
                function () use ($task, $projectId, $command): ContentProjectActionResult {
                    return $this->processPublish($task->fresh() ?? $task, $projectId, $command->attemptRef);
                },
            );

            // Always complete — including delivery_requested — so key không kẹt "processing".
            // Command-bus key includes attempt; WP publish_operation_key stays stable separately.
            if ($idemKey !== '') {
                $this->idempotencyStore->complete($tenantKey, $command->name(), $idemKey, $result);
            }

            return $result;
        });
    }

    private function processPublish(SeoProjectTask $task, int $projectId, ?string $attemptRef): ContentProjectActionResult
    {
        $itemId = (int) $task->getKey();
        $article = $task->article;

        if (! $article instanceof SeoArticle) {
            $this->queue->markFailed($task, 'Task không có article.');
            $this->health->rememberFailure('Task #'.$itemId.' missing article');

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                'Task không có article.',
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        // Atomic claim for delivery dispatch (queued_for_delivery) — not publisher lease.
        $strategy = $this->strategyResolver->resolve($task, $article);
        if ($strategy->isMissingRemote()) {
            $message = 'Khong tim thay bai WordPress goc de cap nhat.';
            $this->persistPublishFailure($task, $message, 'missing_remote_post');

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                $message,
                $projectId,
                affectedItemIds: [$itemId],
                metadata: [
                    'publishing_mode' => ContentPublishingStrategy::FAILED_MISSING_REMOTE,
                    'scheduled_publish_at' => null,
                ],
            );
        }

        try {
            app(WordPressWriteReadinessGuard::class)->assertCanWriteToWordPress($article, 'publishing_queue.process');
        } catch (WordPressSlugFixRequiredException $e) {
            RuntimeLogger::info('publishing.prerequisite_blocked', [
                'task_id' => $itemId,
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'error_code' => WordPressSlugFixRequiredException::ERROR_CODE,
                'pending_count' => (int) ($e->context['pending_count'] ?? 0),
            ]);

            return ContentProjectActionResult::fail(
                WordPressSlugFixRequiredException::ERROR_CODE,
                WordPressSlugFixRequiredException::MESSAGE,
                $projectId,
                affectedItemIds: [$itemId],
                metadata: [
                    'blocked_prerequisite' => true,
                    'retry_count_unchanged' => true,
                    'publisher_invoked' => false,
                    'media_upload_started' => false,
                ],
            );
        }

        $claim = $this->queue->claimForDispatch($task);
        if (! $claim->isClaimed()) {
            $fresh = $claim->task ?? $task->fresh() ?? $task;
            RuntimeLogger::warning('publishing.claim_rejected', [
                'task_id' => $itemId,
                'claim_code' => $claim->code,
                'claim_message' => $claim->message,
                'publish_queue_status' => (string) ($fresh->publish_queue_status ?? ''),
            ]);

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::OPERATION_ALREADY_PROCESSING,
                $claim->message !== ''
                    ? $claim->message
                    : 'Item không claim được.',
                $projectId,
                affectedItemIds: [$itemId],
                metadata: array_merge([
                    'claim_code' => $claim->code,
                    'actively_publishing' => $claim->code === DispatchClaimResult::ACTIVE_PUBLISH,
                    'publish_queue_status' => (string) ($fresh->publish_queue_status ?? ''),
                    'publish_lease_expires_at' => $fresh->publish_lease_expires_at?->utc()->toIso8601String(),
                ], $claim->meta),
            );
        }
        $task = $claim->task;

        $operationKey = trim((string) ($task->publish_operation_key ?? ''));
        if ($operationKey === '') {
            $operationKey = app(\Omnichannel\Addons\Publishing\Application\Publishing\PublishOperationKeyFactory::class)
                ->forTask($task, $article);
        }

        $attemptRef = $attemptRef ?? PublishAttemptRefs::newAttemptRef();
        $attemptToken = trim((string) ($task->publish_attempt_token ?? ''));
        if ($attemptToken === '') {
            $attemptToken = $attemptRef;
        }
        $externalRef = PublishAttemptRefs::forArticle((int) $article->id);
        $payload = new ArticlePublishPayload(
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            wpPostId: (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
            externalReference: $externalRef,
            attemptRef: $attemptRef,
            idempotencyKey: $operationKey,
            projectId: $projectId,
            taskId: $itemId,
            actorUserId: null,
        );

        try {
            $publisher = $this->publisherResolver->resolveForSiteId((int) ($article->site_id ?? 0));
            $publishResult = $publisher->publish($payload);
        } catch (PublisherResolutionException $e) {
            $this->persistPublishFailure($task, $e->getMessage(), 'publisher_resolution');
            $this->health->rememberFailure($e->getMessage());
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                error: $e->getMessage(),
            ));

            return ContentProjectActionResult::fail(
                $e->resultCode,
                $e->getMessage(),
                $projectId,
                affectedItemIds: [$itemId],
            );
        } catch (\Throwable $e) {
            $this->persistPublishFailure($task, $e->getMessage(), 'publish_exception');
            $this->health->rememberFailure($e->getMessage());
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                error: $e->getMessage(),
            ));

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                $e->getMessage(),
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ($publishResult->alreadyPublished && $publishResult->wpPostId !== null && $publishResult->wpPostId > 0) {
            $this->queue->markPublished($task->fresh() ?? $task);
            $this->health->rememberSuccess(1);
            $this->rememberPublishedContentHash($article);
            $this->domainEvents->dispatchAfterCommit(new ArticlePublished(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                wpPostId: $publishResult->wpPostId,
            ));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                'Already published — reconciled.',
                $projectId,
                [$itemId],
            );
        }

        if (! $publishResult->success) {
            $this->persistPublishFailure($task, $publishResult->message, 'publish_result_failed');
            $this->health->rememberFailure($publishResult->message);
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                error: $publishResult->message,
            ));

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FAILED,
                $publishResult->message,
                $projectId,
                affectedItemIds: [$itemId],
            );
        }

        if ($publishResult->deliveryRequested) {
            // Delivery requested only — claim Processing after publisher accept.
            // Do NOT markPublished here (prevents false Published before WP evidence).
            $emitResult = $this->emitter->emitWithOutcome(BusinessEventName::ArticlePublishRequested, $article, [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'project_id' => $projectId ?: null,
                'task_id' => $itemId,
                'scheduled_publish_at' => $task->scheduled_publish_at?->toIso8601String(),
                'status' => 'publish_requested',
                'source' => 'content_project_publishing_queue',
                'publish_mode' => $strategy->isImmediateUpdate() ? 'update_existing' : 'publish',
                'remote_post_id' => $strategy->remotePostId,
                'attempt_ref' => $attemptRef,
                'publish_attempt_token' => $attemptToken,
                'external_reference' => $externalRef,
            ]);

            if ($emitResult->isSkippedNoRule()) {
                $message = 'Thiếu automation rule article.publish_requested → wordpress.article.sync '
                    .'(code dispatch-publish-request). Chạy: php artisan automation:seed-rules '
                    .'rồi bật/publish rule; đảm bảo queue worker chạy.';
                $this->persistPublishFailure($task->fresh() ?? $task, $message, 'automation_missing_rule');
                $this->health->rememberFailure($message);
                $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                    projectId: $projectId,
                    itemId: $itemId,
                    articleId: (int) $article->id,
                    error: $message,
                ));

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    $message,
                    $projectId,
                    affectedItemIds: [$itemId],
                    metadata: [
                        'automation_skip_code' => $emitResult->errorCode,
                        'attempt_ref' => $attemptRef,
                    ],
                );
            }

            if ($emitResult->isRejectedOrInvalid()) {
                $message = 'Không dispatch được article.publish_requested: '
                    .($emitResult->message ?? $emitResult->errorCode ?? 'unknown');
                $this->persistPublishFailure($task->fresh() ?? $task, $message, 'automation_rejected');
                $this->health->rememberFailure($message);
                $this->domainEvents->dispatchAfterCommit(new ArticlePublishFailed(
                    projectId: $projectId,
                    itemId: $itemId,
                    articleId: (int) $article->id,
                    error: $message,
                ));

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FAILED,
                    $message,
                    $projectId,
                    affectedItemIds: [$itemId],
                    metadata: [
                        'automation_skip_code' => $emitResult->errorCode,
                        'attempt_ref' => $attemptRef,
                    ],
                );
            }

            // Stay queued_for_delivery until WP worker calls beginPublisherAttempt.
            $this->domainEvents->dispatchAfterCommit(new ArticlePublishRequested(
                projectId: $projectId,
                itemId: $itemId,
                articleId: (int) $article->id,
                attemptRef: $attemptRef,
            ));

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
                'Publish delivery requested (awaiting worker).',
                $projectId,
                [$itemId],
                metadata: [
                    'attempt_ref' => $attemptRef,
                    'publish_attempt_token' => $attemptToken,
                    'delivery_requested' => true,
                    'queued_for_delivery' => true,
                    'automation_outcome' => $emitResult->outcome->value,
                    'matched_rules' => $emitResult->matchedRules,
                    'operation_key' => $operationKey,
                ],
            );
        }

        $this->queue->markPublished($task->fresh() ?? $task);
        $this->health->rememberSuccess(1);
        $this->rememberPublishedContentHash($article);

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::ITEMS_PUBLISH_QUEUED,
            'Publish processed.',
            $projectId,
            [$itemId],
            metadata: ['attempt_ref' => $attemptRef],
        );
    }

    private function persistPublishFailure(SeoProjectTask $task, string $message, string $code): void
    {
        $classifier = app(\Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassifier::class);
        $retryPolicy = app(\Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy::class);
        $classification = $classifier->classify(null, [
            'code' => $code,
            'message' => $message,
        ]);

        // Missing automation / publisher resolution = permanent (do not burn retries).
        if (in_array($code, ['automation_missing_rule', 'automation_rejected', 'publisher_resolution'], true)) {
            $classification = new \Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification(
                retryable: false,
                code: $classification->code,
                message: $classification->message,
                httpStatus: $classification->httpStatus,
            );
        }

        $attempt = max(1, (int) ($task->publish_attempt_count ?? 1));
        if ($classification->retryable && $retryPolicy->canRetry($attempt)) {
            $this->queue->markRetryWait(
                $task,
                $classification,
                $retryPolicy->nextRetryAt($attempt, $classification->retryAfter),
            );

            return;
        }

        $this->queue->markFailedFromClassification($task, $classification);
    }

    private function rememberPublishedContentHash(SeoArticle $article): void
    {
        $fresh = $article->fresh() ?? $article;
        $this->syncFlags->rememberPublishedContentHash(
            $fresh,
            hash('sha256', trim((string) ($fresh->body ?? ''))),
        );
    }

    /**
     * Command-bus dedupe key — scoped per attempt so retry_wait can re-enter.
     * WordPress payload still uses stable publish_operation_key (not this key).
     */
    private function resolveCommandBusIdempotencyKey(SeoProjectTask $task, ActorContext $actor): string
    {
        $opKey = trim((string) ($task->publish_operation_key ?? ''));
        if ($opKey === '') {
            $opKey = trim((string) ($actor->idempotencyKey ?? ''));
        }
        if ($opKey === '' && $task->scheduled_publish_at !== null) {
            $opKey = ContentProjectIdempotencyKeyFactory::scheduler(
                (int) $task->getKey(),
                $task->scheduled_publish_at->toIso8601String(),
            );
        }
        if ($opKey === '') {
            return '';
        }

        $nextAttempt = max(1, (int) ($task->publish_attempt_count ?? 0) + 1);
        $status = (string) ($task->publish_queue_status ?? '');
        if ($status === ContentProjectPublishQueueStatus::Processing->value) {
            $nextAttempt = max(1, (int) ($task->publish_attempt_count ?? 1));
        }

        return $opKey.':attempt:'.$nextAttempt;
    }

    /** @deprecated use resolveCommandBusIdempotencyKey */
    private function resolveIdempotencyKey(SeoProjectTask $task, ActorContext $actor): string
    {
        return $this->resolveCommandBusIdempotencyKey($task, $actor);
    }
}
