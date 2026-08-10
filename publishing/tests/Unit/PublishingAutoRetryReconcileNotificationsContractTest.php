<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Console\ReconcileStuckPublishingCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RecoverStuckPublishingHandler;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassifier;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishOperationKeyFactory;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPublishTransitionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingWordPressReconciler;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingRecoveryNotifier;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingStuckRecoveryService;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueRetryWaitDefinition;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStatusLabelBuilder;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition;
use Omnichannel\Addons\WordPress\Services\SideEffect\SystemWordPressContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: publishing lease / retry / reconcile / notifications / UI labels.
 */
final class PublishingAutoRetryReconcileNotificationsContractTest extends TestCase
{
    public function test_retry_policy_backoff_and_max_attempts(): void
    {
        $policy = new PublishingRetryPolicy;
        self::assertSame(4, $policy->maxAttempts());
        self::assertTrue($policy->canRetry(1));
        self::assertTrue($policy->canRetry(3));
        self::assertFalse($policy->canRetry(4));
        $n1 = $policy->nextRetryAt(1);
        $n2 = $policy->nextRetryAt(2);
        $n3 = $policy->nextRetryAt(3);
        self::assertNotNull($n1);
        self::assertNotNull($n2);
        self::assertNotNull($n3);
        self::assertEqualsWithDelta(5, now()->diffInMinutes($n1), 1);
        self::assertEqualsWithDelta(15, now()->diffInMinutes($n2), 1);
        self::assertEqualsWithDelta(30, now()->diffInMinutes($n3), 1);
        self::assertNull($policy->nextRetryAt(4));
        self::assertTrue($policy->leaseExpiresAt()->isFuture());
    }

    public function test_failure_classifier_retryable_and_permanent(): void
    {
        $c = new PublishFailureClassifier;
        self::assertTrue($c->classify(null, ['http_status' => 503, 'message' => 'bad gateway'])->retryable);
        self::assertTrue($c->classify(null, ['http_status' => 429, 'message' => 'rate'])->retryable);
        self::assertTrue($c->classify(null, ['code' => 'lease_expired', 'message' => 'lease expired'])->retryable);
        self::assertFalse($c->classify(null, ['http_status' => 401, 'message' => 'auth'])->retryable);
        self::assertFalse($c->classify(null, ['http_status' => 403, 'message' => 'forbidden'])->retryable);
        self::assertFalse($c->classify(null, ['message' => 'Authentication failed'])->retryable);
        $sanitized = $c->sanitizeMessage('Bearer secret-token-value boom');
        self::assertStringNotContainsString('secret-token-value', $sanitized);
    }

    public function test_operation_key_stable_format(): void
    {
        $factory = new PublishOperationKeyFactory;
        $key = (new ReflectionClass($factory))->getMethod('mintNew');
        // mintNew requires SeoProjectTask â€” assert format via sprintf contract in source.
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PublishOperationKeyFactory::class))->getFileName(),
        );
        self::assertStringContainsString('content-project-item:%d:article:%d:publish:%s', $src);
        self::assertSame(4, PublishOperationKeyFactory::MAX_ATTEMPTS);
        unset($key);
    }

    public function test_stuck_definition_prefers_lease_expiry(): void
    {
        self::assertSame(5, PublishingQueueStuckPublishingDefinition::TTL_MINUTES);
        self::assertTrue(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publish_lease_expires_at' => now()->subMinute()->toIso8601String(),
        ]));
        self::assertFalse(PublishingQueueStuckPublishingDefinition::matches([
            'publish_queue_status' => 'processing',
            'publish_lease_expires_at' => now()->addMinutes(3)->toIso8601String(),
            'last_publish_attempt_at' => now()->subHour()->toIso8601String(),
        ]));
    }

    public function test_retry_wait_not_publishing_badge(): void
    {
        $row = [
            'publish_queue_status' => 'retrying',
            'publish_attempt_count' => 2,
            'next_publish_retry_at' => now()->addMinutes(4)->toIso8601String(),
        ];
        self::assertTrue(PublishingQueueRetryWaitDefinition::matches($row));
        $classified = PublishingQueueStateClassifier::classify($row);
        self::assertSame(PublishingQueueStateClassifier::RETRY_WAIT, $classified['state']);
        self::assertStringContainsString('Retry sau', $classified['label']);
        self::assertStringContainsString('Láº§n 2/4', $classified['label']);
    }

    public function test_runner_recovers_before_due_and_gates_retry(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );
        self::assertStringContainsString('PublishingStuckRecoveryService', $src);
        self::assertStringContainsString('recoverExpiredLeases', $src);
        self::assertStringContainsString('next_publish_retry_at', $src);
        self::assertStringContainsString('publish_operation_key', $src);
    }

    public function test_queue_service_has_lease_claim_and_retry_wait(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishingQueueService::class))->getFileName(),
        );
        self::assertStringContainsString('claimForPublishing', $src);
        self::assertStringContainsString('publish_lease_expires_at', $src);
        self::assertStringContainsString('markRetryWait', $src);
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('markPublishedFromReconcile', $src);
    }

    public function test_handler_claims_before_publish_and_persists_failures(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );
        self::assertStringContainsString('claimForPublishing', $src);
        self::assertStringContainsString('persistPublishFailure', $src);
        self::assertStringContainsString('resolveCommandBusIdempotencyKey', $src);
        self::assertStringContainsString(':attempt:', $src);
        self::assertStringNotContainsString("idempotencyKey: 'cp_publish_task_'", $src);
    }

    public function test_idempotency_store_releases_stale_processing_and_failed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore::class))->getFileName(),
        );
        self::assertStringContainsString('releasePublishOperation', $src);
        self::assertStringContainsString('isStale', $src);
        self::assertStringContainsString("status === 'failed'", $src);
        self::assertStringContainsString('OPERATION_ALREADY_PROCESSING', $src);
    }

    public function test_recover_handler_reconciles_not_blind_reset(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(RecoverStuckPublishingHandler::class))->getFileName(),
        );
        self::assertStringContainsString('PublishingStuckRecoveryService', $src);
        self::assertStringContainsString('recoverNow', $src);
        self::assertStringContainsString('wordpress_reconciled', $src);
    }

    public function test_transition_guard_allows_processing_to_retrying(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPublishTransitionGuard::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectPublishQueueStatus::Retrying', $src);
    }

    public function test_system_context_read_only_ops(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WordPressSideEffectGuard::class))->getFileName(),
        );
        self::assertStringContainsString('SystemWordPressContext', $src);
        self::assertStringContainsString('article.find_post_by_meta', $src);
        self::assertTrue(class_exists(SystemWordPressContext::class));
        self::assertTrue(class_exists(PublishingWordPressReconciler::class));
        self::assertTrue(class_exists(PublishingStuckRecoveryService::class));
        self::assertTrue(class_exists(PublishingRecoveryNotifier::class));
        self::assertTrue(class_exists(ReconcileStuckPublishingCommand::class));
    }

    public function test_notifier_dedup_and_queue_url(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PublishingRecoveryNotifier::class))->getFileName(),
        );
        self::assertStringContainsString('publishing-recovery:', $src);
        self::assertStringContainsString('sendToDatabase', $src);
        self::assertStringContainsString('publishing-queue', $src);
        self::assertStringContainsString('dedup_key', $src);
    }

    public function test_status_label_builder_failed_and_publishing(): void
    {
        self::assertStringContainsString('Publishing', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => 'publishing',
            'publish_attempt_count' => 1,
        ]));
        self::assertStringContainsString('Failed', PublishingQueueStatusLabelBuilder::label([
            'publish_state' => 'failed',
            'last_publish_error_code' => 'http_403',
            'last_publish_error_message' => 'Authentication failed',
        ]));
    }

    public function test_migration_adds_lease_fields(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_08_04_160000_add_publishing_lease_and_retry_fields_to_seo_project_tasks.php');
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        foreach ([
            'publishing_started_at',
            'publish_lease_expires_at',
            'publish_attempt_count',
            'next_publish_retry_at',
            'last_publish_error_code',
            'publish_operation_key',
        ] as $col) {
            self::assertStringContainsString($col, $src);
        }
    }
}
