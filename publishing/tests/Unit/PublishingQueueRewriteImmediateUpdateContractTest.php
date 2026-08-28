<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategy;
use Omnichannel\Addons\Publishing\Services\Publishing\ContentPublishingStrategyResolver;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

final class PublishingQueueRewriteImmediateUpdateContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_strategy_uses_canonical_type_and_remote_post_id(): void
    {
        $resolver = new ContentPublishingStrategyResolver;

        $create = new SeoProjectTask(['type' => SeoProjectTask::TYPE_CREATE]);
        self::assertSame(ContentPublishingStrategy::SCHEDULED_CREATE, $resolver->resolve($create)->mode);

        $rewrite = new SeoProjectTask(['type' => SeoProjectTask::TYPE_REWRITE]);
        $article = new SeoArticle;
        $link = new \Omnichannel\Addons\WordPress\Models\WordpressArticleLink(['wp_post_id' => 123]);
        $article->setRelation('wordpressLink', $link);
        $strategy = $resolver->resolve($rewrite, $article);
        self::assertSame(ContentPublishingStrategy::IMMEDIATE_UPDATE, $strategy->mode);
        self::assertSame(123, $strategy->remotePostId);

        $missingArticle = new SeoArticle;
        $missingArticle->setRelation('wordpressLink', null);
        $missing = $resolver->resolve($rewrite, $missingArticle);
        self::assertSame(ContentPublishingStrategy::FAILED_MISSING_REMOTE, $missing->mode);
    }

    public function test_handoff_splits_rewrite_from_scheduled_create(): void
    {
        $handler = $this->readAddon('Services/ContentProject/Application/Handlers/SendToPublishingQueueHandler.php');
        $queue = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');

        self::assertStringContainsString('ContentPublishingStrategyResolver', $handler);
        self::assertStringContainsString('enqueueImmediateUpdateHandoff', $handler);
        self::assertStringContainsString('failMissingRemotePostHandoff', $handler);
        self::assertStringContainsString('PublishDueItemService::TRIGGER_SCHEDULER', $handler);
        self::assertStringContainsString("'scheduled_publish_at' => null", $queue);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Waiting->value', $queue);
    }

    public function test_publish_payload_and_automation_are_update_existing_only_for_rewrite(): void
    {
        $handler = $this->readAddon('Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php');
        $action = $this->readAddon('Automation/BusinessHook/Actions/SyncArticleToWordPressHookAction.php');
        $pipeline = $this->readAddon('Services/WordPress/SyncArticleToWordPressPipeline.php');

        self::assertStringContainsString("'publish_mode' => \$strategy->isImmediateUpdate() ? 'update_existing' : 'publish'", $handler);
        self::assertStringContainsString("'remote_post_id' => \$strategy->remotePostId", $handler);
        self::assertStringContainsString("ContentPublishingStrategy::FAILED_MISSING_REMOTE", $handler);
        self::assertStringContainsString("\$input['publish_mode']", $action);
        self::assertStringContainsString("'update_existing' => \$this->articleSync->updatePublishedArticleOnly", $pipeline);
        self::assertStringNotContainsString("'update_existing' => \$this->articleSync->syncForArticle", $pipeline);
    }

    public function test_rewrite_rows_do_not_expose_schedule_actions(): void
    {
        $a = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'unscheduled',
            'item_type' => SeoProjectTask::TYPE_REWRITE,
        ]);

        self::assertFalse($a['schedule']);
        self::assertFalse($a['unschedule']);
        self::assertTrue($a['publish_now']);
    }

    public function test_auto_schedule_excludes_update_existing_items(): void
    {
        $auto = $this->readAddon('Services/ContentProject/ContentProjectAutoScheduleService.php');
        $queue = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');

        self::assertStringContainsString('update_existing_immediate', $auto);
        self::assertStringContainsString('update_existing_not_schedulable', $queue);
    }
}
