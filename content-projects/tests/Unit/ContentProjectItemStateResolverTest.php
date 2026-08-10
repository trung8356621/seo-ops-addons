<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemErrorSource;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectLifecycle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStatusDecision;
use PHPUnit\Framework\TestCase;

/**
 * Batch D — canonical item state + status normalization contract tests.
 */
final class ContentProjectItemStateResolverTest extends TestCase
{
    private ContentProjectItemStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ContentProjectItemStateResolver;
    }

    public function test_draft_pending_item(): void
    {
        $state = $this->resolver->resolve($this->task(['status' => 'pending']));
        self::assertSame(ContentProjectLifecyclePhase::Draft, $state->lifecycleState);
        self::assertSame(ContentProjectItemGenerationState::Pending, $state->generationState);
    }

    public function test_generating_item(): void
    {
        $state = $this->resolver->resolve($this->task(['status' => 'writing']));
        self::assertSame(ContentProjectLifecyclePhase::Generating, $state->lifecycleState);
        self::assertSame(ContentProjectItemGenerationState::Writing, $state->generationState);
    }

    public function test_generation_failed_not_published(): void
    {
        $state = $this->resolver->resolve($this->task(['status' => 'failed']));
        self::assertSame(ContentProjectLifecyclePhase::Failed, $state->lifecycleState);
        self::assertSame(ContentProjectItemErrorSource::Generation, $state->currentErrorSource);
    }

    public function test_completed_pending_review(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Draft->value]);
        $state = $this->resolver->resolve(
            $this->task(['status' => 'completed'], $article),
            $article,
        );
        self::assertSame(ContentProjectLifecyclePhase::Review, $state->lifecycleState);
    }

    public function test_approved_ready_to_schedule(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Approved->value]);
        $state = $this->resolver->resolve(
            $this->task(['status' => 'completed', 'publish_queue_status' => 'none'], $article),
            $article,
        );
        self::assertSame(ContentProjectLifecyclePhase::Approved, $state->lifecycleState);
        self::assertSame(ContentProjectItemPublishState::None, $state->publishState);
    }

    public function test_scheduled_is_waiting_publish(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Approved->value]);
        $task = $this->task([
            'status' => 'completed',
            'scheduled_publish_at' => '2026-07-01 10:00:00',
            'publish_queue_status' => 'none',
        ], $article);
        $state = $this->resolver->resolve($task, $article);
        self::assertSame(ContentProjectLifecyclePhase::WaitingPublish, $state->lifecycleState);
        self::assertSame(ContentProjectItemPublishState::Scheduled, $state->publishState);
    }

    public function test_queued_publishing(): void
    {
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => ContentProjectPublishQueueStatus::Processing->value,
        ]);
        $state = $this->resolver->resolve($task);
        self::assertSame(ContentProjectLifecyclePhase::WaitingPublish, $state->lifecycleState);
        self::assertSame(ContentProjectItemPublishState::Queued, $state->publishState);
    }

    public function test_publish_failed_without_revision(): void
    {
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => ContentProjectPublishQueueStatus::Failed->value,
            'last_publish_error' => 'WP 500',
        ]);
        $state = $this->resolver->resolve($task);
        self::assertSame(ContentProjectLifecyclePhase::Failed, $state->lifecycleState);
        self::assertSame(ContentProjectItemErrorSource::Publish, $state->currentErrorSource);
        self::assertSame('WP 500', $state->currentError);
    }

    public function test_published_revision(): void
    {
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => 'published',
            'publish_published_at' => '2026-07-01 12:00:00',
        ]);
        $state = $this->resolver->resolve($task);
        self::assertSame(ContentProjectLifecyclePhase::Published, $state->lifecycleState);
        self::assertTrue($state->hasPublishedRevision);
    }

    public function test_published_with_rerun_writing_stays_published(): void
    {
        $task = $this->task([
            'status' => 'writing',
            'publish_published_at' => '2026-07-01 12:00:00',
            'publish_queue_status' => 'published',
        ]);
        $state = $this->resolver->resolve($task);
        self::assertSame(ContentProjectLifecyclePhase::Published, $state->lifecycleState);
        self::assertSame(ContentProjectItemGenerationState::Writing, $state->generationState);
    }

    public function test_published_with_rerun_failed_stays_published_not_publish_error(): void
    {
        $task = $this->task([
            'status' => 'failed',
            'publish_published_at' => '2026-07-01 12:00:00',
            'publish_queue_status' => 'published',
            'last_publish_error' => 'stale gen wrongly stored',
        ]);
        $state = $this->resolver->resolve($task, null, [
            'run_item_error' => 'AI timeout',
            'stale_generation' => true,
        ]);
        self::assertSame(ContentProjectLifecyclePhase::Published, $state->lifecycleState);
        self::assertSame(ContentProjectItemErrorSource::Generation, $state->currentErrorSource);
        self::assertSame('AI timeout', $state->currentError);
    }

    public function test_content_archived(): void
    {
        $task = $this->task([
            'status' => 'completed',
            'archived_at' => '2026-07-01 12:00:00',
        ]);
        $state = $this->resolver->resolve($task);
        self::assertSame(ContentProjectLifecyclePhase::Archived, $state->lifecycleState);
    }

    public function test_review_status_archived_is_review_not_publish_ready(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Archived->value]);
        $state = $this->resolver->resolve(
            $this->task(['status' => 'completed'], $article),
            $article,
        );
        self::assertSame(ContentProjectLifecyclePhase::Review, $state->lifecycleState);
        self::assertNotSame(ContentProjectLifecyclePhase::Approved, $state->lifecycleState);
    }

    public function test_lifecycle_delegates_to_resolver(): void
    {
        $lifecycle = new ContentProjectLifecycle($this->resolver);
        $task = $this->task(['status' => 'writing']);
        self::assertSame(
            $this->resolver->resolvePhase($task),
            $lifecycle->resolvePhase($task),
        );
    }

    public function test_project_status_not_authoritative_for_items(): void
    {
        self::assertFalse(ContentProjectStatusDecision::isAuthoritativeForItems());
        self::assertSame('project_level_flag_non_authoritative_for_items', ContentProjectStatusDecision::MODE);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function task(array $attrs, ?SeoArticle $article = null): SeoProjectTask
    {
        // setRawAttributes — skip datetime cast (pure PHPUnit has no DB connection).
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
