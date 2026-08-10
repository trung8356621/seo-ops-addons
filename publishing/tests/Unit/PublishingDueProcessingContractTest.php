<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Application\Publishing\PublishReconcileResult;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Services\Publishing\PublishingDueItemSelector;
use Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStateClassifier;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;
use ReflectionClass;
use ReflectionMethod;

final class PublishingDueProcessingContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_due_selector_uses_utc_and_separate_retry_clock(): void
    {
        $src = $this->readAddon('Services/ContentProject/Publishing/PublishingDueItemSelector.php');
        self::assertStringContainsString("CarbonImmutable::now('UTC')", $src);
        self::assertStringContainsString('next_publish_retry_at', $src);
        self::assertStringContainsString('applyScheduledDue', $src);
        self::assertStringContainsString('applyRetryDue', $src);
        self::assertStringContainsString('Legacy fallback only when retry clock column missing', $src);

        $selector = new PublishingDueItemSelector;
        $now = $selector->nowUtc();
        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function test_timezone_schedule_roundtrip_claimable_after_due(): void
    {
        // User enters 05/08/2026 08:32 Asia/Ho_Chi_Minh → store UTC 01:32.
        SystemDateTime::useConfig(['timezone' => 'Asia/Ho_Chi_Minh', 'preset' => 'vi']);
        try {
            $utc = SystemDateTime::parseSystemInputToUtc('05/08/2026 08:32');
            self::assertSame('2026-08-05 01:32:00', $utc->format('Y-m-d H:i:s'));

            $before = CarbonImmutable::parse('2026-08-05 01:31:00', 'UTC');
            $after = CarbonImmutable::parse('2026-08-05 01:33:00', 'UTC');
            self::assertTrue($utc->greaterThan($before));
            self::assertTrue($utc->lessThanOrEqualTo($after));
        } finally {
            SystemDateTime::useConfig(null);
        }
    }

    public function test_runner_uses_due_selector_and_does_not_mark_success_on_zero(): void
    {
        $runner = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueRunner.php');
        self::assertStringContainsString('PublishingDueItemSelector', $runner);
        self::assertStringContainsString('rememberScannerRun', $runner);
        self::assertStringContainsString('rememberDueBacklog', $runner);
        self::assertStringContainsString('publishing.due_scan', $runner);
        self::assertStringContainsString('skip_reason_counts', $runner);
        self::assertStringContainsString('if ($claimed > 0 || (int) $stats[\'published\'] > 0)', $runner);
        self::assertStringContainsString('sync-command-bus', $runner);
        self::assertTrue(class_exists(ContentProjectPublishingQueueRunner::class));
    }

    public function test_health_separates_scanner_and_overdue_degraded(): void
    {
        $health = $this->readAddon('Services/ContentProject/ContentProjectQueueHealthService.php');
        self::assertStringContainsString('CACHE_LAST_SCANNER_RUN', $health);
        self::assertStringContainsString('CACHE_LAST_PUBLISHER_PROCESSED', $health);
        self::assertStringContainsString('CACHE_DUE_BACKLOG', $health);
        self::assertStringContainsString('rememberScannerRun', $health);
        self::assertStringContainsString('Degraded —', $health);
        self::assertStringContainsString('Runner stopped', $health);
        self::assertTrue(class_exists(ContentProjectQueueHealthService::class));
    }

    public function test_schedule_clears_all_error_fields(): void
    {
        $svc = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueService.php');
        self::assertStringContainsString('scheduleResetAttributes', $svc);
        self::assertStringContainsString('last_publish_error_message', $svc);
        self::assertStringContainsString('last_publish_error_code', $svc);
        self::assertStringContainsString('next_publish_retry_at', $svc);
    }

    public function test_wp_missing_post_hidden_for_scheduled_states(): void
    {
        $src = $this->readAddon('Services/ContentProject/ContentProjectPublishingQueueReadModel.php');
        self::assertStringContainsString('visiblePublishMessage', $src);
        self::assertStringContainsString('WP_PUBLISHED_POST_NOT_FOUND', $src);

        $method = new ReflectionMethod(ContentProjectPublishingQueueReadModel::class, 'visiblePublishMessage');
        $method->setAccessible(true);
        $rm = (new ReflectionClass(ContentProjectPublishingQueueReadModel::class))->newInstanceWithoutConstructor();

        $hidden = $method->invoke($rm, [
            'publish_state' => PublishingQueueStateClassifier::SCHEDULED,
            'last_publish_error_code' => 'WP_PUBLISHED_POST_NOT_FOUND',
            'last_publish_error_message' => 'WordPress has no matching published post.',
            'last_publish_error' => 'WordPress has no matching published post.',
        ]);
        self::assertSame('', $hidden);

        $retryHidden = $method->invoke($rm, [
            'publish_state' => PublishingQueueStateClassifier::RETRY_WAIT,
            'last_publish_error_code' => 'WP_PUBLISHED_POST_NOT_FOUND',
            'last_publish_error_message' => 'WordPress has no matching published post.',
            'last_publish_error' => 'WordPress has no matching published post.',
        ]);
        self::assertSame('', $retryHidden);

        $failedVisible = $method->invoke($rm, [
            'publish_state' => PublishingQueueStateClassifier::FAILED,
            'last_publish_error_code' => 'WP_PUBLISHED_POST_NOT_FOUND',
            'last_publish_error_message' => 'WordPress has no matching published post.',
            'last_publish_error' => 'WordPress has no matching published post.',
        ]);
        self::assertStringContainsString('WordPress has no matching published post', $failedVisible);
    }

    public function test_reconcile_result_has_structured_code(): void
    {
        self::assertSame('WP_PUBLISHED_POST_NOT_FOUND', PublishReconcileResult::CODE_WP_PUBLISHED_POST_NOT_FOUND);
        $src = $this->readAddon('Services/ContentProject/Application/Publishing/PublishingWordPressReconciler.php');
        self::assertStringContainsString('CODE_WP_PUBLISHED_POST_NOT_FOUND', $src);
    }

    public function test_retry_policy_uses_utc(): void
    {
        $policy = new PublishingRetryPolicy;
        $next = $policy->nextRetryAt(1);
        self::assertNotNull($next);
        self::assertSame('UTC', $next->getTimezone()->getName());
        $lease = $policy->leaseExpiresAt(Carbon::parse('2026-08-05 01:00:00', 'UTC'));
        self::assertSame('UTC', $lease->getTimezone()->getName());
    }

    public function test_requeue_overdue_command_registered(): void
    {
        $cmd = $this->readAddon('Console/RequeueOverduePublishingCommand.php');
        self::assertStringContainsString('seo:publishing:requeue-overdue', $cmd);
        self::assertStringContainsString('--dry-run', $cmd);
        $provider = $this->readAddon('SeoContentAiServiceProvider.php');
        self::assertStringContainsString('RequeueOverduePublishingCommand', $provider);
    }
}
