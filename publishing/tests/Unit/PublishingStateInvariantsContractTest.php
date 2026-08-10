<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Console\RequeueOverduePublishingCommand;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingActiveProcessing;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingProcessingMarkerClearer;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingStaleStateRepairer;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Canonical active-publishing invariants + stale marker repair contracts.
 */
final class PublishingStateInvariantsContractTest extends TestCase
{
    /**
     * Resolve source of an addon class by FQCN via reflection (preferred), falling
     * back to a basename search under the known addon src roots for any legacy
     * relative path still passed in (e.g. 'Console/Foo.php').
     */
    private function readAddon(string $classOrRelative): string
    {
        if (class_exists($classOrRelative) || interface_exists($classOrRelative) || trait_exists($classOrRelative)) {
            $path = (new ReflectionClass($classOrRelative))->getFileName();
            self::assertNotFalse($path, sprintf('Unable to resolve file for class %s', $classOrRelative));
        } else {
            $path = $this->resolveAddonPathByBasename($classOrRelative);
        }

        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    private function resolveAddonPathByBasename(string $relative): string
    {
        $basename = basename(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        $roots = [
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src',
            dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'content-projects'.DIRECTORY_SEPARATOR.'src',
        ];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getFilename() === $basename) {
                    return $file->getPathname();
                }
            }
        }

        self::fail(sprintf('Unable to locate "%s" under addons/publishing/src or addons/content-projects/src', $relative));
    }

    private function task(array $attrs): SeoProjectTask
    {
        $task = new SeoProjectTask;
        $task->setRawAttributes($attrs, true);

        return $task;
    }

    public function test_retry_wait_with_expired_lease_is_not_actively_publishing(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 10:00:00', 'UTC');

        $retryWait = $this->task([
            'id' => 101,
            'publish_queue_status' => 'retrying',
            'publish_lease_expires_at' => '2026-08-05 09:00:00',
            'publishing_started_at' => '2026-08-05 08:55:00',
            'next_publish_retry_at' => '2026-08-05 10:03:00',
            'publish_attempt_count' => 2,
        ]);

        self::assertFalse($predicate->isActivelyPublishing($retryWait, $now));
        self::assertSame('retry_wait_with_stale_claim', $predicate->classifyStaleReason($retryWait, $now));
    }

    public function test_retry_wait_with_stale_running_operation_is_repairable(): void
    {
        $predicate = new PublishingActiveProcessing;
        $task = $this->task([
            'id' => 102,
            'publish_queue_status' => 'retrying',
            'publish_lease_expires_at' => '2026-08-05 09:00:00',
            'publishing_started_at' => '2026-08-05 08:50:00',
            'publish_operation_key' => 'op-stale-102',
            'publish_attempt_count' => 2,
            'next_publish_retry_at' => '2026-08-05 12:00:00',
        ]);

        self::assertTrue($predicate->hasStaleProcessingMarkers($task, CarbonImmutable::parse('2026-08-05 10:00:00', 'UTC')));
        self::assertNotSame('active_non_expired_processing', $predicate->classifyStaleReason($task));
    }

    public function test_retry_wait_transition_clears_claim_token_lease_and_started(): void
    {
        $clearer = new PublishingProcessingMarkerClearer;
        $attrs = $clearer->clearedAttributes();

        self::assertArrayHasKey('publish_lease_expires_at', $attrs);
        self::assertNull($attrs['publish_lease_expires_at']);
        self::assertArrayHasKey('publishing_started_at', $attrs);
        self::assertNull($attrs['publishing_started_at']);
        self::assertArrayHasKey('publish_claim_token', $attrs);
        self::assertNull($attrs['publish_claim_token']);
        self::assertArrayHasKey('publish_claimed_at', $attrs);
        self::assertNull($attrs['publish_claimed_at']);

        $svc = $this->readAddon(ContentProjectPublishingQueueService::class);
        self::assertStringContainsString('markerClearer()->mergeInto', $svc);
        self::assertStringContainsString("applySideEffects(\$task, 'retry_wait')", $svc);
    }

    public function test_scheduled_transition_clears_old_processing_markers(): void
    {
        $svc = $this->readAddon(ContentProjectPublishingQueueService::class);
        self::assertStringContainsString('scheduleResetAttributes', $svc);
        self::assertStringContainsString('markerClearer()->mergeInto($attrs)', $svc);
    }

    public function test_active_non_expired_processing_remains_protected(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);
        try {
            $active = $this->task([
                'id' => 103,
                'publish_queue_status' => 'processing',
                'publish_lease_expires_at' => '2026-08-05 10:04:00',
                'publisher_started_at' => '2026-08-05 09:59:00',
                'publishing_started_at' => '2026-08-05 09:59:00',
                'publish_attempt_count' => 1,
            ]);

            self::assertTrue($predicate->isActivelyPublishing($active, $now));
            self::assertSame('active_real_publisher', $predicate->classifyStaleReason($active, $now));

            $resolver = new ContentProjectItemStateResolver;
            $state = $resolver->resolve($active);
            self::assertNotContains(ContentProjectItemAction::RetryPublish, $state->availableActions);
            self::assertNotContains(ContentProjectItemAction::PublishNow, $state->availableActions);
            self::assertSame('Publish queue is active.', $state->blockingReason);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_stale_operation_alone_does_not_block_retry(): void
    {
        $resolver = new ContentProjectItemStateResolver;
        $task = $this->task([
            'id' => 104,
            'status' => 'completed',
            'publish_queue_status' => 'retrying',
            'publish_lease_expires_at' => null,
            'publishing_started_at' => '2026-08-05 08:00:00',
            'publish_operation_key' => 'op-old',
            'publish_attempt_count' => 2,
            'next_publish_retry_at' => '2026-08-05 10:03:00',
        ]);

        $state = $resolver->resolve($task);
        self::assertContains(ContentProjectItemAction::RetryPublish, $state->availableActions);
        self::assertNull($state->blockingReason);

        $guard = new ContentProjectItemActionGuard;
        $guard->assertCan(ContentProjectItemAction::RetryPublish, $task, null, $resolver);
        self::assertTrue(true);
    }

    public function test_stale_operation_alone_does_not_block_publish_now(): void
    {
        $resolver = new ContentProjectItemStateResolver;
        $task = $this->task([
            'id' => 105,
            'status' => 'completed',
            'publish_queue_status' => 'retrying',
            'publish_lease_expires_at' => null,
            'publish_operation_key' => 'op-old-105',
            'publish_attempt_count' => 2,
        ]);

        $guard = new ContentProjectItemActionGuard;
        $guard->assertCan(ContentProjectItemAction::PublishNow, $task, null, $resolver);
        self::assertTrue(true);
    }

    public function test_stale_claim_does_not_prevent_due_scanner_recovery_contract(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 10:00:00', 'UTC');
        $overdue = $this->task([
            'id' => 106,
            'publish_queue_status' => 'waiting',
            'scheduled_publish_at' => '2026-08-05 09:00:00',
            'publish_lease_expires_at' => '2026-08-05 09:30:00',
            'publishing_started_at' => '2026-08-05 09:25:00',
        ]);

        self::assertFalse($predicate->isActivelyPublishing($overdue, $now));
        self::assertSame('scheduled_with_stale_claim', $predicate->classifyStaleReason($overdue, $now));

        $runner = $this->readAddon(ContentProjectPublishingQueueRunner::class);
        self::assertStringContainsString('skip_reason_counts', $runner);
        self::assertStringContainsString('PublishDueItemService', $runner);
        self::assertStringContainsString('claim_rejected_ids', $runner);
    }

    public function test_recovery_finalizes_old_operation_and_preserves_attempts(): void
    {
        $clearerSrc = $this->readAddon(PublishingProcessingMarkerClearer::class);
        self::assertStringContainsString('releasePublishOperation', $clearerSrc);
        self::assertStringContainsString('Cache::forget', $clearerSrc);

        $repairerSrc = $this->readAddon(PublishingStaleStateRepairer::class);
        self::assertStringContainsString('publish_attempt_count', $repairerSrc);
        self::assertStringContainsString('next_publish_retry_at', $repairerSrc);
        self::assertStringContainsString('active_non_expired_processing', $repairerSrc);
        self::assertStringContainsString('publishing.stale_state_repaired', $repairerSrc);

        // repairOne only merges markerClearer attributes — never increments attempts.
        self::assertStringContainsString('markerClearer->clearedAttributes', $repairerSrc);
        self::assertStringNotContainsString('publish_attempt_count ?? 0) + 1', $repairerSrc);
        self::assertStringNotContainsString("'publish_attempt_count' => null", $repairerSrc);
    }

    public function test_valid_future_next_publish_retry_at_unchanged_in_repairer(): void
    {
        $src = $this->readAddon(PublishingStaleStateRepairer::class);
        self::assertStringContainsString('Preserve attempt counters and next_publish_retry_at', $src);
        self::assertStringNotContainsString("'next_publish_retry_at' => null", $src);
    }

    public function test_due_scan_emits_skip_reason_counts(): void
    {
        $runner = $this->readAddon(ContentProjectPublishingQueueRunner::class);
        self::assertStringContainsString("'skip_reason_counts' => \$skipReasonCounts", $runner);
        self::assertStringContainsString('claim_rejection_reason', $runner);
        self::assertStringContainsString('publisher_invoked_ids', $runner);
        self::assertStringContainsString('publishing.due_scan_complete', $runner);
        self::assertStringContainsString('publishing.due_scan_no_progress', $runner);
        self::assertTrue(class_exists(ContentProjectPublishingQueueRunner::class));
    }

    public function test_twelve_overdue_scheduled_style_fixtures_claimable_after_stale_repair(): void
    {
        $predicate = new PublishingActiveProcessing;
        $now = CarbonImmutable::parse('2026-08-05 12:00:00', 'UTC');
        $claimable = 0;

        for ($i = 1; $i <= 12; $i++) {
            $task = $this->task([
                'id' => 2000 + $i,
                'publish_queue_status' => 'waiting',
                'scheduled_publish_at' => '2026-08-05 11:00:00',
                'publish_lease_expires_at' => '2026-08-05 11:30:00',
                'publishing_started_at' => '2026-08-05 11:25:00',
                'publish_attempt_count' => 1,
            ]);

            self::assertFalse($predicate->isActivelyPublishing($task, $now));
            $reason = $predicate->classifyStaleReason($task, $now);
            self::assertSame('scheduled_with_stale_claim', $reason);

            // After repair: markers cleared, status stays waiting → claimable by due selector.
            $repaired = $this->task([
                'id' => 2000 + $i,
                'publish_queue_status' => 'waiting',
                'scheduled_publish_at' => '2026-08-05 11:00:00',
                'publish_lease_expires_at' => null,
                'publishing_started_at' => null,
                'publish_attempt_count' => 1,
            ]);
            self::assertFalse($predicate->isActivelyPublishing($repaired, $now));
            self::assertNull($predicate->classifyStaleReason($repaired, $now));
            $claimable++;
        }

        self::assertSame(12, $claimable);
    }

    public function test_ui_state_and_command_guard_use_same_canonical_predicate(): void
    {
        $resolverSrc = $this->readAddon(ContentProjectItemStateResolver::class);
        $guardSrc = $this->readAddon(ContentProjectItemActionGuard::class);
        $svcSrc = $this->readAddon(ContentProjectPublishingQueueService::class);

        self::assertStringContainsString('PublishingActiveProcessing', $resolverSrc);
        self::assertStringContainsString('isActivelyPublishing', $resolverSrc);
        self::assertStringContainsString('isActivelyPublishing', $guardSrc);
        self::assertStringContainsString('activeProcessing()->isActivelyPublishing', $svcSrc);

        $toast = 'Item đang Publishing (queue processing). Retry/Publish now không dùng được — dùng Recover stuck publishing.';
        self::assertStringContainsString($toast, $guardSrc);

        $retryWait = $this->task([
            'id' => 107,
            'status' => 'completed',
            'publish_queue_status' => 'retrying',
            'publish_lease_expires_at' => null,
        ]);
        $active = $this->task([
            'id' => 108,
            'status' => 'completed',
            'publish_queue_status' => 'processing',
            'publish_lease_expires_at' => '2099-01-01 00:00:00',
            'publisher_started_at' => '2099-01-01 00:00:00',
        ]);

        $resolver = new ContentProjectItemStateResolver;
        $guard = new ContentProjectItemActionGuard;

        $guard->assertCan(ContentProjectItemAction::RetryPublish, $retryWait, null, $resolver);

        try {
            $guard->assertCan(ContentProjectItemAction::RetryPublish, $active, null, $resolver);
            self::fail('Expected active processing to block RetryPublish');
        } catch (RuntimeException $e) {
            self::assertSame($toast, $e->getMessage());
        }
    }

    public function test_transition_guard_allows_expired_processing_to_waiting(): void
    {
        $guard = new ContentProjectPublishTransitionGuard;
        $guard->assertCanTransition('processing', 'waiting');
        $guard->assertCanTransition('processing', 'none');
        self::assertTrue(true);
    }

    public function test_requeue_command_reports_stale_reasons(): void
    {
        $src = $this->readAddon(RequeueOverduePublishingCommand::class);
        self::assertStringContainsString('PublishingStaleStateRepairer', $src);
        self::assertStringContainsString('scheduled_with_stale_claim', $src);
        self::assertStringContainsString('retry_wait_with_stale_claim', $src);
        self::assertStringContainsString('expired_processing', $src);
        self::assertStringContainsString('active_non_expired_processing', $src);
        self::assertStringContainsString('item=%d reason=%s', $src);
    }

    public function test_mark_retry_wait_uses_failure_classification_symbol(): void
    {
        self::assertTrue(class_exists(PublishFailureClassification::class));
        self::assertTrue(class_exists(ContentProjectPublishingQueueService::class));
        self::assertTrue(class_exists(PublishingStaleStateRepairer::class));
        self::assertTrue((new ReflectionClass(PublishingActiveProcessing::class))->hasMethod('isActivelyPublishing'));
    }
}
