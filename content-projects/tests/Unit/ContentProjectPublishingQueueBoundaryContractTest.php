<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ReturnToContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use PHPUnit\Framework\TestCase;

/**
 * Content Project Ã¢â€ â€ Publishing Queue module boundary.
 */
final class ContentProjectPublishingQueueBoundaryContractTest extends TestCase
{
    public function test_migration_adds_publishing_queued_at(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_08_02_100000_add_publishing_queue_handoff_to_seo_project_tasks.php');
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('publishing_queued_at', $src);
        self::assertStringContainsString('publishing_queued_by', $src);
        self::assertStringContainsString("publish_queue_status = 'none'", $src);
    }

    public function test_schedule_future_sets_execution_none(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectPublishingQueueService.php',
        );
        self::assertStringContainsString('Plan schedule time', $src);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::None->value', $src);
        self::assertStringContainsString('acceptHandoff', $src);
        self::assertStringContainsString('returnToContentProject', $src);
        self::assertStringContainsString('ContentProjectPublishedEvidence', $src);
        self::assertStringContainsString("\$attrs['publish_published_at'] = null", $src);
    }

    public function test_handoff_commands_registered(): void
    {
        self::assertSame('content_project.send_to_publishing_queue', (new SendToPublishingQueueCommand(1, [1]))->name());
        self::assertSame('content_project.return_to_content_project', (new ReturnToContentProjectCommand(1, [1]))->name());

        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/SendToPublishingQueueHandler.php',
        );
        self::assertStringContainsString('canManageContentProjectWorkflow', $handler);
        self::assertStringContainsString('wordpress_called', $handler);

        $registrar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('SendToPublishingQueueCommand::class', $registrar);
        self::assertStringContainsString('ReturnToContentProjectCommand::class', $registrar);
    }

    public function test_auto_schedule_has_project_month_and_quick(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectAutoScheduleService.php',
        );
        self::assertStringContainsString("'project_month'", $src);
        self::assertStringContainsString("'monthly_even'", $src);
        self::assertStringContainsString("'quick'", $src);
        self::assertStringContainsString("'in_day'", $src);
        self::assertStringContainsString('Quick Mode', $src);
        self::assertStringContainsString('resolveEligible', $src);
        self::assertStringNotContainsString('Dev Mode', $src);

        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/AutoScheduleProjectItemsHandler.php',
        );
        self::assertStringContainsString('siteSchedule', $handler);
        self::assertStringContainsString('withScheduleLocks', $handler);
    }

    public function test_cp_ops_ui_removed_scheduled_published_cards(): void
    {
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringNotContainsString("'card' => 'scheduled'", $view);
        self::assertStringNotContainsString("'card' => 'published'", $view);
        self::assertStringNotContainsString('<th class="cp-ops-col-sched"', $view);
        self::assertStringNotContainsString('>Schedule</th>', $view);
        self::assertStringContainsString('sendToPublishingQueueOne', (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        ));
    }

    public function test_publishing_queue_page_redirects_to_hub(): void
    {
        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Pages/ContentProjectPublishingQueue.php',
        );
        self::assertStringNotContainsString("lifecycle' => 'waiting_publish,published'", $page);
        self::assertStringContainsString('redirect', $page);
        self::assertStringContainsString('getPublishingQueueUrl', $page);
        self::assertStringContainsString('canManageContentProjectWorkflow', $page);

        self::assertTrue(class_exists(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class));
        $hub = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub::class))->getFileName(),
        );
        self::assertStringContainsString("slug = 'publishing-queue'", $hub);
        self::assertStringContainsString('canManageContentProjectWorkflow', $hub);
        self::assertStringContainsString('getNavigationParentItem', $hub);
    }

    public function test_classifier_and_eligibility(): void
    {
        $unscheduled = PublishingQueueStateClassifier::classify([
            'publishing_queued_at' => '2026-08-01T00:00:00+00:00',
            'queue_status' => 'none',
        ]);
        self::assertSame('unscheduled', $unscheduled['state']);

        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'publishing_queued_at' => '2026-08-01T00:00:00+00:00',
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'lifecycle' => 'published',
            'observed_post_status' => 'publish',
            'has_unpublished_changes' => false,
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));
        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'lifecycle' => 'published',
            'observed_post_status' => 'publish',
            'has_unpublished_changes' => true,
            'generation_status' => 'completed',
            'execution_status' => 'success',
            'generation_completed_at' => '2026-08-01T10:00:00+00:00',
        ]));

        // «improve» must be publishable without generation completion when dirty.
        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'generation_status' => 'pending',
            'execution_status' => '',
            'generation_completed_at' => '',
            'has_unpublished_changes' => true,
        ]));

        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'generation_status' => 'failed',
            'execution_status' => '',
            'generation_completed_at' => '',
            'has_unpublished_changes' => true,
        ]));

        // via is_improve flag (as passed by read model $rowBase)
        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'is_improve' => true,
            'generation_status' => 'pending',
            'execution_status' => '',
            'generation_completed_at' => '',
            'has_unpublished_changes' => true,
        ]));

        // improve without unpublished changes is not eligible
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'generation_status' => 'pending',
            'has_unpublished_changes' => false,
        ]));

        // rewrite still requires generation readiness
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'generation_status' => 'pending',
            'execution_status' => '',
            'generation_completed_at' => '',
            'has_unpublished_changes' => true,
        ]));

        // improve blocked while genuinely processing
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => true,
            'is_genuinely_running' => true,
        ]));
    }

    /**
     * Regression: SendToPublishingQueueHandler must pass type + is_improve into canSend
     * (same semantics as read-model UI eligibility).
     */
    public function test_send_handler_row_contains_type_and_is_improve(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/Handlers/SendToPublishingQueueHandler.php',
        );

        self::assertStringContainsString("'type' => \$type", $src);
        self::assertStringContainsString("'is_improve' => \$type === SeoProjectTask::TYPE_IMPROVE", $src);
        self::assertStringContainsString('SeoProjectTask::normalizeType($task->type)', $src);
        self::assertStringContainsString('PublishingQueueHandoffEligibility::canSend($row)', $src);
    }

    /**
     * Regression: $rowBase passed to canSend must include type + is_improve
     * so the improve early-return path triggers.
     */
    public function test_read_model_row_base_contains_type_and_is_improve(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectItemOperationsReadModel.php',
        );

        // $rowBase array must include type and is_improve before canSend($rowBase) call
        $rowBaseStart = strpos($src, '$rowBase = [');
        self::assertNotFalse($rowBaseStart, '$rowBase array must exist');
        $canSendCall = strpos($src, 'canSend($rowBase)', $rowBaseStart);
        self::assertNotFalse($canSendCall, 'canSend($rowBase) must be called');

        $rowBaseBlock = substr($src, $rowBaseStart, $canSendCall - $rowBaseStart);
        self::assertStringContainsString("'type' => \$type", $rowBaseBlock, '$rowBase must include type');
        self::assertStringContainsString("'is_improve'", $rowBaseBlock, '$rowBase must include is_improve');
    }

    public function test_lang_keys_exist(): void
    {
        $en = (string) file_get_contents(LegacyAddonPath::resolve('lang/en/filament.php'));
        self::assertStringContainsString("'send_to_publishing_queue'", $en);
        self::assertStringContainsString('Send to Publishing Queue', $en);
    }
}
