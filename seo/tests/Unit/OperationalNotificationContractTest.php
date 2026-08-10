<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Tests\Support\ProjectRoot;

use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\PromptContractNotificationPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;
use ReflectionClass;

final class OperationalNotificationContractTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    private function readCore(string $relative): string
    {
        // Unit → tests → SeoContentAi → Addons → app → project root
        $path = ProjectRoot::path().DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_canonical_service_and_event_codes_exist(): void
    {
        self::assertTrue(enum_exists(OperationalNotificationEventCode::class));
        self::assertTrue(enum_exists(NotificationSeverity::class));
        self::assertSame(['info', 'warning', 'danger', 'critical'], array_map(
            static fn (NotificationSeverity $s): string => $s->value,
            NotificationSeverity::cases(),
        ));

        $codes = OperationalNotificationEventCode::values();
        foreach ([
            'publishing.stuck',
            'publishing.retry_started',
            'publishing.retry_succeeded',
            'publishing.retry_exhausted',
            'publishing.reconciled',
            'prompt.contract_invalid',
            'generation.batch_partial_failed',
            'generation.batch_failed',
            'generation.stuck',
            'generation.recovered',
            'generation.retry_exhausted',
            'runner.unhealthy',
            'runner.recovered',
            'wordpress.connection_failed',
            'wordpress.connection_recovered',
            'wordpress.capability_missing',
            'wordpress.callback_rejected',
            'review.items_assigned',
            'site_sync.partial_failed',
            'site_sync.stuck',
            'site_sync.failed',
            'site_sync.recovered',
        ] as $code) {
            self::assertContains($code, $codes);
        }

        $service = $this->readAddon('Services/Notifications/OperationalNotificationService.php');
        self::assertStringContainsString('function notify(', $service);
        self::assertStringContainsString('function resolve(', $service);
        self::assertStringContainsString('dedup_key', $service);
        self::assertStringContainsString('occurrence_count', $service);
        self::assertStringContainsString('resolved_at', $service);
        self::assertStringContainsString('unreadOperationalCount', $service);
    }

    public function test_migration_adds_operational_columns(): void
    {
        $migration = $this->readCore('database/migrations/2026_08_04_230000_add_operational_fields_to_notifications_table.php');
        foreach ([
            'event_code',
            'severity',
            'dedup_key',
            'group_key',
            'occurrence_count',
            'first_occurred_at',
            'last_occurred_at',
            'resolved_at',
            'notifications_dedup_active_idx',
            'notifications_unread_ops_idx',
        ] as $needle) {
            self::assertStringContainsString($needle, $migration);
        }
    }

    public function test_recipient_resolver_rules_present(): void
    {
        $resolver = $this->readAddon('Services/Notifications/OperationalNotificationRecipientResolver.php');
        self::assertStringContainsString('forPromptOrSystemError', $resolver);
        self::assertStringContainsString('forGenerationBatch', $resolver);
        self::assertStringContainsString('forReviewAssignment', $resolver);
        self::assertStringContainsString('forRunnerHealth', $resolver);
        self::assertStringContainsString('forWordPressConnection', $resolver);
        self::assertStringContainsString('forSiteSync', $resolver);
        self::assertStringContainsString('forPublishing', $resolver);
        self::assertStringContainsString('STATUS_NORMAL', $resolver);
        self::assertStringContainsString('canAccessSeoPanel', $resolver);
    }

    public function test_publishers_and_dedup_keys(): void
    {
        $prompt = $this->readAddon('Services/Notifications/Publishers/PromptContractNotificationPublisher.php');
        self::assertStringContainsString('prompt-contract:%d:%d:%s:%s', $prompt);
        self::assertStringContainsString('Unknown input key', $prompt);

        $batch = $this->readAddon('Services/Notifications/Publishers/GenerationBatchNotificationPublisher.php');
        self::assertStringContainsString('generation-batch:%d:%d:%d', $batch);
        self::assertStringContainsString('GenerationBatchFailed', $batch);
        self::assertStringNotContainsString('generation_batch_success_long', $batch);

        $stuck = $this->readAddon('Services/Notifications/Publishers/GenerationStuckNotificationPublisher.php');
        self::assertStringContainsString('generation-stuck:%d:%d:%s', $stuck);

        $runner = $this->readAddon('Services/Notifications/Publishers/RunnerHealthNotificationPublisher.php');
        self::assertStringContainsString('runner-health:%d:%s', $runner);
        self::assertStringContainsString('warning_cycles', $runner);

        $wp = $this->readAddon('Services/Notifications/Publishers/WordPressConnectionNotificationPublisher.php');
        self::assertStringContainsString('wordpress-connection:%d:%d:%s', $wp);
        self::assertStringContainsString('wordpress-capability:%d:%s', $wp);
        self::assertStringContainsString('[redacted]', $wp);

        $review = $this->readAddon('Services/Notifications/Publishers/ReviewAssignmentNotificationPublisher.php');
        self::assertStringContainsString('review-assignment:%d:%d:%d:%d', $review);
        self::assertStringContainsString('actorUserId === $reviewerId', $review);

        $siteSync = $this->readAddon('Services/Notifications/Publishers/SiteSyncIncidentNotificationPublisher.php');
        self::assertStringContainsString('site-sync-run:%d:%d:%d', $siteSync);
        self::assertStringContainsString('site-sync-stuck:%d:%d:%s', $siteSync);

        $publishing = $this->readAddon('Services/Notifications/Publishers/PublishingOperationalNotificationPublisher.php');
        self::assertStringContainsString('forPublishing', $publishing);
        self::assertStringContainsString('Publishing Queue auto-retry patch', $publishing);
    }

    public function test_prompt_contract_detector(): void
    {
        $publisher = (new ReflectionClass(PromptContractNotificationPublisher::class))
            ->newInstanceWithoutConstructor();

        self::assertTrue($publisher->isContractFailure([
            'failure_category' => 'INVALID_INPUT',
            'message' => 'Unknown input key [topic]',
        ]));
        self::assertFalse($publisher->isContractFailure([
            'failure_category' => 'PROVIDER_TIMEOUT',
            'message' => 'timeout',
        ]));
    }

    public function test_deep_links_include_entity_filters(): void
    {
        $links = $this->readAddon('Services/Notifications/OperationalNotificationDeepLinks.php');
        self::assertStringContainsString('workflow', $links);
        self::assertStringContainsString('recently_completed', $links);
        self::assertStringContainsString('publishing-queue', $links);
        self::assertStringContainsString('content-operations', $links);
        self::assertStringContainsString('site-sync-operations', $links);
        self::assertTrue(class_exists(OperationalNotificationDeepLinks::class));
    }

    public function test_lifecycle_hooks_wired(): void
    {
        $workflow = $this->readAddon('Services/SeoProjectWorkflowRunService.php');
        self::assertStringContainsString('GenerationBatchNotificationPublisher', $workflow);
        self::assertStringContainsString('PromptContractNotificationPublisher', $workflow);
        self::assertStringContainsString('ReviewAssignmentNotificationPublisher', $workflow);

        $recovery = $this->readAddon('Services/ContentProject/ContentProjectGenerationRecoveryService.php');
        self::assertStringContainsString('GenerationStuckNotificationPublisher', $recovery);

        $siteSync = $this->readAddon('Services/SiteSync/Orchestration/SiteSyncStepRunner.php');
        self::assertStringContainsString('SiteSyncIncidentNotificationPublisher', $siteSync);
        self::assertStringContainsString('WordPressConnectionNotificationPublisher', $siteSync);
        self::assertStringContainsString('notifySiteSyncTerminal', $siteSync);

        $provider = $this->readAddon('SeoContentAiServiceProvider.php');
        self::assertStringContainsString('ReconcileActiveOperationalNotificationsCommand', $provider);
        self::assertStringContainsString('CheckOperationalRunnerHealthCommand', $provider);
        self::assertStringContainsString('seo-content-ai:operational-runner-health', $provider);
    }

    public function test_panel_polling_reused_not_faster_than_30s(): void
    {
        $panel = $this->readAddon('Providers/SeoPanelProvider.php');
        self::assertStringContainsString("databaseNotificationsPolling('30s')", $panel);
        self::assertStringContainsString('->databaseNotifications()', $panel);
    }

    public function test_anti_spam_and_no_second_system(): void
    {
        $service = $this->readAddon('Services/Notifications/OperationalNotificationService.php');
        self::assertStringContainsString('Canonical operational notification service', $service);

        $batch = $this->readAddon('Services/Notifications/Publishers/GenerationBatchNotificationPublisher.php');
        self::assertStringContainsString('if ($failed <= 0)', $batch);

        $siteSync = $this->readAddon('Services/Notifications/Publishers/SiteSyncIncidentNotificationPublisher.php');
        self::assertStringNotContainsString('quick sync success', strtolower($siteSync));

        $stepRunner = $this->readAddon('Services/SiteSync/Orchestration/SiteSyncStepRunner.php');
        self::assertStringContainsString('Quick sync success — intentionally no notification', $stepRunner);
    }

    public function test_commands_exist(): void
    {
        $reconcile = $this->readAddon('Console/ReconcileActiveOperationalNotificationsCommand.php');
        self::assertStringContainsString('seo:notifications:reconcile-active-incidents', $reconcile);
        self::assertStringContainsString('--dry-run', $reconcile);
        self::assertStringContainsString('subMinutes(5)', $reconcile);
        self::assertStringContainsString('Site Sync stuck: no progress for 5 minutes', $reconcile);
        self::assertStringContainsString('watchdog_failed_at', $reconcile);

        $health = $this->readAddon('Console/CheckOperationalRunnerHealthCommand.php');
        self::assertStringContainsString('seo:notifications:check-runner-health', $health);
    }

    public function test_severity_filament_mapping(): void
    {
        self::assertSame('danger', NotificationSeverity::Critical->filamentStatus());
        self::assertSame('heroicon-o-fire', NotificationSeverity::Critical->filamentIcon());
        self::assertSame('warning', NotificationSeverity::Warning->filamentIconColor());
        self::assertSame('prompt', OperationalNotificationEventCode::PromptContractInvalid->module());
        self::assertSame('publishing', OperationalNotificationEventCode::PublishingStuck->module());
    }
}
