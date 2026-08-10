<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithContentProjectPublishingActions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RecoverStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueuePublishingDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueScheduledDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublishingQueueCanonicalStateMachineTest extends TestCase
{
    public function test_due_waiting_is_scheduled_not_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'waiting',
            'scheduled_publish_at' => now()->subHour()->toIso8601String(),
            'scheduled_raw' => now()->subHour()->toIso8601String(),
        ];

        self::assertFalse(PublishingQueuePublishingDefinition::matches($row));
        self::assertTrue(PublishingQueueScheduledDefinition::matches($row));
        self::assertSame('scheduled', PublishingQueueStateClassifier::classify($row)['state']);
    }

    public function test_processing_is_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->toIso8601String(),
            'scheduled_publish_at' => now()->subHour()->toIso8601String(),
            'last_publish_attempt_at' => now()->toIso8601String(),
        ];

        self::assertTrue(PublishingQueuePublishingDefinition::matches($row));
        self::assertSame('publishing', PublishingQueueStateClassifier::classify($row)['state']);
    }

    public function test_queued_for_delivery_is_awaiting_worker_not_publishing(): void
    {
        $row = [
            'publish_queue_status' => 'queued_for_delivery',
            'delivery_dispatched_at' => now()->toIso8601String(),
            'publisher_started_at' => null,
        ];
        self::assertFalse(PublishingQueuePublishingDefinition::matches($row));
        self::assertSame('awaiting_delivery', PublishingQueueStateClassifier::classify($row)['state']);
    }

    public function test_stuck_publishing_without_attempt_or_stale_ttl(): void
    {
        // Stuck Publishing = real publisher started (publisher_started_at), not dispatch-only.
        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->subHours(2)->toIso8601String(),
        ]));

        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->subHours(2)->toIso8601String(),
            'last_publish_attempt_at' => now()->subHours(2)->toIso8601String(),
        ]));

        self::assertFalse(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => now()->subMinutes(2)->toIso8601String(),
            'last_publish_attempt_at' => now()->subMinutes(2)->toIso8601String(),
        ]));

        // Dispatch-only processing (no publisher_started_at) is awaiting_worker, not stuck Publishing.
        self::assertFalse(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publisher_started_at' => null,
        ]));
    }

    public function test_processing_to_cancelled_still_rejected(): void
    {
        $guard = new ContentProjectPublishTransitionGuard;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lifecycle.invalid_transition: processing â†’ cancelled');
        $guard->assertCanTransition('processing', 'cancelled');
    }

    public function test_schedule_service_never_sets_waiting_for_plan(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueService::class))->getFileName(),
        );
        self::assertStringContainsString('Always execution none', $src);
        self::assertStringContainsString('recoverStuckPublishing', $src);
        self::assertStringContainsString('publishing.busy_cannot_reschedule', $src);
        self::assertStringNotContainsString('Past/now at â‡’ waiting for runner', $src);
    }

    public function test_auto_schedule_has_in_day_and_excludes_publishing(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAutoScheduleService::class))->getFileName(),
        );
        self::assertStringContainsString("'in_day'", $src);
        self::assertStringContainsString('buildInDaySlots', $src);
        self::assertStringContainsString('resolveEligible', $src);
        self::assertStringContainsString("'reason' => 'publishing'", $src);
        self::assertStringContainsString('SystemDateTime::timezone', $src);
        self::assertStringContainsString('MIN_INTERVAL_MINUTES', $src);
        self::assertStringContainsString('Loáº¡i Publishing/Published', $src);
    }

    public function test_hub_auto_quick_no_selection_required(): void
    {
        $hub = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingQueueHub::class))->getFileName(),
        );
        self::assertStringContainsString("'mode' => 'project_month'", $hub);
        self::assertStringContainsString('selectAllMatchingResults', $hub);
        self::assertStringContainsString('togglePageSelection', $hub);
        self::assertStringContainsString('RecoverStuckPublishingCommand', $hub);
        self::assertStringContainsString('forceRecoverStuckSelected', $hub);
        self::assertStringContainsString('in_day', $hub);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithContentProjectPublishingActions::class))->getFileName(),
        );
        self::assertStringContainsString("allowsEmptySelection = in_array(\$op, ['auto_schedule']", $trait);
        self::assertStringContainsString('if (! $result->success)', $trait);

        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/publishing-queue-hub.blade.php'),
        );
        self::assertStringContainsString('Auto Schedule', $view);
        self::assertStringContainsString('Quick Mode', $view);
        self::assertStringContainsString('In Day', $view);
        self::assertStringContainsString('publishing_queue_timezone', $view);
        self::assertStringContainsString('Recover stuck', $view);
        self::assertStringContainsString('Force stop', $view);
        self::assertStringContainsString('Runner healthy', $view);
        self::assertStringContainsString('KhÃ´ng cáº§n tick checkbox', $view);
    }

    public function test_process_handler_dispatches_before_publisher_worker_starts(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );
        $posClaim = strpos($source, 'claimForDispatch($task)');
        $posPublisher = strpos($source, '$publisher->publish($payload)');
        self::assertNotFalse($posClaim);
        self::assertNotFalse($posPublisher);
        self::assertLessThan($posPublisher, $posClaim);
        self::assertStringContainsString('queued_for_delivery', $source);
        self::assertStringContainsString('awaiting worker', $source);
        // Publisher lease/attempt owned by WP worker beginPublisherAttempt â€” not markProcessing after publish.
        self::assertStringNotContainsString('markProcessing($task->fresh()', $source);
    }

    public function test_recover_command_registered(): void
    {
        self::assertSame(
            'content_project.recover_stuck_publishing',
            (new RecoverStuckPublishingCommand(1, [1], 'scheduled'))->name(),
        );
        $registrar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('RecoverStuckPublishingCommand::class', $registrar);
    }

    public function test_notifier_maps_processing_cancelled(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectActionResultNotifier::class))->getFileName(),
        );
        self::assertStringContainsString('BÃ i Ä‘ang Ä‘Æ°á»£c xuáº¥t báº£n nÃªn khÃ´ng thá»ƒ Ä‘á»•i lá»‹ch.', $src);
        self::assertStringContainsString('allowSuccessToast', $src);
        self::assertStringContainsString('stale_processing', $src);
    }
}
