<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemDashboardBucket;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemExecutionState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ApproveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\StartReviewHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectRerunEligibilityGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemDashboardBucketMapper;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusDecision;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Batch D verification — fixture contract across resolver / lifecycle / dashboard / actions.
 */
final class ContentProjectItemStateContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private ContentProjectItemStateResolver $resolver;

    private ContentProjectLifecycle $lifecycle;

    private ContentProjectItemActionGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ContentProjectItemActionGuard;
        $this->resolver = new ContentProjectItemStateResolver($this->guard);
        $this->lifecycle = new ContentProjectLifecycle($this->resolver);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function fixtureProvider(): iterable
    {
        yield 'draft' => [
            ['status' => 'pending'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Draft,
                'generation' => ContentProjectItemGenerationState::Pending,
                'review' => ContentProjectItemReviewState::None,
                'publish' => ContentProjectItemPublishState::None,
                'archive' => ContentProjectItemArchiveState::None,
                'bucket' => ContentProjectItemDashboardBucket::WaitingAi,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Generate,
                    ContentProjectItemAction::StartReview,
                ],
            ],
        ];
        yield 'generating' => [
            ['status' => 'writing'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Generating,
                'generation' => ContentProjectItemGenerationState::Writing,
                'bucket' => ContentProjectItemDashboardBucket::AiRunning,
                'actions' => [],
                'blocked' => [ContentProjectItemAction::Generate, ContentProjectItemAction::Archive],
            ],
        ];
        yield 'generation_failed' => [
            ['status' => 'failed'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Failed,
                'generation' => ContentProjectItemGenerationState::Failed,
                'error_source' => ContentProjectItemErrorSource::Generation,
                'bucket' => ContentProjectItemDashboardBucket::Failed,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Generate,
                    ContentProjectItemAction::Rerun,
                ],
            ],
        ];
        yield 'completed_pending_review' => [
            ['status' => 'completed', 'article' => ['review_status' => 'draft']],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Review,
                'review' => ContentProjectItemReviewState::Draft,
                'bucket' => ContentProjectItemDashboardBucket::WaitingReview,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                    ContentProjectItemAction::StartReview,
                    ContentProjectItemAction::Approve,
                    ContentProjectItemAction::Schedule,
                    ContentProjectItemAction::PublishNow,
                ],
            ],
        ];
        yield 'approved' => [
            ['status' => 'completed', 'publish_queue_status' => 'none', 'article' => ['review_status' => 'approved']],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Approved,
                'review' => ContentProjectItemReviewState::Approved,
                'bucket' => ContentProjectItemDashboardBucket::Approved,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                    ContentProjectItemAction::Schedule,
                    ContentProjectItemAction::PublishNow,
                ],
                'blocked' => [ContentProjectItemAction::Approve, ContentProjectItemAction::Generate],
            ],
        ];
        yield 'review_status_archived' => [
            ['status' => 'completed', 'article' => ['review_status' => 'archived']],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Review,
                'review' => ContentProjectItemReviewState::ReviewArchived,
                'archive' => ContentProjectItemArchiveState::None,
                'bucket' => ContentProjectItemDashboardBucket::WaitingReview,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                    ContentProjectItemAction::Schedule,
                    ContentProjectItemAction::PublishNow,
                ],
                'blocked' => [ContentProjectItemAction::Approve, ContentProjectItemAction::StartReview],
            ],
        ];
        yield 'scheduled' => [
            [
                'status' => 'completed',
                'scheduled_publish_at' => '2026-07-01 10:00:00',
                'publish_queue_status' => 'none',
                'article' => ['review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::WaitingPublish,
                'publish' => ContentProjectItemPublishState::Scheduled,
                'bucket' => ContentProjectItemDashboardBucket::WaitingPublish,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                    ContentProjectItemAction::Unschedule,
                    ContentProjectItemAction::PublishNow,
                ],
            ],
        ];
        yield 'queue_processing' => [
            [
                'status' => 'completed',
                'publish_queue_status' => 'processing',
                'publish_lease_expires_at' => '2099-01-01 00:00:00',
                'publisher_started_at' => '2099-01-01 00:00:00',
                'article' => ['review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::WaitingPublish,
                'publish' => ContentProjectItemPublishState::Queued,
                'bucket' => ContentProjectItemDashboardBucket::WaitingPublish,
                'actions' => [],
                'blocked' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Schedule,
                ],
            ],
        ];
        yield 'queue_retrying_allows_retry' => [
            [
                'status' => 'completed',
                'publish_queue_status' => 'retrying',
                'next_publish_retry_at' => '2099-01-01 00:00:00',
                'publish_lease_expires_at' => null,
                'article' => ['review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::WaitingPublish,
                'publish' => ContentProjectItemPublishState::Queued,
                'bucket' => ContentProjectItemDashboardBucket::WaitingPublish,
                'actions' => [
                    ContentProjectItemAction::Unschedule,
                    ContentProjectItemAction::CancelPublish,
                    ContentProjectItemAction::SkipPublish,
                    ContentProjectItemAction::RetryPublish,
                    ContentProjectItemAction::PublishNow,
                ],
                'blocked' => [ContentProjectItemAction::Archive, ContentProjectItemAction::Schedule],
            ],
        ];
        yield 'publish_failed_before_first' => [
            ['status' => 'completed', 'publish_queue_status' => 'failed', 'last_publish_error' => 'WP 500'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Failed,
                'publish' => ContentProjectItemPublishState::PublishFailed,
                'error_source' => ContentProjectItemErrorSource::Publish,
                'bucket' => ContentProjectItemDashboardBucket::Failed,
                'has_retry' => true,
                'blocked' => [ContentProjectItemAction::Schedule],
            ],
        ];
        yield 'published' => [
            ['status' => 'completed', 'publish_queue_status' => 'published', 'publish_published_at' => '2026-07-01 12:00:00'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'publish' => ContentProjectItemPublishState::Published,
                'bucket' => ContentProjectItemDashboardBucket::Published,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                ],
            ],
        ];
        yield 'published_rerun_writing' => [
            ['status' => 'writing', 'publish_queue_status' => 'published', 'publish_published_at' => '2026-07-01 12:00:00'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'generation' => ContentProjectItemGenerationState::Writing,
                'bucket' => ContentProjectItemDashboardBucket::Published,
                'blocked' => [ContentProjectItemAction::Rerun, ContentProjectItemAction::Archive],
            ],
        ];
        yield 'published_rerun_failed' => [
            [
                'status' => 'failed',
                'publish_queue_status' => 'published',
                'publish_published_at' => '2026-07-01 12:00:00',
                'hints' => ['run_item_error' => 'AI timeout', 'stale_generation' => true, 'latest_attempt_source' => 'generation'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'error_source' => ContentProjectItemErrorSource::Generation,
                'error' => 'AI timeout',
                'bucket' => ContentProjectItemDashboardBucket::Published,
            ],
        ];
        yield 'published_publish_retry_failed' => [
            [
                'status' => 'completed',
                'publish_queue_status' => 'failed',
                'publish_published_at' => '2026-07-01 12:00:00',
                'last_publish_error' => 'retry 502',
                'hints' => ['latest_attempt_source' => 'publish'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'publish' => ContentProjectItemPublishState::Published,
                'error_source' => ContentProjectItemErrorSource::Publish,
                'error' => 'retry 502',
                'execution' => ContentProjectItemExecutionState::Failed,
                'bucket' => ContentProjectItemDashboardBucket::Published,
                'has_retry' => true,
            ],
        ];
        yield 'content_archived' => [
            ['status' => 'completed', 'archived_at' => '2026-07-01 12:00:00', 'publish_published_at' => '2026-07-01 11:00:00'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Archived,
                'archive' => ContentProjectItemArchiveState::ContentArchived,
                'bucket' => ContentProjectItemDashboardBucket::Archived,
                // Option B — item-level Restore removed; restore is project-level only
                // (content_project.restore). See ContentProjectItemActionGuard.
                'actions' => [],
                'blocked' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Schedule,
                ],
            ],
        ];
        yield 'task_cancelled' => [
            ['status' => 'cancelled'],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Draft,
                'generation' => ContentProjectItemGenerationState::Cancelled,
                'bucket' => ContentProjectItemDashboardBucket::Other,
            ],
        ];
        yield 'queue_skipped' => [
            ['status' => 'completed', 'publish_queue_status' => 'skipped', 'article' => ['review_status' => 'approved']],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Approved,
                'publish' => ContentProjectItemPublishState::Skipped,
                'bucket' => ContentProjectItemDashboardBucket::Approved,
                'actions' => [
                    ContentProjectItemAction::Archive,
                    ContentProjectItemAction::Rerun,
                    ContentProjectItemAction::Schedule,
                    ContentProjectItemAction::PublishNow,
                ],
            ],
        ];
        yield 'stale_generation_recovered' => [
            [
                'status' => 'failed',
                'hints' => ['stale_generation' => true, 'latest_attempt_source' => 'generation'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Failed,
                'error_source' => ContentProjectItemErrorSource::Generation,
                'error' => 'Stale generation recovered.',
                'bucket' => ContentProjectItemDashboardBucket::Failed,
            ],
        ];
        yield 'review_archived_and_content_archived' => [
            [
                'status' => 'completed',
                'archived_at' => '2026-07-02 00:00:00',
                'article' => ['review_status' => 'archived'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Archived,
                'review' => ContentProjectItemReviewState::ReviewArchived,
                'archive' => ContentProjectItemArchiveState::ContentArchived,
                'bucket' => ContentProjectItemDashboardBucket::Archived,
                // Option B — no item-level Restore.
                'actions' => [],
            ],
        ];
        yield 'article_published_task_failed' => [
            [
                'status' => 'failed',
                'article' => ['status' => 'published', 'review_status' => 'approved'],
                'hints' => ['run_item_error' => 'rerun boom', 'latest_attempt_source' => 'generation'],
            ],
            [
                // articles.status=published is NOT WP publish success — generation failed wins.
                'lifecycle' => ContentProjectLifecyclePhase::Failed,
                'error_source' => ContentProjectItemErrorSource::Generation,
                'bucket' => ContentProjectItemDashboardBucket::Failed,
            ],
        ];
        yield 'scheduled_plus_queue_cancelled' => [
            [
                'status' => 'completed',
                'scheduled_publish_at' => '2026-07-01 10:00:00',
                'publish_queue_status' => 'cancelled',
                'article' => ['review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::WaitingPublish,
                'publish' => ContentProjectItemPublishState::Scheduled,
                'bucket' => ContentProjectItemDashboardBucket::WaitingPublish,
            ],
        ];
        yield 'queue_published_task_writing' => [
            [
                'status' => 'writing',
                'publish_queue_status' => 'published',
                'publish_published_at' => '2026-07-01 12:00:00',
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'bucket' => ContentProjectItemDashboardBucket::Published,
            ],
        ];
        yield 'queue_failed_historical_published' => [
            [
                'status' => 'completed',
                'publish_queue_status' => 'failed',
                'publish_published_at' => '2026-07-01 12:00:00',
                'last_publish_error' => 'retry fail',
                'article' => ['status' => 'published', 'review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Published,
                'error_source' => ContentProjectItemErrorSource::Publish,
                'bucket' => ContentProjectItemDashboardBucket::Published,
                'has_retry' => true,
            ],
        ];
        yield 'task_completed_review_archived' => [
            ['status' => 'completed', 'article' => ['review_status' => 'archived']],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Review,
                'review' => ContentProjectItemReviewState::ReviewArchived,
                'bucket' => ContentProjectItemDashboardBucket::WaitingReview,
                'blocked' => [ContentProjectItemAction::Approve],
            ],
        ];
        yield 'content_archived_published_article' => [
            [
                'status' => 'completed',
                'archived_at' => '2026-07-02 00:00:00',
                'publish_published_at' => '2026-07-01 12:00:00',
                'article' => ['status' => 'published', 'review_status' => 'approved'],
            ],
            [
                'lifecycle' => ContentProjectLifecyclePhase::Archived,
                'archive' => ContentProjectItemArchiveState::ContentArchived,
                'bucket' => ContentProjectItemDashboardBucket::Archived,
            ],
        ];
    }

    /**
     * @dataProvider fixtureProvider
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $expect
     */
    public function test_fixture_resolver_lifecycle_dashboard_parity(array $input, array $expect): void
    {
        $hints = is_array($input['hints'] ?? null) ? $input['hints'] : [];
        unset($input['hints']);
        $articleAttrs = is_array($input['article'] ?? null) ? $input['article'] : null;
        unset($input['article']);

        $article = $articleAttrs !== null ? $this->article($articleAttrs) : null;
        $task = $this->task($input, $article);
        $state = $this->resolver->resolve($task, $article, $hints);

        self::assertSame($expect['lifecycle'], $state->lifecycleState);
        self::assertSame($expect['lifecycle'], $this->lifecycle->resolvePhase($task, $article));
        self::assertSame($expect['lifecycle'], $this->lifecycle->resolveState($task, $article, $hints)->lifecycleState);

        if (isset($expect['generation'])) {
            self::assertSame($expect['generation'], $state->generationState);
        }
        if (isset($expect['review'])) {
            self::assertSame($expect['review'], $state->reviewState);
        }
        if (isset($expect['publish'])) {
            self::assertSame($expect['publish'], $state->publishState);
        }
        if (isset($expect['archive'])) {
            self::assertSame($expect['archive'], $state->archiveState);
        }
        if (isset($expect['execution'])) {
            self::assertSame($expect['execution'], $state->executionState);
        }
        if (isset($expect['error_source'])) {
            self::assertSame($expect['error_source'], $state->currentErrorSource);
        }
        if (isset($expect['error'])) {
            self::assertSame($expect['error'], $state->currentError);
        }
        if (! empty($expect['has_retry'])) {
            self::assertContains(ContentProjectItemAction::RetryPublish, $state->availableActions);
        }
        if (isset($expect['actions'])) {
            self::assertSame(
                array_map(static fn (ContentProjectItemAction $a): string => $a->value, $expect['actions']),
                array_map(static fn (ContentProjectItemAction $a): string => $a->value, $state->availableActions),
            );
        }
        foreach ($expect['blocked'] ?? [] as $blocked) {
            self::assertNotContains($blocked, $state->availableActions);
            self::assertFalse($this->guard->allows($blocked, $state));
            try {
                $this->guard->assertCan($blocked, $task, $article, $this->resolver, $hints);
                self::fail('Expected assertCan to reject '.$blocked->value);
            } catch (RuntimeException $e) {
                self::assertStringContainsString($blocked->value, $e->getMessage());
            }
        }
        foreach ($state->availableActions as $action) {
            self::assertTrue($this->guard->allows($action, $state));
            $this->guard->assertCan($action, $task, $article, $this->resolver, $hints);
        }

        $bucketFromState = ContentProjectItemDashboardBucketMapper::fromState($state);
        self::assertSame($expect['bucket'], $bucketFromState);

        $raw = [
            'archived_at' => $input['archived_at'] ?? null,
            'status' => (string) ($input['status'] ?? ''),
            'publish_queue_status' => $input['publish_queue_status'] ?? null,
            'publish_published_at' => $input['publish_published_at'] ?? null,
            'scheduled_publish_at' => $input['scheduled_publish_at'] ?? null,
            'article_status' => $articleAttrs['status'] ?? null,
            'review_status' => $articleAttrs['review_status'] ?? null,
        ];
        self::assertSame(
            $expect['bucket'],
            ContentProjectItemDashboardBucketMapper::fromRawRow($raw),
            'Dashboard SQL-spec row evaluator must match resolver bucket',
        );
    }

    public function test_handlers_wire_shared_action_guard(): void
    {
        $files = [
            ContentProjectPublishingQueueService::class => [
                'actionGuard->assertCan',
                'ContentProjectItemAction::Schedule',
                'ContentProjectItemAction::RetryPublish',
                'ContentProjectItemAction::PublishNow',
                'ContentProjectItemAction::Unschedule',
                'ContentProjectItemAction::SkipPublish',
                'ContentProjectItemAction::CancelPublish',
            ],
            ApproveProjectItemsHandler::class => ['actionGuard->assertCan', 'ContentProjectItemAction::Approve'],
            ContentProjectRerunEligibilityGuard::class => ['actionGuard->assertCan', 'ContentProjectItemAction::Rerun'],
            GenerateProjectItemsHandler::class => ['actionGuard->assertCan', 'ContentProjectItemAction::Generate'],
            StartReviewHandler::class => ['actionGuard->assertCan', 'ContentProjectItemAction::StartReview'],
            ArchiveProjectItemsHandler::class => ['actionGuard->assertCan', 'ContentProjectItemAction::Archive'],
        ];

        foreach ($files as $class => $needles) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $src, $class.' missing '.$needle);
            }
        }
    }

    public function test_project_status_mode_non_authoritative_for_items(): void
    {
        self::assertFalse(ContentProjectStatusDecision::isAuthoritativeForItems());
        self::assertSame('project_level_flag_non_authoritative_for_items', ContentProjectStatusDecision::MODE);
        self::assertSame('A', ContentProjectStatusDecision::consumerClass()['ApproveProjectItemsHandler']);
        self::assertSame('B', ContentProjectStatusDecision::consumerClass()['SeoProjectResource badges']);
        self::assertSame('B', ContentProjectStatusDecision::consumerClass()['ArchiveContentProjectService']);
        self::assertSame('C', ContentProjectStatusDecision::consumerClass()['KeywordResource active-project filter']);
    }

    public function test_review_archived_distinct_from_content_archive(): void
    {
        $reviewOnly = $this->resolver->resolve(
            $this->task(['status' => 'completed'], $this->article(['review_status' => 'archived'])),
            $this->article(['review_status' => 'archived']),
        );
        self::assertSame(ContentProjectItemReviewState::ReviewArchived, $reviewOnly->reviewState);
        self::assertSame(ContentProjectItemArchiveState::None, $reviewOnly->archiveState);
        self::assertSame(ContentProjectLifecyclePhase::Review, $reviewOnly->lifecycleState);
        self::assertNotSame(ContentProjectLifecyclePhase::Approved, $reviewOnly->lifecycleState);
        self::assertNotContains(ContentProjectItemAction::Approve, $reviewOnly->availableActions);

        $contentOnly = $this->resolver->resolve(
            $this->task(['status' => 'completed', 'archived_at' => '2026-07-01 00:00:00']),
        );
        self::assertSame(ContentProjectItemArchiveState::ContentArchived, $contentOnly->archiveState);
        self::assertSame(ContentProjectLifecyclePhase::Archived, $contentOnly->lifecycleState);
        // Option B — item-level Restore removed; project-level content_project.restore only.
        self::assertSame([], $contentOnly->availableActions);
    }

    public function test_error_freshness_successful_after_failed_clears_generation_error(): void
    {
        $task = $this->task([
            'status' => 'completed',
            'publish_published_at' => '2026-07-01 12:00:00',
            'publish_queue_status' => 'published',
            'last_publish_error' => 'old publish',
        ]);
        $state = $this->resolver->resolve($task, null, [
            'run_item_status' => 'success',
            'latest_attempt_source' => '',
        ]);
        self::assertSame(ContentProjectLifecyclePhase::Published, $state->lifecycleState);
        self::assertSame(ContentProjectItemErrorSource::None, $state->currentErrorSource);
        self::assertNull($state->currentError);
    }

    public function test_error_freshness_stale_without_run_item_message(): void
    {
        $state = $this->resolver->resolve(
            $this->task(['status' => 'failed']),
            null,
            ['stale_generation' => true],
        );
        self::assertSame(ContentProjectItemErrorSource::Generation, $state->currentErrorSource);
        self::assertSame('Stale generation recovered.', $state->currentError);
    }

    public function test_production_task_status_writers_bounded(): void
    {
        $files = [
            'Services/SeoProjectWorkflowRunService.php',
            'Services/ContentProject/ContentProjectGenerationRecoveryService.php',
            'Services/ContentProject/Application/Handlers/StartReviewHandler.php',
            'Services/ContentProject/Application/Handlers/UpdateContentProjectItemHandler.php',
            'Services/ContentProject/Application/Handlers/AddContentProjectItemsHandler.php',
            'Services/SeoProjectTaskSyncService.php',
            'Services/SeoProjectRunItemService.php',
            'Services/ContentProject/RunEngine/ContentProjectTaskExecutionService.php',
            'Services/SeoProjectRunConsolidationService.php',
            'Services/ContentProject/ContentProjectLegacyExecutionHydrateService.php',
            'Services/KeywordProjectAssignmentService.php',
            'Services/SeoIssueProjectTaskAssignmentService.php',
            'Console/ReportSeoProjectTaskStatusCommand.php',
        ];
        foreach ($files as $rel) {
            $path = $this->resolveLegacyOrMovedAddonPath($rel);
            self::assertFileExists($path, $rel);
            $src = (string) file_get_contents($path);
            self::assertTrue(
                str_contains($src, 'SeoProjectTask::STATUS_')
                || str_contains($src, 'SeoProjectTaskStatus')
                || str_contains($src, 'ContentProjectTaskStatusNormalizer'),
                $rel.' must use STATUS_* / enum / normalizer',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function task(array $attrs, ?SeoArticle $article = null): SeoProjectTask
    {
        $task = new SeoProjectTask;
        $task->setRawAttributes($attrs, true);
        if ($article instanceof SeoArticle) {
            $task->setRelation('article', $article);
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function article(array $attrs): SeoArticle
    {
        $article = new SeoArticle;
        $article->setRawAttributes($attrs, true);

        return $article;
    }
}
