<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SendToPublishingQueueHandler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueHandoffEligibility;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Manual-only improve must reach Publishing Queue without generation completion.
 */
final class ContentProjectImprovePublishingQueueEligibilityTest extends TestCase
{
    public function test_a_improve_without_generation_but_with_unpublished_changes_is_eligible(): void
    {
        self::assertTrue(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'generation_completed_at' => null,
            'generation_status' => 'pending',
            'execution_status' => '',
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'publishing_queued_at' => null,
            'in_publishing_queue' => false,
            'is_genuinely_running' => false,
        ]));
    }

    public function test_b_improve_without_unpublished_changes_is_not_eligible(): void
    {
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => false,
            'generation_status' => 'pending',
            'queue_status' => 'none',
        ]));
    }

    public function test_c_improve_while_processing_queue_is_not_eligible(): void
    {
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => true,
            'queue_status' => 'processing',
        ]));

        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'is_genuinely_running' => true,
        ]));
    }

    public function test_d_rewrite_without_generation_completed_stays_ineligible(): void
    {
        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'generation_completed_at' => null,
            'generation_status' => 'pending',
            'execution_status' => '',
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
        ]));

        self::assertFalse(PublishingQueueHandoffEligibility::canSend([
            'article_id' => 42,
            'type' => SeoProjectTask::TYPE_CREATE,
            'generation_completed_at' => '',
            'generation_status' => 'pending',
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
        ]));
    }

    public function test_e_single_and_bulk_share_same_eligibility_service(): void
    {
        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(SendToPublishingQueueHandler::class))->getFileName(),
        );
        $trait = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content-projects/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithContentProjectPublishingActions.php',
        );

        self::assertStringContainsString('SendToPublishingQueueCommand', $trait);
        self::assertStringContainsString('sendToPublishingQueueOne', $trait);
        self::assertStringContainsString('bulkSendToPublishingQueue', $trait);
        self::assertStringContainsString('PublishingQueueHandoffEligibility::canSend', $handler);
        self::assertStringContainsString("'type' => \$type", $handler);
        self::assertStringContainsString("'is_improve'", $handler);
        self::assertStringContainsString('No eligible items to send to Publishing Queue.', $handler);
    }

    public function test_f_mixed_bulk_filters_ineligible_not_all_or_nothing_at_eligibility(): void
    {
        $improveReady = [
            'article_id' => 1,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_completed_at' => '',
        ];
        $improveReady2 = [
            'article_id' => 2,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_completed_at' => '',
        ];
        $rewriteNotReady = [
            'article_id' => 3,
            'type' => SeoProjectTask::TYPE_REWRITE,
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'generation_status' => 'pending',
            'generation_completed_at' => '',
        ];

        $eligible = array_values(array_filter(
            [$improveReady, $improveReady2, $rewriteNotReady],
            static fn (array $row): bool => PublishingQueueHandoffEligibility::canSend($row),
        ));

        self::assertCount(2, $eligible);
        self::assertTrue(PublishingQueueHandoffEligibility::canSend($improveReady));
        self::assertFalse(PublishingQueueHandoffEligibility::canSend($rewriteNotReady));
    }

    public function test_improve_with_article_is_not_generation_pending_badge(): void
    {
        $classified = ContentProjectOpsStateClassifier::classify([
            'article_id' => 9,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'is_improve' => true,
            'generation_status' => 'pending',
            'execution_status' => '',
            'generation_completed_at' => '',
            'has_unpublished_changes' => true,
            'queue_status' => 'none',
            'is_genuinely_running' => false,
        ]);

        self::assertSame('generated', $classified['generation_key']);
        self::assertNotSame('pending', $classified['generation_key']);
    }
}
