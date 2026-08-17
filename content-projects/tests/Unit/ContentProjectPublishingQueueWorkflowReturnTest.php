<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SendToPublishingQueueHandler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectPublishedDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Publishing Queue return must recompute workflow from WP evidence, not queue history.
 * Published + unpublished_changes must remain queueable.
 */
final class ContentProjectPublishingQueueWorkflowReturnTest extends TestCase
{
    public function test_a_approved_queue_return_is_approved_not_published(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Approved->value]);
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => 'none',
            'publishing_queued_at' => null,
            'publish_published_at' => '2026-08-01 12:00:00',
        ], $article);
        $state = (new ContentProjectItemStateResolver)->resolve($task, $article, [
            'observed_post_status' => 'draft',
        ]);

        self::assertSame(ContentProjectLifecyclePhase::Approved, $state->lifecycleState);
        self::assertFalse($state->hasPublishedRevision);

        $row = $this->sendReady([
            'lifecycle' => $state->lifecycleState->value,
            'has_published_revision' => $state->hasPublishedRevision,
            'review_status' => 'approved',
            'publish_published_at' => '2026-08-01T12:00:00+00:00',
            'observed_post_status' => 'draft',
        ]);
        $classified = ContentProjectOpsStateClassifier::classify($row);
        self::assertSame('approved', $classified['workflow_key']);
        self::assertFalse($classified['is_published_canonical']);
        self::assertTrue(PublishingQueueHandoffEligibility::canSend($row));
        self::assertTrue(ContentProjectItemActionsPresenter::forRow($row)['send_to_publishing_queue']);
    }

    public function test_b_needs_review_queue_return_is_not_published(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Draft->value]);
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => 'none',
            'publishing_queued_at' => null,
            'publish_published_at' => '2026-08-01 12:00:00',
        ], $article);
        $state = (new ContentProjectItemStateResolver)->resolve($task, $article, [
            'observed_post_status' => 'pending',
        ]);

        self::assertSame(ContentProjectLifecyclePhase::Review, $state->lifecycleState);
        self::assertFalse($state->hasPublishedRevision);

        $row = $this->sendReady([
            'lifecycle' => $state->lifecycleState->value,
            'has_published_revision' => false,
            'review_status' => 'draft',
            'publish_published_at' => '2026-08-01T12:00:00+00:00',
            'observed_post_status' => 'pending',
            'is_content_manager_reviewed' => false,
        ]);
        $classified = ContentProjectOpsStateClassifier::classify($row);
        self::assertNotSame('published', $classified['workflow_key']);
        self::assertFalse($classified['is_published_canonical']);
        self::assertTrue(PublishingQueueHandoffEligibility::canSend($row));
    }

    public function test_c_queue_history_without_wp_live_is_not_published(): void
    {
        self::assertFalse(ContentProjectPublishedDefinition::matches([
            'lifecycle' => 'approved',
            'queue_status' => 'none',
            'publish_published_at' => '2026-08-01T12:00:00+00:00',
            'observed_post_status' => 'draft',
        ]));
        self::assertFalse(ContentProjectPublishedDefinition::matches([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'publish_queue_status' => 'published',
            'publish_published_at' => '2026-08-01T12:00:00+00:00',
            'publishing_queued_at' => null,
            'in_publishing_queue' => false,
        ]));
    }

    public function test_d_real_published_clean_hides_move_to_queue(): void
    {
        $row = $this->sendReady([
            'lifecycle' => 'published',
            'has_published_revision' => true,
            'observed_post_status' => 'publish',
            'has_unpublished_changes' => false,
        ]);
        self::assertSame('published', ContentProjectOpsStateClassifier::classify($row)['workflow_key']);
        self::assertFalse(PublishingQueueHandoffEligibility::canSend($row));
        self::assertFalse(ContentProjectItemActionsPresenter::forRow($row)['send_to_publishing_queue']);
    }

    public function test_e_real_published_unpublished_changes_shows_move_to_queue(): void
    {
        $row = $this->sendReady([
            'lifecycle' => 'published',
            'has_published_revision' => true,
            'observed_post_status' => 'publish',
            'has_unpublished_changes' => true,
        ]);
        self::assertSame('published', ContentProjectOpsStateClassifier::classify($row)['workflow_key']);
        self::assertTrue(PublishingQueueHandoffEligibility::canSend($row));
        self::assertTrue(ContentProjectItemActionsPresenter::forRow($row)['send_to_publishing_queue']);
    }

    public function test_f_real_published_dirty_queue_return_stays_published_and_queueable(): void
    {
        $article = $this->article(['review_status' => ArticleReviewStatus::Approved->value]);
        $task = $this->task([
            'status' => 'completed',
            'publish_queue_status' => 'none',
            'publishing_queued_at' => null,
            'publish_published_at' => '2026-08-01 12:00:00',
        ], $article);
        $state = (new ContentProjectItemStateResolver)->resolve($task, $article, [
            'observed_post_status' => 'publish',
        ]);

        self::assertSame(ContentProjectLifecyclePhase::Published, $state->lifecycleState);
        self::assertTrue($state->hasPublishedRevision);

        $row = $this->sendReady([
            'lifecycle' => $state->lifecycleState->value,
            'has_published_revision' => true,
            'observed_post_status' => 'publish',
            'has_unpublished_changes' => true,
            'publish_published_at' => '2026-08-01T12:00:00+00:00',
        ]);
        self::assertSame('published', ContentProjectOpsStateClassifier::classify($row)['workflow_key']);
        self::assertTrue(PublishingQueueHandoffEligibility::canSend($row));
    }

    public function test_send_handler_does_not_skip_published_before_cansend(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SendToPublishingQueueHandler::class))->getFileName(),
        );
        self::assertStringNotContainsString('ContentProjectPublishedDefinition::matches', $src);
        self::assertStringContainsString('has_unpublished_changes', $src);
        self::assertStringContainsString('observed_post_status', $src);
        self::assertStringContainsString('PublishingQueueHandoffEligibility::canSend', $src);
    }

    /**
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function sendReady(array $overlay): array
    {
        return array_merge([
            'article_id' => 9,
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
            'queue_status' => 'none',
            'publishing_queued_at' => null,
            'in_publishing_queue' => false,
            'is_genuinely_running' => false,
        ], $overlay);
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
